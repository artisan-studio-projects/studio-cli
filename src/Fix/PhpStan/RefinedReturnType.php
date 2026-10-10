<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\DocTags;
use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;

/**
 * `@return Collection<int, string>` on a method PHPStan proves returns
 * `Collection<int, non-empty-string>`: the same type, said less precisely, and
 * generics do not accept the narrower one in place of the wider. The `@return`
 * takes what PHPStan proved. Only when the two differ by refinement alone,
 * such as `non-empty-string` for `string` or `int<1, max>` for `int`.
 */
final class RefinedReturnType implements Fixer
{
    private const array REFINEMENTS = [
        '/\b(?:non-empty-string|non-falsy-string|literal-string|lowercase-string|uppercase-string|numeric-string|truthy-string)\b/' => 'string',
        '/\bclass-string(?:<[^<>]*>)?/' => 'string',
        '/\'[^\']*\'/' => 'string',
        '/\bint<[^<>]*>|\b(?:positive-int|negative-int|non-negative-int|non-positive-int|non-zero-int)\b/' => 'int',
        '/(?<![\w\\\\$-])\d+(?![\w\\\\-])/' => 'int',
        '/\bnon-empty-list\b/' => 'list',
        '/\bnon-empty-array\b/' => 'array',
    ];

    public function rule(): string
    {
        return 'return.type';
    }

    public function label(): string
    {
        return 'return types given the refinement PHPStan proved';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        if (preg_match('/^Method [\w\\\\]+::\w+\(\) should return (.+) but returns (.+)\.$/', $message, $types) !== 1 || str_contains($message, '…')) {
            return false;
        }

        [, $declared, $returned] = $types;
        $file = $bench->open($path);

        if ($file === null || $this->widened($declared) !== $this->widened($returned) || $declared === $returned) {
            return false;
        }

        $tag = DocTags::on($file, $line, '/^[ \t]*\*[ \t]*@return[ \t]+[^\n]*\n/m');

        if ($tag === null || ! DocTags::isWhole($tag['text']) || preg_match('/@return[ \t]+/', $tag['text'], $start, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }

        $from = $start[0][1] + strlen($start[0][0]);
        $written = rtrim(substr($tag['text'], $from));
        $type = $file->localise((string) preg_replace('/(?<![\w\\\\])([A-Z]\w*(?:\\\\\w+)+)/', '\\\\$1', $returned));

        return $this->widened($this->spelledOut($file, $written)) === $this->widened($declared)
            && $file->replace($tag['at'] + $from, $tag['at'] + $from + strlen($written), $type);
    }

    private function widened(string $type): string
    {
        $wide = (string) preg_replace(array_keys(self::REFINEMENTS), array_values(self::REFINEMENTS), $type);
        $flat = (string) preg_replace(['/\s+/', '/(?<![\w\\\\])\\\\/'], ['', ''], $wide);
        $parts = array_unique(explode('|', $flat));
        sort($parts);

        return implode('|', $parts);
    }

    /**
     * The tag's type with its short class names written out, to compare with
     * PHPStan's, which always names classes in full.
     */
    private function spelledOut(SourceFile $file, string $written): string
    {
        return (string) preg_replace_callback('/(?<![\w\\\\$-])([A-Z]\w*)(?![\w\\\\])/', fn (array $short): string => $file->fullName($short[1]), $written);
    }
}
