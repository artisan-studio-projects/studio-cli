<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Livewire;

use ArtisanStudio\StudioCli\Fix\SourceFile;
use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The Livewire components in a project's app folder, read once: each one's
 * class, the parents and traits it takes properties and methods from, and the
 * Blade views its page is made of. Every Livewire check starts here.
 */
final class Components
{
    public const string BASE = 'Livewire\Component';

    private const int INCLUDE_DEPTH = 4;

    /**
     * @var array<string, array{source: SourceFile, node: ClassLike, path: string}>|null
     */
    private ?array $classes = null;

    /**
     * @var array<string, string>
     */
    private array $views = [];

    public function __construct(public readonly string $root) {}

    /**
     * The concrete components, by class name.
     *
     * @return array<string, array{source: SourceFile, node: ClassLike, path: string}>
     */
    public function all(): array
    {
        return array_filter($this->classes(), fn (array $class, string $name): bool => $class['node'] instanceof Class_
            && ! $class['node']->isAbstract()
            && $this->isComponent($name), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, array{source: SourceFile, node: ClassLike, path: string}>
     */
    public function classes(): array
    {
        if ($this->classes !== null) {
            return $this->classes;
        }

        $this->classes = [];

        foreach ($this->phpFiles() as $path) {
            $code = (string) @file_get_contents($this->root.'/'.$path);

            if (! str_contains($code, 'Livewire') && ! str_contains($code, 'trait ') && ! str_contains($path, '/Livewire/')) {
                continue;
            }

            $source = SourceFile::read($this->root.'/'.$path);

            foreach ($source?->all(ClassLike::class) ?? [] as $node) {
                if ($node->namespacedName !== null) {
                    $this->classes[$node->namespacedName->toString()] = ['source' => $source, 'node' => $node, 'path' => $path];
                }
            }
        }

        return $this->classes;
    }

    public function resolved(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim($resolved instanceof Name ? $resolved->toString() : $name->toString(), '\\');
    }

    /**
     * The project classes a class takes members from: its parent and its traits.
     *
     * @return list<string>
     */
    public function inherited(string $name): array
    {
        $node = $this->classes()[$name]['node'] ?? null;

        if (! $node instanceof ClassLike) {
            return [];
        }

        $parent = $node instanceof Class_ && $node->extends instanceof Name ? [$this->resolved($node->extends)] : [];
        $traits = collect($node->stmts)
            ->filter(fn (Node $statement): bool => $statement instanceof TraitUse)
            ->flatMap(fn (TraitUse $use): array => array_map($this->resolved(...), $use->traits))
            ->all();

        return array_values(array_filter([...$parent, ...$traits], fn (string $class): bool => isset($this->classes()[$class])));
    }

    /**
     * Whether a class, or anything it takes members from, uses this trait.
     */
    public function uses(string $name, string $trait, int $depth = 0): bool
    {
        $node = $this->classes()[$name]['node'] ?? null;
        $direct = $node instanceof ClassLike && collect($node->stmts)
            ->filter(fn (Node $statement): bool => $statement instanceof TraitUse)
            ->flatMap(fn (TraitUse $use): array => array_map($this->resolved(...), $use->traits))
            ->contains($trait);

        return $direct || ($depth < 10 && collect($this->inherited($name))->contains(fn (string $parent): bool => $this->uses($parent, $trait, $depth + 1)));
    }

    /**
     * Every property statement a component carries, from itself, its parents
     * and its traits, each with the class that declares it.
     *
     * @return list<array{property: Property, class: string, path: string}>
     */
    public function properties(string $name, int $depth = 0): array
    {
        $class = $this->classes()[$name] ?? null;

        if ($class === null || $depth > 10) {
            return [];
        }

        $own = collect($class['node']->stmts)
            ->filter(fn (Node $statement): bool => $statement instanceof Property)
            ->map(fn (Property $property): array => ['property' => $property, 'class' => $name, 'path' => $class['path']])
            ->all();

        return [...$own, ...collect($this->inherited($name))->flatMap(fn (string $parent): array => $this->properties($parent, $depth + 1))->all()];
    }

    /**
     * Every method a component carries, the same way.
     *
     * @return list<array{method: ClassMethod, class: string, path: string}>
     */
    public function methods(string $name, int $depth = 0): array
    {
        $class = $this->classes()[$name] ?? null;

        if ($class === null || $depth > 10) {
            return [];
        }

        $own = collect($class['node']->stmts)
            ->filter(fn (Node $statement): bool => $statement instanceof ClassMethod)
            ->map(fn (ClassMethod $method): array => ['method' => $method, 'class' => $name, 'path' => $class['path']])
            ->all();

        return [...$own, ...collect($this->inherited($name))->flatMap(fn (string $parent): array => $this->methods($parent, $depth + 1))->all()];
    }

    /**
     * Whether an attribute list holds one of these attributes.
     *
     * @param  array<int, Node\AttributeGroup>  $groups
     * @param  list<string>  $names
     */
    public function hasAttribute(array $groups, array $names): bool
    {
        return collect($groups)
            ->flatMap(fn ($group): array => $group->attrs)
            ->contains(fn (Attribute $attribute): bool => in_array($this->resolved($attribute->name), $names, true));
    }

    /**
     * Everything the component's views hand to Livewire, with the views they
     * include, or null when the page cannot be read or binds by a name worked
     * out at render time.
     */
    public function page(string $name): ?string
    {
        $text = $this->pageText($name);

        return $text === null || preg_match('/wire:[\w.\-:]+\s*=\s*["\'][^"\']*(?:\{\{|\{!!|@php)|:wire:|\$wire\s*\[/', $text) === 1 ? null : $text;
    }

    /**
     * The views' own text, bound or not.
     */
    public function pageText(string $name): ?string
    {
        $class = $this->classes()[$name] ?? null;
        $view = $class === null ? null : $this->viewOf($name, $class['node']);

        return $view === null ? null : $this->withIncludes($view, 0);
    }

    /**
     * The view files a component renders and includes, by path.
     *
     * @return list<string>
     */
    public function viewFiles(string $name): array
    {
        $class = $this->classes()[$name] ?? null;
        $view = $class === null ? null : $this->viewOf($name, $class['node']);

        return $view === null ? [] : $this->includedFrom($view, 0);
    }

    private function isComponent(string $name, int $depth = 0): bool
    {
        $node = $this->classes()[$name]['node'] ?? null;
        $parent = $node instanceof Class_ && $node->extends instanceof Name ? $this->resolved($node->extends) : null;

        return $depth < 10 && $parent !== null && ($parent === self::BASE || $this->isComponent($parent, $depth + 1));
    }

    private function viewOf(string $name, ClassLike $node): ?string
    {
        $render = collect($node->stmts)->first(fn (Node $statement): bool => $statement instanceof ClassMethod && $statement->name->toString() === 'render');
        $call = $render instanceof ClassMethod
            ? (new NodeFinder)->findFirst($render->stmts ?? [], fn (Node $found): bool => $found instanceof FuncCall
                && $found->name instanceof Name
                && $found->name->toString() === 'view'
                && ($found->args[0]->value ?? null) instanceof String_)
            : null;
        $view = $call instanceof FuncCall ? $call->args[0]->value->value : 'livewire.'.implode('.', array_map($this->kebab(...), array_slice(explode('\\', $name), 2)));

        return $this->viewFile($view);
    }

    private function kebab(string $segment): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $segment));
    }

    private function viewFile(string $view): ?string
    {
        $path = $this->root.'/resources/views/'.str_replace('.', '/', $view);

        return collect([$path.'.blade.php', $path.'/index.blade.php'])->first(fn (string $file): bool => is_file($file));
    }

    private function text(string $file): string
    {
        return $this->views[$file] ??= (string) @file_get_contents($file);
    }

    /**
     * @return list<string>
     */
    private function includedFrom(string $file, int $depth): array
    {
        $text = $this->text($file);

        if ($depth >= self::INCLUDE_DEPTH) {
            return [$file];
        }

        preg_match_all('/@include(?:If|When|Unless|First)?\s*\(\s*(?:[^\'"),]*,\s*)?[\'"]([\w.\-:]+)[\'"]/', $text, $included);
        preg_match_all('/<x-([\w.\-]+)/', $text, $components);

        $files = array_unique(array_filter([
            ...array_map($this->viewFile(...), $included[1]),
            ...array_map(fn (string $component): ?string => $this->viewFile('components.'.$component), $components[1]),
        ]));

        return array_values(array_unique([$file, ...collect($files)->flatMap(fn (string $nested): array => $this->includedFrom($nested, $depth + 1))->all()]));
    }

    private function withIncludes(string $file, int $depth): string
    {
        return collect($this->includedFrom($file, $depth))->map(fn (string $included): string => $this->text($included))->implode("\n");
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        if (! is_dir($this->root.'/app')) {
            return [];
        }

        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = ltrim(substr($file->getPathname(), strlen($this->root)), '/');
            }
        }

        sort($found);

        return $found;
    }
}
