<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\FreshSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SampleSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\LocalTime;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Concerns\InteractsWithScreen;
use ArtisanStudio\StudioCli\Terminal\Contracts\HasScreen;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\StudioTabs;
use Illuminate\Console\Command;

class StudioCommand extends Command implements HasScreen
{
    use InteractsWithScreen;

    public const string SIGNATURE = 'studio';

    public const int PING_EVERY_SECONDS = 15;

    private ?string $repository = null;

    private int $pingedAt = 0;

    protected $signature = self::SIGNATURE.'
        {tab? : Open on this tab: dashboard, insights, workflows, activity or settings}
        {--fresh : Show what a developer sees on a brand new project that is not linked yet}
        {--demo : Show made-up data, to see what a busy project looks like}';

    protected $description = 'Your Artisan Studio dashboard, in the terminal';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            config(['studio-cli.token' => null]);
            $this->laravel->instance(SnapshotSource::class, new FreshSnapshots);
        }

        if ($this->option('demo')) {
            $this->laravel->instance(SnapshotSource::class, new SampleSnapshots);
        }

        if (! $this->option('fresh') && ! $this->option('demo')) {
            $this->repository = $this->laravel->make(LocalChanges::class)->originRepository();
            $this->screenTicked();
        }

        $tab = $this->argument('tab');

        return $this->showScreen(is_string($tab) ? $tab : null);
    }

    protected function screenRefreshed(): void
    {
        $this->laravel->make(SnapshotSource::class)->forget();
    }

    protected function screenTicked(): void
    {
        if ($this->repository === null || time() - $this->pingedAt < self::PING_EVERY_SECONDS) {
            return;
        }

        $studio = $this->laravel->make(Studio::class);

        if ($this->pingedAt > 0 && $studio->isLinked()) {
            return;
        }

        $this->pingedAt = time();
        $studio->ping($this->repository);
    }

    public function screen(ScreenContainer $screen): ScreenContainer
    {
        $studio = app(StudioTabs::class);

        return $screen
            ->state(fn (SnapshotSource $source): DashboardSnapshot => $source->snapshot())
            ->heading(fn (DashboardSnapshot $data): string => $data->project)
            ->description(fn (DashboardSnapshot $data): ?string => $data->repository)
            ->aside(fn (DashboardSnapshot $data): string => 'updated '.LocalTime::of($data->takenAt->toIso8601String()))
            ->openUrl(fn (): string => (string) config('studio-cli.url'))
            ->tabs($studio->tabs())
            ->rail($studio->rail())
            ->settings($studio->settings());
    }
}
