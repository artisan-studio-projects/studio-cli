<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasComponents;
use Closure;

final class Grid extends Component
{
    use HasComponents;

    private int $gap = 2;

    private int $columns = 0;

    private ?Closure $source = null;

    /**
     * @param  list<Component>  $components
     */
    public static function make(array $components): self
    {
        return (new self)->components($components);
    }

    /**
     * @param  Closure(mixed): list<Component>  $components
     */
    public static function of(Closure $components): self
    {
        $grid = new self;
        $grid->source = $components;

        return $grid;
    }

    public function gap(int $gap): self
    {
        $this->gap = $gap;

        return $this;
    }

    public function columns(int $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    public function render(Canvas $canvas, int $width, mixed $state): array
    {
        $components = $this->source === null ? $this->components : array_values((array) $this->evaluate($this->source, $state));
        $count = max(count($components), $this->columns);

        if ($components === []) {
            return [];
        }

        $each = intdiv($width - $this->gap * ($count - 1), $count);
        $widths = collect(range(0, $count - 1))->map(fn (int $index): int => $index === $count - 1 ? $width - $each * ($count - 1) - $this->gap * ($count - 1) : $each);
        $blocks = collect($components)->map(fn (Component $component, int $index): array => $component->render($canvas, $widths[$index], $state));
        $rows = (int) $blocks->max(fn (array $lines): int => count($lines));

        return array_values(collect(range(0, max(0, $rows - 1)))
            ->map(fn (int $row): string => $blocks
                ->map(fn (array $lines, int $index): string => $lines[$row] ?? $canvas->cell('', $widths[$index]))
                ->implode($canvas->span(str_repeat(' ', $this->gap), 'ink')))
            ->all());
    }
}
