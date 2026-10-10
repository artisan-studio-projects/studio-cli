<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use Symfony\Component\Process\Process;

/**
 * The files one fixing pass has open, so several fixes to the same file land
 * as one write.
 */
final class Workbench
{
    /**
     * @var array<string, SourceFile|null>
     */
    private array $files = [];

    /**
     * @var array<string, list<string>>|null
     */
    private ?array $autoload = null;

    public function __construct(public readonly string $root) {}

    public function open(string $path): ?SourceFile
    {
        $path = ltrim($path, '/');

        if (! array_key_exists($path, $this->files)) {
            $full = $this->root.'/'.$path;
            $this->files[$path] = match (true) {
                ! is_file($full) => null,
                str_ends_with($path, '.blade.php') => SourceFile::readText($full),
                str_ends_with($path, '.php') => SourceFile::read($full),
                default => null,
            };
        }

        return $this->files[$path];
    }

    /**
     * Where a class lives, by the project's own PSR-4 autoload.
     */
    public function classFile(string $class): ?string
    {
        $class = ltrim($class, '\\');

        return collect($this->autoload())
            ->sortKeysDesc()
            ->filter(fn (array $folders, string $prefix): bool => $prefix !== '' && str_starts_with($class, $prefix))
            ->flatMap(fn (array $folders, string $prefix): array => array_map(
                fn (string $folder): string => rtrim($folder, '/').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php',
                $folders,
            ))
            ->first(fn (string $path): bool => is_file($this->root.'/'.$path));
    }

    /**
     * Whether any class in the project's own folders extends this one.
     */
    public function isExtended(string $class): bool
    {
        $folders = collect($this->autoload())->flatten()->map(fn (string $folder): string => $this->root.'/'.trim($folder, '/'))->filter(fn (string $folder): bool => is_dir($folder))->values()->all();

        return $folders !== [] && collect($folders)->contains(function (string $folder) use ($class): bool {
            $found = Process::fromShellCommandline('grep -rlE '.escapeshellarg('extends\s+\\\\?([A-Za-z0-9_\\\\]+\\\\)?'.preg_quote($class, '/').'\b').' --include=*.php '.escapeshellarg($folder));
            $found->run();

            return trim($found->getOutput()) !== '';
        });
    }

    /**
     * Whether the project says it runs on at least this PHP.
     */
    public function runsPhp(string $version): bool
    {
        $composer = json_decode((string) @file_get_contents($this->root.'/composer.json'), true);
        $constraint = (string) ($composer['require']['php'] ?? '');

        return preg_match('/(\d+\.\d+)/', $constraint, $match) === 1 && version_compare($match[1], $version, '>=');
    }

    /**
     * @return list<string>
     */
    public function save(): array
    {
        return array_values(collect($this->files)
            ->filter(fn (?SourceFile $file): bool => $file?->changed() === true)
            ->filter(fn (SourceFile $file): bool => $file->save())
            ->keys()
            ->all());
    }

    /**
     * @return array<string, list<string>>
     */
    private function autoload(): array
    {
        if ($this->autoload === null) {
            $composer = json_decode((string) @file_get_contents($this->root.'/composer.json'), true);

            $this->autoload = collect([...(array) ($composer['autoload']['psr-4'] ?? []), ...(array) ($composer['autoload-dev']['psr-4'] ?? [])])
                ->map(fn (mixed $folders): array => array_values(array_filter((array) $folders, is_string(...))))
                ->all();
        }

        return $this->autoload;
    }
}
