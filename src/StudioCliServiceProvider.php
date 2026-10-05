<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Console\AvatarSyncCommand;
use ArtisanStudio\StudioCli\Console\BlueprintCommand;
use ArtisanStudio\StudioCli\Console\BuildPresenceCommand;
use ArtisanStudio\StudioCli\Console\ConventionsCommand;
use ArtisanStudio\StudioCli\Console\DashboardCommand;
use ArtisanStudio\StudioCli\Console\InsightsCommand;
use ArtisanStudio\StudioCli\Console\InstallToolsCommand;
use ArtisanStudio\StudioCli\Console\SettingsCommand;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Console\TestsCommand;
use ArtisanStudio\StudioCli\Console\ToolsCommand;
use ArtisanStudio\StudioCli\Console\WatchCommand;
use ArtisanStudio\StudioCli\Console\WorkflowsCommand;
use ArtisanStudio\StudioCli\Dashboard\LiveSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\Scan\ScanProgress;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Scan\Walk;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use Illuminate\Database\Eloquent\ModelInspector;
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

        $this->app->singleton(Editor::class, fn (): Editor => new Editor($this->app->basePath()));

        $this->app->singleton(EnvFile::class, fn (): EnvFile => new EnvFile($this->app->basePath('.env')));

        $this->app->singleton(TestSuite::class, fn (): TestSuite => new TestSuite($this->app->basePath()));

        $this->app->singleton(Blueprint::class, fn (): Blueprint => new Blueprint(
            $this->app->path(),
            $this->app->getNamespace(),
            $this->app->make(ModelInspector::class),
        ));

        $this->app->singleton(Walk::class, fn (): Walk => new Walk($this->app->basePath(), $this->app->make(LocalChanges::class)));

        $this->app->singleton(ActivityLog::class);

        $this->app->singleton(BackgroundTasks::class, fn (): BackgroundTasks => new BackgroundTasks(
            $this->app->basePath(),
            $this->app->make(ActivityLog::class),
            fn (): null => $this->app->make(SnapshotSource::class)->forget(),
        ));

        $this->app->singleton(ScanProgress::class, fn (): ScanProgress => new ScanProgress($this->app->basePath()));

        $this->app->singleton(ToolStatus::class, fn (): ToolStatus => new ToolStatus($this->app->basePath()));

        $this->app->singleton(TaskJournal::class, fn (): TaskJournal => TaskJournal::fromEnvironment());

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
            ConventionsCommand::class,
            DashboardCommand::class,
            InsightsCommand::class,
            InstallToolsCommand::class,
            SettingsCommand::class,
            StudioCommand::class,
            TestsCommand::class,
            ToolsCommand::class,
            WatchCommand::class,
            WorkflowsCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/studio-cli.php' => config_path('studio-cli.php'),
        ], ['studio-cli', 'studio-cli-config']);

        Event::listen(StudioReported::class, $this->refetchWhenTheStudioChanges(...));

        Event::listen(StudioReported::class, $this->mapTheBlueprintWhenAsked(...));

        Event::listen(StudioReported::class, $this->runTheToolsWhenAsked(...));

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

        $this->app->make(ToolStatus::class)->forget();

        $tasks = $this->app->make(BackgroundTasks::class);
        $tasks->start('blueprint', 'Blueprint', 'Mapping your models…', [BlueprintCommand::SIGNATURE]);
        $tasks->start('conventions', 'Conventions', 'Counting how your project is written…', [ConventionsCommand::SIGNATURE]);
        $tasks->start('tools', 'Scan tools', 'Running your project’s own checking tools, read-only…', [ToolsCommand::SIGNATURE], then: [
            'key' => 'tests',
            'label' => 'Your tests',
            'working' => 'Checking whether you switched your tests on…',
            'command' => [TestsCommand::SIGNATURE],
            'timeout' => TestsCommand::TIMEOUT,
        ]);
    }

    private function runTheToolsWhenAsked(StudioReported $reported): void
    {
        if (($reported->event['type'] ?? null) !== 'tools') {
            return;
        }

        $status = $this->app->make(ToolStatus::class);
        $tools = array_values(array_filter((array) ($reported->event['tools'] ?? []), is_string(...)));

        if ($tools !== []) {
            $status->asked($tools);
            $status->pick($tools);
        }

        if ($status->missing() !== []) {
            return;
        }

        $this->app->make(BackgroundTasks::class)->start('tools', 'Scan tools', 'Running the extra checks you picked…', [ToolsCommand::SIGNATURE]);
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
