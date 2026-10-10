<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Arch;

use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;

/**
 * exit with a status code, written straight in a console command's handle(),
 * which Pest's Laravel preset refuses, becomes a return of the same code: the
 * command ends the same way and its caller gets the code back.
 */
final class ExitInCommand implements SweepingFixer
{
    public function rule(): string
    {
        return 'exit-in-command';
    }

    public function label(): string
    {
        return 'exits in commands returned instead';
    }

    public function lines(SourceFile $file): array
    {
        return array_values(array_unique(array_map(
            fn (Expression $statement): int => $statement->getStartLine(),
            array_filter($file->all(Expression::class), fn (Expression $statement): bool => $this->isFixable($file, $statement)),
        )));
    }

    public function message(): string
    {
        return 'A console command exits instead of returning its status.';
    }

    public function couldMatch(string $code): bool
    {
        return preg_match('/\b(?:exit|die)\b/i', $code) === 1;
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->open($path);
        $statement = $file === null ? null : collect($file->on($line, Expression::class))->first(fn (Expression $statement): bool => $this->isFixable($file, $statement));

        if ($file === null || ! $statement instanceof Expression || ! $statement->expr instanceof Exit_ || $statement->expr->expr === null) {
            return false;
        }

        return $file->replace($statement->getStartFilePos(), $statement->getEndFilePos() + 1, 'return '.$file->text($statement->expr->expr).';');
    }

    private function isFixable(SourceFile $file, Expression $statement): bool
    {
        $code = $statement->expr instanceof Exit_ ? $statement->expr->expr : null;

        if (! $code instanceof Int_ && ! $code instanceof ClassConstFetch) {
            return false;
        }

        $line = $statement->getStartLine();
        $method = collect($file->spanning($line, ClassMethod::class))->last();
        $class = collect($file->spanning($line, Class_::class))->last();

        return $method instanceof ClassMethod
            && $method->name->toLowerString() === 'handle'
            && ! ($method->returnType instanceof Identifier && in_array($method->returnType->toLowerString(), ['void', 'never'], true))
            && $class instanceof Class_
            && str_ends_with((string) $class->extends?->toString(), 'Command')
            && $file->spanning($line, Closure::class) === []
            && $file->spanning($line, ArrowFunction::class) === [];
    }
}
