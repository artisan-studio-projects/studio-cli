<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Console\WatchCommand;
use ArtisanStudio\StudioCli\Console\WorkflowsCommand;
use ArtisanStudio\StudioCli\Focus;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Saloon\Requests\ListProjectsRequest;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\StudioTabs;
use ArtisanStudio\StudioCli\Terminal\Tab;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| A tab is a command
|--------------------------------------------------------------------------
|
| Anything that ships as a command and provides a tab turns up in
| `php artisan studio` without the studio being told about it.
|
*/

final class ShipsATab extends Command implements ProvidesTab
{
    protected $signature = 'studio:shipped';

    public function handle(): int
    {
        return self::SUCCESS;
    }

    public function tab(Tab $tab): Tab
    {
        return $tab->label('Shipped')->components([Text::make('Everything shipped')]);
    }
}

beforeEach(function (): void {
    $this->plain = fn (string $screen): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $screen);
    $this->labels = fn (): array => collect(app(StudioTabs::class)->tabs())->map(fn (Tab $tab): string => $tab->getLabel())->all();
    $this->watch = app(Kernel::class)->all()[WatchCommand::SIGNATURE];
    $this->studio = fn (): ScreenContainer => app(StudioCommand::class)->screen(ScreenContainer::make());
});

it('adds any command that provides a tab, in the order the config asks for, leaving out what it hides', function (): void {
    app(Kernel::class)->registerCommand(new ShipsATab);
    $discovered = ($this->labels)();

    config(['studio-cli.tabs.order' => ['studio:shipped', 'studio:watch']]);
    $ordered = ($this->labels)();

    config(['studio-cli.tabs.hidden' => ['studio:watch']]);
    $hidden = ($this->labels)();

    expect($discovered)->toBe(['Dashboard', 'Scan', 'Insights', 'Workflows', 'Activity', 'Shipped'])
        ->and($ordered)->toBe(['Shipped', 'Activity', 'Dashboard', 'Insights', 'Scan', 'Workflows'])
        ->and($hidden)->toBe(['Shipped', 'Dashboard', 'Insights', 'Scan', 'Workflows'])
        ->and(($this->studio)()->tabKeys())->toBe(['shipped', 'dashboard', 'insights', 'scan', 'workflows'])
        ->and(($this->plain)(($this->studio)()->render(120, 30, 'shipped')))->toContain('Everything shipped');
});

it('opens the studio on the tab a command provides, rather than a screen of its own', function (string $command, string $expected): void {
    putenv('COLUMNS=120');
    putenv('LINES=40');

    Artisan::call($command);
    $screen = ($this->plain)(Artisan::output());

    putenv('COLUMNS');
    putenv('LINES');

    expect($screen)->toContain($expected)->toContain('Artisan Studio');
})->with([
    'dashboard' => ['studio:dashboard', '📦 Deliverables'],
    'insights' => ['studio:insights', 'Attempting to fix grouped issues'],
    'workflows' => ['studio:workflows', 'Linear ticket estimation'],
    'activity' => ['studio:watch', 'When a workflow finishes a step'],
]);

it('runs the watcher and the workflows in the background, each once, though the watcher fills both a tab and the rail', function (): void {
    $runners = collect(($this->studio)()->runners())->map(fn (object $runner): string => $runner::class);

    expect($runners->sort()->values()->all())->toBe([WatchCommand::class, WorkflowsCommand::class]);
});

it('lists what the studio reports as it happens, newest first, and lets the avatar react to each', function (): void {
    $avatar = Mockery::mock(Presence::class)->shouldIgnoreMissing();
    app()->instance(Presence::class, $avatar);
    app(Focus::class)->follow('101', 'Insights dashboard with health trends');
    $watch = $this->watch;
    $heard = fn (array $event): mixed => (fn (): mixed => $this->heard(['workflow' => '101', ...$event]))->call($watch);

    $heard(['type' => 'file', 'kind' => 'file', 'path' => 'app/Models/HealthTrend.php', 'agent' => 'mason']);
    $heard(['type' => 'test', 'kind' => 'test', 'failed' => 2, 'passed' => 40, 'agent' => 'pixel']);
    $heard(['kind' => 'tick']);
    $screen = ($this->plain)(($this->studio)()->render(120, 30, 'activity'));

    expect($screen)->toContain('app/Models/HealthTrend.php')->toContain('● Tests failed')->toContain('2 failed, 40 passed')->toContain('mason')
        ->and(strpos($screen, 'Tests failed'))->toBeLessThan(strpos($screen, 'Wrote'));

    $avatar->shouldHaveReceived('react')->twice();
    $avatar->shouldHaveReceived('settle')->once();
});

it('shows what each artisan is doing in words, in the machine\'s own time, and catches up on history without the avatar replaying it', function (): void {
    $zone = getenv('TZ');
    putenv('TZ=Europe/London');
    $avatar = Mockery::mock(Presence::class)->shouldIgnoreMissing();
    app()->instance(Presence::class, $avatar);
    app(Focus::class)->follow('101', 'Insights dashboard with health trends');
    $watch = $this->watch;
    $heard = fn (array $event): mixed => (fn (): mixed => $this->heard(['workflow' => '101', ...$event]))->call($watch);

    $heard(['type' => 'beat', 'kind' => 'self', 'agent' => 'foreman', 'body' => 'Plan received from <span class="text-cyan-300">@sami</span>. Reading the plan.', 'at' => '2026-09-26T21:40:05+00:00', 'history' => true]);
    $heard(['type' => 'beat', 'kind' => 'outbound', 'agent' => 'foreman', 'body' => 'Manifest handed to Mason.', 'history' => false]);
    $heard(['type' => 'changed', 'kind' => 'project', 'state' => 'abc']);
    $screen = ($this->plain)(($this->studio)()->render(120, 30, 'activity'));
    putenv($zone === false ? 'TZ' : "TZ={$zone}");

    expect($screen)->toContain('● Working')->toContain('Plan received from @sami')
        ->toContain('● Handed over')->toContain('Manifest handed to Mason.')
        ->toContain('22:40:05')
        ->and(app(ActivityLog::class)->entries())->toHaveCount(2);

    $avatar->shouldHaveReceived('react')->once();
});

it('reads the studio without ever waiting on it, and says so when the connection drops', function (): void {
    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['data' => [['slug' => '1', 'name' => 'Artisan Studio']]])]);
    Process::fake([
        '*curl*' => Process::describe()
            ->output(['data: {"type":"file","kind":"file","path":"routes/web.php","agent":"mason","workflow":"101"}', ''])
            ->errorOutput('Could not resolve host: studio.test')
            ->exitCode(6)
            ->runsFor(iterations: 3),
    ]);
    app()->instance(Presence::class, Mockery::mock(Presence::class)->shouldIgnoreMissing());
    app(Focus::class)->follow('101', 'Insights dashboard with health trends');
    $watch = $this->watch;
    $screen = fn (): string => ($this->plain)(($this->studio)()->render(120, 30, 'activity'));

    $watch->start();
    $watch->tick();
    $watch->tick();
    $live = $screen();
    collect(range(1, 4))->each(fn (): mixed => $watch->tick());
    $lost = $screen();
    $watch->stop();

    expect($live)->toContain('routes/web.php')->toContain('live')
        ->and($lost)->toContain('lost the studio (Could not resolve host: studio.test), trying again in 5s')
        ->and($lost)->toContain('routes/web.php');
});
