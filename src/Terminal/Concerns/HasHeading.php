<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use Closure;

trait HasHeading
{
    private string|Closure $heading = '';

    private string|Closure|null $description = null;

    private string|Closure|null $aside = null;

    public function heading(string|Closure $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function aside(string|Closure|null $aside): static
    {
        $this->aside = $aside;

        return $this;
    }

    public function getHeading(): string
    {
        return (string) $this->evaluate($this->heading);
    }

    public function getDescription(): ?string
    {
        $description = $this->evaluate($this->description);

        return $description === null || $description === '' ? null : (string) $description;
    }

    public function getAside(): string
    {
        return (string) $this->evaluate($this->aside);
    }
}
