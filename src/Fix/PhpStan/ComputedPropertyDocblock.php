<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\UnionType;

/**
 * `$this->total` on a Livewire `#[Computed]` method is a property PHPStan cannot
 * see. Describing it on the class, with the method's own return type, is the
 * whole fix, wherever the method lives: the class, a trait it uses, or a parent.
 * The older `getTotalProperty()` getter is read the same way.
 */
final class ComputedPropertyDocblock implements Fixer
{
    private const array UNDESCRIBABLE = ['void', 'never'];

    private const int DEEPEST = 6;

    public function rule(): string
    {
        return 'property.notFound';
    }

    public function label(): string
    {
        return 'computed properties described';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        if (preg_match('/undefined property ([\w\\\\]+)::\$(\w+)/', $message, $match) !== 1) {
            return false;
        }

        [, $class, $property] = $match;
        $file = $this->fileOf($bench, $class);
        $node = $file?->classNamed($class);
        $found = $file === null || $node === null ? null : $this->computed($bench, $file, $node, $property, 0);

        if ($file === null || $node === null || $found === null) {
            return false;
        }

        [$owner, $method] = $found;
        $type = $owner === $file ? $file->text($method->returnType) : $this->qualified($method->returnType);

        return $type !== null
            && ! in_array(strtolower($type), self::UNDESCRIBABLE, true)
            && $file->addDocLine($node, '@property-read '.$type.' $'.$property);
    }

    /**
     * @return array{SourceFile, ClassMethod}|null
     */
    private function computed(Workbench $bench, SourceFile $file, ClassLike $node, string $name, int $depth): ?array
    {
        $method = $node->getMethod($name);
        $getter = $node->getMethod('get'.ucfirst($name).'Property');

        if ($method instanceof ClassMethod && $method->returnType !== null && $this->isComputed($method)) {
            return [$file, $method];
        }

        if ($getter instanceof ClassMethod && $getter->returnType !== null) {
            return [$file, $getter];
        }

        if ($method instanceof ClassMethod) {
            return null;
        }

        if ($depth >= self::DEEPEST) {
            return null;
        }

        return collect([...$this->traitsOf($node), ...($node instanceof Class_ && $node->extends !== null ? [$node->extends] : [])])
            ->map(fn (Name $used): string => $this->resolved($used))
            ->map(function (string $class) use ($bench, $name, $depth): ?array {
                $owner = $this->fileOf($bench, $class);
                $owned = $owner?->classNamed($class);

                return $owner === null || $owned === null ? null : $this->computed($bench, $owner, $owned, $name, $depth + 1);
            })
            ->first(fn (?array $found): bool => $found !== null);
    }

    /**
     * @return list<Name>
     */
    private function traitsOf(ClassLike $node): array
    {
        return array_values(collect($node->stmts)
            ->filter(fn (Node $statement): bool => $statement instanceof TraitUse)
            ->flatMap(fn (TraitUse $use): array => $use->traits)
            ->all());
    }

    private function fileOf(Workbench $bench, string $class): ?SourceFile
    {
        $path = $bench->classFile($class);

        return $path === null ? null : $bench->open($path);
    }

    private function isComputed(ClassMethod $method): bool
    {
        return collect($method->attrGroups)
            ->flatMap(fn ($group): array => $group->attrs)
            ->contains(fn ($attribute): bool => $attribute->name->getLast() === 'Computed');
    }

    private function resolved(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim(($resolved instanceof Name ? $resolved : $name)->toString(), '\\');
    }

    /**
     * The type written so it reads the same in any file: classes in full.
     */
    private function qualified(Identifier|Name|ComplexType|null $type): ?string
    {
        return match (true) {
            $type === null => null,
            $type instanceof Identifier => $type->toString(),
            $type instanceof Name => in_array(strtolower($type->toString()), ['self', 'static', 'parent'], true) ? $type->toString() : '\\'.$this->resolved($type),
            $type instanceof NullableType => ($inner = $this->qualified($type->type)) === null ? null : '?'.$inner,
            $type instanceof UnionType => $this->joined($type->types, '|'),
            $type instanceof IntersectionType => $this->joined($type->types, '&'),
            default => null,
        };
    }

    /**
     * @param  array<Identifier|Name|IntersectionType>  $types
     */
    private function joined(array $types, string $glue): ?string
    {
        $parts = array_map(fn (Identifier|Name|IntersectionType $type): ?string => $type instanceof IntersectionType ? ($this->joined($type->types, '&') === null ? null : '('.$this->joined($type->types, '&').')') : $this->qualified($type), $types);

        return in_array(null, $parts, true) ? null : implode($glue, $parts);
    }
}
