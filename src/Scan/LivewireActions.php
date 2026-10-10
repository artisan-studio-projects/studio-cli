<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Scan\Livewire\Components;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * What a Livewire component's public methods do with what the browser sends.
 * Every public method is callable from the page, whatever the page shows, and
 * its arguments and the public properties are whatever the visitor chose.
 *
 * Each finding names a pattern, never a value: an action that looks a record
 * up from a given id with no authorization, one that saves browser-controlled
 * values without validating them, mass assignment from every public property,
 * a property put straight into raw SQL, a sensitive action nothing slows
 * down, and file uploads nothing checks.
 */
final class LivewireActions
{
    public const string UNAUTHORIZED = 'livewire-unauthorized-action';

    public const string UNVALIDATED = 'livewire-unvalidated-input';

    public const string MASS_ASSIGNMENT = 'livewire-mass-assignment';

    public const string RAW_SQL = 'livewire-raw-sql';

    public const string NO_RATE_LIMIT = 'livewire-no-rate-limit';

    public const string UPLOAD_UNVALIDATED = 'livewire-upload-unvalidated';

    public const string UPLOAD_ORIGINAL_NAME = 'livewire-upload-original-name';

    private const string UPLOADS = 'Livewire\WithFileUploads';

    private const array LOOKUPS = ['find', 'findOrFail', 'findOrNew', 'findMany', 'findSole', 'where', 'whereKey', 'whereIn', 'firstWhere'];

    private const array PERSISTS = ['create', 'update', 'fill', 'forceFill', 'forceCreate', 'updateOrCreate', 'firstOrCreate', 'insert', 'upsert'];

    private const array RAW = ['whereRaw', 'orWhereRaw', 'orderByRaw', 'selectRaw', 'havingRaw', 'groupByRaw', 'fromRaw', 'joinRaw', 'unprepared', 'statement'];

    private const array NOT_ACTIONS = [
        'mount', 'boot', 'booted', 'render', 'rendering', 'rendered', 'hydrate', 'dehydrate', 'exception', 'placeholder', 'rules', 'messages',
        'validationAttributes', 'queryString', 'listeners', 'getListeners', 'paginationView', 'paginationSimpleView', 'updatingPage', 'updatedPage',
    ];

    private const string AUTHORIZES = '/^(authori[sz]e\w*|can\w*|cannot|cant|allows|denies|inspect|ensure\w*|assert\w*|abort\w*|gate)$/i';

    private const string SENSITIVE = '/^(login|authenticate|attempt|verify\w*|submit(?:code|otp|pin)\w*|checkcode|otp\w*|resend\w*|sendcode|sendreset\w*|forgotpassword|resetpassword|register|confirmpassword|redeem\w*|applycoupon|applypromo\w*)$/i';

    private const string LIMITS = '/(throttle|ratelimit|rate_limit|tooManyAttempts|hitRateLimit|ensureIsNotRateLimited)/i';

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
        $found = [];

        foreach (array_keys($this->components->all()) as $component) {
            $validated = $this->hasRules($component);

            foreach ($this->components->methods($component) as $declared) {
                foreach ($this->inMethod($declared, $validated) as $finding) {
                    $found[$finding['where'].'|'.$finding['rule']] = $finding;
                }
            }

            foreach ($this->uploads($component, $validated || $this->validatesAnywhere($component)) as $finding) {
                $found[$finding['where'].'|'.$finding['rule']] = $finding;
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * @param  array{method: ClassMethod, class: string, path: string}  $declared
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function inMethod(array $declared, bool $validated): array
    {
        $method = $declared['method'];
        $stmts = $method->stmts ?? [];
        $calls = $this->calls($stmts);
        $name = $declared['class'].'::'.$method->name->toString().'()';
        $where = fn (Node $node): string => $declared['path'].':'.$node->getStartLine();
        $isAction = $method->isPublic() && ! $method->isStatic() && ! $this->isLifecycle($method);
        $found = [];

        foreach ($calls as $call) {
            $called = $this->nameOf($call);

            if (in_array($called, self::PERSISTS, true) && $this->hasArgument($call, fn (Node $node): bool => $this->isAllProperties($node))) {
                $found[] = ['where' => $where($call), 'rule' => self::MASS_ASSIGNMENT, 'message' => $name.' saves every public property at once, so a visitor can set columns the page never shows. Pass only the fields you mean to save.'];
            }

            if ((in_array($called, self::RAW, true) || ($called === 'raw' && $call instanceof StaticCall)) && $this->hasFirstArgument($call, fn (Node $node): bool => $this->isThisProperty($node))) {
                $found[] = ['where' => $where($call), 'rule' => self::RAW_SQL, 'message' => $name.' puts a public property straight into raw SQL, which a visitor can change. Pass it as a binding instead.'];
            }
        }

        if ($isAction && ! $this->authorizes($method, $calls) && $this->looksUpFromArgument($method, $stmts)) {
            $found[] = ['where' => $where($method), 'rule' => self::UNAUTHORIZED, 'message' => $name.' finds a record from an id the browser sends and never checks it is theirs, so anyone can act on any record. Authorize it first, such as $this->authorize(\'update\', $record).'];
        }

        if ($isAction && ! $validated && ! $this->validates($calls) && $this->persistsProperties($calls)) {
            $found[] = ['where' => $where($method), 'rule' => self::UNVALIDATED, 'message' => $name.' saves values the browser controls without validating them. Validate before saving.'];
        }

        if ($isAction && preg_match(self::SENSITIVE, $method->name->toString()) === 1 && ! $this->limits($calls)) {
            $found[] = ['where' => $where($method), 'rule' => self::NO_RATE_LIMIT, 'message' => $name.' can be called as often as a visitor likes, so codes and passwords can be guessed. Slow it down with RateLimiter.'];
        }

        return $found;
    }

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function uploads(string $component, bool $validated): array
    {
        if (! $this->components->uses($component, self::UPLOADS)) {
            return [];
        }

        $class = $this->components->classes()[$component];
        $found = $validated ? [] : [['where' => $class['path'].':'.$class['node']->getStartLine(), 'rule' => self::UPLOAD_UNVALIDATED, 'message' => $component.' accepts file uploads and never validates them, so any file type or size is stored. Validate the upload, such as image|max:1024.']];

        foreach ($this->components->methods($component) as $declared) {
            foreach ($this->calls($declared['method']->stmts ?? []) as $call) {
                if (in_array($this->nameOf($call), ['storeAs', 'storePubliclyAs', 'putFileAs'], true) && $this->hasArgument($call, fn (Node $node): bool => $node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toString() === 'getClientOriginalName')) {
                    $found[] = ['where' => $declared['path'].':'.$call->getStartLine(), 'rule' => self::UPLOAD_ORIGINAL_NAME, 'message' => 'An upload is stored under the name the visitor gave it, which can overwrite files or smuggle in an extension. Use store(), which picks a safe name.'];
                }
            }
        }

        return $found;
    }

    /**
     * Whether the component declares rules for all its actions: a rule on a
     * property, a rules() method, or a form object.
     */
    private function hasRules(string $component): bool
    {
        $properties = $this->components->properties($component);
        $attributed = collect($properties)->contains(fn (array $declared): bool => $this->components->hasAttribute($declared['property']->attrGroups, ['Livewire\Attributes\Validate', 'Livewire\Attributes\Rule']));
        $form = collect($properties)->contains(fn (array $declared): bool => $declared['property']->type instanceof Name && str_ends_with($declared['property']->type->getLast(), 'Form'));

        return $attributed || $form || collect($this->components->methods($component))->contains(fn (array $declared): bool => $declared['method']->name->toString() === 'rules');
    }

    private function validatesAnywhere(string $component): bool
    {
        return collect($this->components->methods($component))->contains(fn (array $declared): bool => $this->validates($this->calls($declared['method']->stmts ?? [])));
    }

    private function isLifecycle(ClassMethod $method): bool
    {
        $name = $method->name->toString();

        return str_starts_with($name, '__')
            || in_array($name, self::NOT_ACTIONS, true)
            || preg_match('/^(updated|updating|hydrate|dehydrate)[A-Z_]/', $name) === 1
            || preg_match('/^get\w+(Property|Attribute)$/', $name) === 1
            || $this->components->hasAttribute($method->attrGroups, ['Livewire\Attributes\Computed', 'Livewire\Attributes\Locked', 'Livewire\Attributes\Authorize']);
    }

    /**
     * @param  list<Node>  $stmts
     */
    private function looksUpFromArgument(ClassMethod $method, array $stmts): bool
    {
        $parameters = array_map(fn ($param): string => (string) ($param->var instanceof Variable ? $param->var->name : ''), $method->params);
        $finder = new NodeFinder;

        return $parameters !== [] && $finder->findFirst($stmts, fn (Node $node): bool => $node instanceof StaticCall
            && $node->class instanceof Name
            && ! in_array(strtolower($node->class->toString()), ['parent', 'self', 'static'], true)
            && $node->name instanceof Identifier
            && in_array($node->name->toString(), self::LOOKUPS, true)
            && $finder->findFirst($node->args, fn (Node $argument): bool => $argument instanceof Variable && in_array($argument->name, $parameters, true)) !== null) !== null;
    }

    /**
     * @param  list<MethodCall|NullsafeMethodCall|StaticCall|FuncCall>  $calls
     */
    private function authorizes(ClassMethod $method, array $calls): bool
    {
        return $this->components->hasAttribute($method->attrGroups, ['Livewire\Attributes\Authorize'])
            || collect($calls)->contains(fn (Node $call): bool => preg_match(self::AUTHORIZES, $this->nameOf($call)) === 1
                || ($call instanceof StaticCall && $call->class instanceof Name && $call->class->getLast() === 'Gate'));
    }

    /**
     * @param  list<MethodCall|NullsafeMethodCall|StaticCall|FuncCall>  $calls
     */
    private function validates(array $calls): bool
    {
        return collect($calls)->contains(fn (Node $call): bool => str_starts_with($this->nameOf($call), 'validate') || $this->nameOf($call) === 'make');
    }

    /**
     * @param  list<MethodCall|NullsafeMethodCall|StaticCall|FuncCall>  $calls
     */
    private function limits(array $calls): bool
    {
        return collect($calls)->contains(fn (Node $call): bool => preg_match(self::LIMITS, $this->nameOf($call)) === 1
            || ($call instanceof StaticCall && $call->class instanceof Name && $call->class->getLast() === 'RateLimiter'));
    }

    /**
     * @param  list<MethodCall|NullsafeMethodCall|StaticCall|FuncCall>  $calls
     */
    private function persistsProperties(array $calls): bool
    {
        return collect($calls)->contains(fn (Node $call): bool => in_array($this->nameOf($call), self::PERSISTS, true)
            && $this->hasArgument($call, fn (Node $node): bool => $this->isThisProperty($node)));
    }

    /**
     * @param  list<Node>  $stmts
     * @return list<MethodCall|NullsafeMethodCall|StaticCall|FuncCall>
     */
    private function calls(array $stmts): array
    {
        return array_values((new NodeFinder)->find($stmts, fn (Node $node): bool => $node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall || $node instanceof FuncCall));
    }

    private function nameOf(Node $call): string
    {
        return match (true) {
            $call instanceof MethodCall, $call instanceof NullsafeMethodCall, $call instanceof StaticCall => $call->name instanceof Identifier ? $call->name->toString() : '',
            $call instanceof FuncCall => $call->name instanceof Name ? $call->name->toString() : '',
            default => '',
        };
    }

    /**
     * @param  callable(Node): bool  $test
     */
    private function hasArgument(Node $call, callable $test): bool
    {
        $arguments = $call instanceof MethodCall || $call instanceof NullsafeMethodCall || $call instanceof StaticCall || $call instanceof FuncCall ? $call->args : [];

        return $arguments !== [] && (new NodeFinder)->findFirst($arguments, $test) !== null;
    }

    /**
     * @param  callable(Node): bool  $test
     */
    private function hasFirstArgument(Node $call, callable $test): bool
    {
        $first = ($call instanceof MethodCall || $call instanceof NullsafeMethodCall || $call instanceof StaticCall || $call instanceof FuncCall) ? ($call->args[0] ?? null) : null;

        return $first !== null && (new NodeFinder)->findFirst($first, $test) !== null;
    }

    private function isThisProperty(Node $node): bool
    {
        return $node instanceof PropertyFetch && $node->var instanceof Variable && $node->var->name === 'this';
    }

    private function isAllProperties(Node $node): bool
    {
        return ($node instanceof MethodCall && $node->var instanceof Variable && $node->var->name === 'this' && $node->name instanceof Identifier && $node->name->toString() === 'all')
            || ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'get_object_vars');
    }
}
