<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\DocTags;
use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\Workbench;

/**
 * `@param Builder<Model> $query` where that `Builder` takes no type arguments
 * keeps only `Builder`.
 */
final class GenericOnPlainType implements Fixer
{
    public function rule(): string
    {
        return 'generics.notGeneric';
    }

    public function label(): string
    {
        return 'type arguments on plain types removed';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->open($path);

        if ($file === null || preg_match('/^PHPDoc tag @(param|return|var)(?: for parameter \$(\w+))? contains generic type ([\w\\\\]+)<.*> but (?:interface|class) [\w\\\\]+ is not generic/', $message, $match) !== 1) {
            return false;
        }

        $short = substr((string) strrchr('\\'.$match[3], '\\'), 1);
        $tag = DocTags::on($file, $line, '/^[ \t]*\*[ \t]*@'.$match[1].'\b[^\n]*'.($match[2] !== '' ? '\$'.$match[2].'\b' : '').'[^\n]*\n/m');
        $opening = $tag === null ? false : strpos($tag['text'], $short.'<');

        if ($tag === null || $opening === false) {
            return false;
        }

        $closing = DocTags::closingBracket($tag['text'], $opening + strlen($short));

        return $closing !== null && $file->replace($tag['at'] + $opening + strlen($short), $tag['at'] + $closing + 1, '');
    }
}
