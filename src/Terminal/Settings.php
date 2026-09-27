<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal;

use ArtisanStudio\StudioCli\Terminal\Components\Component;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasComponents;

final class Settings
{
    use Concerns\HasOwnState;
    use HasComponents;

    /**
     * @var list<Action>
     */
    private array $actions = [];

    /**
     * @var list<Component>
     */
    private array $below = [];

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

    /**
     * @param  list<Action>  $actions
     */
    public function actions(array $actions): self
    {
        $this->actions = $actions;

        return $this;
    }

    /**
     * @param  list<Component>  $components
     */
    public function belowActions(array $components): self
    {
        $this->below = $components;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return list<Action>
     */
    public function visibleActions(): array
    {
        return array_values(array_filter($this->actions, fn (Action $action): bool => $action->isVisible($this->getState())));
    }

    /**
     * @return list<string>
     */
    public function render(Canvas $canvas, int $width, mixed $state): array
    {
        return $this->blocks($this->components, $canvas, $width, $state);
    }

    /**
     * @return list<string>
     */
    public function renderBelowActions(Canvas $canvas, int $width, mixed $state): array
    {
        return $this->blocks($this->below, $canvas, $width, $state);
    }

    /**
     * @param  list<Component>  $components
     * @return list<string>
     */
    private function blocks(array $components, Canvas $canvas, int $width, mixed $state): array
    {
        return array_values(collect($components)
            ->map(fn (Component $component): array => $component->render($canvas, $width, $state))
            ->reject(fn (array $block): bool => $block === [])
            ->reduce(fn (array $lines, array $block): array => $lines === [] ? $block : [...$lines, '', ...$block], []));
    }
}
