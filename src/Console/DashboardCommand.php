<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Concerns\OpensInStudio;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\NotConnected;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Scan\ScanProgress;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Terminal\Components\Alert;
use ArtisanStudio\StudioCli\Terminal\Components\Card;
use ArtisanStudio\StudioCli\Terminal\Components\Grid;
use ArtisanStudio\StudioCli\Terminal\Components\Progress;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Tab;
use ArtisanStudio\StudioCli\TestRun;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DashboardCommand extends Command implements ProvidesTab
{
    use OpensInStudio;

    private const string INSTALLING = 'install-tools';

    private const string QUEUED = 'queued';

    /**
     * @var array<string, array{title: string, line: string, colour: string, mark: string}>
     */
    public const array SAMI_SAYS = [
        'before' => ['title' => "Heads up! Here's what's about to happen once you have authorized your scan.", 'line' => "I'll read your files once, just looking, never touching. Your code stays right here with you. Passing tests boost your score, so only opt out in the app if you don't care about testing right now!", 'colour' => 'sky', 'mark' => Alert::INFO],
        'almost' => ['title' => 'Almost ready.', 'line' => "Please confirm below by simply pressing enter. I'll take care of the rest, then your scan will begin.", 'colour' => 'sky', 'mark' => Alert::INFO],
        'rules-found' => ['title' => 'So I ran the additional tools you selected.', 'line' => 'A few things came up, so we will tackle these together soon.', 'colour' => 'sky', 'mark' => Alert::INFO],
        'rules-clean' => ['title' => 'WOW, impressive, all checks passed!', 'line' => 'I challenge you to change PHPStan to the max and try again!', 'colour' => 'green', 'mark' => Alert::SUCCESS],
        'rules-clean-plain' => ['title' => 'WOW, impressive, all checks passed!', 'line' => "Nothing to fix in the rules you picked. We're only just getting started!", 'colour' => 'green', 'mark' => Alert::SUCCESS],
        'extras' => ['title' => 'All ready to go with the extra rules.', 'line' => "Scanning now. Let's hope this makes a positive impact on your score!", 'colour' => 'sky', 'mark' => Alert::INFO],
        'learned' => ['title' => 'Knowledge is power!', 'line' => "WOW, I have learned a lot about your application. Can't wait to get started on this!", 'colour' => 'sky', 'mark' => Alert::INFO],
        'passing' => ['title' => 'WOW, OK, a 100% pass rate on your tests!', 'line' => 'Do you even need me? lol. Of course you do, we are only just getting started!', 'colour' => 'green', 'mark' => Alert::SUCCESS],
        'so-close' => ['title' => 'Oh so close!', 'line' => "We both know that's an easy fix, and a very small, temporary reduction to your project's health!", 'colour' => 'amber', 'mark' => Alert::WARNING],
        'failing' => ['title' => "OK, so we're getting a fair few failures here.", 'line' => "Don't panic, we can fix these together after your scan!", 'colour' => 'amber', 'mark' => Alert::WARNING],
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
                Alert::make(fn (DashboardSnapshot $data): string => self::SAMI_SAYS[$this->samiSays($data)]['title'] ?? '')
                    ->colour(fn (DashboardSnapshot $data): string => self::SAMI_SAYS[$this->samiSays($data)]['colour'] ?? 'sky')
                    ->mark(fn (DashboardSnapshot $data): string => self::SAMI_SAYS[$this->samiSays($data)]['mark'] ?? Alert::INFO)
                    ->description(fn (DashboardSnapshot $data): string => self::SAMI_SAYS[$this->samiSays($data)]['line'] ?? '')
                    ->descriptionColour('soft'),
                Progress::make(fn (DashboardSnapshot $data): string => $this->happeningNow($data))
                    ->value(fn (DashboardSnapshot $data): float => app(ScanProgress::class)->fraction() ?? $this->toolsFraction() ?? $data->scanFraction()),
                Grid::of(fn (?DashboardSnapshot $data): array => $data?->hasScanned() ? $this->scanCards() : [])->columns(4),
            ])
            ->entersBy(
                fn (mixed $data): bool => $data instanceof DashboardSnapshot && $data->hasScanned() && app(ToolStatus::class)->missing() !== [] && ! app(BackgroundTasks::class)->isRunning(self::INSTALLING),
                fn (): string => $this->installMissing(),
                'Install',
            );
    }

    private function happeningNow(DashboardSnapshot $data): string
    {
        $tasks = app(BackgroundTasks::class);
        $walk = app(ScanProgress::class)->read();

        return match (true) {
            $walk !== null => sprintf('Reading %s of %s files on your machine: conventions, best practices, security patterns and your tests', number_format($walk['done']), number_format($walk['total'])),
            $tasks->isRunning('blueprint') || $tasks->isRunning('conventions') => 'Mapping your models and counting your conventions…',
            $tasks->isRunning(self::INSTALLING) => 'Installing the checking tools you switched on…',
            $tasks->isRunning('tools') && $this->toolsFraction() !== null => sprintf('Running your checking tools, read-only: %d of %d done…', count(app(ToolStatus::class)->run()['done'] ?? []), count(app(ToolStatus::class)->run()['tools'] ?? [])),
            $tasks->isRunning('tools') => 'Running your checking tools, read-only…',
            $tasks->isRunning(TestsCommand::TESTS) => 'Running your tests in parallel…',
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
            || collect(['blueprint', 'conventions', 'tools', TestsCommand::TESTS, self::INSTALLING])->contains(fn (string $task): bool => $tasks->isRunning($task));
    }

    /**
     * @return list<Card>
     */
    private function scanCards(): array
    {
        $status = app(ToolStatus::class);
        $tests = $status->tests() ?? ($status->asks(TestsCommand::TESTS) ? ['state' => self::QUEUED] : null);

        $toolbox = new Toolbox;
        $missing = $status->missing();

        return [
            ...($tests === null ? [] : [$this->testsCard($tests)]),
            ...collect([...array_keys($missing), ...$status->picked()])
                ->unique()
                ->map(fn (string $key): Card => $this->toolCard($key, $missing[$key]['name'] ?? $toolbox->name($key), isset($missing[$key])))
                ->values()
                ->all(),
        ];
    }

    private function toolCard(string $key, string $name, bool $missing): Card
    {
        $tasks = app(BackgroundTasks::class);
        $run = app(ToolStatus::class)->run();
        $outcome = app(ToolStatus::class)->outcome($key);
        $inRun = $tasks->isRunning('tools') && in_array($key, $run['tools'] ?? [], true) && ! in_array($key, $run['done'] ?? [], true);
        $current = $inRun && collect($run['tools'] ?? [])->first(fn (string $tool): bool => ! in_array($tool, $run['done'] ?? [], true)) === $key;

        return match (true) {
            $current => $this->spinningCard($name, 'cyan', 'Running…', 'read-only'),
            $inRun => Card::make($name)->icon('⏳', '…')->colour('cyan')->value('Waiting…')->description('its turn is next')->descriptionColour('soft'),
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
    private function testsCard(array $tests): Card
    {
        $failed = (int) ($tests['failed'] ?? 0);
        $skipped = (int) ($tests['skipped'] ?? 0);
        $passed = max(0, (int) ($tests['tests'] ?? 0) - $failed - $skipped);

        [$colour, $value, $description] = match ($tests['state']) {
            self::QUEUED => ['cyan', 'Waiting…', 'after the scan tools'],
            ToolStatus::RUNNING => ['cyan', 'Running…', 'in the background'],
            ToolStatus::RAN => [
                $failed > 0 ? 'rose' : 'green',
                $failed > 0 ? number_format($failed).' failing' : number_format($passed).' passed',
                $failed > 0 ? number_format($passed).' passed · '.round(($tests['took'] ?? 0) / 1000).'s' : $failed.' failed · '.$skipped.' skipped',
            ],
            ToolStatus::OFF => ['dim', 'Off', 'on at your next scan'],
            default => ['amber', 'Not run', 'see Activity'],
        };

        if (in_array($tests['state'], [self::QUEUED, ToolStatus::RUNNING], true)) {
            return Card::make(fn (): string => TestRun::SPINNER[intdiv((int) (microtime(true) * 1000), 100) % count(TestRun::SPINNER)].' Tests')
                ->colour($colour)
                ->value($value)
                ->description($description)
                ->descriptionColour('soft');
        }

        return Card::make('Tests')
            ->icon('🧪', '✓')
            ->colour($colour)
            ->value($value)
            ->description($description)
            ->descriptionColour($colour === 'dim' ? 'dim' : 'soft');
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
            ],
            timeout: InstallToolsCommand::TIMEOUT,
        );

        return 'Installing '.$listed.'. I\'ll run your checks again when it\'s done.';
    }
}
