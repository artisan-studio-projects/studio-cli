<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Console\BuildPresenceCommand;
use ArtisanStudio\StudioCli\Console\LinkCommand;
use ArtisanStudio\StudioCli\Console\WatchCommand;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class StudioCliServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/studio-cli.php', 'studio-cli');

        $this->app->singleton(Studio::class);

        $this->app->singleton(Workspace::class, fn (): Workspace => new Workspace($this->app->basePath()));

        $this->app->singleton(Mirror::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            BuildPresenceCommand::class,
            LinkCommand::class,
            WatchCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/studio-cli.php' => config_path('studio-cli.php'),
        ], ['studio-cli', 'studio-cli-config']);

        $this->registerDevTab();
    }

    private function registerDevTab(): void
    {
        if (config('studio-cli.dev_tab.enabled', true) === false) {
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
                WatchCommand::SIGNATURE,
                (string) config('studio-cli.dev_tab.name', 'Artisan Studio'),
            )->color((string) config('studio-cli.dev_tab.color', '#22d3ee'));
        });
    }
}
