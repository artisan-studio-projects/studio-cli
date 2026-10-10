<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Psalm;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;

/**
 * A cast Psalm proved changes nothing is taken out, but only when the value
 * is a parameter the function itself declares as that type and never
 * reassigns. Psalm trusts docblocks and model property types, which can say
 * int where the database hands back null, so a cast on anything it only
 * believes stays, and stays in the report. Only the cast goes: the value stays
 * exactly as written. A line with two casts of the same kind is left alone,
 * since the report names the line, not which cast.
 */
final class RedundantCast implements Fixer
{
    /**
     * @var array<string, class-string<Cast>>
     */
    private const array KINDS = [
        'int' => Cast\Int_::class,
        'float' => Cast\Double::class,
        'string' => Cast\String_::class,
        'bool' => Cast\Bool_::class,
        'array' => Cast\Array_::class,
    ];

    public function rule(): string
    {
        return 'RedundantCast';
    }

    public function label(): string
    {
        return 'casts that did nothing removed';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $type = preg_match('/Redundant cast to (\w+)/', $message, $found) === 1 ? strtolower($found[1]) : null;
        $class = self::KINDS[$type ?? ''] ?? null;
        $file = $class === null ? null : $bench->open($path);
        $casts = $file === null ? [] : array_values(array_filter($file->on($line, Cast::class), fn (Cast $cast): bool => $cast instanceof $class));

        if ($file === null || count($casts) !== 1 || ! $this->isDeclared($file->spanning($line, FunctionLike::class), $casts[0], (string) $type)) {
            return false;
        }

        $start = $casts[0]->getStartFilePos();
        $token = preg_match('/\G\(\s*[a-z]+\s*\)\s*/i', $file->code, $match, 0, $start) === 1 ? $match[0] : null;

        return $token !== null && $file->replace($start, $start + strlen($token), '');
    }

    /**
     * @param  list<FunctionLike>  $functions
     */
    private function isDeclared(array $functions, Cast $cast, string $type): bool
    {
        $function = $functions === [] ? null : $functions[array_key_last($functions)];
        $value = $cast->expr;

        if ($function === null || ! $value instanceof Variable || ! is_string($value->name)) {
            return false;
        }

        $name = $value->name;
        $declared = collect($function->getParams())->contains(fn ($param): bool => $param->var instanceof Variable
            && $param->var->name === $name
            && $param->type instanceof Identifier
            && strtolower($param->type->toString()) === $type);
        $reassigned = (new NodeFinder)->findFirst($function->getStmts() ?? [], fn ($node): bool => $node instanceof Assign && $node->var instanceof Variable && $node->var->name === $name);

        return $declared && $reassigned === null;
    }
}
