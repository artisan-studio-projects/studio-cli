<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;

/**
 * `$user?->email ?? 'x'` becomes `$user->email ?? 'x'`.
 *
 * Only when everything from that `?->` up to the `??` is a property or an
 * offset: `??` reads such a chain the way `isset()` does, so a null part of it
 * falls through to the default instead of throwing, whatever the declared types
 * say. A method call in the chain, or a nullsafe anywhere else, rests on types
 * that can be wrong at runtime, so it is left alone.
 */
final class NeverNullNullsafe implements Fixer
{
    public function rule(): string
    {
        return 'nullsafe.neverNull';
    }

    public function label(): string
    {
        return 'unneeded ?-> made ->';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->open($path);

        if ($file === null || preg_match('/"\?->(\w+)" on left side of \?\? is unnecessary/', $message, $match) !== 1) {
            return false;
        }

        $coalesces = $file->spanning($line, Coalesce::class);
        $candidates = collect($file->on($line, NullsafePropertyFetch::class))
            ->filter(fn (NullsafePropertyFetch $node): bool => $node->name instanceof Identifier && $node->name->toString() === $match[1])
            ->filter(fn (NullsafePropertyFetch $node): bool => collect($coalesces)->contains(fn (Coalesce $coalesce): bool => $this->readsAsIsset($coalesce->left, $node)))
            ->values();

        return $candidates->count() === 1 && $this->dropTheQuestionMark($file, $candidates->first());
    }

    private function readsAsIsset(Expr $expression, NullsafePropertyFetch $target): bool
    {
        return match (true) {
            $expression === $target => true,
            $expression instanceof PropertyFetch, $expression instanceof NullsafePropertyFetch, $expression instanceof ArrayDimFetch => $this->readsAsIsset($expression->var, $target),
            default => false,
        };
    }

    private function dropTheQuestionMark(SourceFile $file, NullsafePropertyFetch $node): bool
    {
        $after = $node->var->getEndFilePos() + 1;
        $operator = strpos(substr($file->code, $after, $node->name->getStartFilePos() - $after), '?->');

        return $operator !== false && $file->replace($after + $operator, $after + $operator + 1, '');
    }
}
