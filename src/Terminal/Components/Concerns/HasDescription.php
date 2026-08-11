<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Concerns;

use Closure;

trait HasDescription
{
    protected string|Closure|null $description = null;

    protected string|Closure $descriptionColour = 'soft';

    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function descriptionColour(string|Closure $colour): static
    {
        $this->descriptionColour = $colour;

        return $this;
    }

    public function getDescription(mixed ...$arguments): ?string
    {
        $description = $this->evaluate($this->description, ...$arguments);

        return $description === null ? null : (string) $description;
    }

    public function getDescriptionColour(mixed ...$arguments): string
    {
        return (string) $this->evaluate($this->descriptionColour, ...$arguments);
    }
}
