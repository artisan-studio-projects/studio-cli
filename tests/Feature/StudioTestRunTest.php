<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BranchStatus;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\Saloon\Requests\CheckoutRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowSnapshotRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowWorkflowRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitTestRunRequest;
use ArtisanStudio\StudioCli\Terminal\Contracts\RunsInBackground;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\TestSuite;
use ArtisanStudio\StudioCli\Workspace;
use Illuminate\Console\OutputStyle;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process as Processes;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    app()->forgetInstance(SnapshotSource::class);
    $this->root = sys_get_temp_dir().'/studio-tests-'.uniqid();
    $this->origin = $this->root.'/origin.git';
    $this->work = $this->root.'/work';
    $this->git = function (string $in, string ...$arguments): string {
        $process = new Process(['git', ...$arguments], $in);
        $process->mustRun();

        return trim($process->getOutput());
    };
    $this->write = function (string $path, string $contents): void {
        @mkdir(dirname($this->work.'/'.$path), 0777, true);
        file_put_contents($this->work.'/'.$path, $contents);
    };

    mkdir($this->work, 0777, true);
    ($this->git)($this->root, 'init', '--quiet', '--bare', '--initial-branch=develop', 'origin.git');
    ($this->git)($this->work, 'init', '--quiet', '--initial-branch=develop');
    ($this->git)($this->work, 'config', 'user.email', 'dev@example.test');
    ($this->git)($this->work, 'config', 'user.name', 'Dev');
    ($this->git)($this->work, 'remote', 'add', 'origin', $this->origin);
    ($this->write)('README.md', "shop\n");
    ($this->write)('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/><env name="DB_DATABASE" value=":memory:"/></php></phpunit>');
    ($this->git)($this->work, 'add', '--all');
    ($this->git)($this->work, 'commit', '--quiet', '-m', 'feat(shop): start');
    ($this->git)($this->work, 'push', '--quiet', 'origin', 'develop');
    ($this->git)($this->work, 'checkout', '--quiet', '-b', 'sami/checkout');
    ($this->write)('tests/Feature/CartTest.php', "<?php\n");
    ($this->write)('tests/Unit/CartTotalTest.php', "<?php\n");
    ($this->git)($this->work, 'add', '--all');
    ($this->git)($this->work, 'commit', '--quiet', '-m', 'test(cart): @prover created the cart tests');
    ($this->git)($this->work, 'push', '--quiet', 'origin', 'sami/checkout');
    ($this->git)($this->work, 'checkout', '--quiet', 'develop');
    ($this->git)($this->work, 'branch', '--quiet', '-D', 'sami/checkout');

    app()->instance(LocalChanges::class, new LocalChanges($this->work));
    app()->instance(Workspace::class, new Workspace($this->work));
    app()->instance(BranchStatus::class, new BranchStatus(app(LocalChanges::class), app(Workspace::class)));
    app()->instance(TestSuite::class, new TestSuite($this->work));

    $this->ran = [];
    $this->cartFails = true;
    $this->suites = fn (): array => [
        'tests/Feature/CartTest.php' => [
            'suite' => '<testsuite name="Tests\Feature\CartTest" file="tests/Feature/CartTest.php" tests="3" assertions="3" errors="0" failures="'.($this->cartFails ? 1 : 0).'" skipped="0">
                <testcase name="it adds a line" file="tests/Feature/CartTest.php::it adds a line" assertions="1"/>
                <testcase name="it totals the cart" file="tests/Feature/CartTest.php::it totals the cart" assertions="1">'
                .($this->cartFails ? '<failure type="PHPUnit\Framework\ExpectationFailedException">Failed asserting that 10 is identical to 12.
            at tests/Feature/CartTest.php:14</failure>' : '').'
                </testcase>
                <testcase name="it empties" file="tests/Feature/CartTest.php::it empties" assertions="1"/>
              </testsuite>',
            'output' => $this->cartFails ? "FAILED  Tests\\Feature\\CartTest > it totals the cart\nTests:    1 failed, 2 passed (3 assertions)" : 'Tests:    3 passed (3 assertions)',
            'exit' => $this->cartFails ? 1 : 0,
        ],
        'tests/Unit/CartTotalTest.php' => [
            'suite' => '<testsuite name="Tests\Unit\CartTotalTest" file="tests/Unit/CartTotalTest.php" tests="2" assertions="2" errors="0" failures="0" skipped="0">
                <testcase name="it rounds" file="tests/Unit/CartTotalTest.php::it rounds" assertions="1"/>
                <testcase name="it adds tax" file="tests/Unit/CartTotalTest.php::it adds tax" assertions="1"/>
              </testsuite>',
            'output' => 'Tests:    2 passed (2 assertions)',
            'exit' => 0,
        ],
    ];
    Processes::fake(function (PendingProcess $process) {
        $command = (array) $process->command;
        $this->ran[] = $command;
        $file = ($this->suites)()[end($command)];
        file_put_contents($command[array_search('--log-junit', $command, true) + 1], '<?xml version="1.0" encoding="UTF-8"?><testsuites>'.$file['suite'].'</testsuites>');

        return Processes::result(output: $file['output'], exitCode: $file['exit']);
    });

    $this->state = 'requested';
    $this->workflow = fn (): array => [
        'workflow' => ['id' => '7', 'name' => 'Checkout redesign', 'status' => 'Paused', 'branch' => 'sami/checkout', 'url' => 'https://studio.test/workflow/7'],
        'tasks' => [
            ['id' => '41', 'title' => 'Cart tests', 'artisan' => 'Prover', 'status' => 'Done', 'summary' => '', 'files' => [['path' => 'tests/Feature/CartTest.php', 'kind' => 'create']], 'tests' => null],
            ['id' => '42', 'title' => 'Run the test suite and triage what fails', 'artisan' => 'Prover', 'status' => 'Open', 'summary' => '', 'files' => [],
                'waiting' => $this->state === 'requested', 'checkpoint' => $this->state === 'ready',
                'tests' => ['state' => $this->state, 'files' => ['tests/Feature/CartTest.php', 'tests/Unit/CartTotalTest.php']]],
        ],
    ];

    Saloon::fake([
        ShowSnapshotRequest::class => MockResponse::make(['project' => ['name' => 'Acme Shop'], 'workflows' => ['list' => [['id' => '7', 'name' => 'Checkout redesign', 'status' => 'Paused']]]]),
        ShowWorkflowRequest::class => fn (): MockResponse => MockResponse::make(($this->workflow)()),
        CheckoutRequest::class => MockResponse::make(['branch' => 'sami/checkout', 'remote' => $this->origin]),
        SubmitTestRunRequest::class => MockResponse::make(['run' => 9, 'passed' => false]),
    ]);

    $this->plain = fn (string $text): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $text);
    $this->command = app(StudioCommand::class);
    $this->command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    (fn (): string => $this->screenTab = 'workflows')->call($this->command);
    $this->press = fn (string ...$keys): mixed => collect($keys)->each(fn (string $key): mixed => (fn (): mixed => $this->handleScreenAction($this->screenActionFor($key)))->call($this->command));
    $this->studio = fn (): ScreenContainer => (fn (): ScreenContainer => $this->getScreen())->call($this->command);
    $this->screen = fn (): string => ($this->plain)(implode("\n", ($this->studio)()->lines(120, 50, 'workflows')));
    $this->flash = fn (): ?string => (fn (): ?string => $this->screenFlash)->call($this->command);
    $this->tick = fn (int $times = 1): ScreenContainer => tap(($this->studio)(), fn (ScreenContainer $screen) => collect(range(1, $times))->each(fn (): array => array_map(fn (RunsInBackground $runner) => $runner->tick(), $screen->runners())))->refreshState();
    $this->sent = fn (): ?array => collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response): mixed => $response->getPendingRequest())
        ->filter(fn (PendingRequest $request): bool => $request->getRequest() instanceof SubmitTestRunRequest)
        ->map(fn (PendingRequest $request): array => ['url' => $request->getUrl(), 'body' => $request->body()?->all()])
        ->first();
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->root);
});

it('runs the tests Prover wrote on the build\'s branch and sends the results back', function (): void {
    ($this->press)("\n", "\e[B", "\n");
    $task = ($this->screen)();
    ($this->press)("\n");
    $panel = ($this->screen)();
    ($this->press)("\n");
    $started = ($this->flash)();
    ($this->tick)(2);
    $red = ($this->screen)();
    $sentWhileRed = ($this->sent)();
    ($this->press)("\e[B", "\n");
    ($this->studio)()->refreshState();
    $finished = ($this->screen)();
    $sent = ($this->sent)();

    expect($task)->toContain('Run the test suite')->toContain('ready to run on your machine')->toMatch('/⏎ Run tests\s+View workflow ↗/u')
        ->and($panel)->toContain('Run tests · Checkout redesign')->toContain('Prover wrote 2 test files')
        ->toContain('Running them switches you from develop to sami/checkout.')
        ->toContain('tests/Feature/CartTest.php')->toContain('tests/Unit/CartTotalTest.php')
        ->toMatch('/⏎ Switch to sami\/checkout and run tests\s+esc  Not now/u')
        ->and($started)->toBe('Running the tests on sami/checkout.')
        ->and(($this->git)($this->work, 'rev-parse', '--abbrev-ref', 'HEAD'))->toBe('sami/checkout')
        ->and($this->ran)->toBe([
            ['php', 'artisan', 'test', '--without-tty', '--compact', '--log-junit', $this->ran[0][6], 'tests/Feature/CartTest.php'],
            ['php', 'artisan', 'test', '--without-tty', '--compact', '--log-junit', $this->ran[1][6], 'tests/Unit/CartTotalTest.php'],
        ])
        ->and($sentWhileRed)->toBeNull()
        ->and($red)->toContain('Test run 1 · 4 passed · 1 failed')->toContain('✗  tests/Feature/CartTest.php')->toContain('✓  tests/Unit/CartTotalTest.php')
        ->toContain('it totals the cart: Failed asserting that 10 is identical to 12.')
        ->toContain('Fix them in your editor, then run them again.')
        ->toMatch('/Run again.*Send as failing/su')
        ->and($sent['url'])->toEndWith('/workflows/7/tasks/42/tests')
        ->and($sent['body'])->toMatchArray([
            'passed' => false,
            'results' => [
                ['file' => 'tests/Feature/CartTest.php', 'passed' => false, 'summary' => 'it totals the cart: Failed asserting that 10 is identical to 12.'],
                ['file' => 'tests/Unit/CartTotalTest.php', 'passed' => true, 'summary' => null],
            ],
            'cases' => ['passed' => 4, 'failed' => 1],
        ])
        ->and($sent['body']['output'])->toContain('1 failed, 2 passed')->toContain('2 passed (2 assertions)')
        ->and($finished)->toContain('Sent to the studio. Guard checks the build next.')
        ->and(file_exists($this->ran[0][6]))->toBeFalse()
        ->and(file_exists($this->ran[1][6]))->toBeFalse()
        ->and(collect(app(ActivityLog::class)->entries('7'))->pluck('label')->all())->toBe(['Tests failed', 'Running the tests']);
});

it('shows what Pest said when it stopped before running any test', function (): void {
    Processes::fake(fn (): mixed => Processes::result(output: "PHPUnit 13.3.0 by Sebastian Bergmann and contributors.\n\e[31mUnknown option \"--nope\".\e[0m", exitCode: 1));
    ($this->press)("\n", "\e[B", "\n", "\n", "\n");
    ($this->tick)(2);
    $stopped = ($this->screen)();

    expect($stopped)->toContain('Test run 1 · did not run')
        ->toContain('Pest stopped before running a single test.')
        ->toContain('Unknown option "--nope".')
        ->not->toContain('0 passed · 0 failed')
        ->not->toContain('Fix them in your editor')
        ->toMatch('/Run again.*Send as failing/su')
        ->and(($this->sent)())->toBeNull();
});

it('will not run tests that would use the developer\'s own database', function (): void {
    ($this->write)('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="mysql"/><env name="DB_DATABASE" value="shop"/></php></phpunit>');
    ($this->git)($this->work, 'commit', '--quiet', '-am', 'chore: tests on the shop database');
    ($this->press)("\n", "\e[B", "\n");
    $task = ($this->screen)();
    ($this->press)("\n");
    $panel = ($this->screen)();

    expect($panel)->toContain('Your tests would run against your own database')
        ->not->toContain('Switch to sami/checkout and run tests')
        ->and($task)->toContain('⏎ Run tests')
        ->and($this->ran)->toBe([])
        ->and(($this->git)($this->work, 'rev-parse', '--abbrev-ref', 'HEAD'))->toBe('develop');
});

it('waits for Run in my terminal in the studio before offering to run them', function (): void {
    $this->state = 'ready';
    ($this->press)("\n", "\e[B", "\n");
    $task = ($this->screen)();
    ($this->press)("\n");

    expect($task)->toContain('● Checkpoint')->toContain('Choose Run in my terminal in the studio')
        ->not->toContain('⏎ Run tests')
        ->and(($this->studio)()->settingsAreOpen())->toBeFalse()
        ->and($this->ran)->toBe([]);
});

it('shows a spinner on the file that is running, and each file as it finishes', function (): void {
    Processes::fake(function (PendingProcess $process) {
        $command = (array) $process->command;
        $file = ($this->suites)()[end($command)];
        file_put_contents($command[array_search('--log-junit', $command, true) + 1], '<?xml version="1.0" encoding="UTF-8"?><testsuites>'.$file['suite'].'</testsuites>');

        return Processes::describe()->output($file['output'])->exitCode($file['exit'])->iterations(2);
    });

    ($this->press)("\n", "\e[B", "\n", "\n", "\n");
    ($this->studio)()->refreshState();
    $started = ($this->screen)();
    ($this->tick)();
    $turning = ($this->screen)();
    ($this->tick)(2);
    $halfway = ($this->screen)();

    expect($started)->toContain('Test run 1 · 0 of 2 files')->toContain('⠋  tests/Feature/CartTest.php')->toContain('·  tests/Unit/CartTotalTest.php')
        ->and($turning)->toContain('⠙  tests/Feature/CartTest.php')
        ->and($halfway)->toContain('Test run 1 · 1 of 2 files')->toContain('✗  tests/Feature/CartTest.php')
        ->toContain('it totals the cart: Failed asserting that 10 is identical to 12.')
        ->toMatch('/[⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏]  tests\/Unit\/CartTotalTest\.php/u');
});

it('saves the developer\'s fix with a reason that wraps, scoped like the build and not like a merge', function (): void {
    $this->cartFails = false;
    ($this->git)($this->work, 'checkout', '--quiet', '-b', 'sami/checkout', 'origin/sami/checkout');
    ($this->git)($this->work, 'checkout', '--quiet', '-b', 'side');
    ($this->write)('README.md', "shop\nside\n");
    ($this->git)($this->work, 'commit', '--quiet', '-am', 'docs: a note from develop');
    ($this->git)($this->work, 'checkout', '--quiet', 'sami/checkout');
    ($this->git)($this->work, 'merge', '--quiet', '--no-ff', '-m', 'chore: bring develop into the build branch', 'side');
    ($this->git)($this->work, 'push', '--quiet', 'origin', 'sami/checkout');
    ($this->git)($this->work, 'checkout', '--quiet', 'develop');
    ($this->git)($this->work, 'branch', '--quiet', '-D', 'sami/checkout', 'side');
    $why = 'The cart total test expected tax on shipping, which the brief never asked for, so I changed the expectation to the subtotal plus tax and left the code alone.';

    ($this->press)("\n", "\e[B", "\n", "\n", "\n");
    ($this->write)('tests/Feature/CartTest.php', "<?php\n\nit('totals the cart');\n");
    ($this->tick)(2);
    $green = ($this->screen)();
    ($this->press)("\n", "\e[200~{$why}\e[201~");
    $typing = ($this->screen)();
    ($this->press)("\n");

    expect($green)->toContain('Test run 1 · 5 passed · 0 failed')->toContain('Save changes')
        ->and($typing)->toContain('How did you get them passing?')->toContain('› The cart total test expected tax on shipping')->toContain('left the code alone.')
        ->and(($this->git)($this->work, 'log', '-1', '--format=%s'))->toBe('fix(cart): developer got the tests passing for "Checkout redesign"')
        ->and(($this->git)($this->work, 'log', '-1', '--format=%b'))->toContain("Reason for change:\n{$why}")
        ->and(($this->sent)()['body'])->toMatchArray(['passed' => true, 'files' => [['path' => 'tests/Feature/CartTest.php', 'status' => 'modified']]])
        ->and(($this->sent)()['body']['commit'])->toBe(($this->git)($this->work, 'rev-parse', 'HEAD'));
});
