<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Concerns;

use Closure;

trait HasLabel
{
    protected string|Closure|null $label = null;

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(mixed ...$arguments): string
    {
        return (string) $this->evaluate($this->label, ...$arguments);
    }
}
