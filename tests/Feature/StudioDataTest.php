<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BranchStatus;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\LiveSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Errand;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\Saloon\Requests\CommandResultRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\NextCommandRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowSnapshotRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowWorkflowRequest;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Workspace;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| The studio's own numbers
|--------------------------------------------------------------------------
|
| What the tabs show comes from the linked studio. Made-up numbers only ever
| appear when asked for with --demo.
|
*/

beforeEach(function (): void {
    app()->forgetInstance(SnapshotSource::class);
    $this->plain = fn (string $text): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $text);
    $this->studio = [
        'project' => ['name' => 'Acme Shop', 'repository' => 'acme/shop'],
        'health' => ['percent' => 82, 'label' => 'Good', 'open' => 3],
        'deliverables' => ['percent' => 50, 'done' => 1, 'total' => 2],
        'workflows' => ['running' => 1, 'done' => 0, 'total' => 1, 'list' => [
            ['id' => '7', 'name' => 'Checkout redesign', 'status' => 'Active', 'updated_at' => Carbon::now()->subMinutes(5)->toIso8601String(), 'url' => 'https://studio.test/workflow/7'],
        ]],
        'credits' => 480,
        'scan' => ['status' => 'Scanned', 'files_done' => 900, 'files_total' => 900, 'scanned_at' => Carbon::now()->subHours(2)->toIso8601String()],
        'insights' => ['url' => 'https://studio.test/insights/rulesets', 'rulesets' => [['name' => 'Code Quality', 'open' => 3]]],
    ];
    Saloon::fake([ShowSnapshotRequest::class => MockResponse::make($this->studio)]);
});

it('shows the linked studio\'s own workflows, and nothing made up', function (): void {
    Artisan::call('studio', ['tab' => 'workflows', '--once' => true, '--width' => 120, '--height' => 30]);
    $screen = ($this->plain)(Artisan::output());

    expect($screen)->toContain('Acme Shop')->toContain('acme/shop')
        ->toContain('Checkout redesign')->toContain('● Active')->toContain('5m ago')
        ->not->toContain('Linear ticket estimation');
});

it('says so plainly when the studio has no workflows yet', function (): void {
    Saloon::fake([ShowSnapshotRequest::class => MockResponse::make([...$this->studio, 'workflows' => ['running' => 0, 'done' => 0, 'total' => 0, 'list' => []]])]);

    Artisan::call('studio', ['tab' => 'workflows', '--once' => true, '--width' => 120, '--height' => 30]);

    expect(($this->plain)(Artisan::output()))->toContain('No workflows yet. Start one in Artisan Studio')->not->toContain('Checkout redesign');
});

it('shows made-up numbers only when asked for with --demo', function (): void {
    Artisan::call('studio', ['tab' => 'workflows', '--demo' => true, '--once' => true, '--width' => 120, '--height' => 30]);

    expect(($this->plain)(Artisan::output()))->toContain('Linear ticket estimation')->not->toContain('Checkout redesign');

    Saloon::assertNothingSent();
});

it('asks the studio once, not on every redraw, and again the moment it is told the project changed', function (): void {
    $source = app(SnapshotSource::class);

    $first = $source->snapshot();
    $source->snapshot();
    Saloon::assertSentCount(1);

    event(new StudioReported(['type' => 'changed', 'kind' => 'project']));
    $source->snapshot();

    expect($source)->toBeInstanceOf(LiveSnapshots::class)
        ->and($first->credits)->toBe(480)
        ->and($first->scanLabel())->toContain('2h ago');

    Saloon::assertSentCount(2);
});

it('asks nothing of a studio this project is not linked to', function (): void {
    config()->set('studio-cli.token', null);

    expect(app(SnapshotSource::class)->snapshot()->workflows)->toBe([]);

    Saloon::assertNothingSent();
});

it('moves through the workflows with the arrows, and Enter shows one with its tasks', function (): void {
    Saloon::fake([
        ShowSnapshotRequest::class => MockResponse::make($this->studio),
        ShowWorkflowRequest::class => MockResponse::make([
            'workflow' => ['id' => '7', 'name' => 'Checkout redesign', 'status' => 'Active', 'branch' => 'workflow/checkout', 'url' => 'https://studio.test/workflow/7'],
            'tasks' => [
                ['ordinal' => 1, 'title' => 'Cart model', 'artisan' => 'Mason', 'status' => 'Done', 'waiting' => false],
                ['ordinal' => 2, 'title' => 'Cart page', 'artisan' => 'Pixel', 'status' => 'Review', 'waiting' => true],
            ],
        ]),
    ]);
    $command = app(StudioCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    $press = fn (string ...$keys): mixed => collect($keys)->each(fn (string $key): mixed => (fn (): mixed => $this->handleScreenAction($this->screenActionFor($key)))->call($command));
    $screen = fn (): string => ($this->plain)(implode("\n", (fn (): ScreenContainer => $this->getScreen())->call($command)->lines(120, 30, 'workflows')));
    (fn (): string => $this->screenTab = 'workflows')->call($command);

    $list = $screen();
    $press("\e[B", "\n");
    $opened = $screen();
    $press("\e");

    expect($list)->toContain('▸ Checkout redesign')->toContain('Details')
        ->and($opened)->toContain('Checkout redesign')->toContain('artisans on workflow/checkout')
        ->and($opened)->toContain('Cart model')->toContain('● Waiting for you')->toContain('Pixel')->toContain('Back')
        ->and($screen())->toBe($list);
});

it('moves through a workflow\'s tasks the same way, and Enter shows one task with what it does and its files', function (): void {
    Saloon::fake([
        ShowSnapshotRequest::class => MockResponse::make($this->studio),
        ShowWorkflowRequest::class => MockResponse::make([
            'workflow' => ['id' => '7', 'name' => 'Checkout redesign', 'status' => 'Active', 'branch' => 'workflow/checkout', 'url' => 'https://studio.test/workflow/7'],
            'tasks' => [
                ['id' => '31', 'ordinal' => 4, 'title' => 'Cart page', 'artisan' => 'Pixel', 'status' => 'Review', 'waiting' => true, 'summary' => 'The page a shopper checks the cart on.', 'files' => [['path' => 'resources/views/cart.blade.php', 'kind' => 'write'], ['path' => 'app/Models/User.php', 'kind' => 'read']]],
                ['id' => '32', 'ordinal' => 2, 'title' => 'Cart model', 'artisan' => 'Mason', 'status' => 'Done', 'waiting' => false, 'finished' => true, 'summary' => '', 'files' => []],
            ],
        ]),
    ]);
    $command = app(StudioCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    $press = fn (string ...$keys): mixed => collect($keys)->each(fn (string $key): mixed => (fn (): mixed => $this->handleScreenAction($this->screenActionFor($key)))->call($command));
    $screen = fn (): string => ($this->plain)(implode("\n", (fn (): ScreenContainer => $this->getScreen())->call($command)->lines(120, 40, 'workflows')));
    (fn (): string => $this->screenTab = 'workflows')->call($command);

    $press("\n");
    $tasks = $screen();
    $press("\e[B", "\e[A", "\n");
    $task = $screen();
    $press("\e");
    $backToTasks = $screen();
    $press("\e");

    expect($tasks)->toContain('▸ 1')->toContain('Cart page')->toContain('● Waiting for you')->toContain('Details')->toContain('Back')
        ->toContain('● Finished')->not->toContain('● Done')
        ->and($task)->toContain('Cart page')->not->toContain('Checkout redesign · task 1 · Pixel')->toContain('The page a shopper checks the cart on.')
        ->and($task)->toContain('resources/views/cart.blade.php')->toContain('1 file')->not->toContain('app/Models/User.php')->toContain('has finished and is waiting for your review')
        ->and($backToTasks)->toContain('Cart model')->toContain('▸ 1')
        ->and($screen())->toContain('▸ Checkout redesign');
});

it('opens the review of the task waiting the moment Review is pressed in the studio', function (): void {
    $git = Mockery::mock(BranchStatus::class, [app(LocalChanges::class), app(Workspace::class)])->makePartial();
    $git->shouldReceive('now')->andReturn(['branch' => 'develop', 'uncommitted' => 0]);
    app()->instance(BranchStatus::class, $git);
    Saloon::fake([
        ShowSnapshotRequest::class => MockResponse::make($this->studio),
        ShowWorkflowRequest::class => MockResponse::make([
            'workflow' => ['id' => '7', 'name' => 'Checkout redesign', 'status' => 'Active', 'branch' => 'sami/checkout', 'url' => null],
            'tasks' => [
                ['id' => '31', 'ordinal' => 1, 'title' => 'Cart model', 'artisan' => 'Mason', 'status' => 'Done', 'waiting' => false, 'summary' => '', 'files' => []],
                ['id' => '32', 'ordinal' => 2, 'title' => 'Cart page', 'artisan' => 'Pixel', 'status' => 'Review', 'waiting' => true, 'summary' => '', 'files' => []],
            ],
        ]),
    ]);
    $command = app(StudioCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    $studio = (fn (): ScreenContainer => $this->getScreen())->call($command);
    app(Kernel::class)->all()['studio:workflows']->start();

    event(new StudioReported(['type' => 'checkpoint', 'kind' => 'checkpoint', 'agent' => 'pixel', 'workflow' => '7', 'task' => '32', 'history' => false]));
    (fn (): mixed => $this->followScreenRequests())->call($command);
    $screen = ($this->plain)(implode("\n", $studio->lines(120, 40, 'workflows')));

    $studio->closeSettings();
    $task = ($this->plain)(implode("\n", $studio->lines(120, 40, 'workflows')));

    expect((fn (): ?string => $this->screenTab)->call($command))->toBe('workflows')
        ->and($studio->tab('workflows')->depth())->toBe(2)
        ->and($screen)->toContain('Review · Cart page')->toContain('Pixel finished this and is waiting for you.')
        ->toContain('⏎ Switch to sami/checkout and start')->not->toContain('Looks good')
        ->and($task)->toContain('Pixel has finished and is waiting for your review.')->toContain('⏎ Start review');
});

it('says straight away when uncommitted changes stand in the way of a review, and whose branch is whose', function (): void {
    $git = Mockery::mock(BranchStatus::class, [app(LocalChanges::class), app(Workspace::class)])->makePartial();
    $git->shouldReceive('now')->andReturn(['branch' => 'develop', 'uncommitted' => 342]);
    app()->instance(BranchStatus::class, $git);
    Saloon::fake([
        ShowSnapshotRequest::class => MockResponse::make($this->studio),
        ShowWorkflowRequest::class => MockResponse::make([
            'workflow' => ['id' => '7', 'name' => 'Checkout redesign', 'status' => 'Active', 'branch' => 'sami/checkout', 'url' => null],
            'tasks' => [['id' => '32', 'ordinal' => 1, 'title' => 'Cart page', 'artisan' => 'Pixel', 'status' => 'Review', 'waiting' => true, 'summary' => '', 'files' => []]],
        ]),
    ]);
    $command = app(StudioCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    $studio = (fn (): ScreenContainer => $this->getScreen())->call($command);
    app(Kernel::class)->all()['studio:workflows']->start();

    event(new StudioReported(['type' => 'checkpoint', 'agent' => 'pixel', 'workflow' => '7', 'task' => '32', 'history' => true]));
    (fn (): mixed => $this->followScreenRequests())->call($command);
    $task = ($this->plain)(implode("\n", $studio->lines(120, 40, 'workflows')));
    $studio->tab('workflows')->close();
    $tasks = ($this->plain)(implode("\n", $studio->lines(140, 40, 'workflows')));

    expect($studio->tab('workflows')->depth())->toBe(1)
        ->and($studio->showsPanel())->toBeFalse()
        ->and($task)->toContain('You are on develop with 342 uncommitted changes.')->toContain('Commit or stash them first')->toContain('sami/checkout')
        ->not->toContain('Start review')
        ->and($tasks)->toContain('artisans on sami/checkout · you on develop')
        ->and(app(ActivityLog::class)->entries('7')[0])->toMatchArray(['label' => 'Can\'t switch yet']);
});

it('opens a workflow when its row is clicked, and still opens the browser from the ↗', function (): void {
    Illuminate\Support\Facades\Process::fake();
    Saloon::fake([
        ShowSnapshotRequest::class => MockResponse::make($this->studio),
        ShowWorkflowRequest::class => MockResponse::make(['workflow' => ['id' => '7', 'name' => 'Checkout redesign', 'status' => 'Active', 'branch' => null, 'url' => null], 'tasks' => []]),
    ]);
    $command = app(StudioCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    (fn (): string => $this->screenTab = 'workflows')->call($command);
    (fn (): int => $this->screenWidthNow = 120)->call($command);
    $studio = (fn (): ScreenContainer => $this->getScreen())->call($command);
    $lines = array_map($this->plain, $studio->lines(120, 30, 'workflows'));
    (fn (): array => $this->screenLines = $studio->lines(120, 30, 'workflows'))->call($command);
    $row = (int) collect($lines)->search(fn (string $line): bool => str_contains($line, 'Checkout redesign')) + 1;
    $click = fn (int $column): mixed => (fn (): mixed => $this->handleScreenAction($this->screenActionFor("\e[<0;{$column};{$row}M")))->call($command);

    $click(mb_strpos($lines[$row - 1], '↗') + 1);
    $click(1);
    $click(120);
    $wide = array_map($this->plain, $studio->lines(150, 30, 'workflows'));
    (fn (): int => $this->screenWidthNow = 150)->call($command);
    $click(mb_strrpos($wide[$row - 1], '│') + 3);
    $stillTheList = $studio->tab('workflows')->isOpen();
    $click(mb_strpos($wide[$row - 1], 'Checkout') + 1);

    expect($stillTheList)->toBeFalse()
        ->and($studio->tab('workflows')->isOpen())->toBeTrue();

    Illuminate\Support\Facades\Process::assertRanTimes(fn (PendingProcess $process): bool => in_array('https://studio.test/workflow/7', (array) $process->command, true), 1);
});

it('answers what the artisans ask of this machine in the background, and says so in Activity', function (): void {
    $asked = false;
    Saloon::fake([
        NextCommandRequest::class => function (PendingRequest $request) use (&$asked): MockResponse {
            $first = ! $asked;
            $asked = true;

            return MockResponse::make(['command' => $first ? ['id' => 9, 'name' => 'describe_schema', 'arguments' => [], 'workflow' => '7'] : null]);
        },
        CommandResultRequest::class => MockResponse::make(['ok' => true]),
    ]);
    $errands = Mockery::mock(Errand::class, [getcwd()])->makePartial();
    $errands->shouldReceive('start')->andReturnUsing(fn (): Process => tap(new Process([PHP_BINARY, '-r', 'echo "12 tables";']))->start());
    app()->instance(Errand::class, $errands);
    $workflows = app(Kernel::class)->all()['studio:workflows'];

    $workflows->start();
    $workflows->tick();
    $answering = app(ActivityLog::class)->entries()[0]['label'];
    usleep(500_000);
    $workflows->tick();

    expect($answering)->toBe('Answering')
        ->and(app(ActivityLog::class)->entries())->toHaveCount(1)
        ->and(app(ActivityLog::class)->entries()[0])->toMatchArray(['label' => 'Answered', 'detail' => 'An artisan read your database schema']);

    Saloon::assertSent(fn (CommandResultRequest $request): bool => str_contains((string) json_encode($request->body()->all()), '12 tables'));
});

it('answers a question it does not understand straight away, rather than leaving the artisan waiting', function (): void {
    Saloon::fake([
        NextCommandRequest::class => MockResponse::make(['command' => ['id' => 3, 'name' => 'something_new', 'arguments' => [], 'workflow' => '7']]),
        CommandResultRequest::class => MockResponse::make(['ok' => true]),
    ]);
    $workflows = app(Kernel::class)->all()['studio:workflows'];

    $workflows->start();
    $workflows->tick();

    expect(app(ActivityLog::class)->entries()[0]['label'])->toBe('Answered with a problem');

    Saloon::assertSent(fn (CommandResultRequest $request): bool => str_contains((string) json_encode($request->body()->all()), 'does not know how to answer'));
});

it('tells the artisan when the studio is closed before its answer is ready', function (): void {
    Saloon::fake([
        NextCommandRequest::class => MockResponse::make(['command' => ['id' => 4, 'name' => 'run_tests', 'arguments' => [], 'workflow' => '7']]),
        CommandResultRequest::class => MockResponse::make(['ok' => true]),
    ]);
    $errands = Mockery::mock(Errand::class, [getcwd()])->makePartial();
    $errands->shouldReceive('start')->andReturnUsing(fn (): Process => tap(new Process([PHP_BINARY, '-r', 'sleep(30);']))->start());
    app()->instance(Errand::class, $errands);
    $workflows = app(Kernel::class)->all()['studio:workflows'];

    $workflows->start();
    $workflows->tick();
    $workflows->stop();

    Saloon::assertSent(fn (CommandResultRequest $request): bool => str_contains((string) json_encode($request->body()->all()), 'closed on this machine'));
});

it('reads how long ago a workflow moved from the studio\'s timestamp', function (): void {
    $snapshot = DashboardSnapshot::fromApi($this->studio, Carbon::now());

    expect($snapshot->workflows[0]['updated'])->toBe('5m')
        ->and($snapshot->project)->toBe('Acme Shop')
        ->and($snapshot->rulesets)->toBe([['name' => 'Code Quality', 'open' => 3]]);
});
