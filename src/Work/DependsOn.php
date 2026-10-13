<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use ArtisanStudio\StudioCli\Fix\LocalBranch;
use Illuminate\Support\Str;

/**
 * The files the studio needs to read to decide a file's findings, beside the file
 * itself: for a Livewire component, the views it renders, the Blade
 * components they use and the policies of the models it uses; for a test,
 * the app classes it imports, the views of the components it renders, and
 * for each page it asks for the routes, the page's component and views and
 * the middleware guarding it, so a failing test is judged against the code it
 * covers. Only ones that exist; the caller sends only those in the repository.
 */
final class DependsOn
{
    private const int MOST_FOR_A_TEST = 8;

    private const int MOST_COMPONENTS = 8;

    private const int MOST_FILES = 24;

    public function __construct(private readonly string $root) {}

    /**
     * @param  string|null  $focus  for a test file, the code of only the tests that fail, so what they use comes before what the rest of the file imports
     * @return list<string>
     */
    public function of(string $path, string $content, ?string $focus = null): array
    {
        $about = $focus ?? $content;
        $needs = match (true) {
            ! str_ends_with($path, '.php') => [],
            str_starts_with($path, 'app/Livewire/') => [...$this->views($path, $content), ...$this->writers($content), ...$this->policies($content)],
            str_starts_with($path, 'tests/') => [...$this->rendered($content, $about), ...$this->routed($about), ...$this->pages($about), ...$this->imported($content, $about)],
            default => [],
        };

        return array_slice(array_values(array_unique(array_filter($needs, fn (string $file): bool => $file !== $path && is_file($this->root.'/'.$file)))), 0, self::MOST_FILES);
    }

    /**
     * Every view or script in the repository that writes one of this
     * component's or trait's public properties from the browser, found by
     * grep, so a property is never locked while a page still sets it.
     *
     * @return list<string>
     */
    private function writers(string $content): array
    {
        preg_match_all('/public\s+(?:readonly\s+)?(?:\??[\w\\\\|]+\s+)?\$(\w+)/', $content, $properties);

        return array_slice((new LocalBranch($this->root))->writersOf(array_values(array_unique($properties[1]))), 0, self::MOST_COMPONENTS);
    }

    /**
     * The app classes the file imports that the code it is about uses.
     *
     * @return list<string>
     */
    private function imported(string $content, ?string $about = null): array
    {
        preg_match_all('/^use App\\\\([\w\\\\]+);/m', $content, $classes);
        $used = array_values(array_filter($classes[1], fn (string $class): bool => $about === null || preg_match('/\b'.preg_quote(class_basename($class), '/').'\b/', $about) === 1));

        return array_slice(array_map(fn (string $class): string => 'app/'.str_replace('\\', '/', $class).'.php', $used), 0, self::MOST_FOR_A_TEST);
    }

    /**
     * The Livewire components a test imports, each with its views and the
     * Blade components they use: what the test is about comes first.
     *
     * @return list<string>
     */
    private function rendered(string $content, string $about): array
    {
        return array_merge([], ...array_map(
            fn (string $component): array => [$component, ...$this->viewsAndComponents($component, (string) file_get_contents($this->root.'/'.$component))],
            array_values(array_filter($this->imported($content, $about), fn (string $class): bool => str_starts_with($class, 'app/Livewire/') && is_file($this->root.'/'.$class))),
        ));
    }

    /**
     * The routes a test reaches when it asks for a page.
     *
     * @return list<string>
     */
    private function routed(string $content): array
    {
        if (preg_match('/(->|\b)(get|post|put|patch|delete|getJson|postJson|putJson|patchJson|deleteJson|call)\(\s*(route\(|[\'"]\/)/', $content) !== 1) {
            return [];
        }

        return preg_match('/[\'"]\/api\/|Json\(/', $content) === 1 ? ['routes/web.php', 'routes/api.php'] : ['routes/web.php'];
    }

    /**
     * For each page a test asks for, by route name or by path: the class the
     * route points at, its views when it is a Livewire page, and the
     * middleware of the groups just above it.
     *
     * @return list<string>
     */
    private function pages(string $content): array
    {
        preg_match_all('/[\'"]([\w.\-]+)[\'"]/', $content, $names);
        preg_match_all('/(?:->|\b)(?:get|post|put|patch|delete|getJson|postJson|putJson|patchJson|deleteJson)\(\s*[\'"](\/[^\'"?]*)[\'"]/', $content, $paths);
        $asked = array_values(array_unique([
            ...array_map(fn (string $name): string => "name('".$name."')", $names[1]),
            ...array_map(fn (string $path): string => "'".$path."'", $paths[1]),
        ]));

        if ($asked === []) {
            return [];
        }

        return array_merge([], ...array_map(
            fn (string $routes): array => $this->reachedIn($routes, $asked),
            array_values(array_filter(['routes/web.php', 'routes/api.php'], fn (string $routes): bool => is_file($this->root.'/'.$routes))),
        ));
    }

    /**
     * @param  list<string>  $asked
     * @return list<string>
     */
    private function reachedIn(string $routes, array $asked): array
    {
        $source = (string) file_get_contents($this->root.'/'.$routes);
        $lines = explode("\n", $source);
        preg_match_all('/^use App\\\\([\w\\\\]+);/m', $source, $uses);
        $classes = collect($uses[1])->mapWithKeys(fn (string $class): array => [class_basename($class) => 'app/'.str_replace('\\', '/', $class).'.php']);

        return collect($lines)
            ->filter(fn (string $line): bool => Str::contains($line, $asked))
            ->flatMap(fn (string $line, int $at): array => [$line, ...collect(array_slice($lines, 0, $at))->reverse()->filter(fn (string $above): bool => str_contains($above, 'middleware('))->take(2)->all()])
            ->flatMap(fn (string $line): array => preg_match_all('/(\w+)::class/', $line, $named) > 0 ? $named[1] : [])
            ->map(fn (string $name): ?string => $classes->get($name))
            ->filter(fn (?string $class): bool => $class !== null && is_file($this->root.'/'.$class))
            ->unique()
            ->flatMap(fn (string $class): array => str_starts_with($class, 'app/Livewire/') ? [$class, ...$this->viewsAndComponents($class, (string) file_get_contents($this->root.'/'.$class))] : [$class])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function viewsAndComponents(string $path, string $content): array
    {
        $views = $this->views($path, $content);

        return [...$views, ...$this->components($views)];
    }

    /**
     * Every view the component can render: the ones it names, wherever it
     * names them, and the one its name gives.
     *
     * @return list<string>
     */
    private function views(string $path, string $content): array
    {
        preg_match_all('/view\(\s*[\'"]([A-Za-z0-9_.\-\/]+)[\'"]/', $content, $named);
        preg_match_all('/[\'"](livewire\.[\w.\-]+)[\'"]/', $content, $quoted);
        $conventional = 'livewire.'.collect(explode('/', Str::beforeLast(Str::after($path, 'app/Livewire/'), '.php')))->map(fn (string $part): string => Str::kebab($part))->implode('.');

        return array_values(array_unique(array_map(fn (string $view): string => 'resources/views/'.str_replace('.', '/', $view).'.blade.php', [...$named[1], ...$quoted[1], $conventional])));
    }

    /**
     * The anonymous Blade components the views use, one level deep.
     *
     * @param  list<string>  $views
     * @return list<string>
     */
    private function components(array $views): array
    {
        return collect($views)
            ->filter(fn (string $view): bool => is_file($this->root.'/'.$view))
            ->flatMap(fn (string $view): array => preg_match_all('/<x-([\w\-]+(?:\.[\w\-]+)*)/', (string) file_get_contents($this->root.'/'.$view), $tags) > 0 ? $tags[1] : [])
            ->unique()
            ->flatMap(fn (string $tag): array => ['resources/views/components/'.str_replace('.', '/', $tag).'.blade.php', 'resources/views/components/'.str_replace('.', '/', $tag).'/index.blade.php'])
            ->filter(fn (string $component): bool => is_file($this->root.'/'.$component))
            ->take(self::MOST_COMPONENTS)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function policies(string $content): array
    {
        preg_match_all('/^use App\\\\Models\\\\(\w+);/m', $content, $models);

        return array_map(fn (string $model): string => 'app/Policies/'.$model.'Policy.php', $models[1]);
    }
}
