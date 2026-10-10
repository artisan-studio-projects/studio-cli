<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\DocTags;
use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\Workbench;

/**
 * An `@param` for a parameter the function no longer has is deleted. Only a
 * tag on one line: a shape that runs over several lines is left alone.
 */
final class StaleParamTag implements Fixer
{
    public function rule(): string
    {
        return 'parameter.notFound';
    }

    public function label(): string
    {
        return 'stale @param tags removed';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->open($path);

        if ($file === null || preg_match('/^PHPDoc tag @param references unknown parameter: \$(\w+)/', $message, $match) !== 1) {
            return false;
        }

        $tag = DocTags::on($file, $line, '/^[ \t]*\*[ \t]*@param\b[^\n]*\$'.$match[1].'\b[^\n]*\n/m');

        return $tag !== null && DocTags::isWhole($tag['text']) && $file->replace($tag['at'], $tag['at'] + strlen($tag['text']), '');
    }
}
