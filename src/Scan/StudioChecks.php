<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Studio;

/**
 * A security check that runs in Artisan Studio on the project's files. The
 * studio says which files it needs, this machine sends them, and it answers
 * with what it found. Only the files it asks for leave, never one holding a
 * secret, and the studio keeps none of them.
 */
final class StudioChecks
{
    private const int MOST_ROUNDS = 8;

    private const int MOST_BYTES_EACH_TIME = 16_000_000;

    public function __construct(
        private readonly string $root,
        private readonly Studio $studio,
    ) {}

    /**
     * What the check found, and the files and rules it could not read. Null
     * when the studio could not be reached or never had what it needed.
     *
     * @return array{findings: list<array{where: string, rule: string, message: string}>, unread: list<array{where: string, rule: string}>}|null
     */
    public function run(string $check): ?array
    {
        $reads = new ProjectReads($this->root, self::MOST_BYTES_EACH_TIME);
        $sent = [];

        for ($round = 1; $round <= self::MOST_ROUNDS; $round++) {
            $answer = $this->studio->runScanCheck($check, ['files' => $this->files($reads, $sent), 'missing' => $reads->missing(), 'found' => (object) $reads->found()]);

            if (! is_array($answer) || is_array($answer['findings'] ?? null)) {
                return is_array($answer) ? self::report($answer) : null;
            }

            $more = $reads->answer((array) ($answer['needs'] ?? []), $sent);

            if ($more === null) {
                return null;
            }

            $sent = [...$sent, ...$more];
        }

        return null;
    }

    /**
     * @param  list<string>  $paths
     * @return list<array{path: string, content: string}>
     */
    private function files(ProjectReads $reads, array $paths): array
    {
        return array_values(array_filter(array_map(
            fn (string $path): ?array => ($text = $reads->readable($path)) === null ? null : ['path' => $path, 'content' => base64_encode($text)],
            array_values(array_unique($paths)),
        )));
    }

    /**
     * @param  array<mixed>  $answer
     * @return array{findings: list<array{where: string, rule: string, message: string}>, unread: list<array{where: string, rule: string}>}
     */
    private static function report(array $answer): array
    {
        return [
            'findings' => array_values(array_map(
                fn (array $finding): array => ['where' => $finding['where'], 'rule' => $finding['rule'], 'message' => $finding['message']],
                array_filter((array) $answer['findings'], fn (mixed $finding): bool => is_array($finding) && is_string($finding['where'] ?? null) && is_string($finding['rule'] ?? null) && is_string($finding['message'] ?? null)),
            )),
            'unread' => array_values(array_map(
                fn (array $unread): array => ['where' => $unread['where'], 'rule' => $unread['rule']],
                array_filter((array) ($answer['unread'] ?? []), fn (mixed $unread): bool => is_array($unread) && is_string($unread['where'] ?? null) && is_string($unread['rule'] ?? null)),
            )),
        ];
    }
}
