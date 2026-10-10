<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;

/**
 * "Method foo() never returns null so it can be removed from the return
 * type": the type is taken out of the method's own signature.
 *
 * A narrower return type is allowed on an override, so this never breaks a
 * parent's contract. It would break a child that returns the type it was
 * left for, so a method on a class something extends is left alone unless it
 * is private or final.
 */
final class UnusedReturnType implements Fixer
{
    public function rule(): string
    {
        return 'return.unusedType';
    }

    public function label(): string
    {
        return 'return types that were never returned narrowed';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->open($path);

        if ($file === null || preg_match('/^Method \S+::(\w+)\(\) never returns ([\w\\\\]+) so it can be removed from the return type\.$/', $message, $found) !== 1) {
            return false;
        }

        $method = collect($file->spanning($line, ClassMethod::class))->first(fn (ClassMethod $method): bool => $method->name->toString() === $found[1]);
        $class = $method instanceof ClassMethod ? collect($file->spanning($line, Class_::class))->last() : null;

        if (! $method instanceof ClassMethod || $method->returnType === null || ! $class instanceof Class_ || ! $this->isSafeToNarrow($bench, $class, $method)) {
            return false;
        }

        $narrowed = $this->without($file, $method->returnType, strtolower(ltrim(strrchr('\\'.$found[2], '\\'), '\\')));

        return $narrowed !== null && $file->replace($method->returnType->getStartFilePos(), $method->returnType->getEndFilePos() + 1, $narrowed);
    }

    private function isSafeToNarrow(Workbench $bench, Class_ $class, ClassMethod $method): bool
    {
        return $class->isFinal() || $method->isPrivate() || ! $bench->isExtended((string) $class->name);
    }

    private function without(SourceFile $file, Node $type, string $name): ?string
    {
        $parts = match (true) {
            $type instanceof UnionType => $type->types,
            $type instanceof NullableType => [new Identifier('null'), $type->type],
            default => [],
        };
        $kept = array_values(array_filter($parts, fn (Node $part): bool => $this->nameOf($part) !== $name));

        $written = count(array_filter($kept, fn (Node $part): bool => $part->getStartFilePos() >= 0)) === count($kept);

        return count($parts) - count($kept) === 1 && count($kept) >= 1 && $written
            ? implode('|', array_map(fn (Node $part): string => $file->text($part), $kept))
            : null;
    }

    private function nameOf(Node $part): string
    {
        return match (true) {
            $part instanceof Identifier => strtolower($part->toString()),
            $part instanceof Name => strtolower($part->getLast()),
            default => '',
        };
    }
}
