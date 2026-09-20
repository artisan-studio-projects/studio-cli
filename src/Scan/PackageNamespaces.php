<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;

/**
 * What Artisan Studio needs to tell which installed package a finding
 * involves: the namespaces each package autoloads, read from the project's own
 * vendor/composer/installed.json, and the classes a flagged file's own classes
 * extend or implement. The studio does the matching.
 */
final class PackageNamespaces
{
    /**
     * @var array<string, list<string>>
     */
    private array $builtOn = [];

    public function __construct(private readonly string $root) {}

    /**
     * Each installed package's namespace prefixes, leaving out any that are
     * the project's own.
     *
     * @return array<string, string> namespace prefix => package
     */
    public function namespaces(): array
    {
        $installed = json_decode((string) @file_get_contents($this->root.'/vendor/composer/installed.json'), true);
        $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];
        $project = json_decode((string) @file_get_contents($this->root.'/composer.json'), true);
        $own = is_array($project) ? array_keys([...(array) ($project['autoload']['psr-4'] ?? []), ...(array) ($project['autoload-dev']['psr-4'] ?? [])]) : [];

        return collect(is_array($packages) ? $packages : [])
            ->filter(fn (mixed $package): bool => is_array($package) && is_string($package['name'] ?? null))
            ->flatMap(fn (array $package): array => collect([...array_keys((array) ($package['autoload']['psr-4'] ?? [])), ...array_keys((array) ($package['autoload']['psr-0'] ?? []))])
                ->filter(fn (mixed $prefix): bool => is_string($prefix) && str_ends_with($prefix, '\\') && ! collect($own)->contains(fn (mixed $mine): bool => is_string($mine) && (str_starts_with($prefix, $mine) || str_starts_with($mine, $prefix))))
                ->mapWithKeys(fn (string $prefix): array => [$prefix => (string) $package['name']])
                ->all())
            ->all();
    }

    /**
     * The classes the file's own classes extend or implement.
     *
     * @return list<string>
     */
    public function builtOn(string $path): array
    {
        $path = (string) preg_replace('/:\d+$/', '', $path);

        if (! isset($this->builtOn[$path])) {
            $file = str_ends_with($path, '.php') ? SourceFile::read($this->root.'/'.ltrim($path, '/')) : null;

            $this->builtOn[$path] = $file === null ? [] : array_values(array_unique(collect($file->all(ClassLike::class))
                ->flatMap(fn (ClassLike $class): array => match (true) {
                    $class instanceof Class_ => [...($class->extends === null ? [] : [$class->extends]), ...$class->implements],
                    $class instanceof Interface_ => $class->extends,
                    default => [],
                })
                ->map(fn (Name $name): string => ltrim(($name->getAttribute('resolvedName') ?? $name)->toString(), '\\'))
                ->take(20)
                ->all()));
        }

        return $this->builtOn[$path];
    }
}
