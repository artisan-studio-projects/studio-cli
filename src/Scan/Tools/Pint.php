<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

class Pint extends Tool
{
    public const string UNNAMED = 'style';

    public function key(): string
    {
        return 'pint';
    }

    public function name(): string
    {
        return 'Pint';
    }

    public function command(string $root): ?array
    {
        $bin = $this->bin($root, 'pint');

        if ($bin === null) {
            return null;
        }

        return [$bin, '--test', '--format=json', '-v', ...($this->offers($root, [$bin, '--help'], '--parallel') ? ['--parallel'] : [])];
    }

    public function fixCommand(string $root): ?array
    {
        $bin = $this->bin($root, 'pint');

        return $bin === null ? null : [$bin, ...($this->offers($root, [$bin, '--help'], '--parallel') ? ['--parallel'] : [])];
    }

    public function canFix(string $root): bool
    {
        return $this->bin($root, 'pint') !== null;
    }

    public function isSetUp(string $root): bool
    {
        return $this->bin($root, 'pint') !== null;
    }

    public function install(): array
    {
        return ['packages' => ['laravel/pint'], 'files' => [], 'about' => 'Adds Laravel Pint, which checks code style. It runs with --test, so it never changes a file.'];
    }

    public function findings(string $output, string $root): ?array
    {
        $json = $this->json($output);

        if ($json === null) {
            return null;
        }

        $files = (array) ($json['files'] ?? []);

        return collect(array_is_list($files) ? $files : array_map(fn (mixed $file, string $path): array => [...(array) $file, 'path' => $path], $files, array_keys($files)))
            ->flatMap(fn (mixed $file): array => collect((array) (($file['fixers'] ?? null) ?? ($file['appliedFixers'] ?? null) ?? [self::UNNAMED]))
                ->map(fn (mixed $fixer): array => $this->finding(
                    $this->relative((string) ($file['path'] ?? $file['name'] ?? ''), $root),
                    null,
                    (string) $fixer,
                    $fixer === self::UNNAMED ? 'Fails Pint\'s style rules.' : 'Fails Pint\'s '.$fixer.' rule.',
                ))
                ->all())
            ->filter(fn (array $finding): bool => $finding['where'] !== '')
            ->values()
            ->all();
    }
}
