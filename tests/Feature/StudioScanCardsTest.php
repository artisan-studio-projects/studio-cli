<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\FreshSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Scan\ScanProgress;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Alert;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\TestRun;

/*
|--------------------------------------------------------------------------
| The scan's own cards on the Dashboard
|--------------------------------------------------------------------------
|
| Under the progress bar: the tests the scan ran in the background, and a
| card for each tool switched on for the scan that the project does not have
| yet. Enter offers to install it, and nothing installs until the developer
| says yes.
|
*/

beforeEach(function (): void {
    app()->instance(Presence::class, Mockery::mock(Presence::class)->shouldIgnoreMissing());
    $this->plain = fn (string $screen): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $screen);
    $this->status = app(ToolStatus::class);
    $this->studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    @unlink($this->status->path());
});

afterEach(function (): void {
    @unlink($this->status->path());
});

it('shows how the tests went and offers to install each tool the scan could not run', function (): void {
    $this->status->asked(['phpstan', 'tests']);
    $this->status->testsFinished(['ran' => true, 'took' => 70_000, 'summary' => ['tests' => 3499, 'failed' => 0, 'skipped' => 3]]);

    $dashboard = ($this->plain)($this->studio->render(170, 40, 'dashboard'));

    expect($dashboard)
        ->toContain('Tests')
        ->toContain('3,496 passed')
        ->toContain('0 failed · 3 skipped')
        ->toContain('PHPStan')
        ->toContain('Not installed')
        ->toContain('⏎ or click to install')
        ->and(collect($this->studio->footerKeys('dashboard'))->pluck('label'))->toContain('Install');
});

it('spins while the tests wait and run, says off when the developer left them off, and opens the install panel when a card is clicked', function (): void {
    $this->status->asked(['tests', 'phpstan']);

    expect(($this->plain)($this->studio->render(170, 40, 'dashboard')))->toContain('Waiting…')->toContain('after the scan tools')
        ->and($this->studio->render(170, 40, 'dashboard'))->toContain(Canvas::ACTION.'row:enter');

    $this->status->asked(['tests']);
    $this->status->testsRunning();
    $running = ($this->plain)($this->studio->render(170, 40, 'dashboard'));

    expect($running)->toContain('Running…')->not->toContain('to install')
        ->and(collect(TestRun::SPINNER)->contains(fn (string $frame): bool => str_contains($running, $frame.' Tests')))->toBeTrue();

    $this->status->testsOff();

    expect(($this->plain)($this->studio->render(170, 40, 'dashboard')))->toContain('on at your next scan')
        ->and(collect($this->studio->footerKeys('dashboard'))->pluck('label'))->not->toContain('Install');
});

it('installs everything picked with one Enter and no panel to read, since the developer already confirmed in the app', function (): void {
    $root = sys_get_temp_dir().'/studio-install-'.bin2hex(random_bytes(4));
    @mkdir($root, 0755, true);
    file_put_contents($root.'/artisan', '<?php echo "Installed.", PHP_EOL;');
    app()->instance(BackgroundTasks::class, new BackgroundTasks($root, app(ActivityLog::class)));
    $this->status->asked(['phpstan', 'rector']);

    expect($this->studio->tab('dashboard')->canEnter())->toBeTrue()
        ->and($this->studio->tab('dashboard')->enter())->toBe([])
        ->and(app(ActivityLog::class)->ofKinds(['install-tools'])[0])->toMatchArray(['label' => 'Install tools', 'detail' => 'Installing PHPStan and Rector with composer…'])
        ->and(app(BackgroundTasks::class)->isRunning('install-tools'))->toBeTrue()
        ->and($this->studio->tab('dashboard')->canEnter())->toBeFalse();
});

it('says she is almost ready while a picked rule waits to be installed, then that the extra rules are running', function (): void {
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 62, 'label' => 'Fair', 'scored' => true], 'scan' => ['status' => 'Read locally', 'files_done' => 3048, 'files_total' => 3048]]);
        }

        public function workflow(string $id): ?array
        {
            return null;
        }

        public function forget(): void {}
    });
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $this->status->asked(['phpstan']);
    $this->status->pick(['phpstan']);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('Almost ready.')->toContain('pressing enter');

    $this->status->pick([]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->not->toContain('Almost ready.')->not->toContain('All ready to go');

    $this->status->asked([]);
    $this->status->pick(['filacheck', 'pest-type-coverage']);
    $this->status->running(['filacheck', 'pest-type-coverage']);
    $this->status->ran('filacheck', ['ran' => true, 'findings' => []]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->not->toContain('So I ran the additional tools')->not->toContain('all checks passed');

    $this->status->ran('pest-type-coverage', ['ran' => true, 'findings' => [['where' => 'app/A.php:1', 'rule' => 'pr', 'message' => 'x']]]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('So I ran the additional tools you selected.')->toContain('we will tackle these together soon');

    $this->status->running(['pest-type-coverage']);
    $this->status->ran('pest-type-coverage', ['ran' => true, 'findings' => []]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('WOW, impressive, all checks passed!')->toContain("We're only just getting started!");

    $this->status->pick(['filacheck', 'pest-type-coverage', 'phpstan']);
    $this->status->running(['phpstan']);
    $this->status->ran('phpstan', ['ran' => true, 'findings' => []]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('I challenge you to change PHPStan to the max and try again!');
});

it('drops the cards once the scan is reset in the studio, even though the last results are still on this machine', function (): void {
    $this->status->asked(['phpstan', 'tests']);
    $this->status->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 10, 'failed' => 0, 'skipped' => 0]]);
    app()->instance(SnapshotSource::class, new FreshSnapshots);
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('Not scanned yet')->not->toContain('Not installed')->not->toContain('10 passed')
        ->and($studio->tab('dashboard')->canEnter())->toBeFalse();

    app(ToolStatus::class)->forget();

    expect(app(ToolStatus::class)->tests())->toBeNull();
});

it('shows no health number until the studio has scored the conventions, only what it is doing', function (): void {
    $scanning = DashboardSnapshot::fromApi(['health' => ['percent' => 0, 'label' => 'Scanning…', 'scored' => false]]);
    $scored = DashboardSnapshot::fromApi(['health' => ['percent' => 72, 'label' => 'Fair', 'scored' => true]]);

    expect([$scanning->healthValue(), $scanning->healthLabel, $scanning->healthColour()])->toBe(['—', 'Scanning…', 'dim'])
        ->and([$scored->healthValue(), $scored->healthColour()])->toBe(['72%', 'amber'])
        ->and(DashboardSnapshot::fromApi(['health' => ['percent' => 60, 'label' => 'Fair']])->healthValue())->toBe('60%');
});

it('says what will happen on this machine before the scan is authorized, and what is happening once it runs', function (): void {
    app()->instance(SnapshotSource::class, new FreshSnapshots);
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $before = ($this->plain)($studio->render(170, 40, 'dashboard'));

    expect($before)->toContain("Heads up! Here's what's about to happen")
        ->toContain('just looking, never touching')
        ->toContain('Your code stays right here with you.');

    app(ScanProgress::class)->progressed(1250, 3048);
    $during = ($this->plain)($studio->render(170, 40, 'dashboard'));
    app(ScanProgress::class)->finished();

    expect($during)->toContain('Reading 1,250 of 3,048 files on your machine')
        ->not->toContain('Heads up!');
});

it('lets SAMI say what she learned once the conventions are in, then how the tests went', function (): void {
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 72, 'label' => 'Fair', 'scored' => true], 'scan' => ['status' => 'Read locally · conventions learned', 'files_done' => 3048, 'files_total' => 3048]]);
        }

        public function workflow(string $id): ?array
        {
            return null;
        }

        public function forget(): void {}
    });
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $says = fn (): string => ($this->plain)($studio->render(170, 40, 'dashboard'));
    $finished = fn (int $failed): mixed => $this->status->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 100, 'failed' => $failed, 'skipped' => 0]]);

    $this->status->asked(['tests']);
    $this->status->testsRunning();

    expect($says())->toContain(Alert::INFO.'  Knowledge is power!')->toContain('I have learned a lot about your application')->not->toContain('Heads up!');

    $finished(0);

    expect($says())->toContain(Alert::SUCCESS.'  WOW, OK, a 100% pass rate on your tests!')->toContain('we are only just getting started!')->not->toContain('Knowledge is power!');

    $finished(2);

    expect($says())->toContain(Alert::WARNING.'  Oh so close!')->toContain("We both know that's an easy fix");

    $finished(12);

    expect($says())->toContain(Alert::WARNING."  OK, so we're getting a fair few failures here.")->toContain("Don't panic, we can fix these together after your scan!");
});

it('shows no scan cards before a scan has run', function (): void {
    expect(($this->plain)($this->studio->render(170, 40, 'dashboard')))->not->toContain('Not installed')->not->toContain('passed')
        ->and($this->studio->tab('dashboard')->canEnter())->toBeFalse();
});
