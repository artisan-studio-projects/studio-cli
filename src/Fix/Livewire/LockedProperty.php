<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Livewire;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

/**
 * Locks a public Livewire property the scan found the page never edits, so
 * only the component's own code can change it.
 *
 * Locking a property the browser is meant to change blocks the user, so the
 * scan's own check is not enough. It is only locked when nothing could be
 * changing it from the browser:
 *
 * - no view, script or test anywhere binds, reads through $wire, sets or
 *   entangles it;
 * - the component has no updated or updating hook for it, or a general one;
 * - nothing validates it, by rule, attribute or validate call;
 * - it is one property in its declaration, not already locked, read from the
 *   URL, modelable, reactive or session-held.
 *
 * Anything else is left, and stays in the report.
 */
final class LockedProperty implements Fixer
{
    private const string LOCKED = 'Livewire\Attributes\Locked';

    /**
     * Attributes that say the browser changes the property, or already guard it.
     *
     * @var list<string>
     */
    private const array LEAVE_ALONE = [
        self::LOCKED,
        'Livewire\Attributes\Url',
        'Livewire\Attributes\Modelable',
        'Livewire\Attributes\Reactive',
        'Livewire\Attributes\Session',
        'Livewire\Attributes\Validate',
        'Livewire\Attributes\Rule',
    ];

    private readonly ProjectUsage $usage;

    public function __construct(string $root, ?ProjectUsage $usage = null)
    {
        $this->usage = $usage ?? new ProjectUsage($root);
    }

    public function rule(): string
    {
        return 'livewire-locked';
    }

    public function label(): string
    {
        return 'Livewire properties only your code changes locked';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->open($path);
        $properties = $file === null ? [] : $file->on($line, Property::class);
        $property = count($properties) === 1 ? $properties[0] : null;
        $class = $file === null ? null : collect($file->spanning($line, ClassLike::class))->last();

        if ($file === null || $property === null || ! $class instanceof Class_ || ! $this->isSafe($file, $class, $property)) {
            return false;
        }

        $start = $property->getStartFilePos();
        $lineStart = (int) strrpos(substr($file->code, 0, $start), "\n") + 1;
        $indent = substr($file->code, $lineStart, $start - $lineStart);

        return trim($indent) === '' && $file->insert($start, '#['.$file->nameFor(self::LOCKED)."]\n".$indent);
    }

    private function isSafe(SourceFile $file, Class_ $class, Property $property): bool
    {
        $name = $property->props[0]->name->toString();

        return count($property->props) === 1
            && $property->isPublic()
            && ! $property->isStatic()
            && ! $property->isReadonly()
            && ! $this->hasAttribute($file, $property, self::LEAVE_ALONE)
            && ! $this->hasUpdateHook($class, $name)
            && ! $this->isValidated($class, $name)
            && ! $this->usage->mentions($name);
    }

    /**
     * @param  list<string>  $names
     */
    private function hasAttribute(SourceFile $file, Property $property, array $names): bool
    {
        return collect($property->attrGroups)
            ->flatMap(fn ($group): array => $group->attrs)
            ->contains(fn (Attribute $attribute): bool => in_array($this->resolved($file, $attribute->name), $names, true));
    }

    private function resolved(SourceFile $file, Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim($resolved instanceof Name ? $resolved->toString() : $file->fullName($name->toString()), '\\');
    }

    /**
     * An updated or updating hook, for this property or in general, means the
     * browser changes properties here.
     */
    private function hasUpdateHook(Class_ $class, string $name): bool
    {
        $studly = ucfirst((string) preg_replace_callback('/_([a-z])/', fn (array $found): string => strtoupper($found[1]), $name));

        return collect($class->getMethods())->contains(fn (ClassMethod $method): bool => in_array($method->name->toString(), ['updated', 'updating', 'updated'.$studly, 'updating'.$studly], true));
    }

    /**
     * Whether a rules() method or a validate call names the property.
     */
    private function isValidated(Class_ $class, string $name): bool
    {
        $finder = new NodeFinder;
        $named = fn (Node $node): bool => $node instanceof String_ && ($node->value === $name || str_starts_with($node->value, $name.'.'));

        $inRulesProperty = collect($class->getProperties())
            ->filter(fn (Property $property): bool => $property->props[0]->name->toString() === 'rules')
            ->contains(fn (Property $property): bool => $finder->findFirst($property->props[0]->default === null ? [] : [$property->props[0]->default], $named) !== null);

        return $inRulesProperty || collect($class->getMethods())->contains(function (ClassMethod $method) use ($finder, $named): bool {
            $inRules = $method->name->toString() === 'rules' && $finder->findFirst($method->stmts ?? [], $named) !== null;
            $calls = $finder->find($method->stmts ?? [], fn (Node $node): bool => ($node instanceof MethodCall || $node instanceof StaticCall || $node instanceof FuncCall) && $this->isValidation($node));

            return $inRules || collect($calls)->contains(fn (Node $call): bool => $finder->findFirst($call->args, $named) !== null);
        });
    }

    private function isValidation(Node $call): bool
    {
        $name = $call instanceof FuncCall ? ($call->name instanceof Name ? $call->name->toString() : '') : ($call->name instanceof Identifier ? $call->name->toString() : '');

        return str_starts_with($name, 'validate') || $name === 'make';
    }
}
