<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

final class TaskJournal
{
    public const string ENV = 'STUDIO_TASK_JOURNAL';

    public function __construct(private readonly ?string $path) {}

    public static function fromEnvironment(): self
    {
        $path = getenv(self::ENV);

        return new self(is_string($path) && $path !== '' ? $path : null);
    }

    public function note(string $line): void
    {
        if ($this->path !== null) {
            @file_put_contents($this->path, $line."\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * @return array{lines: list<string>, offset: int}
     */
    public static function readFrom(string $path, int $offset): array
    {
        $contents = is_file($path) ? (string) file_get_contents($path, offset: $offset) : '';
        $complete = strrpos($contents, "\n");

        if ($complete === false) {
            return ['lines' => [], 'offset' => $offset];
        }

        return [
            'lines' => array_values(array_filter(explode("\n", substr($contents, 0, $complete)), fn (string $line): bool => trim($line) !== '')),
            'offset' => $offset + $complete + 1,
        ];
    }
}
