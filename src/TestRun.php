<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Feed;
use ArtisanStudio\StudioCli\Terminal\Components\Section;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Settings;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;

final class TestRun
{
    private const int OUTPUT_LINES = 60;

    private const int WRAP = 96;

    private const int SHOWN_LINES = 12;

    private const array SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private ?InvokedProcess $running = null;

    private ?string $current = null;

    /**
     * @var list<string>
     */
    private array $queue = [];

    /**
     * @var list<array{file: string, passed: bool, summary: ?string, failures: list<string>}>
     */
    private array $results = [];

    /**
     * @var array{passed: int, failed: int}
     */
    private array $cases = ['passed' => 0, 'failed' => 0];

    private int $stopped = 0;

    private int $frame = 0;

    private string $report = '';

    private string $output = '';

    private int $runs = 0;

    /**
     * @var array{passed: bool, results: list<array{file: string, passed: bool, summary: ?string, failures: list<string>}>, cases: array{passed: int, failed: int}}|null
     */
    private ?array $outcome = null;

    /**
     * @var array{commit?: ?string, files?: list<array{path: string, status: string}>}
     */
    private array $saved = [];

    private bool $sent = false;

    private bool $tried = false;

    private ?Settings $panel = null;

    /**
     * @param  array<string, mixed>  $task
     */
    public function __construct(
        private array $task,
        private readonly Studio $studio,
        private readonly LocalChanges $changes,
        private readonly BranchStatus $git,
        private readonly SnapshotSource $source,
        private readonly ActivityLog $log,
        private readonly TestSuite $suite,
        private readonly Editor $editor,
    ) {}

    /**
     * @param  array<string, mixed>  $task
     */
    public function seeing(array $task): self
    {
        $this->task = $task;

        return $this;
    }

    public function isOpen(): bool
    {
        return $this->running !== null || $this->outcome !== null;
    }

    public function panel(): Settings
    {
        return $this->panel ??= Settings::make('Run tests · '.(string) ($this->workflow()['name'] ?? 'this build'))
            ->state(fn (): array => $this->state())
            ->components([
                Text::make(fn (array $state): string => $state['about'])->colour('soft')->wrap(),
                Text::make(fn (array $state): string => $state['situation'])
                    ->colour(fn (array $state): string => $state['colour'])
                    ->wrap(),
            ])
            ->belowActions([
                Section::make('Tests')
                    ->aside(fn (array $state): string => $state['aside'])
                    ->components([
                        Feed::make(fn (array $state): array => $state['rows'])
                            ->columns([
                                Column::make('mark')->width(3)->colour(fn (array $row): string => $row['colour']),
                                Column::make('line')->colour(fn (array $row): string => $row['text']),
                            ]),
                    ]),
            ])
            ->actions([
                Action::make('run')
                    ->label('Run tests')
                    ->visible(fn (array $state): bool => $state['ready'] && $state['on'])
                    ->action(fn (): string => $this->start()),
                Action::make('switch')
                    ->label('Switch to '.$this->branch().' and run tests')
                    ->visible(fn (array $state): bool => $state['ready'] && ! $state['on'])
                    ->action(fn (): string => $this->start()),
                Action::make('save')
                    ->label('Save changes')
                    ->visible(fn (array $state): bool => $state['green'] && $state['edited'] && ! $state['sent'])
                    ->asks('How did you get them passing? What was wrong, in your own words.')
                    ->action(fn (string $why): string => $this->save($why)),
                Action::make('again')
                    ->label('Run again')
                    ->visible(fn (array $state): bool => $state['red'] && ! $state['sent'])
                    ->action(fn (): string => $this->start()),
                Action::make('failing')
                    ->label('Send as failing')
                    ->visible(fn (array $state): bool => $state['red'] && ! $state['sent'])
                    ->action(fn (): string => $this->deliver()),
                Action::make('retry')
                    ->label('Send the results again')
                    ->visible(fn (array $state): bool => $state['unsent'])
                    ->action(fn (): string => $this->deliver()),
            ]);
    }

    public function tick(): void
    {
        if ($this->running === null) {
            return;
        }

        if ($this->running->running()) {
            $this->frame++;
            $this->panel?->refreshState();

            return;
        }

        $this->collect($this->running->wait());

        if ($this->queue !== []) {
            $this->next();
            $this->panel?->refreshState();

            return;
        }

        $this->running = null;
        $this->current = null;
        $this->outcome = [
            'passed' => $this->results !== [] && $this->stopped === 0 && collect($this->results)->every(fn (array $result): bool => $result['passed']),
            'results' => $this->results,
            'cases' => $this->cases,
        ];

        match (true) {
            ! $this->outcome['passed'] => $this->openTheFailures(),
            $this->changes->sinceTheLastCommit() === [] => $this->deliver(),
            default => null,
        };

        $this->panel?->refreshState();
    }

    /**
     * @return array{about: string, situation: string, colour: string, on: bool, ready: bool, green: bool, red: bool, edited: bool, sent: bool, unsent: bool, aside: string, rows: list<array{mark: string, colour: string, line: string, text: string}>}
     */
    private function state(): array
    {
        $branch = $this->branch();
        $on = $branch !== null && $this->git->now()['branch'] === $branch;
        $problem = $this->suite->problem();
        $blocked = $branch === null || (! $on && $this->git->blocksReviewing($branch));
        $settled = $this->running === null && $this->outcome !== null;

        return [
            'about' => $this->about(),
            'situation' => $this->situation($branch, $on, $problem),
            'colour' => $problem !== null || ($blocked && $this->outcome === null) ? 'rose' : 'amber',
            'on' => $on,
            'ready' => $this->running === null && $this->outcome === null && $problem === null && ! $blocked,
            'green' => $settled && $this->outcome['passed'],
            'red' => $settled && ! $this->outcome['passed'],
            'edited' => $settled && $this->changes->sinceTheLastCommit() !== [],
            'sent' => $this->sent,
            'unsent' => $this->tried && ! $this->sent,
            'aside' => $this->aside(),
            'rows' => $this->rows(),
        ];
    }

    private function about(): string
    {
        $count = trans_choice(':count test file|:count test files', count($this->files()));

        return match (true) {
            $this->running !== null => "Running {$count} on your machine…",
            $this->outcome === null => "Prover wrote {$count} for this build. Running them here runs only those files, and the results go to Guard.",
            $this->sent => 'Sent to the studio. Guard checks the build next.',
            $this->didNotRun() => 'Pest stopped before running a single test. Its last lines are below: fix what it says, then run them again.',
            $this->outcome['passed'] => 'They pass. Save your changes to send them, with why you made them.',
            default => 'Fix them in your editor, then run them again. Nothing is committed while they fail.',
        };
    }

    private function aside(): string
    {
        if ($this->running !== null) {
            return "Test run {$this->runs} · ".count($this->results).' of '.trans_choice(':count file|:count files', count($this->results) + count($this->queue) + 1);
        }

        if ($this->outcome === null) {
            return trans_choice(':count file|:count files', count($this->files()));
        }

        if ($this->didNotRun()) {
            return "Test run {$this->runs} · did not run";
        }

        return "Test run {$this->runs} · {$this->outcome['cases']['passed']} passed · {$this->outcome['cases']['failed']} failed";
    }

    private function situation(?string $branch, bool $on, ?string $problem): string
    {
        $now = $this->git->now();
        $changes = trans_choice(':count uncommitted change|:count uncommitted changes', $now['uncommitted']);

        return match (true) {
            $problem !== null => $problem,
            $this->running !== null || $this->outcome !== null => '',
            $branch === null => 'The artisans have not pushed a branch for this yet.',
            $on => "On {$branch}.",
            $now['uncommitted'] > 0 => "You are on {$now['branch']} with {$changes}. Commit or stash them first: running the tests switches you to {$branch}.",
            default => "Running them switches you from {$now['branch']} to {$branch}.",
        };
    }

    /**
     * @return list<array{mark: string, colour: string, line: string, text: string}>
     */
    private function rows(): array
    {
        if ($this->didNotRun()) {
            return $this->whatPestSaid();
        }

        $finished = collect($this->results)->keyBy('file');

        return array_values(collect($this->files())
            ->flatMap(fn (string $file): array => $finished->has($file) ? $this->resultRows($finished->get($file)) : [$this->waitingRow($file)])
            ->merge($finished->except($this->files())->flatMap(fn (array $result): array => $this->resultRows($result)))
            ->all());
    }

    /**
     * @param  array{file: string, passed: bool, summary: ?string, failures: list<string>}  $result
     * @return list<array{mark: string, colour: string, line: string, text: string}>
     */
    private function resultRows(array $result): array
    {
        return [
            ['mark' => $result['passed'] ? '✓' : '✗', 'colour' => $result['passed'] ? 'green' : 'rose', 'line' => $result['file'], 'text' => 'ink'],
            ...collect($result['failures'])
                ->flatMap(fn (string $failure): array => explode("\n", wordwrap($failure, self::WRAP, "\n", true)))
                ->map(fn (string $line): array => ['mark' => '', 'colour' => 'dim', 'line' => '  '.$line, 'text' => 'dim'])
                ->all(),
        ];
    }

    /**
     * @return array{mark: string, colour: string, line: string, text: string}
     */
    private function waitingRow(string $file): array
    {
        return $file === $this->current
            ? ['mark' => self::SPINNER[$this->frame % count(self::SPINNER)], 'colour' => 'blue', 'line' => $file, 'text' => 'ink']
            : ['mark' => '·', 'colour' => 'dim', 'line' => $file, 'text' => 'soft'];
    }

    /**
     * @return list<array{mark: string, colour: string, line: string, text: string}>
     */
    private function whatPestSaid(): array
    {
        $lines = collect(explode("\n", (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $this->output)))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->take(-self::SHOWN_LINES)
            ->whenEmpty(fn ($lines) => $lines->push('Pest printed nothing.'));

        return array_values($lines
            ->flatMap(fn (string $line): array => explode("\n", wordwrap($line, self::WRAP, "\n", true)))
            ->map(fn (string $line): array => ['mark' => '', 'colour' => 'dim', 'line' => $line, 'text' => 'rose'])
            ->all());
    }

    private function didNotRun(): bool
    {
        return $this->running === null && $this->outcome !== null && $this->stopped === count($this->outcome['results']);
    }

    private function next(): void
    {
        $this->current = (string) array_shift($this->queue);
        $this->report = sys_get_temp_dir().'/studio-tests-'.bin2hex(random_bytes(8)).'.xml';
        $this->running = $this->suite->start([$this->current], $this->report);
    }

    private function collect(ProcessResult $result): void
    {
        $said = $result->output().$result->errorOutput();
        $read = $this->suite->read($this->report, $result->successful());
        $this->output = $this->tail($this->output."\n".$said);

        if (is_file($this->report)) {
            unlink($this->report);
        }

        if ($read['results'] === []) {
            $this->stopped++;
            $failure = 'Pest stopped before running it: '.$this->lastLineOf($said);
            $this->results[] = ['file' => (string) $this->current, 'passed' => false, 'summary' => $failure, 'failures' => [$failure]];

            return;
        }

        $this->results = [...$this->results, ...$read['results']];
        $this->cases = [
            'passed' => $this->cases['passed'] + $read['cases']['passed'],
            'failed' => $this->cases['failed'] + $read['cases']['failed'],
        ];
    }

    private function lastLineOf(string $said): string
    {
        return mb_substr((string) collect(explode("\n", (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $said)))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->last(default: 'it printed nothing.'), 0, 300);
    }

    private function start(): string
    {
        $branch = $this->branch();
        $files = $this->suite->runnable($this->files());

        if ($branch === null || $files === [] || $this->suite->problem() !== null) {
            return $this->suite->problem() ?? 'There are no tests to run for this build yet.';
        }

        $remote = $this->remote();

        if (! $this->changes->switchTo($branch, $remote)) {
            return 'Could not switch to '.$branch.'. The tests are still waiting.';
        }

        if ($this->changes->sinceTheLastCommit() === []) {
            $this->changes->catchUp($remote);
        }

        $this->git->forget();
        $this->outcome = null;
        $this->results = [];
        $this->cases = ['passed' => 0, 'failed' => 0];
        $this->stopped = 0;
        $this->output = '';
        $this->queue = $files;
        $this->runs++;
        $this->next();
        $this->log('Running the tests', 'blue', trans_choice(':count test file|:count test files', count($files)));

        $aside = $this->changes->setAside($branch);

        return 'Running the tests on '.$branch.'.'.($aside === null ? '' : ' Your local '.$branch.' was from an earlier run, so it is kept as '.$aside.'.');
    }

    private function openTheFailures(): void
    {
        $failing = array_values(array_map(
            fn (array $result): string => $result['file'],
            array_filter($this->outcome['results'] ?? [], fn (array $result): bool => ! $result['passed']),
        ));

        if ($failing !== [] && $this->editor->name() !== null) {
            $this->editor->open($failing);
        }
    }

    private function save(string $why): string
    {
        $files = $this->changes->sinceTheLastCommit();
        $branch = (string) $this->branch();

        if ($files === []) {
            return $this->deliver();
        }

        $scope = $this->changes->scopeOfTheLastCommit();
        $sha = $this->changes->commitEverything(($scope === null ? 'fix' : 'fix('.$scope.')').': developer got the tests passing for "'.(string) ($this->workflow()['name'] ?? 'this build').'"'."\n\nReason for change:\n".trim($why));

        if (! $this->changes->publish($branch, $this->remote())) {
            return 'Committed here, but it could not be pushed to '.$branch.'. Try saving again.';
        }

        $this->saved = ['commit' => $sha, 'files' => $files];

        return $this->deliver();
    }

    private function deliver(): string
    {
        if ($this->outcome === null) {
            return 'Nothing has run yet.';
        }

        $this->tried = true;
        $results = array_map(fn (array $result): array => [
            'file' => $result['file'],
            'passed' => $result['passed'],
            'summary' => $result['summary'],
        ], $this->outcome['results']);

        $taken = $this->studio->submitTestRun($this->workflowId(), (string) $this->task['id'], [
            'passed' => $this->outcome['passed'],
            'results' => $results,
            'cases' => $this->outcome['cases'],
            'output' => $this->output,
            ...$this->saved,
        ]);

        if ($taken === null) {
            return 'The tests ran, but the studio did not take the results. Try sending again.';
        }

        $this->sent = true;
        $this->source->forget();
        $this->log($this->outcome['passed'] ? 'Tests passed' : 'Tests failed', $this->outcome['passed'] ? 'green' : 'rose', "{$this->outcome['cases']['passed']} passed, {$this->outcome['cases']['failed']} failed");

        return 'Sent to the studio. Guard checks the build next.';
    }

    private function tail(string $output): string
    {
        return mb_substr(implode("\n", array_slice(explode("\n", trim($output)), -self::OUTPUT_LINES)), -20000);
    }

    private function log(string $label, string $colour, string $detail): void
    {
        $this->log->add([
            'agent' => 'you',
            'label' => $label,
            'detail' => $detail,
            'colour' => $colour,
            'kind' => 'review',
            'workflow' => $this->workflowId(),
        ]);
    }

    private function remote(): ?string
    {
        $remote = data_get(rescue(fn (): ?array => $this->studio->howToReach($this->workflowId()), null, false), 'remote');

        return is_string($remote) ? $remote : null;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return array_values(array_filter((array) ($this->task['tests']['files'] ?? []), is_string(...)));
    }

    /**
     * @return array<mixed>
     */
    private function workflow(): array
    {
        return (array) ($this->task['workflow'] ?? []);
    }

    private function workflowId(): string
    {
        return (string) ($this->workflow()['id'] ?? '');
    }

    private function branch(): ?string
    {
        $branch = $this->workflow()['branch'] ?? null;

        return is_string($branch) && $branch !== '' ? $branch : null;
    }
}
