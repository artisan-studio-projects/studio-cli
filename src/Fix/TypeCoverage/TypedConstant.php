<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\TypeCoverage;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;

/**
 * A class constant with a literal value gets that literal's type, on PHP 8.3
 * and later, where typed constants exist.
 */
final class TypedConstant implements Fixer
{
    public function rule(): string
    {
        return 'constant';
    }

    public function label(): string
    {
        return 'constants typed';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->runsPhp('8.3') ? $bench->open($path) : null;
        $constant = collect($file?->spanning($line, ClassConst::class) ?? [])->first();

        if ($file === null || ! $constant instanceof ClassConst || $constant->type !== null) {
            return false;
        }

        $types = collect($constant->consts)->map(fn (Const_ $const): ?string => $this->typeOf($const->value))->unique()->values();
        $type = $types->count() === 1 ? $types->first() : null;
        $name = $constant->consts[0] ?? null;

        if ($type === null || $name === null) {
            return false;
        }

        $head = substr($file->code, $constant->getStartFilePos(), $name->getStartFilePos() - $constant->getStartFilePos());

        $keyword = preg_match_all('/\bconst\b/i', $head, $matches, PREG_OFFSET_CAPTURE) > 0 ? collect($matches[0])->last() : null;

        return is_array($keyword) && $file->insert($constant->getStartFilePos() + $keyword[1] + strlen('const'), ' '.$type);
    }

    private function typeOf(Expr $value): ?string
    {
        return match (true) {
            $value instanceof String_, $value instanceof Concat => 'string',
            $value instanceof Int_ => 'int',
            $value instanceof Float_ => 'float',
            $value instanceof Array_ => 'array',
            $value instanceof UnaryMinus, $value instanceof UnaryPlus => $this->typeOf($value->expr) === 'int' ? 'int' : ($this->typeOf($value->expr) === 'float' ? 'float' : null),
            $value instanceof ConstFetch && in_array(strtolower($value->name->toString()), ['true', 'false'], true) => 'bool',
            default => null,
        };
    }
}
