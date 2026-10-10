<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;

/**
 * `$row['note'] ?? ''` against a docblock shape that says `note` is always
 * there, or never mentions it: the code is right to be careful, so the shape
 * is made honest. The key becomes optional, or is added as `note?: mixed`.
 * Only docblocks change; the code reads exactly as it did.
 */
final class OptionalShapeKey implements Fixer
{
    private const int FEWEST_KEYS_TO_MATCH_A_CUT_SHAPE = 3;

    /**
     * @var array<string, true>
     */
    private array $done = [];

    public function __construct(private readonly ArrayShapes $shapes) {}

    public function rule(): string
    {
        return 'nullCoalesce.offset';
    }

    public function label(): string
    {
        return 'array shapes made honest about optional keys';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        if (preg_match('/^Offset \'([\w-]+)\' on (array\{.*?)(?: on left side of \?\? (always exists and is not nullable|does not exist)\.|…)$/', $message, $match) !== 1) {
            return false;
        }

        [, $key, $shape] = $match;
        $whole = ($match[3] ?? '') !== '';
        $keys = ArrayShapes::keys($shape);

        if (! $whole && count($keys) < self::FEWEST_KEYS_TO_MATCH_A_CUT_SHAPE) {
            return false;
        }

        return collect($this->shapes->declaring($keys))
            ->filter(fn (array $found): bool => ! $whole || $found['keys'] === $keys)
            ->map(function (array $found) use ($bench, $key): bool {
                $done = $found['path'].':'.$found['at'].':'.$key;
                $file = $bench->open($found['path']);

                if (isset($this->done[$done])) {
                    return true;
                }

                $this->done[$done] = true;

                return $file !== null && (in_array($key, $found['keys'], true) ? $this->optional($file, $found, $key) : $this->add($file, $found, $key));
            })
            ->contains(true);
    }

    /**
     * @param  array{path: string, at: int, text: string, keys: list<string>}  $shape
     */
    private function optional(SourceFile $file, array $shape, string $key): bool
    {
        preg_match_all('/[\'"]?\b'.preg_quote($key, '/').'\b[\'"]?(\s*)(\??):/', $shape['text'], $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $colon = collect($matches)
            ->filter(fn (array $found): bool => $this->depth($shape['text'], $found[0][1]) === 1 && $found[2][0] === '')
            ->map(fn (array $found): int => $found[0][1] + strlen($found[0][0]) - 1)
            ->first();

        return $colon !== null && $file->insert($shape['at'] + $colon, '?');
    }

    /**
     * @param  array{path: string, at: int, text: string, keys: list<string>}  $shape
     */
    private function add(SourceFile $file, array $shape, string $key): bool
    {
        $text = $shape['text'];
        $close = strlen($text) - 1;
        $entry = $key.'?: mixed';

        if (! str_contains($text, "\n")) {
            return $file->insert($shape['at'] + $close, (trim(substr($text, 1, -1)) === '' ? '' : ', ').$entry);
        }

        $lastBreak = strrpos(substr($text, 0, $close), "\n");
        $previousBreak = $lastBreak === false ? false : strrpos(substr($text, 0, $lastBreak), "\n");

        if ($lastBreak === false || $previousBreak === false) {
            return false;
        }

        $previous = substr($text, $previousBreak + 1, $lastBreak - $previousBreak - 1);
        $prefix = preg_match('/^\s*\*?\s*/', $previous, $indent) === 1 ? $indent[0] : '';

        return $file->insert($shape['at'] + $lastBreak, (str_ends_with(rtrim($previous), ',') ? '' : ',')."\n".$prefix.$entry.',');
    }

    private function depth(string $text, int $before): int
    {
        return collect(str_split(substr($text, 0, $before)))
            ->sum(fn (string $character): int => match ($character) {
                '{', '<', '(', '[' => 1,
                '}', '>', ')', ']' => -1,
                default => 0,
            });
    }
}
