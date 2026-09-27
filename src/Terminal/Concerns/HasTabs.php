<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use ArtisanStudio\StudioCli\Terminal\Contracts\RunsInBackground;
use ArtisanStudio\StudioCli\Terminal\Rail;
use ArtisanStudio\StudioCli\Terminal\Tab;

trait HasTabs
{
    /**
     * @var list<Tab>
     */
    private array $tabs = [];

    /**
     * @var array<int, int>
     */
    private array $recordBodyTargets = [];

    /**
     * @var array<int, int>
     */
    private array $recordTargets = [];

    /**
     * @var array{from: int, to: int}
     */
    private array $recordColumns = ['from' => 0, 'to' => 0];

    public function recordAt(int $column, int $row): ?int
    {
        return $column >= $this->recordColumns['from'] && $column <= $this->recordColumns['to'] ? $this->recordTargets[$row] ?? null : null;
    }

    /**
     * @param  list<Tab>  $tabs
     */
    public function tabs(array $tabs): static
    {
        $this->tabs = $tabs;

        return $this;
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $besideUs = $this->shownRail()?->getInsteadOf();

        return collect($this->tabs === [] ? [Tab::make('Main')->components($this->getComponents())] : $this->tabs)
            ->reject(fn (Tab $tab): bool => $tab->getKey() === $besideUs)
            ->keyBy(fn (Tab $tab): string => $tab->getKey())
            ->all();
    }

    /**
     * @return list<string>
     */
    public function tabKeys(): array
    {
        return array_keys($this->getTabs());
    }

    public function showsTabs(): bool
    {
        return count($this->getTabs()) > 1;
    }

    public function tab(?string $key): Tab
    {
        $tabs = $this->getTabs();

        return $tabs[(string) $key] ?? $tabs[array_key_first($tabs)];
    }

    /**
     * @return list<RunsInBackground>
     */
    public function runners(): array
    {
        return array_values(collect($this->panes())
            ->map(fn (Tab|Rail $pane): ?RunsInBackground => $pane->getRunner())
            ->filter()
            ->unique(fn (RunsInBackground $runner): int => spl_object_id($runner))
            ->all());
    }

    public function refreshRunningTabs(): static
    {
        collect($this->panes())
            ->filter(fn (Tab|Rail $pane): bool => $pane->getRunner() !== null && $pane->hasOwnState())
            ->each(fn (Tab|Rail $pane): Tab|Rail => $pane->refreshState());

        return $this;
    }
}
