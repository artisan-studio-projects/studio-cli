<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BranchStatus;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Dashboard\SampleSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Editor;
use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\Saloon\Requests\CheckoutRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\FinishTaskReviewRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowSnapshotRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowWorkflowRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\StartTaskReviewRequest;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
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

/*
|--------------------------------------------------------------------------
| Reviewing one task at a time
|--------------------------------------------------------------------------
|
| The studio holds an artisan once, at the end of its slice. Here every task
| it held is reviewed on its own: started on the build's branch, sent back
| with the developer's edits, or passed as good without a look.
|
*/

beforeEach(function (): void {
    app()->forgetInstance(SnapshotSource::class);
    $this->root = sys_get_temp_dir().'/studio-review-'.uniqid();
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
    ($this->git)($this->work, 'add', '--all');
    ($this->git)($this->work, 'commit', '--quiet', '-m', 'feat(shop): start');
    ($this->git)($this->work, 'push', '--quiet', 'origin', 'develop');
    ($this->git)($this->work, 'checkout', '--quiet', '-b', 'sami/checkout');
    ($this->write)('database/migrations/2026_09_27_000000_create_carts_table.php', "<?php\n");
    ($this->write)('resources/views/cart.blade.php', "<div>cart</div>\n");
    ($this->git)($this->work, 'add', '--all');
    ($this->git)($this->work, 'commit', '--quiet', '-m', 'feat(cart): the cart page');
    ($this->git)($this->work, 'push', '--quiet', 'origin', 'sami/checkout');
    ($this->git)($this->work, 'checkout', '--quiet', 'develop');
    ($this->git)($this->work, 'branch', '--quiet', '-D', 'sami/checkout');

    app()->instance(LocalChanges::class, new LocalChanges($this->work));
    app()->instance(Workspace::class, new Workspace($this->work));
    app()->instance(BranchStatus::class, new BranchStatus(app(LocalChanges::class), app(Workspace::class)));
    app()->instance(Editor::class, new Editor($this->work));
    $this->terminal = [getenv('TERMINAL_EMULATOR'), getenv('TERM_PROGRAM')];
    putenv('TERMINAL_EMULATOR');
    putenv('TERM_PROGRAM');
    Processes::fake();

    $this->reviews = [];
    $this->released = false;
    $this->reviewPressed = true;
    $this->workflow = fn (): array => [
        'workflow' => ['id' => '7', 'name' => 'Checkout redesign', 'status' => 'Paused', 'branch' => 'sami/checkout', 'url' => 'https://studio.test/workflow/7'],
        'tasks' => collect([
            ['id' => '31', 'title' => 'Cart model', 'artisan' => 'Mason', 'held' => false, 'status' => 'Done'],
            ['id' => '32', 'title' => 'Cart page', 'artisan' => 'Pixel', 'held' => true, 'status' => 'Review', 'files' => [
                ['path' => 'resources/views/cart.blade.php', 'kind' => 'modify'],
                ['path' => 'app/Models/Cart.php', 'kind' => 'create'],
                ['path' => 'README.md', 'kind' => 'read'],
            ]],
            ['id' => '33', 'title' => 'Cart totals', 'artisan' => 'Pixel', 'held' => true, 'status' => 'Review'],
            ['id' => '34', 'title' => 'Cart tests', 'artisan' => 'Prover', 'held' => false, 'status' => 'Open'],
        ])->map(fn (array $task): array => [
            ...$task,
            'status' => $task['held'] && $this->released ? 'Done' : $task['status'],
            'waiting' => $task['held'] && ! $this->released && $this->reviewPressed,
            'checkpoint' => $task['held'] && ! $this->released && ! $this->reviewPressed,
            'review' => $task['held'] && ! $this->released ? ($this->reviews[$task['id']] ?? null) : null,
            'summary' => '',
            'files' => $task['files'] ?? [],
        ])->all(),
    ];
    $this->taskIn = fn (PendingRequest $request): string => (string) preg_replace('#^.*/tasks/(\d+)/review.*$#', '$1', $request->getUrl());

    Saloon::fake([
        ShowSnapshotRequest::class => MockResponse::make(['project' => ['name' => 'Acme Shop'], 'workflows' => ['list' => [['id' => '7', 'name' => 'Checkout redesign', 'status' => 'Paused']]]]),
        ShowWorkflowRequest::class => fn (): MockResponse => MockResponse::make(($this->workflow)()),
        CheckoutRequest::class => MockResponse::make(['branch' => 'sami/checkout', 'remote' => $this->origin]),
        StartTaskReviewRequest::class => function (PendingRequest $request): MockResponse {
            $this->reviews[($this->taskIn)($request)] = 'reviewing';

            return MockResponse::make(['review' => 1, 'state' => 'reviewing']);
        },
        FinishTaskReviewRequest::class => function (PendingRequest $request): MockResponse {
            $this->reviews[($this->taskIn)($request)] = 'reviewed';
            $this->released = ($this->reviews['32'] ?? null) === 'reviewed' && ($this->reviews['33'] ?? null) === 'reviewed';

            return MockResponse::make(['review' => 2, 'released' => $this->released]);
        },
    ]);

    $this->plain = fn (string $text): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $text);
    $this->command = app(StudioCommand::class);
    $this->command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    (fn (): string => $this->screenTab = 'workflows')->call($this->command);
    $this->press = fn (string ...$keys): mixed => collect($keys)->each(fn (string $key): mixed => (fn (): mixed => $this->handleScreenAction($this->screenActionFor($key)))->call($this->command));
    $this->studio = fn (): ScreenContainer => (fn (): ScreenContainer => $this->getScreen())->call($this->command);
    $this->screen = fn (): string => ($this->plain)(implode("\n", ($this->studio)()->lines(120, 50, 'workflows')));
    $this->flash = fn (): ?string => (fn (): ?string => $this->screenFlash)->call($this->command);
    $this->finished = fn (string $task): ?array => collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response): mixed => $response->getPendingRequest())
        ->filter(fn (PendingRequest $request): bool => $request->getRequest() instanceof FinishTaskReviewRequest && ($this->taskIn)($request) === $task)
        ->map(fn (PendingRequest $request): mixed => $request->body()?->all())
        ->first();
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->root);
    collect(['TERMINAL_EMULATOR', 'TERM_PROGRAM'])->each(fn (string $name, int $index): bool => putenv($this->terminal[$index] === false ? $name : "{$name}={$this->terminal[$index]}"));
});

it('offers Start review beside the task\'s title, opens it with a click, and Esc closes it again', function (): void {
    ($this->press)("\n", "\e[B", "\n");
    $task = ($this->screen)();
    $lines = ($this->studio)()->lines(120, 50, 'workflows');
    $row = (int) collect($lines)->search(fn (string $line): bool => str_contains(($this->plain)($line), 'Start review')) + 1;
    (fn (): array => $this->screenLines = $lines)->call($this->command);
    (fn (): int => $this->screenWidthNow = 120)->call($this->command);
    ($this->press)("\e[<0;".(mb_strpos(($this->plain)($lines[$row - 1]), 'Start review') + 1).";{$row}M");
    $review = ($this->screen)();
    ($this->press)("\e");

    expect($task)->toContain('Cart page')->toContain('● Waiting for you')->toMatch('/⏎ Start review\s+View workflow ↗/u')
        ->not->toContain('⏎  Review')->not->toContain('Press Enter')
        ->and($review)->toContain('Review · Cart page')
        ->toContain('Pixel finished this and is waiting for you. 1 more of Pixel\'s is waiting too.')
        ->toMatch('/switches you from develop to sami\/checkout\.\s*\n\s+Close your editor tabs first, then open the 2 files this task changed:/')
        ->toContain('app/Models/Cart.php')->not->toContain('README.md')
        ->not->toContain('Looks good')->not->toContain('Choose')->not->toContain('Select')
        ->toMatch('/⏎ Switch to sami\/checkout and start\s+esc  Not now/u')
        ->and(($this->studio)()->settingsAreOpen())->toBeFalse()
        ->and(($this->studio)()->tab('workflows')->depth())->toBe(2);
});

it('switches to the branch to start, opens what the task changed in the editor, then sends the developer\'s edits back with why, and nothing for the next artisan', function (): void {
    config(['studio-cli.review.editor' => 'phpstorm']);
    $opened = fn (string $path): Closure => fn (PendingProcess $process): bool => end($process->command) === 'phpstorm://open?file='.implode('/', array_map(rawurlencode(...), explode('/', $this->work.'/'.$path)));
    ($this->press)("\n", "\e[B", "\n", "\n");
    $asked = ($this->screen)();
    ($this->press)("\n");
    $started = ($this->screen)();
    $startedFlash = ($this->flash)();

    ($this->write)('resources/views/cart.blade.php', "<div class=\"cart\">cart</div>\n<p>total</p>\n");
    ($this->write)('resources/views/partials/line.blade.php', "<li>line</li>\n");
    ($this->studio)()->refreshState();
    $edited = ($this->screen)();

    ($this->press)("\e");
    $later = ($this->screen)();
    $laterFlash = ($this->flash)();
    ($this->press)("\n");
    $drawn = ($this->studio)()->lines(120, 50, 'workflows');
    $lines = array_map($this->plain, $drawn);
    (fn (): array => $this->screenLines = $drawn)->call($this->command);
    (fn (): int => $this->screenWidthNow = 120)->call($this->command);
    $footer = (int) collect($lines)->search(fn (string $line): bool => str_contains($line, 'Save changes'));
    ($this->press)("\e[<0;".(mb_strpos($lines[$footer], 'Save changes') + 1).';'.($footer + 1).'M');
    $asking = ($this->screen)();
    ($this->press)('The cart needed a class', "\n");

    expect($asked)->toContain('Close your editor tabs first. The review then opens just the 2 files this task changed, in PhpStorm.')
        ->toContain('⏎ Switch to sami/checkout and start')
        ->and($startedFlash)->toBe('On sami/checkout. 1 migration landed: run php artisan migrate before you try it. Opened its 2 files in PhpStorm.')
        ->and($started)->not->toContain('Close your editor tabs first')->not->toContain('Cancel')
        ->and($started)->toContain('Nothing changed yet.')->toContain('Reviewing on sami/checkout.')->toContain('⏎ Looks good')
        ->and($this->reviews['32'])->toBe('reviewed')
        ->and($edited)->toContain('Your edits')->toContain('2 files')
        ->toContain('● Changed')->toContain('resources/views/cart.blade.php')->toContain('+2')->toContain('−1')
        ->toContain('● Added')->toContain('resources/views/partials/line.blade.php')
        ->not->toContain('▸ Save changes')->not->toContain('Looks good')->not->toContain('Open its files again')->not->toContain('Choose')
        ->and($edited)->toMatch('/Your edits\s+2 files.*⏎ Save changes\s+esc  Not now/su')
        ->and($later)->toContain('● In review')->toContain('You are reviewing this.')->toContain('⏎ Finish review')
        ->and($laterFlash)->toBe($startedFlash)
        ->and($asking)->toContain('Why did you change it?')->not->toContain('next artisan')
        ->and(($this->flash)())->toBe('Saved and sent to the studio. 1 more of Pixel\'s waits for you.')
        ->and(($this->studio)()->settingsAreOpen())->toBeFalse()
        ->and(($this->studio)()->tab('workflows')->depth())->toBe(1)
        ->and(($this->screen)())->toContain('● Reviewed')->toContain('Cart totals');

    $sent = ($this->finished)('32');
    $pushed = ($this->git)($this->work, '--git-dir='.$this->origin, 'log', '-1', '--format=%B', 'sami/checkout');

    expect($sent)->toMatchArray([
        'outcome' => 'updated',
        'note' => 'The cart needed a class',
        'commit' => ($this->git)($this->work, 'rev-parse', 'HEAD'),
    ])
        ->and($sent)->not->toHaveKey('forward')
        ->and(collect($sent['files'])->sortBy('path')->values()->all())->toBe([
            ['path' => 'resources/views/cart.blade.php', 'status' => 'modified'],
            ['path' => 'resources/views/partials/line.blade.php', 'status' => 'added'],
        ])
        ->and($pushed)->toBe("fix(cart): developer review of \"Cart page\" by @pixel\n\nReason for change:\nThe cart needed a class")
        ->and(collect(app(ActivityLog::class)->entries('7'))->pluck('label', 'detail')->all())->toBe([
            'Cart page · The cart needed a class' => 'Saved changes',
        ]);

    Processes::assertRanTimes($opened('resources/views/cart.blade.php'), 1);
    Processes::assertRanTimes($opened('app/Models/Cart.php'), 1);
    Processes::assertNotRan($opened('README.md'));
});

it('passes a task as good on its branch without starting a review, back to the tasks, and the last one lets the build carry on', function (): void {
    ($this->git)($this->work, 'checkout', '--quiet', 'sami/checkout');
    ($this->press)("\n", "\e[B", "\n", "\n", "\e[B", "\n");
    $first = ($this->flash)();
    $closed = ! ($this->studio)()->settingsAreOpen();
    $tasks = ($this->screen)();
    $depth = ($this->studio)()->tab('workflows')->depth();
    ($this->press)("\e[B", "\n", "\n");
    $second = ($this->screen)();
    ($this->press)("\e[B", "\n");

    expect($first)->toBe('Marked as good. 1 more of Pixel\'s waits for you.')
        ->and($closed)->toBeTrue()
        ->and($depth)->toBe(1)
        ->and($tasks)->toContain('Cart page')->toContain('● Reviewed')->toContain('Cart totals')->toContain('● Waiting for you')
        ->and(($this->studio)()->tab('workflows')->depth())->toBe(1)
        ->and(($this->finished)('32'))->toBe(['outcome' => 'accepted'])
        ->and($second)->toContain('Review · Cart totals')
        ->and(($this->flash)())->toBe('Marked as good. That was the last of Pixel\'s, so the build carries on.');

    Saloon::assertNotSent(StartTaskReviewRequest::class);
});

it('passes only the task on screen, with no way to wave the rest of the artisan\'s through', function (): void {
    ($this->git)($this->work, 'checkout', '--quiet', 'sami/checkout');
    ($this->press)("\n", "\e[B", "\n", "\n");
    $review = ($this->screen)();
    ($this->press)("\e[B", "\n");

    expect($review)->not->toContain('look good')
        ->and(($this->finished)('32'))->toBe(['outcome' => 'accepted'])
        ->and(($this->finished)('33'))->toBeNull()
        ->and(($this->flash)())->toBe('Marked as good. 1 more of Pixel\'s waits for you.');
});

it('will not switch over uncommitted changes, and says what to do instead', function (): void {
    ($this->write)('notes.txt', "mine\n");
    ($this->press)("\n", "\e[B", "\n", "\n");
    $review = ($this->screen)();
    ($this->press)("\e");

    expect($review)->toContain('You are on develop with 1 uncommitted change. Commit or stash them first')
        ->not->toContain('Switch to sami/checkout')->not->toContain('Looks good')->not->toContain('Start review')
        ->and(($this->studio)()->settingsAreOpen())->toBeFalse()
        ->and(($this->flash)())->toBeNull()
        ->and(($this->git)($this->work, 'rev-parse', '--abbrev-ref', 'HEAD'))->toBe('develop');

    Saloon::assertNotSent(FinishTaskReviewRequest::class);
});

it('holds a finished task at its checkpoint, offering no review until Review is pressed in the studio', function (): void {
    $this->reviewPressed = false;
    ($this->press)("\n");
    $tasks = ($this->screen)();
    ($this->press)("\e[B", "\n");
    $task = ($this->screen)();
    ($this->press)("\n");

    expect($tasks)->toContain('● Checkpoint')->not->toContain('Waiting for you')
        ->and($task)->toContain('Pixel has finished. Press Review in the studio to go through it here, or Continue to carry on.')
        ->not->toContain('Start review')
        ->and(($this->studio)()->settingsAreOpen())->toBeFalse();
});

it('offers no review of made-up tasks from --demo', function (): void {
    app()->instance(SnapshotSource::class, new SampleSnapshots);
    ($this->press)("\n", "\e[B", "\n", "\n");

    expect(($this->screen)())->toContain('● Waiting for you')->not->toContain('Start review')
        ->and(($this->studio)()->settingsAreOpen())->toBeFalse();
});

it('sets aside a local branch left over from an earlier run and reviews the one the studio built', function (): void {
    ($this->git)($this->work, 'checkout', '--quiet', '-b', 'sami/checkout');
    ($this->write)('README.md', "shop, from last night\n");
    ($this->git)($this->work, 'commit', '--quiet', '-am', 'feat(cart): an earlier run');
    $earlier = ($this->git)($this->work, 'rev-parse', '--short=7', 'HEAD');
    ($this->git)($this->work, 'checkout', '--quiet', 'develop');

    ($this->press)("\n", "\e[B", "\n", "\n", "\n");

    expect(($this->flash)())->toStartWith("On sami/checkout. Your local sami/checkout was from an earlier run, so it is kept as sami/checkout-earlier-{$earlier}.")
        ->and(($this->git)($this->work, 'rev-parse', '--abbrev-ref', 'HEAD'))->toBe('sami/checkout')
        ->and(($this->git)($this->work, 'rev-parse', 'HEAD'))->toBe(($this->git)($this->root, '--git-dir='.$this->origin, 'rev-parse', 'sami/checkout'))
        ->and(($this->git)($this->work, 'log', '-1', '--format=%s', "sami/checkout-earlier-{$earlier}"))->toBe('feat(cart): an earlier run');
});

it('sets aside the earlier run even when the developer is already on it', function (): void {
    ($this->git)($this->work, 'checkout', '--quiet', '-b', 'sami/checkout');
    ($this->write)('README.md', "shop, from last night\n");
    ($this->git)($this->work, 'commit', '--quiet', '-am', 'feat(cart): an earlier run');

    ($this->press)("\n", "\e[B", "\n", "\n", "\n");

    expect(($this->flash)())->toContain('was from an earlier run')
        ->and(($this->git)($this->work, 'rev-parse', '--abbrev-ref', 'HEAD'))->toBe('sami/checkout')
        ->and(($this->git)($this->work, 'log', '-1', '--format=%s'))->toBe('feat(cart): the cart page');
});

it('keeps a local branch that only has the developer\'s own commits on top', function (): void {
    ($this->git)($this->work, 'fetch', '--quiet', 'origin');
    ($this->git)($this->work, 'checkout', '--quiet', '-b', 'sami/checkout', '--track', 'origin/sami/checkout');
    ($this->write)('README.md', "shop, with my fix\n");
    ($this->git)($this->work, 'commit', '--quiet', '-am', 'fix(cart): mine');
    ($this->git)($this->work, 'checkout', '--quiet', 'develop');

    ($this->press)("\n", "\e[B", "\n", "\n", "\n");

    expect(($this->flash)())->not->toContain('earlier run')
        ->and(($this->git)($this->work, 'rev-parse', '--abbrev-ref', 'HEAD'))->toBe('sami/checkout')
        ->and(($this->git)($this->work, 'log', '-1', '--format=%s'))->toBe('fix(cart): mine')
        ->and(($this->git)($this->work, 'branch', '--list', 'sami/checkout-earlier-*'))->toBe('');
});
