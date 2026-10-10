<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Scan\Livewire\Components;
use FilesystemIterator;
use PhpParser\Node\Name;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Livewire matters that belong to the whole project rather than one
 * component: a Livewire release with a known remote-code-execution flaw,
 * route middleware that Livewire's later requests do not run again, and
 * model class names that travel in the page.
 */
final class LivewireProject
{
    public const string OUTDATED = 'livewire-outdated';

    public const string MIDDLEWARE = 'livewire-middleware-not-persisted';

    public const string MORPH_MAP = 'livewire-morph-map';

    private const string FIXED_IN = '3.6.4';

    private readonly Components $components;

    public function __construct(private readonly string $root, ?Components $components = null)
    {
        $this->components = $components ?? new Components($root);
    }

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    public function findings(): array
    {
        if ($this->components->all() === []) {
            return [];
        }

        return array_values(array_filter([$this->outdated(), ...$this->middleware(), $this->morphMap()]));
    }

    /**
     * @return array{where: string, rule: string, message: string}|null
     */
    private function outdated(): ?array
    {
        $lock = (array) json_decode((string) @file_get_contents($this->root.'/composer.lock'), true);
        $version = collect([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])])
            ->first(fn (mixed $package): bool => is_array($package) && ($package['name'] ?? null) === 'livewire/livewire')['version'] ?? null;
        $version = is_string($version) ? ltrim($version, 'v') : null;

        return $version !== null && str_starts_with($version, '3.') && version_compare($version, self::FIXED_IN, '<') ? [
            'where' => 'composer.lock',
            'rule' => self::OUTDATED,
            'message' => 'Livewire '.$version.' has a known remote-code-execution flaw, fixed in '.self::FIXED_IN.'. Update it with composer update livewire/livewire.',
        ] : null;
    }

    /**
     * Route middleware of the project's own that Livewire's later requests do
     * not run again unless it is registered as persistent.
     *
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function middleware(): array
    {
        if ($this->anywhere('/addPersistentMiddleware/')) {
            return [];
        }

        $custom = $this->customMiddleware();
        $found = [];

        foreach (glob($this->root.'/routes/*.php') ?: [] as $routes) {
            $text = (string) @file_get_contents($routes);

            foreach ($custom as $name => $class) {
                $pattern = '/middleware\s*\([^;]*?(?:[\'"]'.preg_quote($name, '/').'(?::[^\'"]*)?[\'"]|'.preg_quote(ltrim(strrchr('\\'.$class, '\\'), '\\'), '/').'::class)/s';

                if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $found[$name] ??= [
                        'where' => 'routes/'.basename($routes).':'.(substr_count(substr($text, 0, $match[0][1]), "\n") + 1),
                        'rule' => self::MIDDLEWARE,
                        'message' => 'The '.$name.' middleware runs when a page loads, but Livewire\'s later requests do not run it again, so what it checks can be skipped. Register it with Livewire::addPersistentMiddleware().',
                    ];
                }
            }
        }

        return array_values($found);
    }

    /**
     * The middleware aliases bootstrap/app.php gives to the project's own
     * classes, by alias.
     *
     * @return array<string, string>
     */
    private function customMiddleware(): array
    {
        $text = (string) @file_get_contents($this->root.'/bootstrap/app.php');
        preg_match_all('/^use\s+([\w\\\\]+?)(?:\s+as\s+(\w+))?;/m', $text, $uses, PREG_SET_ORDER);
        $imports = collect($uses)->mapWithKeys(fn (array $use): array => [($use[2] ?? '') !== '' ? $use[2] : substr((string) strrchr('\\'.$use[1], '\\'), 1) => $use[1]])->all();
        preg_match_all('/[\'"]([\w.\-]+)[\'"]\s*=>\s*([\w\\\\]+)::class/', $text, $aliases, PREG_SET_ORDER);

        return collect($aliases)
            ->mapWithKeys(fn (array $alias): array => [$alias[1] => ltrim($imports[$alias[2]] ?? $alias[2], '\\')])
            ->filter(fn (string $class): bool => str_starts_with($class, 'App\\Http\\Middleware\\'))
            ->all();
    }

    /**
     * @return array{where: string, rule: string, message: string}|null
     */
    private function morphMap(): ?array
    {
        $holder = collect($this->components->all())->first(fn (array $class, string $name): bool => collect($this->components->properties($name))
            ->contains(fn (array $declared): bool => $declared['property']->isPublic()
                && $declared['property']->type instanceof Name
                && str_starts_with($this->components->resolved($declared['property']->type), 'App\\Models\\')));

        if ($holder === null || $this->anywhere('/enforceMorphMap|Relation::morphMap/')) {
            return null;
        }

        $name = collect($this->components->all())->keys()->first(fn (string $name): bool => $this->components->classes()[$name]['path'] === $holder['path']);
        $model = collect($this->components->properties((string) $name))->first(fn (array $declared): bool => $declared['property']->type instanceof Name && str_starts_with($this->components->resolved($declared['property']->type), 'App\\Models\\'));

        return [
            'where' => $holder['path'].':'.($model['property']->getStartLine() ?? 1),
            'rule' => self::MORPH_MAP,
            'message' => 'Components hold models in public properties, and the page carries each model\'s full class name. Alias them with Relation::enforceMorphMap() so the names stay private.',
        ];
    }

    private function anywhere(string $pattern): bool
    {
        foreach (['app', 'bootstrap'] as $folder) {
            if (! is_dir($this->root.'/'.$folder)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/'.$folder, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && preg_match($pattern, (string) @file_get_contents($file->getPathname())) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
