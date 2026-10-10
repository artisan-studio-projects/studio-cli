<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Arch;

use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;

/**
 * A debug call left on a line of its own, the kind Pest's php and Laravel
 * presets refuse, is removed with its line. One used as a value, or sharing
 * its line with other code, is left for the developer.
 */
final class DebugLeftover implements SweepingFixer
{
    /**
     * @var list<string>
     */
    public const array CALLS = ['dd', 'ddd', 'dump', 'ray', 'ds', 'trap', 'var_dump', 'print_r', 'var_export', 'debug_zval_dump', 'debug_print_backtrace', 'xdebug_break', 'xdebug_var_dump'];

    public function rule(): string
    {
        return 'debug-call';
    }

    public function label(): string
    {
        return 'debug calls left behind removed';
    }

    public function lines(SourceFile $file): array
    {
        return array_values(array_unique(array_map(fn (Expression $statement): int => $statement->getStartLine(), array_filter($file->all(Expression::class), $this->isLeftover(...)))));
    }

    public function message(): string
    {
        return 'A debug call was left in app code.';
    }

    public function couldMatch(string $code): bool
    {
        return preg_match('/\b(?:'.implode('|', self::CALLS).')\s*\(/i', $code) === 1;
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->open($path);
        $statement = collect($file?->on($line, Expression::class) ?? [])->first($this->isLeftover(...));

        if ($file === null || ! $statement instanceof Expression) {
            return false;
        }

        $from = strrpos(substr($file->code, 0, $statement->getStartFilePos()), "\n");
        $from = $from === false ? 0 : $from + 1;
        $to = strpos($file->code, "\n", $statement->getEndFilePos());
        $to = $to === false ? strlen($file->code) : $to + 1;
        $before = substr($file->code, $from, $statement->getStartFilePos() - $from);
        $after = substr($file->code, $statement->getEndFilePos() + 1, $to - $statement->getEndFilePos() - 1);

        return trim($before) === '' && trim($after) === '' && $file->replace($from, $to, '');
    }

    private function isLeftover(Expression $statement): bool
    {
        return $statement->expr instanceof FuncCall
            && $statement->expr->name instanceof Name
            && in_array(strtolower(ltrim($statement->expr->name->toString(), '\\')), self::CALLS, true);
    }
}
