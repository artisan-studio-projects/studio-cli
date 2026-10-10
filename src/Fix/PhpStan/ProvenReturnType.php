<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * A `@return` that says one thing while the method returns another. The code
 * is what runs, so the docblock takes the type PHPStan proves it returns,
 * with literal values widened to their types.
 *
 * Only when the method's own native return type already takes that type: if
 * the native type disagrees too, the method really returns the wrong thing,
 * and that is a decision, not a docblock. And never when the proven type
 * knows less than the written one, such as `mixed` for a shape.
 */
final class ProvenReturnType implements Fixer
{
    public function rule(): string
    {
        return 'return.type';
    }

    public function label(): string
    {
        return 'return docblocks given the type the method really returns';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        if (preg_match('/^Method ([\w\\\\]+)::(\w+)\(\) should return (.+) but returns (.+)\.$/', $message, $found) !== 1 || ProvenTypes::cutShort($message)) {
            return false;
        }

        [, $class, $method, $declared, $returned] = $found;
        $file = $bench->open($path);
        $node = $file === null ? null : $this->method($file, $line, $method);
        $doc = $node?->getDocComment();

        if ($file === null || $node === null || $doc === null || $this->knowsLess($returned, $declared)) {
            return false;
        }

        $type = ProvenTypes::writable(ProvenTypes::generalised($returned));
        $span = ProvenTypes::tagType($doc->getText(), 'return');

        if ($span === null || ! ProvenTypes::nativeTakes($node->returnType, $type, $this->owner($file, $node) ?? $class)) {
            return false;
        }

        $same = ProvenTypes::generalised($returned) === ProvenTypes::generalised($declared);
        $written = match (true) {
            $same => ProvenTypes::covariant(trim($span['type'])),
            $this->returnsMoreThanOnce($node) => $file->localise(ProvenTypes::union(trim($span['type']), $type)),
            default => $file->localise($type),
        };

        return $written !== trim($span['type']) && $file->replace($doc->getStartFilePos() + $span['from'], $doc->getStartFilePos() + $span['to'], $written);
    }

    /**
     * Whether the method has other `return`s than the one PHPStan named. They
     * still return what the docblock says, so it keeps that alongside.
     */
    private function returnsMoreThanOnce(ClassMethod $method): bool
    {
        $finder = new class extends NodeVisitorAbstract
        {
            public int $returns = 0;

            public function enterNode(Node $node): ?int
            {
                $this->returns += $node instanceof Return_ ? 1 : 0;

                return $node instanceof FunctionLike || $node instanceof ClassLike ? NodeVisitor::DONT_TRAVERSE_CHILDREN : null;
            }
        };

        $traverser = new NodeTraverser($finder);
        $traverser->traverse($method->stmts ?? []);

        return $finder->returns > 1;
    }

    private function method(SourceFile $file, int $line, string $name): ?ClassMethod
    {
        return collect($file->spanning($line, ClassMethod::class))->first(fn (ClassMethod $method): bool => $method->name->toString() === $name);
    }

    private function owner(SourceFile $file, ClassMethod $method): ?string
    {
        $class = collect($file->all(ClassLike::class))->first(fn (ClassLike $class): bool => in_array($method, $class->getMethods(), true));

        return $class?->namespacedName?->toString();
    }

    private function knowsLess(string $returned, string $declared): bool
    {
        $onlyMixed = preg_match('/^(?:mixed|(?:array|list|non-empty-array|non-empty-list)<(?:[^<>,]+,\s*)?mixed>)(?:\|null)?$/', trim($returned)) === 1;

        return $onlyMixed && ! str_contains($declared, 'mixed');
    }
}
