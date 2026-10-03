<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Concerns\OpensInStudio;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\NotConnected;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\BarColumn;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Section;
use ArtisanStudio\StudioCli\Terminal\Components\Table;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Tab;
use Illuminate\Console\Command;

class InsightsCommand extends Command implements ProvidesTab
{
    use OpensInStudio;

    protected $signature = 'studio:insights';

    protected $description = 'Open Artisan Studio on the Insights tab';

    public function tab(Tab $tab): Tab
    {
        return $tab->label('Insights')
            ->state(fn (SnapshotSource $source): DashboardSnapshot => $source->snapshot())
            ->unavailable(fn (NotConnected $notConnected): array => $notConnected->panel())
            ->components([
                Text::make(fn (DashboardSnapshot $data): string => "Health {$data->health}%")
                    ->colour(fn (DashboardSnapshot $data): string => $data->healthColour())
                    ->bold()
                    ->description(fn (DashboardSnapshot $data): string => " {$data->healthLabel} · ".trans_choice(':count open issue|:count open issues', $data->totalOpen()).' across '.trans_choice(':count ruleset|:count rulesets', count($data->rulesets)))
                    ->link('View insights', fn (DashboardSnapshot $data): ?string => $data->insightsUrl),
                Section::make('Open issues by ruleset')
                    ->aside(fn (DashboardSnapshot $data): string => "{$data->totalOpen()} total")
                    ->components([
                        Table::make(fn (DashboardSnapshot $data): array => $data->rulesets)
                            ->emptyState('Run a scan to see issues here.')
                            ->columns([
                                Column::make('name')->label('Ruleset')->width(28),
                                Column::make('open')->width(7)->bold()->colour(fn (array $ruleset): string => $ruleset['open'] > 0 ? 'amber' : 'green'),
                                BarColumn::make('bar')
                                    ->label('')
                                    ->colour('amber')
                                    ->fraction(fn (array $ruleset, DashboardSnapshot $data): ?float => $ruleset['open'] > 0 ? $ruleset['open'] / $data->mostOpen() : null)
                                    ->placeholder('clean', 'green'),
                            ]),
                    ]),
            ]);
    }
}
