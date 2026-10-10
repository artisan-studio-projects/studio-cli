<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Fix\SourceFile;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;

/**
 * Which installed Composer package a class comes from, read from the
 * project's own vendor/composer/installed.json: every package says which
 * namespaces it autoloads.
 *
 * A finding involves a package when its message names one of its classes,
 * or when it sits in a class built on one: one that extends or implements
 * a class of the package.
 */
final class PackageNamespaces
{
    /**
     * @var array<string, string>|null namespace prefix => package
     */
    private ?array $prefixes = null;

    /**
     * @var array<string, list<string>>
     */
    private array $builtOn = [];

    public function __construct(private readonly string $root) {}

    /**
     * @return list<string>
     */
    public function involvedIn(string $message, string $path): array
    {
        preg_match_all('/(?<![\w\\\\])\\\\?((?:[A-Z][A-Za-z0-9_]*\\\\)+[A-Z][A-Za-z0-9_]*)/', $message, $classes);

        return array_values(array_unique([
            ...array_filter(array_map($this->packageOf(...), $classes[1])),
            ...$this->builtOn($path),
        ]));
    }

    public function packageOf(string $class): ?string
    {
        $class = ltrim($class, '\\');

        return collect($this->prefixes())
            ->filter(fn (string $package, string $prefix): bool => str_starts_with($class, $prefix))
            ->sortKeysUsing(fn (string $a, string $b): int => strlen($b) <=> strlen($a))
            ->first();
    }

    /**
     * The packages whose classes the file's own classes extend or implement.
     *
     * @return list<string>
     */
    private function builtOn(string $path): array
    {
        $path = (string) preg_replace('/:\d+$/', '', $path);

        if (! isset($this->builtOn[$path])) {
            $file = str_ends_with($path, '.php') ? SourceFile::read($this->root.'/'.ltrim($path, '/')) : null;

            $this->builtOn[$path] = $file === null ? [] : array_values(array_unique(array_filter(collect($file->all(ClassLike::class))
                ->flatMap(fn (ClassLike $class): array => match (true) {
                    $class instanceof Class_ => [...($class->extends === null ? [] : [$class->extends]), ...$class->implements],
                    $class instanceof Interface_ => $class->extends,
                    default => [],
                })
                ->map(fn (Name $name): ?string => $this->packageOf(($name->getAttribute('resolvedName') ?? $name)->toString()))
                ->all())));
        }

        return $this->builtOn[$path];
    }

    /**
     * @return array<string, string>
     */
    private function prefixes(): array
    {
        if ($this->prefixes !== null) {
            return $this->prefixes;
        }

        $installed = json_decode((string) @file_get_contents($this->root.'/vendor/composer/installed.json'), true);
        $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];
        $project = json_decode((string) @file_get_contents($this->root.'/composer.json'), true);
        $own = is_array($project) ? array_keys([...(array) ($project['autoload']['psr-4'] ?? []), ...(array) ($project['autoload-dev']['psr-4'] ?? [])]) : [];

        return $this->prefixes = collect(is_array($packages) ? $packages : [])
            ->filter(fn (mixed $package): bool => is_array($package) && is_string($package['name'] ?? null))
            ->flatMap(fn (array $package): array => collect([...array_keys((array) ($package['autoload']['psr-4'] ?? [])), ...array_keys((array) ($package['autoload']['psr-0'] ?? []))])
                ->filter(fn (mixed $prefix): bool => is_string($prefix) && $prefix !== '' && ! collect($own)->contains(fn (mixed $mine): bool => is_string($mine) && (str_starts_with($prefix, $mine) || str_starts_with($mine, $prefix))))
                ->mapWithKeys(fn (string $prefix): array => [$prefix => (string) $package['name']])
                ->all())
            ->all();
    }
}
