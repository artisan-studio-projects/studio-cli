<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

final class Focus
{
    private ?string $workflow = null;

    private ?string $name = null;

    public function follow(?string $workflow, ?string $name = null): self
    {
        $this->workflow = $workflow === '' ? null : $workflow;
        $this->name = $this->workflow === null ? null : $name;

        return $this;
    }

    public function workflow(): ?string
    {
        return $this->workflow;
    }

    public function name(): ?string
    {
        return $this->name;
    }
}
