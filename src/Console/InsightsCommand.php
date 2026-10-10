<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Concerns\OpensInStudio;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\NotConnected;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Fix\FixPanel;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Tab;
use Illuminate\Console\Command;

/**
 * The project's insights, and the fix path: what SAMI is fixing, rule by rule,
 * as it goes.
 */
class InsightsCommand extends Command implements ProvidesTab
{
    use OpensInStudio;

    protected $signature = 'studio:insights';

    protected $description = 'Open Artisan Studio on the Insights tab';

    public function tab(Tab $tab): Tab
    {
        $fixes = FixPanel::insights();

        return $tab->label('Insights')
            ->visibleWhen(fn (): bool => $fixes->isOnShow())
            ->state(fn (SnapshotSource $source): DashboardSnapshot => $source->snapshot())
            ->unavailable(fn (NotConnected $notConnected): array => $notConnected->panel())
            ->components([
                ...$fixes->components(),
                Text::make(fn (DashboardSnapshot $data): string => "Health {$data->health}%")
                    ->colour(fn (DashboardSnapshot $data): string => $data->healthColour())
                    ->bold()
                    ->description(fn (DashboardSnapshot $data): string => " {$data->healthLabel} · ".trans_choice(':count open issue|:count open issues', $data->totalOpen()).' across '.trans_choice(':count ruleset|:count rulesets', count($data->rulesets)))
                    ->link('View insights', fn (DashboardSnapshot $data): ?string => $data->insightsUrl),
            ])
            ->entersBy(
                fn (mixed $data): bool => $data instanceof DashboardSnapshot && $fixes->canStart($data),
                fn (DashboardSnapshot $data): string => $fixes->start($data),
                'Fix with SAMI',
            );
    }
}
