<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Concerns\OpensInStudio;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\NotConnected;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Terminal\Components\Card;
use ArtisanStudio\StudioCli\Terminal\Components\Grid;
use ArtisanStudio\StudioCli\Terminal\Components\Progress;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Tab;
use Illuminate\Console\Command;

class DashboardCommand extends Command implements ProvidesTab
{
    use OpensInStudio;

    protected $signature = 'studio:dashboard';

    protected $description = 'Open Artisan Studio on the Dashboard tab';

    public function tab(Tab $tab): Tab
    {
        return $tab->label('Dashboard')
            ->state(fn (SnapshotSource $source): DashboardSnapshot => $source->snapshot())
            ->unavailable(fn (NotConnected $notConnected): array => $notConnected->panel())
            ->components([
                Grid::make([
                    Card::make('Health')
                        ->icon('💙', '♥')
                        ->colour(fn (DashboardSnapshot $data): string => $data->healthColour())
                        ->value(fn (DashboardSnapshot $data): string => "{$data->health}%")
                        ->description(fn (DashboardSnapshot $data): string => "{$data->healthLabel} · {$data->insightsOpen} open")
                        ->descriptionColour(fn (DashboardSnapshot $data): string => $data->healthColour()),
                    Card::make('Deliverables')
                        ->icon('📦', '◆')
                        ->colour('cyan')
                        ->value(fn (DashboardSnapshot $data): string => "{$data->deliverablesPercent}%")
                        ->description(fn (DashboardSnapshot $data): string => "{$data->deliverablesDone} of {$data->deliverablesTotal} done"),
                    Card::make('Workflows')
                        ->icon('⚡', '▶')
                        ->colour('blue')
                        ->value(fn (DashboardSnapshot $data): string => (string) $data->workflowsRunning)
                        ->description(fn (DashboardSnapshot $data): string => "running · {$data->workflowsDone}/{$data->workflowsTotal} done")
                        ->descriptionColour('blue'),
                    Card::make('Credits')
                        ->icon('🪙', '●')
                        ->colour('amber')
                        ->value(fn (DashboardSnapshot $data): string => number_format($data->credits))
                        ->description('credits left'),
                ]),
                Progress::make(fn (DashboardSnapshot $data): string => $data->scanLabel())
                    ->value(fn (DashboardSnapshot $data): float => $data->scanFraction()),
            ]);
    }
}
