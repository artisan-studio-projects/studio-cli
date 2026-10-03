<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Console\AvatarSyncCommand;
use ArtisanStudio\StudioCli\Console\BlueprintCommand;
use ArtisanStudio\StudioCli\Console\BuildPresenceCommand;
use ArtisanStudio\StudioCli\Console\DashboardCommand;
use ArtisanStudio\StudioCli\Console\InsightsCommand;
use ArtisanStudio\StudioCli\Console\SettingsCommand;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Console\WatchCommand;
use ArtisanStudio\StudioCli\Console\WorkflowsCommand;
use ArtisanStudio\StudioCli\Dashboard\LiveSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

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

        $this->app->singleton(Editor::class, fn (): Editor => new Editor($this->app->basePath()));

        $this->app->singleton(EnvFile::class, fn (): EnvFile => new EnvFile($this->app->basePath('.env')));

        $this->app->singleton(TestSuite::class, fn (): TestSuite => new TestSuite($this->app->basePath()));

        $this->app->singleton(Blueprint::class, fn (): Blueprint => new Blueprint(
            $this->app->path(),
            $this->app->getNamespace(),
            $this->app->make(ModelInspector::class),
        ));

        $this->app->singleton(ActivityLog::class);

        $this->app->singleton(Focus::class);

        $this->app->singleton(ScreenRequests::class);

        $this->app->singleton(BranchStatus::class);

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
            BlueprintCommand::class,
            BuildPresenceCommand::class,
            DashboardCommand::class,
            InsightsCommand::class,
            SettingsCommand::class,
            StudioCommand::class,
            WatchCommand::class,
            WorkflowsCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/studio-cli.php' => config_path('studio-cli.php'),
        ], ['studio-cli', 'studio-cli-config']);

        Event::listen(StudioReported::class, $this->refetchWhenTheStudioChanges(...));

        Event::listen(StudioReported::class, $this->mapTheBlueprintWhenAsked(...));

        $this->registerDevTab();
    }

    private function refetchWhenTheStudioChanges(StudioReported $reported): void
    {
        if (in_array($reported->event['type'] ?? null, ['changed', 'checkpoint'], true)) {
            $this->app->make(SnapshotSource::class)->forget();
        }
    }

    private function mapTheBlueprintWhenAsked(StudioReported $reported): void
    {
        if (($reported->event['type'] ?? null) !== 'blueprint') {
            return;
        }

        try {
            $models = $this->app->make(Blueprint::class)->map()['models'];
            $sha = $this->app->make(LocalChanges::class)->currentSha();
            $sent = $models !== [] && $this->app->make(Studio::class)->submitBlueprint([
                'commit' => $sha === '' ? null : $sha,
                'models' => $models,
            ]) !== null;
        } catch (Throwable) {
            $models = [];
            $sent = false;
        }

        $this->app->make(ActivityLog::class)->add([
            'agent' => 'SAMI',
            'label' => 'Blueprint',
            'detail' => $sent
                ? 'Sent the map of '.trans_choice(':count model|:count models', count($models))
                : 'Could not send the map of your models. Run php artisan studio:blueprint to see why.',
            'colour' => $sent ? 'green' : 'amber',
            'kind' => 'blueprint',
        ]);
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
