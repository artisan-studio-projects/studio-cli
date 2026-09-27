<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Concerns;

use Closure;

trait HasColour
{
    protected string|Closure|null $colour = null;

    public function colour(string|Closure|null $colour): static
    {
        $this->colour = $colour;

        return $this;
    }

    public function getColour(mixed ...$arguments): string
    {
        return (string) ($this->evaluate($this->colour, ...$arguments) ?? $this->defaultColour());
    }

    protected function defaultColour(): string
    {
        return 'ink';
    }
}
