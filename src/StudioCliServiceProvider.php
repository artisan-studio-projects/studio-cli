<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Console\AvatarSyncCommand;
use ArtisanStudio\StudioCli\Console\BuildPresenceCommand;
use ArtisanStudio\StudioCli\Console\RenderCommand;
use ArtisanStudio\StudioCli\Dashboard\LiveSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class StudioCliServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/studio-cli.php', 'studio-cli');

        $this->app->singleton(Studio::class);

        $this->app->singleton(Says::class, fn (): Says => new Says($this->app->make(Studio::class), $this->app->basePath()));

        $this->app->singleton(LocalChanges::class, fn (): LocalChanges => new LocalChanges($this->app->basePath()));

        $this->app->singleton(EnvFile::class, fn (): EnvFile => new EnvFile($this->app->basePath('.env')));

        $this->app->singleton(ActivityLog::class);

        $this->app->singleton(BackgroundTasks::class, fn (): BackgroundTasks => new BackgroundTasks(
            $this->app->basePath(),
            $this->app->make(ActivityLog::class),
            fn (): null => $this->app->make(SnapshotSource::class)->forget(),
        ));

        $this->app->singleton(TaskJournal::class, fn (): TaskJournal => TaskJournal::fromEnvironment());

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
            RenderCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/studio-cli.php' => config_path('studio-cli.php'),
        ], ['studio-cli', 'studio-cli-config']);

        Event::listen(StudioReported::class, $this->refetchWhenTheStudioChanges(...));
    }

    private function refetchWhenTheStudioChanges(StudioReported $reported): void
    {
        if (in_array($reported->event['type'] ?? null, ['changed', 'checkpoint'], true)) {
            $this->app->make(SnapshotSource::class)->forget();
        }
    }
}
