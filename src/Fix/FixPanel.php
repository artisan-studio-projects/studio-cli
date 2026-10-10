<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Scan\ScanRules;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Terminal\Components\Alert;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\BarColumn;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Component;
use ArtisanStudio\StudioCli\Terminal\Components\Section;
use ArtisanStudio\StudioCli\Terminal\Components\Table;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\TestRun;

/**
 * A fix run as the Insights tab tells it: what SAMI says, and one card per
 * rule asked for.
 */
final class FixPanel
{
    /**
     * What SAMI says as a fix run goes, from the ask to the last check.
     *
     * @var array<string, array{title: string, line: string, colour: string}>
     */
    public const array SAMI_SAYS = [
        'asked' => ['title' => "Let's get these fixed.", 'line' => "I'll work on a new safety branch, one commit per step, and push nothing. Your own code stays exactly where it is. Press ⏎ when you're ready.", 'colour' => 'sky'],
        'fixing' => ['title' => "I'm on it, on a safety branch.", 'line' => 'Every fix is applied at the exact line your tools reported, and nothing is pushed.', 'colour' => 'sky'],
        'all' => ['title' => 'We got them all!', 'line' => "Now I'm going to run one more check to update your insights.", 'colour' => 'green'],
        'most' => ['title' => 'I fixed most of them.', 'line' => "Now I'm going to run one more check to update your insights.", 'colour' => 'green'],
        'some' => ['title' => 'I fixed what I safely could.', 'line' => "Now I'm going to run one more check to update your insights.", 'colour' => 'sky'],
        'clean' => ['title' => 'All done, looking good!', 'line' => 'Everything I fixed checks out, and your insights are up to date.', 'colour' => 'green'],
        'left' => ['title' => 'All done, looking good.', 'line' => "A few things I could not fix automatically, so let's work through those together.", 'colour' => 'green'],
        'refused' => ['title' => "I can't start just yet.", 'line' => 'Something on your side needs sorting first. See why below, then press ⏎ again.', 'colour' => 'amber'],
        'stopped' => ['title' => 'My fixes stopped before they finished.', 'line' => 'Everything that landed is committed on the safety branch. Press ⏎ to try again.', 'colour' => 'amber'],
    ];

    /**
     * From this table width the columns have their full room; below it they
     * narrow, so the table fits a small terminal.
     */
    private const int ROOMY = 100;

    private const string FIXING = 'fixing';

    private const string CHECKING = 'checking';

    private const string FIXED = 'fixed';

    private const string LEFT = 'left';

    private const string STOPPED = 'stopped';

    private const string YOURS = 'yours';

    private const string CLEAN = 'clean';

    private const string OFF = 'off';

    /**
     * Below this share fixed, SAMI does not say "most".
     */
    private const float MOST = 0.5;

    public static function insights(): self
    {
        return new self;
    }

    /**
     * @param  list<string>  $asked
     * @return list<string>
     */
    public function asked(array $asked): array
    {
        return array_values($asked);
    }

    /**
     * @param  list<string>  $asked
     * @return list<string>
     */
    public function fixable(array $asked): array
    {
        return app(FixLauncher::class)->fixable($this->asked($asked));
    }

    /**
     * Whether fixes are asked for, or a run is going or on show: the Insights
     * tab is in the row only then.
     */
    public function isOnShow(): bool
    {
        return app(SnapshotSource::class)->snapshot()->fixesAsked !== []
            || app(FixProgress::class)->read() !== null
            || app(FixLauncher::class)->isRunning();
    }

    public function canStart(DashboardSnapshot $data): bool
    {
        return app(FixLauncher::class)->canStart($this->asked($data->fixesAsked));
    }

    public function start(DashboardSnapshot $data): string
    {
        return app(FixLauncher::class)->start($this->asked($data->fixesAsked));
    }

    /**
     * What SAMI says, the reason a run could not start, and one
     * row per rule: how many fixed of how many, how far along, and how long.
     *
     * @return list<Component>
     */
    public function components(): array
    {
        return [
            Alert::make(fn (DashboardSnapshot $data): string => $this->says($data)['title'])
                ->colour(fn (DashboardSnapshot $data): string => $this->says($data)['colour'])
                ->description(fn (DashboardSnapshot $data): string => $this->says($data)['line'])
                ->descriptionColour('soft'),
            Text::make(fn (DashboardSnapshot $data): string => ($run = $this->shownRun($data)) !== null && $run['phase'] === FixProgress::REFUSED ? (string) $run['reason'] : '')
                ->colour('soft')
                ->wrap(),
            Section::make(fn (DashboardSnapshot $data): string => $this->title($data))
                ->aside(fn (DashboardSnapshot $data): string => $this->totals($data)['aside'])
                ->asideColour(fn (DashboardSnapshot $data): string => $this->totals($data)['colour'])
                ->components([
                    Table::make(fn (DashboardSnapshot $data): array => $this->rows($data))
                        ->emptyState('Nothing to fix yet. Once a scan finds something SAMI can fix, its rules line up here.')
                        ->columns([
                            Column::make('group')->label('Group')->width(fn (int $table): int => $table >= self::ROOMY ? 9 : 4)->colour('soft'),
                            Column::make('name')->label('Rule')->width(fn (int $table): int => $table >= self::ROOMY ? 22 : 14),
                            Column::make('count')
                                ->label('Fixed')
                                ->width(fn (int $table): int => $table >= self::ROOMY ? 22 : 10)
                                ->bold()
                                ->colour(fn (array $row): string => $this->colourOf($row)),
                            BarColumn::make('bar')
                                ->label('')
                                ->colour('green')
                                ->fraction(fn (array $row): ?float => $row['fraction'])
                                ->placeholder(''),
                            Column::make('note')
                                ->label('')
                                ->width(fn (int $table): int => $table >= self::ROOMY ? 21 : 11)
                                ->colour(fn (array $row): string => $this->colourOf($row))
                                ->formatStateUsing(fn (mixed $note, array $row): string => (in_array($row['state'], [self::FIXING, self::CHECKING], true) ? TestRun::SPINNER[intdiv((int) (microtime(true) * 1000), 100) % count(TestRun::SPINNER)].' ' : '').$note),
                            Column::make('elapsed')
                                ->label('Time')
                                ->width(fn (int $table): int => $table >= self::ROOMY ? 8 : 7)
                                ->colour(fn (array $row): string => in_array($row['state'], [self::FIXING, self::CHECKING], true) ? 'cyan' : 'soft')
                                ->formatStateUsing(fn (mixed $elapsed): string => $elapsed === null || $elapsed === '' ? '—' : $this->duration((int) $elapsed)),
                        ]),
                ]),
        ];
    }

    /**
     * @return array{title: string, line: string, colour: string}
     */
    private function says(DashboardSnapshot $data): array
    {
        $fixes = app(FixProgress::class);
        $progress = $this->shownRun($data);
        $launcher = app(FixLauncher::class);
        $rulesets = collect($progress['rulesets'] ?? []);
        $own = $rulesets->only(AutomatedFixes::OWN);
        $share = $own->sum('found') > 0 ? $own->sum('fixed') / $own->sum('found') : 1.0;
        $skipped = $rulesets->contains(fn (array $ruleset): bool => $ruleset['state'] === FixProgress::SKIPPED);
        $fixing = $rulesets->contains(fn (array $ruleset): bool => in_array($ruleset['state'], [FixProgress::WAITING, FixProgress::RUNNING], true));
        $left = $rulesets->sum(fn (array $ruleset): int => (int) ($ruleset['left'] ?? 0));

        $key = match (true) {
            $progress === null && $launcher->isRunning() && $this->fixable($data->fixesAsked) !== [] && app(FixProgress::class)->read() === null => 'fixing',
            $progress === null && $this->canStart($data) => 'asked',
            $progress === null => null,
            $progress['phase'] === FixProgress::REFUSED => 'refused',
            $fixes->hasStopped() => 'stopped',
            $progress['phase'] === FixProgress::FINISHED && $left === 0 => 'clean',
            $progress['phase'] === FixProgress::FINISHED => 'left',
            $fixing => 'fixing',
            $share >= 1.0 && ! $skipped => 'all',
            $share >= self::MOST => 'most',
            default => 'some',
        };

        return match (true) {
            $key !== null => self::SAMI_SAYS[$key],
            default => ['title' => '', 'line' => '', 'colour' => 'sky'],
        };
    }

    /**
     * The run this panel shows: a finished one only until the developer asks
     * for these fixes again.
     *
     * @return array{phase: string, branch: string, at: float, pid: ?int, reason: ?string, checking: ?float, finished: ?float, rulesets: array<string, array{state: string, found: int, fixed: int, files: int, started: ?float, took: ?int, left: ?int, rechecking: bool}>}|null
     */
    private function shownRun(DashboardSnapshot $data): ?array
    {
        $progress = app(FixProgress::class)->read();
        $keys = array_keys($progress['rulesets'] ?? []);

        return match (true) {
            $progress === null, $keys === [] => null,
            $progress['phase'] === FixProgress::FINISHED && $this->canStart($data) => null,
            default => $progress,
        };
    }

    /**
     * The same rows as the Scan tab, in the same order: how many are fixed of
     * the issues the scan found, from what the re-check says is left once it
     * has, and how long it has taken. A rule SAMI does not fix says so, and
     * one outside this run says it was not asked.
     *
     * @return list<array{group: string, key: string, name: string, state: string, found: int, fixed: int, left: ?int, count: string, fraction: ?float, percent: ?int, note: string, elapsed: ?int}>
     */
    private function rows(DashboardSnapshot $data): array
    {
        $progress = $this->shownRun($data);
        $started = $progress !== null && $progress['phase'] !== FixProgress::REFUSED;

        if (! $started && ! $data->hasScanned()) {
            return [];
        }

        $scan = app(ScanRules::class)->forStudio($data->hasScanned())->rows();
        $fixable = $this->fixable(array_column($scan, 'key'));
        $asked = $this->canStart($data) ? $this->fixable($data->fixesAsked) : [];
        $stopped = app(FixProgress::class)->hasStopped();
        $toolbox = new Toolbox;
        $rules = app(ScanRules::class);
        $leftover = $started ? array_values(array_diff(array_keys($progress['rulesets']), array_column($scan, 'key'))) : [];

        return array_values([
            ...array_map(function (array $scanned) use ($progress, $started, $fixable, $asked, $stopped): array {
                $key = $scanned['key'];
                $found = (int) ($scanned['caught'] ?? 0);

                return match (true) {
                    $started && isset($progress['rulesets'][$key]) => $this->row($scanned['group'], $key, $scanned['name'], $progress['rulesets'][$key], $stopped, 'next'),
                    ! in_array($key, $fixable, true) => $found > 0
                        ? $this->plain($scanned['group'], $key, $scanned['name'], self::YOURS, $found, 'SAMI will assist you')
                        : $this->plain($scanned['group'], $key, $scanned['name'], self::CLEAN, 0, 'all clear'),
                    $found === 0 => $this->plain($scanned['group'], $key, $scanned['name'], self::CLEAN, 0, 'all clear'),
                    $started => $this->plain($scanned['group'], $key, $scanned['name'], self::OFF, $found, 'not asked for'),
                    default => $this->row($scanned['group'], $key, $scanned['name'], ['state' => FixProgress::WAITING, 'found' => $found, 'fixed' => 0, 'files' => 0, 'started' => null, 'took' => null, 'left' => null, 'rechecking' => false], false, in_array($key, $asked, true) ? 'press ⏎ to start' : 'Autofixes available'),
                };
            }, $scan),
            ...array_map(fn (string $key): array => $this->row($rules->groupOf($key), $key, $toolbox->name($key), $progress['rulesets'][$key], $stopped, 'next'), $leftover),
        ]);
    }

    /**
     * What is left of a rule SAMI does not fix: the tests say how many are
     * still failing out of how many ran, the others how many were found.
     */
    private function leftOutOf(string $key, int $found): string
    {
        $tests = $key === 'tests' ? app(ToolStatus::class)->tests() : null;

        return $tests !== null && (int) ($tests['tests'] ?? 0) > 0
            ? number_format($found).' left out of '.number_format((int) $tests['tests'])
            : number_format($found).' left';
    }

    /**
     * A rule that is not part of the fixing: SAMI leaves it to the developer,
     * it has nothing to fix, or it was not asked for.
     *
     * @return array{group: string, key: string, name: string, state: string, found: int, fixed: int, left: ?int, count: string, fraction: ?float, percent: ?int, note: string, elapsed: ?int}
     */
    private function plain(string $group, string $key, string $name, string $state, int $found, string $note): array
    {
        return [
            'group' => $group,
            'key' => $key,
            'name' => $name,
            'state' => $state,
            'found' => $found,
            'fixed' => 0,
            'left' => null,
            'count' => match ($state) {
                self::CLEAN => $key === 'tests' ? $this->leftOutOf($key, 0) : '0',
                self::YOURS => $this->leftOutOf($key, $found),
                default => number_format($found).' found',
            },
            'fraction' => null,
            'percent' => null,
            'note' => $note,
            'elapsed' => null,
        ];
    }

    /**
     * The rows SAMI is attempting: not the ones left to the developer, with
     * nothing to fix, or outside this run.
     *
     * @param  list<array{state: string, found: int, fixed: int}>  $rows
     * @return list<array{state: string, found: int, fixed: int}>
     */
    private function attempted(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => ! in_array($row['state'], [self::YOURS, self::CLEAN, self::OFF], true)));
    }

    /**
     * @param  array{state: string, found: int, fixed: int, files: int, started: ?float, took: ?int, left: ?int, rechecking: bool}  $ruleset
     * @return array{group: string, key: string, name: string, state: string, found: int, fixed: int, left: ?int, count: string, fraction: ?float, percent: ?int, note: string, elapsed: ?int}
     */
    private function row(string $group, string $key, string $name, array $ruleset, bool $stopped, string $waiting): array
    {
        $found = $ruleset['found'];
        $left = $ruleset['left'];
        $fixed = $left !== null ? max(0, $found - $left) : min($found, $ruleset['fixed']);
        $running = in_array($ruleset['state'], [FixProgress::WAITING, FixProgress::RUNNING], true);
        $state = match (true) {
            $stopped && $running => self::STOPPED,
            $ruleset['state'] === FixProgress::WAITING => FixProgress::WAITING,
            $ruleset['state'] === FixProgress::RUNNING => self::FIXING,
            $ruleset['state'] === FixProgress::SKIPPED => FixProgress::SKIPPED,
            $ruleset['rechecking'] => self::CHECKING,
            $left === 0 => self::FIXED,
            $left !== null => self::LEFT,
            default => FixProgress::DONE,
        };
        $percent = $found > 0 ? (int) round($fixed / $found * 100) : null;

        return [
            'group' => $group,
            'key' => $key,
            'name' => $name,
            'state' => $state,
            'found' => $found,
            'fixed' => $fixed,
            'left' => $left,
            'count' => number_format($fixed).'/'.number_format($found),
            'fraction' => $found > 0 && $fixed > 0 ? $fixed / $found : null,
            'percent' => $state === FixProgress::WAITING ? null : ($percent !== null && $percent >= 100 && $fixed < $found ? 99 : $percent),
            'note' => match ($state) {
                self::STOPPED => 'stopped',
                FixProgress::WAITING => $waiting,
                self::FIXING => 'fixing…',
                self::CHECKING => 'checking again…',
                FixProgress::SKIPPED => $ruleset['files'] > 0 ? 'error · '.$ruleset['files'].' '.($ruleset['files'] === 1 ? 'file' : 'files') : 'did not run',
                self::FIXED => $found === 0 && $ruleset['files'] > 0 ? 'tidied '.$ruleset['files'].' '.($ruleset['files'] === 1 ? 'file' : 'files') : 'All fixed',
                self::LEFT => number_format((int) $left).' left',
                default => $ruleset['files'] > 0 ? 'tidied '.$ruleset['files'].' '.($ruleset['files'] === 1 ? 'file' : 'files') : 'done',
            },
            'elapsed' => match (true) {
                in_array($state, [self::FIXING, self::CHECKING], true) && $ruleset['started'] !== null => (int) ((microtime(true) - $ruleset['started']) * 1000),
                default => $ruleset['took'],
            },
        ];
    }

    private function colourOf(array $row): string
    {
        return match ($row['state']) {
            self::FIXING, self::CHECKING => 'cyan',
            self::FIXED, self::CLEAN => 'green',
            self::YOURS => 'soft',
            self::LEFT, self::STOPPED, FixProgress::SKIPPED => 'amber',
            FixProgress::DONE => 'green',
            default => 'dim',
        };
    }

    /**
     * The table's title: how many grouped issues Insights lists that SAMI is
     * attempting, the same number the Scan tab gives, once the studio has said.
     */
    private function title(DashboardSnapshot $data): string
    {
        $grouped = app(ToolStatus::class)->groupedProblems();

        return $grouped === null || $this->totals($data)['found'] === 0
            ? 'Attempting to fix grouped issues'
            : number_format($grouped['open']).' grouped issues attempting to autofix';
    }

    /**
     * Everything the rules had, what is fixed of it, and how long the run has taken.
     *
     * @return array{found: int, fixed: int, percent: ?int, elapsed: ?int, running: bool, aside: string, colour: string}
     */
    private function totals(DashboardSnapshot $data): array
    {
        $rows = $this->attempted($this->rows($data));
        $found = (int) array_sum(array_column($rows, 'found'));
        $fixed = (int) array_sum(array_column($rows, 'fixed'));
        $run = $this->shownRun($data);
        $running = $run !== null && in_array($run['phase'], [FixProgress::FIXING, FixProgress::CHECKING], true) && ! app(FixProgress::class)->hasStopped();
        $end = $run === null ? null : ($running ? microtime(true) : ($run['finished'] ?? microtime(true)));
        $percent = $found > 0 ? (int) round($fixed / $found * 100) : null;

        return [
            'found' => $found,
            'fixed' => $fixed,
            'percent' => $percent !== null && $percent >= 100 && $fixed < $found ? 99 : $percent,
            'elapsed' => $run === null || $end === null ? null : (int) (($end - $run['at']) * 1000),
            'running' => $running,
            'aside' => match (true) {
                $found === 0 => '',
                $run === null || $run['phase'] === FixProgress::REFUSED => number_format($fixed).'/'.number_format($found).' issues',
                default => number_format($fixed).'/'.number_format($found).' issues'.($percent === null ? '' : ' · '.($percent >= 100 && $fixed < $found ? 99 : $percent).'%').($run['at'] === null || $end === null ? '' : ' · '.$this->duration((int) (($end - $run['at']) * 1000)).($running ? ' elapsed' : '')),
            },
            'colour' => $found > 0 && $fixed >= $found ? 'green' : ($fixed > 0 || $running ? 'cyan' : 'amber'),
        ];
    }

    private function duration(int $milliseconds): string
    {
        $seconds = intdiv(max(0, $milliseconds), 1000);

        return $seconds < 60 ? $seconds.'s' : intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s';
    }
}
