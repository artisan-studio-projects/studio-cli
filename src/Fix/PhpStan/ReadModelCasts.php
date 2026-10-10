<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

/**
 * Larastan reads a model's casts from `casts()` only when told to parse the
 * method; otherwise it sees the declared `array` and ignores every cast, so an
 * enum column reads as a string and each comparison with the enum is reported
 * as always false. One line in the project's PHPStan config clears all of it.
 */
final class ReadModelCasts
{
    private const array CONFIGS = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

    private const string OPTION = 'parseModelCastsMethod';

    public function label(): string
    {
        return 'PHPStan set to read casts() on your models';
    }

    public function apply(string $root): bool
    {
        $config = collect(self::CONFIGS)->first(fn (string $file): bool => is_file($root.'/'.$file));
        $neon = $config === null ? '' : (string) file_get_contents($root.'/'.$config);

        if ($config === null || ! str_contains($neon, 'larastan') || str_contains($neon, self::OPTION) || ! $this->modelsUseCastsMethod($root)) {
            return false;
        }

        $changed = preg_match('/^parameters:[ \t]*\R/m', $neon, $match, PREG_OFFSET_CAPTURE) === 1
            ? substr_replace($neon, $match[0][0].$this->indent($neon).self::OPTION.": true\n", $match[0][1], strlen($match[0][0]))
            : rtrim($neon)."\n\nparameters:\n    ".self::OPTION.": true\n";

        return file_put_contents($root.'/'.$config, $changed) !== false;
    }

    private function indent(string $neon): string
    {
        return preg_match('/^parameters:[ \t]*\R([ \t]+)\S/m', $neon, $match) === 1 ? $match[1] : '    ';
    }

    private function modelsUseCastsMethod(string $root): bool
    {
        return collect(glob($root.'/app/Models/*.php') ?: [])
            ->contains(fn (string $file): bool => preg_match('/function\s+casts\s*\(/', (string) file_get_contents($file)) === 1);
    }
}
