<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use Symfony\Component\Finder\Finder;

/**
 * A trait whose methods read `$this->videoResolution`, used by components of
 * which only some declare it, fails on the others the moment that method runs.
 * The declaration moves into the trait, copied word for word from the classes
 * that have it, so every component using the trait has it too.
 *
 * Only when every class that declares it declares it the same way, since PHP
 * refuses a trait and a class that disagree. Nobody declaring it at all is a
 * real gap and is left for a person.
 */
final class TraitHostProperty implements Fixer
{
    private const int DEEPEST = 6;

    /**
     * @var array<string, list<array{path: string, class: string, trait: bool}>>|null trait => what uses it
     */
    private ?array $users = null;

    /**
     * @var array<string, bool>
     */
    private array $done = [];

    /**
     * @var array<string, array<string, string>> trait => property => what this pass declared
     */
    private array $added = [];

    public function rule(): string
    {
        return 'property.notFound';
    }

    public function label(): string
    {
        return 'properties a trait reads declared in the trait';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->open($path);
        $trait = collect($file?->spanning($line, Trait_::class) ?? [])->first();

        if ($file === null || ! $trait instanceof Trait_ || preg_match('/undefined property (?:[\w\\\\]+|\$this\([\w\\\\]+\))::\$(\w+)/', $message, $match) !== 1) {
            return false;
        }

        $name = ltrim((string) $trait->namespacedName?->toString(), '\\');
        $key = $name.'::'.$match[1];

        return $this->done[$key] ??= $this->declare($bench, $file, $trait, $name, $match[1]);
    }

    private function declare(Workbench $bench, SourceFile $file, Trait_ $trait, string $name, string $property): bool
    {
        $users = $this->classesUsing($bench, $name, 0);

        $declarations = collect($users)
            ->flatMap(function (array $user) use ($bench, $property, $name): array {
                $file = $bench->open($user['path']);
                $class = $file?->classNamed($user['class']);

                return $file === null || $class === null ? ['#unreadable'] : $this->composed($bench, $file, $class, $property, $name, 0);
            })
            ->map(fn (string $declaration): string => (string) preg_replace('/\s+/', ' ', $declaration))
            ->unique()
            ->values();

        $first = $trait->stmts[0] ?? null;

        if ($declarations->count() !== 1 || $first === null || $trait->getProperty($property) !== null) {
            return false;
        }

        $lineStart = strrpos(substr($file->code, 0, $first->getStartFilePos()), "\n");
        $indent = substr($file->code, $lineStart === false ? 0 : $lineStart + 1, $first->getStartFilePos() - ($lineStart === false ? 0 : $lineStart + 1));

        if (! $file->insert($first->getStartFilePos(), $file->localise((string) $declarations->first())."\n\n".$indent)) {
            return false;
        }

        $this->added[$name][$property] = (string) $declarations->first();

        return true;
    }

    /**
     * The classes that use a trait, directly or through other traits.
     *
     * @return list<array{path: string, class: string}>
     */
    private function classesUsing(Workbench $bench, string $trait, int $depth): array
    {
        if ($depth >= self::DEEPEST) {
            return [];
        }

        return array_values(collect($this->index($bench)[$trait] ?? [])
            ->flatMap(fn (array $user): array => $user['trait']
                ? $this->classesUsing($bench, $user['class'], $depth + 1)
                : [['path' => $user['path'], 'class' => $user['class']]])
            ->unique(fn (array $user): string => $user['class'])
            ->all());
    }

    /**
     * Which classes and traits under app/ use which traits.
     *
     * @return array<string, list<array{path: string, class: string, trait: bool}>>
     */
    private function index(Workbench $bench): array
    {
        return $this->users ??= collect(is_dir($bench->root.'/app') ? iterator_to_array(Finder::create()->files()->in($bench->root.'/app')->name('*.php')->contains('/^\s*use\s+[\w\\\\]+\s*[;,{]/m'), false) : [])
            ->flatMap(function ($file): array {
                $source = SourceFile::read($file->getPathname());

                return $source === null ? [] : collect($source->all(ClassLike::class))
                    ->flatMap(fn (ClassLike $owner): array => collect($owner->stmts)
                        ->filter(fn (Node $statement): bool => $statement instanceof TraitUse)
                        ->flatMap(fn (TraitUse $use): array => $use->traits)
                        ->map(fn (Name $used): array => [
                            'used' => ltrim(($used->getAttribute('resolvedName') ?? $used)->toString(), '\\'),
                            'path' => 'app/'.$file->getRelativePathname(),
                            'class' => ltrim((string) $owner->namespacedName?->toString(), '\\'),
                            'trait' => $owner instanceof Trait_,
                        ])
                        ->all())
                    ->all();
            })
            ->groupBy('used')
            ->map(fn ($users): array => array_values($users->map(fn (array $user): array => ['path' => $user['path'], 'class' => $user['class'], 'trait' => $user['trait']])->all()))
            ->all();
    }

    /**
     * Every declaration of the property anywhere in a class's composition: the
     * class, the traits it uses, theirs, and its parents, other than the trait
     * being fixed. A promoted or grouped declaration counts as one that differs.
     *
     * @return list<string>
     */
    private function composed(Workbench $bench, SourceFile $file, ClassLike $class, string $property, string $skip, int $depth): array
    {
        $own = $class->getProperty($property);
        $promoted = $class->getMethod('__construct')?->getParams() ?? [];
        $pending = $this->added[ltrim((string) $class->namespacedName?->toString(), '\\')][$property] ?? null;
        $here = match (true) {
            $own instanceof Property => [count($own->props) === 1 ? $file->canonical($own) : '#grouped'],
            collect($promoted)->contains(fn ($param): bool => $param->flags !== 0 && $param->var instanceof Node\Expr\Variable && $param->var->name === $property) => ['#promoted'],
            $pending !== null => [$pending],
            default => [],
        };

        if ($depth >= self::DEEPEST) {
            return [...$here, '#too-deep'];
        }

        $parents = $class instanceof Class_ && $class->extends !== null ? [$class->extends] : [];
        $traits = collect($class->stmts)->filter(fn (Node $statement): bool => $statement instanceof TraitUse)->flatMap(fn (TraitUse $use): array => $use->traits)->all();

        return [...$here, ...collect([...$traits, ...$parents])
            ->map(fn (Name $used): string => ltrim(($used->getAttribute('resolvedName') ?? $used)->toString(), '\\'))
            ->reject(fn (string $used): bool => $used === $skip)
            ->flatMap(function (string $used) use ($bench, $property, $skip, $depth): array {
                $path = $bench->classFile($used);
                $owner = $path === null ? null : $bench->open($path);
                $node = $owner?->classNamed($used);

                return $owner === null || $node === null ? [] : $this->composed($bench, $owner, $node, $property, $skip, $depth + 1);
            })
            ->all()];
    }
}
