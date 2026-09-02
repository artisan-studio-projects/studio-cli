<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use ArtisanStudio\StudioCli\Terminal\Rail;
use ArtisanStudio\StudioCli\Terminal\Tab;
use Closure;
use Illuminate\Container\Container;

trait HasState
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

    public function refreshState(): static
    {
        $this->state = $this->stateUsing === null ? null : Container::getInstance()->call($this->stateUsing);
        $this->stateLoaded = true;

        collect($this->panes())->filter(fn (Tab|Rail $pane): bool => $pane->hasOwnState())->each(fn (Tab|Rail $pane): Tab|Rail => $pane->refreshState());

        return $this->refreshPanel();
    }

    public function getState(): mixed
    {
        if (! $this->stateLoaded) {
            $this->refreshState();
        }

        return $this->state;
    }

    public function fingerprint(): string
    {
        $state = $this->getState();
        $own = is_object($state) && method_exists($state, 'fingerprint') ? (string) $state->fingerprint() : serialize($state);

        return hash('xxh128', $own.serialize(collect($this->panes())->map(fn (Tab|Rail $pane): mixed => $pane->hasOwnState() ? $pane->getState() : null)->all()));
    }

    protected function evaluate(mixed $value): mixed
    {
        return $value instanceof Closure ? $value($this->getState()) : $value;
    }
}
