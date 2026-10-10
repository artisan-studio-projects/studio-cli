<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Livewire;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\Workbench;

/**
 * Gives the row of a Blade loop the wire:key Livewire needs to tell one row
 * from the next, so a row's state is never mixed up with another's.
 *
 * Only a loop whose body is exactly one element is changed, and the key is
 * the most trustworthy thing the loop already has: the item's own id when the
 * body reads it, else the array key the loop gives, else the position.
 * Anything else, such as a body of several elements or one wrapped in @if,
 * is left for a person.
 */
final class MissingWireKey implements Fixer
{
    public function rule(): string
    {
        return 'livewire-missing-key';
    }

    public function label(): string
    {
        return 'Blade loops given a wire:key';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->open($path);

        if ($file === null || ! str_ends_with($path, '.blade.php')) {
            return false;
        }

        $lines = explode("\n", $file->code);
        $offset = array_sum(array_map(fn (string $text): int => strlen($text) + 1, array_slice($lines, 0, max(0, $line - 1))));
        $loop = $this->loopAt($file->code, $offset, strlen($lines[$line - 1] ?? ''));

        if ($loop === null) {
            return false;
        }

        $root = $this->rootElement($loop['body']);

        if ($root === null || preg_match('/wire:key|:key\s*=/', $root['open']) === 1) {
            return false;
        }

        $key = $this->keyFor($loop['item'], $loop['key'], $loop['body']);
        $attribute = str_starts_with($root['tag'], 'livewire:') ? ' :key="'.$key.'"' : ' wire:key="{{ '.$key.' }}"';

        return $file->insert($loop['bodyAt'] + $root['at'] + 1 + strlen($root['tag']), $attribute);
    }

    /**
     * The @foreach on the line: what it loops as, and the body to its @endforeach.
     *
     * @return array{item: string, key: ?string, body: string, bodyAt: int}|null
     */
    private function loopAt(string $code, int $lineAt, int $lineLength): ?array
    {
        $found = preg_match('/@foreach\s*\(/', substr($code, $lineAt, $lineLength), $match, PREG_OFFSET_CAPTURE) === 1;

        if (! $found) {
            return null;
        }

        $open = $lineAt + $match[0][1] + strlen($match[0][0]);
        $close = $this->closing($code, $open);

        if ($close === null) {
            return null;
        }

        $expression = substr($code, $open, $close - $open);

        if (preg_match('/\bas\s+(?:(\$\w+)\s*=>\s*)?(\$\w+)\s*$/', $expression, $names) !== 1) {
            return null;
        }

        $bodyAt = $close + 1;
        $end = $this->endOfLoop($code, $bodyAt);

        return $end === null ? null : ['item' => $names[2], 'key' => $names[1] !== '' ? $names[1] : null, 'body' => substr($code, $bodyAt, $end - $bodyAt), 'bodyAt' => $bodyAt];
    }

    private function closing(string $code, int $from): ?int
    {
        $depth = 1;

        for ($at = $from, $length = strlen($code); $at < $length; $at++) {
            $depth += match ($code[$at]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $at;
            }
        }

        return null;
    }

    private function endOfLoop(string $code, int $from): ?int
    {
        $depth = 1;
        preg_match_all('/@foreach\b|@endforeach\b/', $code, $tags, PREG_OFFSET_CAPTURE, $from);

        foreach ($tags[0] as [$tag, $at]) {
            $depth += $tag === '@foreach' ? 1 : -1;

            if ($depth === 0) {
                return $at;
            }
        }

        return null;
    }

    /**
     * The body's one root element: its opening tag, name and where it starts,
     * only when nothing but whitespace is around it.
     *
     * @return array{tag: string, open: string, at: int}|null
     */
    private function rootElement(string $body): ?array
    {
        if (preg_match('/^(\s*)<([A-Za-z][\w:.\-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $body, $open) !== 1) {
            return null;
        }

        $start = strlen($open[1]);
        $tag = $open[2];
        $after = $start + strlen($open[0]) - $start;
        $end = str_ends_with($open[0], '/>') ? $after : $this->closeOf($body, $tag, $after);

        if ($end === null || trim(substr($body, $end)) !== '') {
            return null;
        }

        return ['tag' => $tag, 'open' => substr($open[0], $start), 'at' => $start];
    }

    /**
     * Where the element's own closing tag ends, counting same-named elements inside it.
     */
    private function closeOf(string $body, string $tag, int $from): ?int
    {
        $depth = 1;
        $name = preg_quote($tag, '/');
        preg_match_all('/<'.$name.'(?=[\s>\/])(?:[^>"\']|"[^"]*"|\'[^\']*\')*?(\/?)>|<\/'.$name.'\s*>/s', $body, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, $from);

        foreach ($tags as $found) {
            $isClose = str_starts_with($found[0][0], '</');
            $isSelf = ! $isClose && ($found[1][0] ?? '') === '/';
            $depth += $isClose ? -1 : ($isSelf ? 0 : 1);

            if ($depth === 0) {
                return $found[0][1] + strlen($found[0][0]);
            }
        }

        return null;
    }

    /**
     * The item's id when the loop body reads it, else the array key, else the position.
     */
    private function keyFor(string $item, ?string $key, string $body): string
    {
        return match (true) {
            preg_match('/'.preg_quote($item, '/').'->id\b/', $body) === 1 => $item.'->id',
            preg_match('/'.preg_quote($item, '/').'\[[\'"]id[\'"]\]/', $body) === 1 => $item."['id']",
            $key !== null => $key,
            default => '$loop->index',
        };
    }
}
