<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Scan\PackageNamespaces;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class PhpStan extends Tool
{
    /**
     * @var array{files: int, flagged: int}|null
     */
    private ?array $totals = null;

    public function key(): string
    {
        return 'phpstan';
    }

    public function name(): string
    {
        return 'PHPStan';
    }

    public const array CONFIGS = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

    public function command(string $root): ?array
    {
        $bin = $this->bin($root, 'phpstan');
        $configured = collect(self::CONFIGS)->contains(fn (string $config): bool => is_file($root.'/'.$config));

        return $bin === null || ! $configured ? null : [$bin, 'analyse', '--error-format=json', '--no-progress', '--no-interaction', '--memory-limit=2G'];
    }

    public function missing(): string
    {
        return 'Not set up in this project: it needs PHPStan or Larastan and a phpstan.neon.';
    }

    public function install(): array
    {
        return [
            'packages' => ['larastan/larastan'],
            'files' => ['phpstan.neon' => <<<'NEON'
                includes:
                    - vendor/larastan/larastan/extension.neon

                parameters:
                    paths:
                        - app
                    level: 5

                NEON],
            'about' => 'Adds Larastan, PHPStan for Laravel, and a phpstan.neon that checks app/ at level 5. Raise the level whenever you like.',
        ];
    }

    public function findings(string $output, string $root): ?array
    {
        $json = $this->json($output);

        if ($json === null || ! isset($json['totals'])) {
            return null;
        }

        $packages = new PackageNamespaces($root);
        $findings = array_values(collect((array) ($json['files'] ?? []))
            ->flatMap(fn (mixed $file, string $path): array => collect((array) ($file['messages'] ?? []))
                ->map(function (mixed $message) use ($path, $root, $packages): array {
                    $where = $this->relative((string) preg_replace('/ \(in context of .*\)$/', '', $path), $root);
                    $involved = $packages->involvedIn((string) ($message['message'] ?? ''), $where);

                    return [
                        ...$this->finding($where, isset($message['line']) ? (int) $message['line'] : null, (string) ($message['identifier'] ?? 'phpstan'), (string) ($message['message'] ?? '')),
                        ...($involved === [] ? [] : ['packages' => $involved]),
                    ];
                })
                ->all())
            ->all());

        $flagged = count(array_unique(array_map(fn (array $finding): string => (string) preg_replace('/:\d+$/', '', $finding['where']), $findings)));
        $this->totals = ['files' => max($flagged, $this->analysed($root)), 'flagged' => $flagged];

        return $findings;
    }

    /**
     * How many PHP files PHPStan read, and how many it found something in, so
     * the score can count the files that came back clean.
     *
     * @return array{files: int, flagged: int}|null
     */
    public function summary(): ?array
    {
        return $this->totals;
    }

    /**
     * The PHP files under the paths the project's own config tells PHPStan to read.
     */
    private function analysed(string $root): int
    {
        $config = collect(self::CONFIGS)->map(fn (string $name): string => $root.'/'.$name)->first(fn (string $path): bool => is_file($path));
        $neon = $config === null ? '' : (string) file_get_contents($config);
        $paths = preg_match('/^\s*paths:\s*\n((?:\s+-\s*.+\n?)+)/m', $neon, $match) === 1
            ? array_map(fn (string $line): string => trim((string) preg_replace('/^\s*-\s*/', '', $line), " \t'\""), array_filter(explode("\n", $match[1]), fn (string $line): bool => trim($line) !== ''))
            : ['app'];

        return array_sum(array_map(fn (string $path): int => $this->phpFilesIn(str_starts_with($path, '/') ? $path : $root.'/'.$path), $paths));
    }

    private function phpFilesIn(string $path): int
    {
        if (is_file($path)) {
            return str_ends_with($path, '.php') ? 1 : 0;
        }

        if (! is_dir($path)) {
            return 0;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        return iterator_count(new RegexIterator($files, '/\.php$/'));
    }
}
