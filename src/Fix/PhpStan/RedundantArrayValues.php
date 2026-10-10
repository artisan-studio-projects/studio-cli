<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;

/**
 * `array_values($list)` on something PHPStan proved is already a list is
 * just `$list`. Only when the argument is a plain value (a variable, a
 * property, a call), so taking the function away cannot change how the
 * expression around it reads.
 */
final class RedundantArrayValues implements Fixer
{
    public function rule(): string
    {
        return 'arrayValues.list';
    }

    public function label(): string
    {
        return 'array_values() calls that changed nothing removed';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->open($path);
        $calls = collect($file?->on($line, FuncCall::class) ?? [])
            ->filter(fn (FuncCall $call): bool => $call->name instanceof Name && strtolower(ltrim($call->name->toString(), '\\')) === 'array_values' && count($call->args) === 1 && ! ($call->args[0]->unpack ?? false))
            ->values();
        $value = $calls->count() === 1 ? $calls->first()->args[0]->value : null;

        if ($file === null || ! $this->isPlain($value)) {
            return false;
        }

        return $file->replace($calls->first()->getStartFilePos(), $calls->first()->getEndFilePos() + 1, $file->text($value));
    }

    private function isPlain(?Expr $value): bool
    {
        return $value instanceof Variable
            || $value instanceof PropertyFetch
            || $value instanceof NullsafePropertyFetch
            || $value instanceof StaticPropertyFetch
            || $value instanceof ArrayDimFetch
            || $value instanceof MethodCall
            || $value instanceof NullsafeMethodCall
            || $value instanceof StaticCall
            || $value instanceof FuncCall
            || $value instanceof ClassConstFetch
            || $value instanceof ConstFetch;
    }
}
