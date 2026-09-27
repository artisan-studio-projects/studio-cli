<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Concerns;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Component;
use Closure;

trait HasComponents
{
    /**
     * @var list<Component>
     */
    protected array $components = [];

    /**
     * @param  list<Component>  $components
     */
    public function components(array $components): static
    {
        $this->components = $components;

        return $this;
    }

    /**
     * @return list<Component>
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    public function findComponent(Closure $matches): ?Component
    {
        return collect($this->components)->reduce(
            fn (?Component $found, Component $component): ?Component => $found ?? match (true) {
                (bool) $matches($component) => $component,
                method_exists($component, 'findComponent') => $component->findComponent($matches),
                default => null,
            },
        );
    }

    /**
     * @return list<string>
     */
    protected function renderComponents(Canvas $canvas, int $width, mixed $state): array
    {
        return array_values(collect($this->components)
            ->flatMap(fn (Component $component): array => $component->render($canvas, $width, $state))
            ->all());
    }
}
