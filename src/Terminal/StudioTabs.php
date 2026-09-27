<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal;

use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesRail;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesSettings;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Contracts\RunsInBackground;
use Illuminate\Contracts\Console\Kernel;

final readonly class StudioTabs
{
    public function __construct(private Kernel $artisan) {}

    /**
     * @return list<Tab>
     */
    public function tabs(): array
    {
        $order = array_values((array) config('studio-cli.tabs.order', []));
        $hidden = (array) config('studio-cli.tabs.hidden', []);

        return array_values(collect($this->artisan->all())
            ->filter(fn (mixed $command): bool => $command instanceof ProvidesTab)
            ->unique(fn (ProvidesTab $command): int => spl_object_id($command))
            ->reject(fn (ProvidesTab $command): bool => in_array($command->getName(), $hidden, true))
            ->sortBy(fn (ProvidesTab $command): int => ($position = array_search($command->getName(), $order, true)) === false ? PHP_INT_MAX : (int) $position)
            ->map(fn (ProvidesTab $command): Tab => $command->tab(Tab::make())->runsWith($command instanceof RunsInBackground ? $command : null))
            ->all());
    }

    /**
     * @return list<Settings>
     */
    public function settings(): array
    {
        return array_values(collect($this->artisan->all())
            ->filter(fn (mixed $command): bool => $command instanceof ProvidesSettings)
            ->unique(fn (ProvidesSettings $command): int => spl_object_id($command))
            ->map(fn (ProvidesSettings $command): Settings => $command->settings(Settings::make()))
            ->all());
    }

    public function rail(): ?Rail
    {
        $command = $this->artisan->all()[(string) config('studio-cli.rail.command')] ?? null;

        if (! $command instanceof ProvidesRail) {
            return null;
        }

        return $command->rail(Rail::make())
            ->width((int) config('studio-cli.rail.width', 40))
            ->from((int) config('studio-cli.rail.from', 130))
            ->runsWith($command instanceof RunsInBackground ? $command : null)
            ->insteadOf($command instanceof ProvidesTab ? $command->tab(Tab::make())->getKey() : null);
    }
}
