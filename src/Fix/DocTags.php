<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Property;

/**
 * Finding one tag line in the docblock of whatever PHPStan reported at a line.
 */
final class DocTags
{
    /**
     * @return array{at: int, text: string}|null
     */
    public static function on(SourceFile $file, int $line, string $pattern): ?array
    {
        return collect([...$file->spanning($line, FunctionLike::class), ...$file->spanning($line, Property::class)])
            ->filter(fn (Node $node): bool => $node->getDocComment() !== null)
            ->sortBy(fn (Node $node): int => $node->getEndLine() - $node->getStartLine())
            ->map(fn (Node $node): ?array => preg_match($pattern, (string) $node->getDocComment()?->getText(), $match, PREG_OFFSET_CAPTURE) === 1
                ? ['at' => (int) $node->getDocComment()?->getStartFilePos() + $match[0][1], 'text' => $match[0][0]]
                : null)
            ->first(fn (?array $tag): bool => $tag !== null);
    }

    /**
     * Whether a tag's line holds the whole tag: every bracket it opens, it closes.
     */
    public static function isWhole(string $text): bool
    {
        return substr_count($text, '{') === substr_count($text, '}')
            && substr_count($text, '<') === substr_count($text, '>')
            && substr_count($text, '(') === substr_count($text, ')');
    }

    public static function closingBracket(string $text, int $opening): ?int
    {
        $depth = 0;

        for ($at = $opening; $at < strlen($text); $at++) {
            $depth += match ($text[$at]) {
                '<' => 1,
                '>' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $at;
            }
        }

        return null;
    }
}
