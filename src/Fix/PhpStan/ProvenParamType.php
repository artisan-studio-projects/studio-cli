<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;

/**
 * A `@param` on one of the project's own methods that turns away what its
 * callers really pass, in two shapes PHPStan proves are the same values:
 *
 * - narrower values in a generic, such as `Collection<int, array{score:
 *   int<1, max>}>` for `Collection<int, array{score: int}>`: generics do
 *   not take the narrower one, so the `@param` says it is happy with any
 *   subtype, `Collection<int, covariant array{score: int}>`;
 * - keys a `filter()` or `unique()` left as they were, `(int|string)` where
 *   the `@param` says `int`: the `@param` takes `array-key`.
 *
 * Never on a package's method, and never when the types really differ.
 */
final class ProvenParamType implements Fixer
{
    private const array REFINEMENTS = [
        '/\b(?:non-empty-string|non-falsy-string|literal-string|lowercase-string|uppercase-string|numeric-string|truthy-string)\b/' => 'string',
        '/\bclass-string(?:<[^<>]*>)?/' => 'string',
        "/'(?:[^'\\\\]|\\\\.)*'/" => 'string',
        '/\bint<[^<>]*>|\b(?:positive-int|negative-int|non-negative-int|non-positive-int|non-zero-int)\b/' => 'int',
        '/\bnon-empty-list\b/' => 'list',
        '/\bnon-empty-array\b/' => 'array',
        '/\b(?:true|false)\b/' => 'bool',
    ];

    public function rule(): string
    {
        return 'argument.type';
    }

    public function label(): string
    {
        return 'parameter docblocks that turned away what callers really pass';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $method = preg_match('/^Parameter (?:#\d+ )?\$(\w+) of (?:static )?method ([\w\\\\]+)::(\w+)\(\) expects (.+) given\.$/', $message, $found) === 1;
        $constructor = ! $method && preg_match('/^Parameter (?:#\d+ )?\$(\w+) of class ([\w\\\\]+) constructor expects (.+) given\.$/', $message, $built) === 1;

        if ((! $method && ! $constructor) || ProvenTypes::cutShort($message)) {
            return false;
        }

        [$parameter, $class, $method, $both] = $method ? [$found[1], $found[2], $found[3], $found[4]] : [$built[1], $built[2], '__construct', $built[3]];
        $types = ProvenTypes::expectsGiven($both);
        [$file, $node] = $this->declaration($bench, $class, $method) ?? [null, null];
        $doc = $node?->getDocComment();

        if ($types === null || $file === null || $doc === null) {
            return false;
        }

        [$expects, $given] = $types;
        $span = ProvenTypes::tagType($doc->getText(), 'param', $parameter);
        $widened = $span === null ? null : $this->widened($span['type'], $expects, $given);

        return $widened !== null
            && $file->replace($doc->getStartFilePos() + $span['from'], $doc->getStartFilePos() + $span['to'], $widened);
    }

    /**
     * Where the project declares the method: on the class itself, or in one of
     * the traits it uses, however deep.
     *
     * @return array{0: SourceFile, 1: ClassMethod}|null
     */
    private function declaration(Workbench $bench, string $class, string $method, int $depth = 0): ?array
    {
        $where = $depth > 3 ? null : $bench->classFile($class);
        $file = $where === null ? null : $bench->open($where);
        $owner = $file?->classNamed($class);

        if ($file === null || $owner === null) {
            return null;
        }

        $declared = collect($owner->getMethods())->first(fn (ClassMethod $node): bool => $node->name->toString() === $method);

        return $declared !== null
            ? [$file, $declared]
            : collect($owner->getTraitUses())
                ->flatMap(fn (TraitUse $use): array => $use->traits)
                ->map(fn (Name $trait): ?array => $this->declaration($bench, $this->resolved($trait), $method, $depth + 1))
                ->first(fn (?array $found): bool => $found !== null);
    }

    private function resolved(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }

    /**
     * The written type, widened to take what is given, or null when the given
     * type is not the same values said differently.
     */
    private function widened(string $written, string $expects, string $given): ?string
    {
        $ignoresReturn = preg_match('/^\(?Closure\((.*)\): void\)?(\|null)?$/', $expects, $wants) === 1
            && preg_match('/^Closure\((.*)\): [^()]+$/', $given, $gets) === 1
            && $this->flat($wants[1]) === $this->flat($gets[1]);

        if ($ignoresReturn) {
            $mixed = (string) preg_replace('/(Closure\([^()]*\)):\s*void\b/', '$1: mixed', $written, 1);

            return $mixed === $written ? null : $mixed;
        }

        $keysWidened = (string) preg_replace('/<\(int\|string\),/', '<int,', $given);

        if ($this->flat($keysWidened) !== $this->flat($expects)) {
            return null;
        }

        $keys = $keysWidened !== $given ? (string) preg_replace('/^([\w\\\\]+)<int,/', '$1<array-key,', $written, 1) : $written;
        $covariant = $this->flat($given) === $this->flat($expects) || $keysWidened !== $given
            ? (string) preg_replace('/^([\w\\\\]*Collection|[\w\\\\]*Enumerable)<([^,<>]+),\s*(?!covariant\b)/', '$1<$2, covariant ', $keys)
            : $keys;

        return $covariant === $written ? null : $covariant;
    }

    private function flat(string $type): string
    {
        $wide = (string) preg_replace(array_keys(self::REFINEMENTS), array_values(self::REFINEMENTS), $type);

        return (string) preg_replace(['/\s+/', '/\\\\/'], '', $wide);
    }
}
