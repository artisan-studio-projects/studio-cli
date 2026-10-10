<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

/**
 * `$matches[1] ?? []` after `preg_match_all($pattern, $text, $matches)`: PHP
 * fills every group with a list, matched or not, so the fallback never applies
 * and is removed. Only with the default flags, where that holds.
 */
final class MatchesAlwaysSet implements Fixer
{
    public function rule(): string
    {
        return 'nullCoalesce.offset';
    }

    public function label(): string
    {
        return 'fallbacks on preg_match_all groups removed';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->open($path);
        $coalesces = collect($file?->on($line, Coalesce::class) ?? [])
            ->filter(fn (Coalesce $node): bool => $node->left instanceof ArrayDimFetch && $node->left->var instanceof Variable && is_string($node->left->var->name))
            ->values();

        if ($file === null || $coalesces->count() !== 1 || ! str_contains($message, 'always exists')) {
            return false;
        }

        $node = $coalesces->first();
        $name = $node->left instanceof ArrayDimFetch && $node->left->var instanceof Variable ? $node->left->var->name : null;
        $function = collect($file->spanning($line, FunctionLike::class))->sortBy(fn (FunctionLike $owner): int => $owner->getEndLine() - $owner->getStartLine())->first();
        $filled = $function !== null && (new NodeFinder)->findFirst((array) $function->getStmts(), fn ($call): bool => $call instanceof FuncCall
            && $call->name instanceof Name
            && $call->name->toLowerString() === 'preg_match_all'
            && count($call->args) === 3
            && ($call->args[2]->value ?? null) instanceof Variable
            && $call->args[2]->value->name === $name
            && $call->getStartFilePos() < $node->getStartFilePos()) !== null;

        return $filled && $file->replace($node->getStartFilePos(), $node->getEndFilePos() + 1, $file->text($node->left));
    }
}
