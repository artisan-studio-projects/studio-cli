<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\TypeCoverage;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\NodeFinder;

/**
 * `fn ($issue) => …` gets the type PHPStan already infers for `$issue` inside
 * the closure, from what it is passed to, as {@see InferredClosureTypes} found.
 */
final class TypedClosureParam implements Fixer
{
    private const array TYPE_CHECKS = ['is_array', 'is_string', 'is_int', 'is_integer', 'is_float', 'is_bool', 'is_object', 'is_numeric', 'is_scalar', 'is_iterable', 'is_callable', 'is_null', 'gettype', 'get_debug_type'];

    /**
     * @var array<string, true>
     */
    private array $typed = [];

    /**
     * @param  array<string, array<int, array<string, string>>>  $inferred
     */
    public function __construct(private readonly array $inferred) {}

    public function rule(): string
    {
        return 'parameter';
    }

    public function label(): string
    {
        return 'closure parameters typed from what PHPStan infers';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $types = $this->inferred[$path][$line] ?? [];
        $file = $types === [] ? null : $bench->open($path);

        if ($file === null) {
            return false;
        }

        return collect([...$file->on($line, Closure::class), ...$file->on($line, ArrowFunction::class)])
            ->flatMap(fn (Closure|ArrowFunction $closure): array => $closure->params)
            ->filter(fn (Param $param): bool => $param->getStartLine() === $line && $param->type === null && ! $param->byRef && ! $param->variadic && $param->attrGroups === [])
            ->filter(fn (Param $param): bool => $param->var instanceof Variable && is_string($param->var->name) && isset($types[$param->var->name]))
            ->reject(fn (Param $param): bool => $this->checksItsOwnType($file->all(Closure::class), $file->all(ArrowFunction::class), $param))
            ->map(function (Param $param) use ($file, $types, $path): bool {
                $key = $path.':'.$param->getStartFilePos();

                if (isset($this->typed[$key])) {
                    return true;
                }

                $this->typed[$key] = true;

                $name = $param->var instanceof Variable && is_string($param->var->name) ? $param->var->name : '';

                $type = (string) preg_replace_callback('/\\\\[\w\\\\]+/', fn (array $class): string => $file->nameFor($class[0]), $types[$name]);

                return $file->insert($param->getStartFilePos(), $type.' ');
            })
            ->contains(true);
    }

    /**
     * Whether the closure tests what its parameter is, with `is_array()`,
     * `instanceof` and the like. Code that is unsure of a type has a reason to
     * be, so the parameter stays untyped.
     *
     * @param  list<Closure>  $closures
     * @param  list<ArrowFunction>  $arrows
     */
    private function checksItsOwnType(array $closures, array $arrows, Param $param): bool
    {
        $owner = collect([...$closures, ...$arrows])->first(fn (Closure|ArrowFunction $closure): bool => in_array($param, $closure->params, true));
        $name = $param->var instanceof Variable ? $param->var->name : null;

        return $owner !== null && (new NodeFinder)->findFirst($owner->getStmts(), fn (Node $node): bool => match (true) {
            $node instanceof Instanceof_ => $node->expr instanceof Variable && $node->expr->name === $name,
            $node instanceof FuncCall => $node->name instanceof Name
                && in_array($node->name->toLowerString(), self::TYPE_CHECKS, true)
                && ($node->args[0]->value ?? null) instanceof Variable
                && $node->args[0]->value->name === $name,
            default => false,
        }) !== null;
    }
}
