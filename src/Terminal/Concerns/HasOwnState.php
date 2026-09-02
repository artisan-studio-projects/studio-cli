<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use Closure;
use Illuminate\Container\Container;

trait HasOwnState
{
    private ?Closure $stateUsing = null;

    private mixed $state = null;

    private bool $stateLoaded = false;

    public function state(Closure $using): static
    {
        $this->stateUsing = $using;
        $this->stateLoaded = false;

        return $this;
    }

    public function hasOwnState(): bool
    {
        return $this->stateUsing !== null;
    }

    public function refreshState(): static
    {
        $this->state = $this->stateUsing === null ? null : Container::getInstance()->call($this->stateUsing);
        $this->stateLoaded = true;

        return $this;
    }

    public function getState(): mixed
    {
        if (! $this->stateLoaded) {
            $this->refreshState();
        }

        return $this->state;
    }
}
