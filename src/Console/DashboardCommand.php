<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Concerns\OpensInStudio;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\NotConnected;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Fix\FixLauncher;
use ArtisanStudio\StudioCli\Fix\FixPanel;
use ArtisanStudio\StudioCli\Fix\FixProgress;
use ArtisanStudio\StudioCli\Scan\ScanProgress;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Terminal\Components\Alert;
use ArtisanStudio\StudioCli\Terminal\Components\Card;
use ArtisanStudio\StudioCli\Terminal\Components\Grid;
use ArtisanStudio\StudioCli\Terminal\Components\Progress;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Tab;
use ArtisanStudio\StudioCli\TestRun;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DashboardCommand extends Command implements ProvidesTab
{
    use OpensInStudio;

    private const string INSTALLING = 'install-tools';

    private const string BEST_PRACTICES = 'Best practices';

    private const string ADVISORY = 'Advisory only';

    private const string DETECTION = 'Detection stage';

    /**
     * @var list<string>
     */
    private array $fixesAsked = [];

    private const string QUEUED = 'queued';

    /**
     * @var array<string, array{title: string, line: string, colour: string, mark: string}>
     */
    public const array SAMI_SAYS = [
        'before' => ['title' => "Heads up! Here's what's about to happen once you have authorized your scan.", 'line' => "I'll read your files once, just looking, never touching. Your code stays right here with you. Passing tests boost your score, so only opt out in the app if you don't care about testing right now!", 'colour' => 'sky'],
        'almost' => ['title' => 'Almost ready.', 'line' => "Please confirm below by simply pressing enter. I'll take care of the rest, then your scan will begin.", 'colour' => 'sky'],
        'rules-found' => ['title' => 'So I ran the additional tools you selected.', 'line' => 'A few things came up, so we will tackle these together soon.', 'colour' => 'sky'],
        'rules-clean' => ['title' => 'WOW, impressive, all checks passed!', 'line' => 'I challenge you to change PHPStan to the max and try again!', 'colour' => 'green'],
        'rules-clean-plain' => ['title' => 'WOW, impressive, all checks passed!', 'line' => "Nothing to fix in the rules you picked. We're only just getting started!", 'colour' => 'green'],
        'extras' => ['title' => 'All ready to go with the extra rules.', 'line' => "Scanning now. Let's hope this makes a positive impact on your score!", 'colour' => 'sky'],
        'phpstan-behind' => ['title' => "Your findings are in. PHPStan's still reading.", 'line' => 'PHPStan reads every file, so I run it last, in the background. Carry on, your score updates the moment it lands!', 'colour' => 'sky'],
        'excluded' => ['title' => "I've set aside findings from outdated packages.", 'line' => "Right now they're just noise until you update them. See what's excluded and why below.", 'colour' => 'sky'],
        'learned' => ['title' => 'Knowledge is power!', 'line' => "WOW, I have learned a lot about your application. Can't wait to get started on this!", 'colour' => 'sky'],
        'passing' => ['title' => 'WOW, OK, a 100% pass rate on your tests!', 'line' => 'Do you even need me? lol. Of course you do, we are only just getting started!', 'colour' => 'green'],
        'so-close' => ['title' => 'Oh so close!', 'line' => "We both know that's an easy fix, and a very small, temporary reduction to your project's health!", 'colour' => 'amber'],
        'failing' => ['title' => "OK, so we're getting a fair few failures here.", 'line' => "Don't panic, we can fix these together after your scan!", 'colour' => 'amber'],
    ];

    private const int SO_CLOSE = 3;

    protected $signature = 'studio:dashboard';

    protected $description = 'Open Artisan Studio on the Dashboard tab';

    public function tab(Tab $tab): Tab
    {
        return $tab->label('Dashboard')
            ->state(fn (SnapshotSource $source): DashboardSnapshot => $source->snapshot())
            ->unavailable(fn (NotConnected $notConnected): array => $notConnected->panel())
            ->components([
                Grid::make([
                    Card::make('Health')
                        ->icon('💙', '♥')
                        ->colour(fn (DashboardSnapshot $data): string => $data->healthColour())
                        ->value(fn (DashboardSnapshot $data): string => $data->healthValue())
                        ->description(fn (DashboardSnapshot $data): string => $data->healthLabel)
                        ->descriptionColour(fn (DashboardSnapshot $data): string => $data->healthColour()),
                    Card::make('Deliverables')
                        ->icon('📦', '◆')
                        ->colour('cyan')
                        ->value(fn (DashboardSnapshot $data): string => "{$data->deliverablesPercent}%")
                        ->description(fn (DashboardSnapshot $data): string => "{$data->deliverablesDone} of {$data->deliverablesTotal} done"),
                    Card::make('Workflows')
                        ->icon('⚡', '▶')
                        ->colour('blue')
                        ->value(fn (DashboardSnapshot $data): string => (string) $data->workflowsRunning)
                        ->description(fn (DashboardSnapshot $data): string => "running · {$data->workflowsDone}/{$data->workflowsTotal} done")
                        ->descriptionColour('blue'),
                    Card::make('Credits')
                        ->icon('🪙', '●')
                        ->colour('amber')
                        ->value(fn (DashboardSnapshot $data): string => number_format($data->credits))
                        ->description('credits left'),
                ]),
                Text::make(fn (DashboardSnapshot $data): string => $data->hasScanned() ? $this->testsLine()['text'] : '')
                    ->colour(fn (): string => $this->testsLine()['colour'])
                    ->wrap()
                    ->center()
                    ->tight(),
                Alert::make(fn (DashboardSnapshot $data): string => self::SAMI_SAYS[$this->samiSays($data)]['title'] ?? '')
                    ->colour(fn (DashboardSnapshot $data): string => self::SAMI_SAYS[$this->samiSays($data)]['colour'] ?? 'sky')
                    ->description(fn (DashboardSnapshot $data): string => self::SAMI_SAYS[$this->samiSays($data)]['line'] ?? '')
                    ->descriptionColour('soft'),
                Text::make(fn (DashboardSnapshot $data): string => $this->samiSays($data) === 'excluded' ? $this->excludedLine() : '')
                    ->colour('soft')
                    ->wrap(),
                Progress::make(fn (DashboardSnapshot $data): string => $this->happeningNow($data))
                    ->value(fn (DashboardSnapshot $data): float => app(ScanProgress::class)->fraction() ?? (app(FixLauncher::class)->isRunning() ? app(FixProgress::class)->fraction() : null) ?? $this->toolsFraction() ?? $data->scanFraction()),
                Grid::of(fn (?DashboardSnapshot $data): array => $data === null ? [] : ($data->hasScanned() || $this->isScanning() ? [...$this->fixCards($data), ...$this->scanCards()] : $this->waitingCards()))->columns(4),
            ])
            ->entersBy(
                fn (mixed $data): bool => $data instanceof DashboardSnapshot && $data->hasScanned() && ($this->canInstall() || $this->canFix($data)),
                fn (): string => $this->canInstall() ? $this->installMissing() : $this->startFixing(),
                fn (): string => $this->canInstall() ? 'Install' : 'Fix with SAMI',
            );
    }

    private function canInstall(): bool
    {
        return app(ToolStatus::class)->missing() !== [] && ! app(BackgroundTasks::class)->isRunning(self::INSTALLING);
    }

    private function canFix(DashboardSnapshot $data): bool
    {
        $this->fixesAsked = FixPanel::insights()->asked($data->fixesAsked);

        return app(FixLauncher::class)->canStart($this->fixesAsked);
    }

    /**
     * One fix path at a time: the everyday rules first, then static analysis.
     *
     * @return list<Card>
     */
    private function fixCards(DashboardSnapshot $data): array
    {
        $this->fixesAsked = FixPanel::insights()->asked($data->fixesAsked);
        $names = app(FixLauncher::class)->names($this->fixesAsked);

        return match (true) {
            app(FixLauncher::class)->isRunning() => [$this->spinningCard('Fix with SAMI', 'cyan', 'Fixing…', 'on a safety branch')],
            $names === '' => [],
            default => [Card::make('Fix with SAMI')
                ->icon('🔧', '⚒')
                ->colour('cyan')
                ->value($names)
                ->description('⏎ or click to fix')
                ->descriptionColour('cyan')
                ->action('row:enter')],
        };
    }

    private function startFixing(): string
    {
        return app(FixLauncher::class)->start($this->fixesAsked);
    }

    private function happeningNow(DashboardSnapshot $data): string
    {
        $tasks = app(BackgroundTasks::class);
        $walk = app(ScanProgress::class)->read();

        return match (true) {
            $walk !== null => sprintf('Reading %s of %s files on your machine: conventions, best practices, security patterns and your tests', number_format($walk['done']), number_format($walk['total'])),
            $tasks->isRunning('blueprint') || $tasks->isRunning('conventions') => 'Mapping your models and counting your conventions…',
            $tasks->isRunning(self::INSTALLING) => 'Installing the checking tools you switched on…',
            $tasks->isRunning(FixLauncher::TASK) => 'SAMI is fixing your rules on a safety branch…',
            $tasks->isRunning('tools') && $this->toolsFraction() !== null => sprintf('Running your checking tools, read-only: %d of %d done…', count(app(ToolStatus::class)->run()['done'] ?? []), count(app(ToolStatus::class)->run()['tools'] ?? [])),
            $tasks->isRunning('tools') => 'Running your checking tools, read-only…',
            $tasks->isRunning(PhpStanCommand::PHPSTAN) => 'PHPStan is reading every file, last and in the background. Your other findings are already in…',
            $data->scanFilesTotal > 0 => sprintf('Read %s files on your machine: conventions, best practices, security patterns and your tests', number_format($data->scanFilesTotal)).($data->scannedAgo === null ? '' : " · {$data->scannedAgo}"),
            default => $data->scanLabel(),
        };
    }

    private function samiSays(DashboardSnapshot $data): string
    {
        $status = app(ToolStatus::class);
        $tasks = app(BackgroundTasks::class);
        $tests = $status->tests();
        $ran = ($tests['state'] ?? null) === ToolStatus::RAN;
        $failed = (int) ($tests['failed'] ?? 0);
        $picked = $status->picked() !== [];
        $outcomes = collect($status->picked())->map(fn (string $tool): ?array => $status->outcome($tool));
        $ranPicked = $picked && ! $outcomes->contains(null);

        return match (true) {
            ! $data->hasScanned() && ! $this->isScanning() => 'before',
            app(ScanProgress::class)->read() !== null || ! $data->healthScored => '',
            $picked && ($tasks->isRunning(self::INSTALLING) || $tasks->isRunning('tools')) => 'extras',
            $status->isBehind(PhpStanCommand::PHPSTAN) !== null => 'phpstan-behind',
            ! $ran && $status->excludedPackages() !== [] => 'excluded',
            $picked && $status->missing() !== [] => 'almost',
            $ranPicked && $outcomes->contains(fn (array $outcome): bool => ! $outcome['ran'] || $outcome['findings'] > 0) => 'rules-found',
            $ranPicked && in_array('phpstan', $status->picked(), true) => 'rules-clean',
            $ranPicked => 'rules-clean-plain',
            $ran && $failed === 0 => 'passing',
            $ran && $failed <= self::SO_CLOSE => 'so-close',
            $ran => 'failing',
            default => 'learned',
        };
    }

    /**
     * One short line under SAMI: the first two packages set aside, then how many more.
     */
    public function excludedLine(): string
    {
        $packages = collect(app(ToolStatus::class)->excludedPackages());
        $named = $packages->take(2)->map(fn (array $package): string => sprintf('%s %s → %s', $package['package'], $this->majorMinor($package['installed']), $this->majorMinor($package['latest'])));

        return $packages->isEmpty() ? '' : 'Excluded: '.$named->implode(', ').($packages->count() > 2 ? ' and '.($packages->count() - 2).' more' : '');
    }

    private function majorMinor(string $version): string
    {
        return implode('.', array_slice(explode('.', ltrim($version, 'v')), 0, 2));
    }

    private function toolsFraction(): ?float
    {
        $run = app(ToolStatus::class)->run();

        return ! app(BackgroundTasks::class)->isRunning('tools') || $run === null || $run['tools'] === []
            ? null
            : count($run['done']) / count($run['tools']);
    }

    private function isScanning(): bool
    {
        $tasks = app(BackgroundTasks::class);

        return app(ScanProgress::class)->read() !== null
            || collect(['blueprint', 'conventions', 'tools', PhpStanCommand::PHPSTAN, TestsCommand::TESTS, TestsCommand::COVERAGE_TASK, self::INSTALLING])->contains(fn (string $task): bool => $tasks->isRunning($task));
    }

    /**
     * The four cards of the first stage, and nothing more: every rule picked
     * afterwards is a row on the Scan tab. The one exception is a picked rule
     * that is not installed, which gets its card so it can be installed from here.
     *
     * @return list<Card>
     */
    private function scanCards(): array
    {
        $toolbox = new Toolbox;
        $missing = app(ToolStatus::class)->missing();

        return [
            ...$this->firstStageCards(),
            $this->toolCard('pint', $missing['pint']['name'] ?? $toolbox->name('pint'), isset($missing['pint'])),
            ...collect($missing)
                ->except('pint')
                ->map(fn (array $tool, string $key): Card => $this->toolCard($key, $tool['name'], true))
                ->values()
                ->all(),
        ];
    }

    /**
     * What the first stage sends back, as cards that wait while their step
     * runs and fill in the moment it lands: the models mapped, the
     * conventions counted, and the files the best-practice patterns flagged.
     *
     * @return list<Card>
     */
    private function firstStageCards(): array
    {
        $status = app(ToolStatus::class);
        $tasks = app(BackgroundTasks::class);
        $counted = $status->conventions();
        $blueprint = $status->blueprint();
        $waits = $this->isScanning() ? 'starts in a moment' : 'starts with your scan';

        return array_values(array_filter([
            match (true) {
                $blueprint !== null => $this->doneCard('Blueprint', $blueprint['models'] === 0 ? 'No models' : trans_choice(':count model mapped|:count models mapped', $blueprint['models']), 'green', $blueprint['relationships'] === null ? 'on your machine' : trans_choice('Across :count relation|Across :count relations', $blueprint['relationships'])),
                $tasks->isRunning('blueprint') => $this->spinningCard('Blueprint', 'cyan', 'Mapping…', 'read-only'),
                default => $this->waitingCard('Blueprint', $waits),
            },
            match (true) {
                $counted !== null => $this->doneCard('Conventions', number_format($counted['followed']).' followed', $counted['undecided'] === 0 ? 'green' : 'amber', $counted['undecided'] === 0 ? 'all decided' : number_format($counted['undecided']).' new to decide'),
                $tasks->isRunning('conventions') => $this->spinningCard('Conventions', 'cyan', 'Counting…', 'read-only'),
                default => $this->waitingCard('Conventions', $waits),
            },
            match (true) {
                ($counted['flagged'] ?? null) !== null => $this->doneCard(self::BEST_PRACTICES, $counted['flagged'] === 0 ? 'All clear' : trans_choice(':count file to look at|:count files to look at', $counted['flagged']), $counted['flagged'] === 0 ? 'green' : 'amber', self::ADVISORY),
                $counted !== null => null,
                $tasks->isRunning('conventions') => $this->spinningCard(self::BEST_PRACTICES, 'cyan', 'Checking…', self::ADVISORY),
                default => $this->waitingCard(self::BEST_PRACTICES, self::ADVISORY),
            },
        ]));
    }

    /**
     * The first stage's cards before any scan, so they are there to watch
     * fill in. Nothing left on this machine from an earlier scan shows.
     *
     * @return list<Card>
     */
    private function waitingCards(): array
    {
        return [
            $this->waitingCard('Blueprint', 'starts with your scan'),
            $this->waitingCard('Conventions', 'starts with your scan'),
            $this->waitingCard(self::BEST_PRACTICES, self::ADVISORY),
            $this->waitingCard((new Toolbox)->name('pint'), self::DETECTION),
        ];
    }

    private function doneCard(string $name, string $value, string $colour, string $description): Card
    {
        return Card::make($name)->icon('✅', '✓')->colour($colour)->value($value)->description($description)->descriptionColour('soft');
    }

    private function waitingCard(string $name, string $description): Card
    {
        return Card::make($name)->icon('⏳', '…')->colour('cyan')->value('Waiting…')->description($description)->descriptionColour('soft');
    }

    private function toolCard(string $key, string $name, bool $missing): Card
    {
        $tasks = app(BackgroundTasks::class);
        $run = app(ToolStatus::class)->run();
        $outcome = app(ToolStatus::class)->outcome($key);
        $inRun = $tasks->isRunning('tools') && app(ToolStatus::class)->startedAt($key) !== null && in_array($key, $run['tools'] ?? [], true) && ! in_array($key, $run['done'] ?? [], true);
        $behind = app(ToolStatus::class)->isBehind($key);

        $card = match (true) {
            $inRun => $this->spinningCard($name, 'cyan', 'Running…', 'read-only'),
            $behind === ToolStatus::RUNNING => $this->spinningCard($name, 'cyan', 'Running…', 'in the background'),
            $behind !== null => Card::make($name)->icon('⏳', '…')->colour('cyan')->value('Waiting…')->description('after the other tools')->descriptionColour('soft'),
            $missing && $tasks->isRunning(self::INSTALLING) => $this->spinningCard($name, 'amber', 'Installing…', 'composer require'),
            $missing => $this->installCard($key, $name),
            $outcome !== null && $outcome['ran'] => Card::make($name)
                ->icon('✅', '✓')
                ->colour($outcome['findings'] === 0 ? 'green' : 'amber')
                ->value($outcome['findings'] === 0 ? 'All clear' : ($outcome['findings'] === 1 ? '1 issue found' : number_format($outcome['findings']).' issues found'))
                ->description('done · read-only')
                ->descriptionColour('soft'),
            $outcome !== null => Card::make($name)->icon('⚠️', '!')->colour('amber')->value('Did not run')->description(Str::limit($outcome['reason'], 28))->descriptionColour('soft'),
            default => Card::make($name)->icon('⏳', '…')->colour('cyan')->value('Waiting…')->description('starts in a moment')->descriptionColour('soft'),
        };

        return $key === 'pint' && ! $missing && ($outcome === null || $outcome['ran']) ? $card->description(self::DETECTION) : $card;
    }

    private function spinningCard(string $name, string $colour, string $value, string $description): Card
    {
        return Card::make(fn (): string => TestRun::SPINNER[intdiv((int) (microtime(true) * 1000), 100) % count(TestRun::SPINNER)].' '.$name)
            ->colour($colour)
            ->value($value)
            ->description($description)
            ->descriptionColour('soft');
    }

    /**
     * @param  array{state: string, tests?: int, failed?: int, skipped?: int, took?: int, reason?: string}  $tests
     */
    /**
     * The tests as one plain line under the stats, not a card of their own.
     *
     * @return array{text: string, colour: string}
     */
    private function testsLine(): array
    {
        $status = app(ToolStatus::class);
        $tests = $status->tests() ?? ($status->asks(TestsCommand::TESTS) ? ['state' => self::QUEUED] : null);

        if ($tests === null) {
            return ['text' => '', 'colour' => 'soft'];
        }

        $failed = (int) ($tests['failed'] ?? 0);
        $skipped = (int) ($tests['skipped'] ?? 0);
        $passed = max(0, (int) ($tests['tests'] ?? 0) - $failed - $skipped);
        $spinner = TestRun::SPINNER[intdiv((int) (microtime(true) * 1000), 100) % count(TestRun::SPINNER)];

        return match ($tests['state']) {
            self::QUEUED => ['text' => $spinner.' Tests · waiting for the scan tools', 'colour' => 'cyan'],
            ToolStatus::RUNNING => ['text' => $spinner.' Tests · running in the background…', 'colour' => 'cyan'],
            ToolStatus::RAN => [
                'text' => ($failed > 0 ? '! ' : '✓ ').'Tests · '.number_format($passed).' passed · '.number_format($failed).' failed · '.number_format($skipped).' skipped · in '.round(($tests['took'] ?? 0) / 1000).'s',
                'colour' => $failed > 0 ? 'rose' : 'green',
            ],
            ToolStatus::OFF => ['text' => 'Tests · off, on at your next scan', 'colour' => 'dim'],
            default => ['text' => '! Tests · did not run, see Activity', 'colour' => 'amber'],
        };
    }

    private function installCard(string $key, string $name): Card
    {
        return Card::make($name)
            ->icon('🧰', '◇')
            ->colour('amber')
            ->value('Not installed')
            ->description('⏎ or click to install')
            ->descriptionColour('amber')
            ->action('row:enter');
    }

    private function installMissing(): string
    {
        $missing = collect(app(ToolStatus::class)->missing());

        return $this->install($missing->keys()->all(), $missing->pluck('name')->values()->all());
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $names
     */
    private function install(array $keys, array $names): string
    {
        $listed = collect($names)->join(', ', ' and ');

        app(BackgroundTasks::class)->start(
            self::INSTALLING,
            'Install tools',
            'Installing '.$listed.' with composer…',
            [InstallToolsCommand::SIGNATURE, ...array_map(fn (string $key): string => '--tool='.$key, $keys), '--yes'],
            then: [
                'key' => 'tools',
                'label' => 'Scan tools',
                'working' => 'Running your checking tools again, now with '.$listed.'…',
                'command' => [ToolsCommand::SIGNATURE],
                'next' => [PhpStanCommand::chained()],
            ],
            timeout: InstallToolsCommand::TIMEOUT,
        );

        return 'Installing '.$listed.'. I\'ll run your checks again when it\'s done.';
    }
}
