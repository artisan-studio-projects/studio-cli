<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

class Blueprint
{
    private const string CAST = '/^[A-Za-z0-9_\\\\:,.-]{1,255}$/';

    public function __construct(
        private readonly string $appPath,
        private readonly string $namespace,
        private readonly ModelInspector $inspector,
    ) {}

    /**
     * @return array{models: array<int, array{class: string, table: string, columns: array<int, array{name: string, type: string, nullable: bool, cast: ?string, fillable: bool, hidden: bool, unique: bool}>, relationships: array<int, array{name: string, type: string, related: string}>, observers: array<int, array{event: string, observer: string}>}>, skipped: array<int, string>}
     */
    public function map(): array
    {
        $inspected = $this->models()->mapWithKeys(fn (string $class): array => [$class => $this->inspect($class)]);

        return [
            'models' => $inspected->filter()->values()->all(),
            'skipped' => $inspected->filter(fn (?array $model): bool => $model === null)->keys()->all(),
        ];
    }

    /**
     * @return Collection<int, string>
     */
    public function models(): Collection
    {
        if (! is_dir($this->appPath)) {
            return collect();
        }

        return collect(Finder::create()->in($this->appPath)->files()->name('*.php')->contains('/\bextends\b/'))
            ->map(fn (SplFileInfo $file): string => $this->namespace.str_replace(['/', '\\'], '\\', Str::beforeLast($file->getRelativePathname(), '.php')))
            ->filter(fn (string $class): bool => $this->isAModel($class))
            ->sort()
            ->values();
    }

    /**
     * @return array{class: string, table: string, columns: array<int, array{name: string, type: string, nullable: bool, cast: ?string, fillable: bool, hidden: bool, unique: bool}>, relationships: array<int, array{name: string, type: string, related: string}>, observers: array<int, array{event: string, observer: string}>}|null
     */
    private function inspect(string $class): ?array
    {
        try {
            $info = $this->inspector->inspect($class);
        } catch (Throwable) {
            return null;
        }

        return [
            'class' => $class,
            'table' => (string) $info->table,
            'columns' => collect($info->attributes)
                ->filter(fn (array $attribute): bool => $attribute['type'] !== null)
                ->map(fn (array $attribute): array => [
                    'name' => (string) $attribute['name'],
                    'type' => $this->typeOf((string) $attribute['type'], is_string($attribute['cast'] ?? null) ? $attribute['cast'] : null),
                    'nullable' => (bool) $attribute['nullable'],
                    'cast' => is_string($attribute['cast'] ?? null) && preg_match(self::CAST, $attribute['cast']) === 1 ? $attribute['cast'] : null,
                    'fillable' => (bool) ($attribute['fillable'] ?? false),
                    'hidden' => (bool) ($attribute['hidden'] ?? false),
                    'unique' => (bool) ($attribute['unique'] ?? false),
                ])
                ->values()
                ->all(),
            'observers' => collect($info->observers)
                ->flatMap(fn (array $listener): array => array_map(
                    fn (string $observer): array => ['event' => (string) $listener['event'], 'observer' => $observer === 'Closure' ? 'booted()' : Str::before($observer, '@')],
                    (array) $listener['observer'],
                ))
                ->unique(fn (array $listener): string => $listener['event'].$listener['observer'])
                ->values()
                ->all(),
            'relationships' => collect($info->relations)
                ->map(fn (array $relation): array => [
                    'name' => (string) $relation['name'],
                    'type' => (string) $relation['type'],
                    'related' => (string) $relation['related'],
                ])
                ->values()
                ->all(),
        ];
    }

    private function isAModel(string $class): bool
    {
        try {
            return class_exists($class)
                && is_subclass_of($class, Model::class)
                && ! (new ReflectionClass($class))->isAbstract();
        } catch (Throwable) {
            return false;
        }
    }

    private function typeOf(string $databaseType, ?string $cast): string
    {
        return $this->typeFromCast((string) $cast) ?? $this->typeFromDatabase(
            Str::of($databaseType)->before('(')->trim()->before(' ')->lower()->toString(),
        );
    }

    private function typeFromCast(string $cast): ?string
    {
        $name = Str::lower(Str::before($cast, ':'));

        return match (true) {
            $cast !== '' && enum_exists($cast) => 'enum',
            in_array($name, ['bool', 'boolean'], true) => 'boolean',
            in_array($name, ['int', 'integer'], true) => 'integer',
            in_array($name, ['float', 'double', 'real', 'decimal'], true) => 'number',
            in_array($name, ['array', 'json', 'collection', 'object'], true) => 'json',
            Str::endsWith(class_basename(Str::before($cast, ':')), ['Collection', 'ArrayObject']) => 'json',
            in_array($name, ['date', 'immutable_date'], true) => 'date',
            in_array($name, ['datetime', 'immutable_datetime', 'custom_datetime', 'timestamp'], true) => 'datetime',
            default => null,
        };
    }

    private function typeFromDatabase(string $type): string
    {
        return match (true) {
            str_contains($type, 'datetime') || str_contains($type, 'timestamp') => 'datetime',
            str_contains($type, 'date') => 'date',
            in_array($type, ['bool', 'boolean'], true) => 'boolean',
            in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'year'], true) => 'integer',
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real'], true) => 'number',
            in_array($type, ['json', 'jsonb'], true) => 'json',
            $type === 'enum' => 'enum',
            default => 'string',
        };
    }
}
