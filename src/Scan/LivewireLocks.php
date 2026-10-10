<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Scan\Livewire\Components;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;

/**
 * What Livewire sends the browser in its public properties. Livewire hands
 * every public property to the page and takes changes to it back, so:
 *
 * - a plain value the page never edits (a user id, a role, a price) can be
 *   rewritten by anyone with dev tools unless it is #[Locked];
 * - a whole model the page never edits travels with the page and should be
 *   locked, or held in a computed property;
 * - a key, token or secret in a public property is readable in the page;
 * - a #[Locked] property is only as safe as what it is set from, so one set
 *   from the request is no safer than an open one.
 *
 * A property is left alone once it is #[Locked], read from the URL, a
 * modelable or reactive, bound by the page, or a form, enum or other object
 * Livewire guards itself. A component that binds by a computed name is left
 * alone, since which properties the page edits cannot be known.
 */
final class LivewireLocks
{
    public const string RULE = 'livewire-locked';

    public const string SECRET_RULE = 'livewire-secret-property';

    public const string REQUEST_RULE = 'livewire-locked-from-request';

    private const string LOCKED = 'Livewire\Attributes\Locked';

    private const string MODELS = 'App\Models\\';

    /**
     * Attributes that mean the property is meant to change from the browser,
     * or already cannot.
     *
     * @var list<string>
     */
    private const array LEAVE_ALONE = [
        self::LOCKED,
        'Livewire\Attributes\Url',
        'Livewire\Attributes\Modelable',
        'Livewire\Attributes\Reactive',
    ];

    private const array PLAIN_TYPES = ['int', 'float', 'string', 'bool', 'array'];

    private const string SECRET_NAME = '/(api_?key|secret|token|private_?key|credential|access_?key)/i';

    private readonly Components $components;

    public function __construct(string $root, ?Components $components = null)
    {
        $this->components = $components ?? new Components($root);
    }

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    public function findings(): array
    {
        $declared = [];

        foreach (array_keys($this->components->all()) as $component) {
            $page = $this->components->page($component);

            foreach ($this->publicProperties($component) as $property) {
                $key = $property['where'].'#'.$property['name'];
                $declared[$key] ??= [...$property, 'touched' => false];
                $declared[$key]['touched'] = $declared[$key]['touched'] || $page === null || $this->isTouched($page, $property['name']);
            }
        }

        return array_values([
            ...$this->secrets($declared),
            ...$this->unlocked($declared),
            ...$this->lockedFromRequest(),
        ]);
    }

    /**
     * @param  array<string, array{where: string, owner: string, name: string, kind: string, locked: bool, leave: bool, touched: bool}>  $declared
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function unlocked(array $declared): array
    {
        return collect($declared)
            ->reject(fn (array $property): bool => $property['touched'] || $property['leave'] || $property['kind'] === 'other')
            ->sortKeys()
            ->map(fn (array $property): array => [
                'where' => $property['where'],
                'rule' => self::RULE,
                'message' => $property['kind'] === 'model'
                    ? $property['owner'].'::$'.$property['name'].' holds a whole model in a public property the page never edits, so it travels with the page. Add #[Locked], or use a computed property.'
                    : $property['owner'].'::$'.$property['name'].' is public, so anyone can change it from the browser, but the page never edits it. Add #[Locked] so only your code can.',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{where: string, owner: string, name: string, kind: string, locked: bool, leave: bool, touched: bool}>  $declared
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function secrets(array $declared): array
    {
        return collect($declared)
            ->filter(fn (array $property): bool => $property['kind'] === 'plain' && preg_match(self::SECRET_NAME, $property['name']) === 1 && preg_match('/^(is|has|use|show|with|needs|can)[A-Z]/', $property['name']) !== 1)
            ->sortKeys()
            ->map(fn (array $property): array => [
                'where' => $property['where'],
                'rule' => self::SECRET_RULE,
                'message' => $property['owner'].'::$'.$property['name'].' looks like a key or token held in a public property, which is readable in the page. Keep it in a protected property or a computed one.',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{where: string, owner: string, name: string, kind: string, locked: bool, leave: bool}>
     */
    private function publicProperties(string $component): array
    {
        return collect($this->components->properties($component))
            ->filter(fn (array $declared): bool => $declared['property']->isPublic() && ! $declared['property']->isStatic() && ! $declared['property']->isReadonly())
            ->flatMap(fn (array $declared): array => array_map(fn ($prop): array => [
                'where' => $declared['path'].':'.$declared['property']->getStartLine(),
                'owner' => $declared['class'],
                'name' => $prop->name->toString(),
                'kind' => $this->kind($declared['property']),
                'locked' => $this->components->hasAttribute($declared['property']->attrGroups, [self::LOCKED]),
                'leave' => $this->components->hasAttribute($declared['property']->attrGroups, self::LEAVE_ALONE),
            ], $declared['property']->props))
            ->all();
    }

    /**
     * Whether a property is a plain value, a model, or something Livewire
     * guards on its own.
     */
    private function kind(Property $property): string
    {
        $type = $property->type instanceof NullableType ? $property->type->type : $property->type;

        return match (true) {
            $this->isPlain($property->type) => 'plain',
            $type instanceof Name && str_starts_with($this->components->resolved($type), self::MODELS) => 'model',
            default => 'other',
        };
    }

    private function isPlain(?Node $type): bool
    {
        return match (true) {
            $type === null => true,
            $type instanceof Identifier => in_array(strtolower($type->toString()), self::PLAIN_TYPES, true),
            $type instanceof NullableType => $this->isPlain($type->type),
            $type instanceof UnionType => collect($type->types)->every(fn (Node $part): bool => $this->isPlain($part) || ($part instanceof Identifier && strtolower($part->toString()) === 'null')),
            default => false,
        };
    }

    /**
     * Whether the page binds, sets, entangles or otherwise hands this
     * property to the browser to change.
     */
    private function isTouched(string $page, string $property): bool
    {
        $word = preg_quote($property, '/');

        return preg_match('/wire:[\w.\-:]+\s*=\s*(?:"[^"]*|\'[^\']*)(?<![\w$])'.$word.'(?![\w(])/', $page) === 1
            || preg_match('/\$wire\.'.$word.'(?![\w(])/', $page) === 1
            || preg_match('/\$wire\.\$(?:set|toggle|get|entangle)\(\s*[\'"]'.$word.'\b/', $page) === 1
            || preg_match('/(?:\$set|\$toggle|entangle)\(\s*[\'"]'.$word.'\b/', $page) === 1;
    }

    /**
     * Locked properties assigned from the request.
     *
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function lockedFromRequest(): array
    {
        $found = [];

        foreach (array_keys($this->components->all()) as $component) {
            $locked = collect($this->publicProperties($component))->filter(fn (array $property): bool => $property['locked'])->pluck('name')->all();

            foreach ($this->components->methods($component) as $declared) {
                foreach ($this->assignedFromRequest($declared['method']->stmts ?? [], $locked) as $assignment) {
                    $found[$declared['path'].':'.$assignment->getStartLine()] = [
                        'where' => $declared['path'].':'.$assignment->getStartLine(),
                        'rule' => self::REQUEST_RULE,
                        'message' => '$this->'.$assignment->var->name->toString().' is #[Locked] but set here from the request, so a visitor chooses its value anyway. Check the value belongs to them first.',
                    ];
                }
            }
        }

        return array_values($found);
    }

    /**
     * @param  array<Node>  $statements
     * @param  list<string>  $locked
     * @return list<Assign>
     */
    private function assignedFromRequest(array $statements, array $locked): array
    {
        $finder = new NodeFinder;

        return $locked === [] ? [] : array_values(array_filter(
            $finder->findInstanceOf($statements, Assign::class),
            fn (Assign $assign): bool => $assign->var instanceof PropertyFetch
                && $assign->var->var instanceof Variable
                && $assign->var->var->name === 'this'
                && $assign->var->name instanceof Identifier
                && in_array($assign->var->name->toString(), $locked, true)
                && $finder->findFirst($assign->expr, fn (Node $node): bool => ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'request')
                    || ($node instanceof StaticCall && $node->class instanceof Name && in_array($node->class->getLast(), ['Request', 'Route'], true))
                    || ($node instanceof Variable && $node->name === 'request')) !== null,
        ));
    }
}
