<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

/**
 * Who speaks in the terminal, and what they say on each tab, by tab and
 * moment. Both come from Artisan Studio: asked for once, kept for a while,
 * and kept from the last answer when the studio cannot be reached.
 */
class Says
{
    /**
     * Who is named until the studio has said, such as before linking.
     */
    public const string NOBODY_YET = 'Artisan Studio';

    private const int FRESH_FOR = 600;

    private const string STATE = 'speech';

    /**
     * @var array{name: string, says: array<string, array<string, array{title: string, line: string, colour: string}>>}|null
     */
    private ?array $speech = null;

    private int $askedAt = 0;

    public function __construct(
        private readonly Studio $studio,
        private readonly string $root,
    ) {}

    /**
     * The text with `{name}` as whoever is speaking.
     */
    public static function named(string $text): string
    {
        return str_replace('{name}', app(self::class)->name(), $text);
    }

    public function name(): string
    {
        return $this->speech()['name'];
    }

    /**
     * @return array{title: string, line: string, colour: string}
     */
    public function line(string $tab, string $moment): array
    {
        return $this->speech()['says'][$tab][$moment] ?? ['title' => '', 'line' => '', 'colour' => 'sky'];
    }

    public function has(string $tab, string $moment): bool
    {
        return isset($this->speech()['says'][$tab][$moment]);
    }

    /**
     * @return array{name: string, says: array<string, array<string, array{title: string, line: string, colour: string}>>}
     */
    private function speech(): array
    {
        if ($this->speech !== null && time() - $this->askedAt < self::FRESH_FOR) {
            return $this->speech;
        }

        $path = StateFile::for(self::STATE, $this->root);
        $kept = json_decode((string) @file_get_contents($path), true);
        $fresh = is_array($kept) && time() - (int) @filemtime($path) < self::FRESH_FOR;
        $asked = $fresh ? null : $this->studio->speech();
        $speech = self::read($asked ?? (is_array($kept) ? $kept : []));

        if ($asked !== null) {
            @file_put_contents($path, (string) json_encode($asked));
        }

        $this->askedAt = time();

        return $this->speech = $speech;
    }

    /**
     * @param  array<mixed>  $answer
     * @return array{name: string, says: array<string, array<string, array{title: string, line: string, colour: string}>>}
     */
    private static function read(array $answer): array
    {
        $name = $answer['speaker']['name'] ?? null;

        return [
            'name' => is_string($name) && preg_match('/^[\pL\pN .\'’-]{1,40}$/u', $name) === 1 ? $name : self::NOBODY_YET,
            'says' => collect(is_array($answer['says'] ?? null) ? $answer['says'] : [])
                ->filter(fn (mixed $moments, mixed $tab): bool => is_string($tab) && is_array($moments))
                ->map(fn (array $moments): array => collect($moments)
                    ->filter(fn (mixed $says, mixed $moment): bool => is_string($moment) && is_string($says['title'] ?? null) && is_string($says['line'] ?? null))
                    ->map(fn (array $says): array => [
                        'title' => mb_substr($says['title'], 0, 300),
                        'line' => mb_substr($says['line'], 0, 600),
                        'colour' => is_string($says['colour'] ?? null) && preg_match('/^[a-z]{1,20}$/', $says['colour']) === 1 ? $says['colour'] : 'sky',
                    ])
                    ->all())
                ->all(),
        ];
    }
}
