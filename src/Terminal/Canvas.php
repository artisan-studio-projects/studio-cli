<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal;

final readonly class Canvas
{
    public const int GUTTER = 2;

    public const string OPENS = '↗';

    public const string ACTION = 'studio-action:';

    private const string SEGMENT = '▌';

    private const string LINK = '/\e\]8;[^;\e\x07]*;([^\e\x07]*)(?:\e\\\\|\x07)/';

    /**
     * @var array<string, string>
     */
    public array $palette;

    /**
     * @param  array<string, mixed>  $palette
     */
    public function __construct(
        public bool $trueColour = true,
        public bool $emoji = true,
        public ?string $background = '000000',
        array $palette = [],
        string $theme = Theme::DARK,
    ) {
        $this->palette = [...Theme::palette($theme), ...self::hexes($palette)];
    }

    /**
     * @param  array<string, mixed>  $colours
     * @return array<string, string>
     */
    private static function hexes(array $colours): array
    {
        return collect($colours)
            ->map(fn (mixed $hex): string => strtolower(ltrim((string) $hex, '#')))
            ->filter(fn (string $hex): bool => preg_match('/^[0-9a-f]{6}$/', $hex) === 1)
            ->all();
    }

    public static function visibleWidth(string $text): int
    {
        return mb_strwidth((string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', self::LINK], '', $text));
    }

    public static function linkAt(string $line, int $column): ?string
    {
        $parts = collect(preg_split(self::LINK, $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: []);
        $texts = $parts->filter(fn (string $part, int $index): bool => $index % 2 === 0)->values();
        $urls = collect(['', ...$parts->filter(fn (string $part, int $index): bool => $index % 2 === 1)->values()]);
        $widths = $texts->map(fn (string $text): int => self::visibleWidth($text));

        return $texts->keys()
            ->map(fn (int $index): array => ['to' => (int) $widths->take($index + 1)->sum(), 'width' => $widths[$index], 'url' => $urls[$index]])
            ->first(fn (array $span): bool => $span['url'] !== '' && $column > $span['to'] - $span['width'] && $column <= $span['to'])['url'] ?? null;
    }

    public function takeOver(): string
    {
        return "\e[0;".$this->colour('screen', true)."m\e[2J\e[H";
    }

    public function clearBelow(): string
    {
        return "\e[0;".$this->colour('screen', true)."m\e[J\e[0m";
    }

    public function eraseToEdge(): string
    {
        return "\e[0;".$this->colour('screen', true)."m\e[K\e[0m";
    }

    public function span(string $text, string $colour, string $background = 'screen', bool $bold = false): string
    {
        return $text === '' ? '' : "\e[0".($bold ? ';1' : '').';'.$this->colour($colour, false).';'.$this->colour($background, true).'m'.$text;
    }

    public function row(string $content, int $width): string
    {
        $gutter = $this->span(str_repeat(' ', self::GUTTER), 'ink');

        return $this->line([$gutter, $this->cell($content, $width - 2 * self::GUTTER), $gutter], $width);
    }

    public function blank(int $width): string
    {
        return $this->line([], $width);
    }

    /**
     * @param  list<string>  $parts
     */
    public function line(array $parts, int $width): string
    {
        return $this->cell(implode('', $parts), $width)."\e[0m";
    }

    public function cell(string $content, int $width, string $background = 'screen'): string
    {
        return $content.$this->span(str_repeat(' ', max(0, $width - self::visibleWidth($content))), 'ink', $background);
    }

    public function centred(string $content, int $width, string $background = 'screen'): string
    {
        return $this->cell($this->span(str_repeat(' ', intdiv(max(0, $width - self::visibleWidth($content)), 2)), 'ink', $background).$content, $width, $background);
    }

    public function spread(string $left, string $right, int $width): string
    {
        $space = $width - self::visibleWidth($left) - self::visibleWidth($right);

        return $space >= 1 ? $left.$this->span(str_repeat(' ', $space), 'ink').$right : $left;
    }

    public function gradient(string $text, ?int $across = null, bool $bold = false): string
    {
        $characters = mb_str_split($text);
        $span = max(1, ($across ?? count($characters)) - 1);

        return collect($characters)
            ->map(fn (string $character, int $index): string => $this->span($character, $this->blend('glow-from', 'glow-to', $index / $span), bold: $bold))
            ->implode('');
    }

    public function segments(float $fraction, int $width, ?string $colour = null): string
    {
        $filled = (int) round(max(0.0, min(1.0, $fraction)) * $width);
        $span = max(1, $width - 1);

        return collect(range(0, max(0, $width - 1)))
            ->map(fn (int $index): string => $this->span(self::SEGMENT, match (true) {
                $index >= $filled => 'track',
                $colour !== null => $colour,
                $index === $filled - 1 && $filled < $width => 'glow-head',
                default => $this->blend('glow-from', 'glow-to', $index / $span),
            }))
            ->implode('');
    }

    public function link(string $content, string $url): string
    {
        return "\e]8;;{$url}\e\\{$content}\e]8;;\e\\";
    }

    public function button(string $label, string $action, string $colour): string
    {
        return $this->link($this->span(" {$label} ", $colour, "{$colour}-bg", bold: true), self::ACTION.$action);
    }

    public function opens(?string $url): string
    {
        return $url === null ? '' : $this->span(' ', 'ink').$this->link($this->span(self::OPENS, 'cyan', bold: true), $url);
    }

    public function fit(string $text, int $width): string
    {
        return mb_strwidth($text) <= $width ? $text : mb_strimwidth($text, 0, max(1, $width), '…');
    }

    /**
     * @return list<string>
     */
    public function wrap(string $text, int $width): array
    {
        return array_values(collect(explode("\n", $text))
            ->flatMap(fn (string $line): array => explode("\n", wordwrap($line, max(1, $width), "\n", true)))
            ->all());
    }

    public function colour(string $name, bool $background): string
    {
        if ($name === 'screen' && $this->background === null) {
            return $background ? '49' : '39';
        }

        [$red, $green, $blue] = sscanf($name === 'screen' ? (string) $this->background : ($this->palette[$name] ?? $name), '%02x%02x%02x');
        $layer = $background ? '48' : '38';

        return $this->trueColour
            ? "{$layer};2;{$red};{$green};{$blue}"
            : "{$layer};5;".(16 + 36 * (int) round($red / 255 * 5) + 6 * (int) round($green / 255 * 5) + (int) round($blue / 255 * 5));
    }

    private function blend(string $from, string $to, float $at): string
    {
        $start = sscanf($this->palette[$from], '%02x%02x%02x');
        $end = sscanf($this->palette[$to], '%02x%02x%02x');

        return collect(range(0, 2))
            ->map(fn (int $channel): string => sprintf('%02x', (int) round($start[$channel] + ($end[$channel] - $start[$channel]) * $at)))
            ->implode('');
    }
}
