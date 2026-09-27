<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Console\AvatarSyncCommand;
use ArtisanStudio\StudioCli\Console\BuildPresenceCommand;
use ArtisanStudio\StudioCli\Console\DashboardCommand;
use ArtisanStudio\StudioCli\Console\InsightsCommand;
use ArtisanStudio\StudioCli\Console\LinkCommand;
use ArtisanStudio\StudioCli\Console\ReviewCommand;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Console\WatchCommand;
use ArtisanStudio\StudioCli\Dashboard\LiveSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class StudioCliServiceProvider extends ServiceProvider
{
    public const string DEV_TAB_COMMAND = StudioCommand::SIGNATURE.' activity --tab';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/studio-cli.php', 'studio-cli');

        $this->app->singleton(Studio::class);

        $this->app->singleton(Workspace::class, fn (): Workspace => new Workspace($this->app->basePath()));

        $this->app->singleton(LocalChanges::class, fn (): LocalChanges => new LocalChanges($this->app->basePath()));

        $this->app->singleton(Errand::class, fn (): Errand => new Errand($this->app->basePath()));

        $this->app->singleton(ActivityLog::class);

        $this->app->singleton(Focus::class);

        $this->app->singleton(ScreenRequests::class);

        $this->app->singleton(SnapshotSource::class, LiveSnapshots::class);

        $this->app->singleton(Presence::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            AvatarSyncCommand::class,
            BuildPresenceCommand::class,
            DashboardCommand::class,
            InsightsCommand::class,
            LinkCommand::class,
            ReviewCommand::class,
            StudioCommand::class,
            WatchCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/studio-cli.php' => config_path('studio-cli.php'),
        ], ['studio-cli', 'studio-cli-config']);

        Event::listen(StudioReported::class, $this->refetchWhenTheStudioChanges(...));

        $this->registerDevTab();
    }

    private function refetchWhenTheStudioChanges(StudioReported $reported): void
    {
        if (in_array($reported->event['type'] ?? null, ['changed', 'checkpoint'], true)) {
            $this->app->make(SnapshotSource::class)->forget();
        }
    }

    private function registerDevTab(): void
    {
        if (config('studio-cli.dev_tab.enabled', false) === false) {
            return;
        }

        if (! class_exists(DevCommands::class)) {
            return;
        }

        if (! config('studio-cli.dev_tab.until_linked', false) && ! $this->app->make(Studio::class)->isLinked()) {
            return;
        }

        $this->app->booted(function (): void {
            DevCommands::artisan(
                self::DEV_TAB_COMMAND,
                (string) config('studio-cli.dev_tab.name', 'Artisan Studio'),
            )->color((string) config('studio-cli.dev_tab.color', '#22d3ee'));
        });
    }
}
