<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal;

use ArtisanStudio\StudioCli\Terminal\Components\Component;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasComponents;
use ArtisanStudio\StudioCli\Terminal\Components\Table;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Str;

final class Tab
{
    use Concerns\HasOwnState {
        refreshState as refreshOwnState;
    }
    use Concerns\HasRunner;
    use HasComponents;

    private ?string $key = null;

    /**
     * @var list<array{state: Closure, components: list<Component>, enters?: Closure}>
     */
    private array $levels = [];

    /**
     * @var list<array{record: array<string, mixed>, state: mixed}>
     */
    private array $trail = [];

    /**
     * @var array<int, int>
     */
    private array $cursors = [];

    private ?Closure $closesUsing = null;

    private function __construct(private string $label) {}

    public static function make(string $label = ''): self
    {
        return new self($label);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function key(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    /**
     * @param  list<Component>  $components
     */
    public function opens(Closure $state, array $components): self
    {
        $this->levels[] = ['state' => $state, 'components' => $components];

        return $this;
    }

    public function enters(Closure $panel): self
    {
        $last = array_key_last($this->levels);

        if ($last !== null) {
            $this->levels[$last]['enters'] = $panel;
        }

        return $this;
    }

    public function closes(Closure $callback): self
    {
        $this->closesUsing = $callback;

        return $this;
    }

    /**
     * @return list<Settings>
     */
    public function enter(): array
    {
        $enters = $this->levels[$this->depth() - 1]['enters'] ?? null;

        return $enters === null ? [] : array_values(array_filter((array) $enters($this->visibleState(null)), fn (mixed $group): bool => $group instanceof Settings));
    }

    public function canEnter(): bool
    {
        return $this->enter() !== [];
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getKey(): string
    {
        return $this->key ?? Str::slug($this->label);
    }

    public function depth(): int
    {
        return count($this->trail);
    }

    public function isNavigable(): bool
    {
        return isset($this->levels[$this->depth()]) && $this->list() !== null;
    }

    public function isOpen(): bool
    {
        return $this->trail !== [];
    }

    public function move(int $step): self
    {
        $this->cursors[$this->depth()] = max(0, min(count($this->records()) - 1, $this->cursor() + $step));

        return $this;
    }

    public function select(int $record): self
    {
        $this->cursors[$this->depth()] = max(0, min(count($this->records()) - 1, $record));

        return $this;
    }

    public function openWhere(Closure $matches): self
    {
        if ($this->depth() === 0) {
            $this->refreshOwnState();
        }

        $record = collect($this->records())->search(fn (array $each): bool => (bool) $matches($each));

        return $record === false ? $this : $this->select($record)->open();
    }

    public function open(): self
    {
        $record = $this->records()[$this->cursor()] ?? null;
        $level = $this->levels[$this->depth()] ?? null;

        if ($record !== null && $level !== null) {
            $this->trail[] = ['record' => $record, 'state' => $this->stateOf($level, $record, $this->visibleState($this->getState()))];
        }

        return $this;
    }

    public function close(): self
    {
        array_pop($this->trail);
        unset($this->cursors[$this->depth() + 1]);

        if ($this->trail === [] && $this->closesUsing !== null) {
            Container::getInstance()->call($this->closesUsing);
        }

        return $this;
    }

    public function closeAll(): self
    {
        collect($this->trail)->each(fn (): self => $this->close());

        return $this;
    }

    public function refreshState(): static
    {
        $this->refreshOwnState();
        $parent = $this->getState();

        $this->trail = array_values(collect($this->trail)->map(function (array $step, int $depth) use (&$parent): array {
            $parent = $this->stateOf($this->levels[$depth], $step['record'], $parent);

            return ['record' => $step['record'], 'state' => $parent];
        })->all());

        return $this;
    }

    /**
     * @return list<Component>
     */
    public function visibleComponents(): array
    {
        $components = $this->currentComponents();
        $this->listIn($components)?->cursor($this->isNavigable() ? min($this->cursor(), max(0, count($this->records()) - 1)) : null);

        return $components;
    }

    public function visibleState(mixed $state): mixed
    {
        return $this->trail === [] ? $state : $this->trail[array_key_last($this->trail)]['state'];
    }

    /**
     * @return list<string>
     */
    public function listLines(Canvas $canvas, int $width): array
    {
        return $this->isNavigable() ? (array) $this->list()?->render($canvas, $width, $this->visibleState($this->getState())) : [];
    }

    /**
     * @return list<string>
     */
    public function render(Canvas $canvas, int $width, mixed $state): array
    {
        return array_values(collect($this->visibleComponents())
            ->flatMap(fn (Component $component): array => $component->render($canvas, $width, $this->visibleState($state)))
            ->all());
    }

    /**
     * @param  array{state: Closure, components: list<Component>}  $level
     * @param  array<string, mixed>  $record
     */
    private function stateOf(array $level, array $record, mixed $parent): mixed
    {
        return Container::getInstance()->call($level['state'], ['record' => $record, 'parent' => $parent]);
    }

    private function cursor(): int
    {
        return $this->cursors[$this->depth()] ?? 0;
    }

    private function list(): ?Table
    {
        return $this->listIn($this->currentComponents());
    }

    /**
     * @return list<Component>
     */
    private function currentComponents(): array
    {
        return $this->depth() === 0 ? $this->getComponents() : ($this->levels[$this->depth() - 1]['components'] ?? []);
    }

    /**
     * @param  list<Component>  $components
     */
    private function listIn(array $components): ?Table
    {
        $list = collect($components)->reduce(
            fn (?Component $found, Component $component): ?Component => $found ?? match (true) {
                $component instanceof Table && $component->isSelectable() => $component,
                method_exists($component, 'findComponent') => $component->findComponent(fn (Component $inner): bool => $inner instanceof Table && $inner->isSelectable()),
                default => null,
            },
        );

        return $list instanceof Table ? $list : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(): array
    {
        return $this->list()?->getRecords($this->visibleState($this->hasOwnState() ? $this->getState() : null)) ?? [];
    }
}
