<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\StatusColumn;
use ArtisanStudio\StudioCli\Terminal\Components\Feed;
use ArtisanStudio\StudioCli\Terminal\Components\Section;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Settings;
use Illuminate\Contracts\Process\InvokedProcess;

final class TestRun
{
    private const int OUTPUT_LINES = 60;

    private ?InvokedProcess $running = null;

    private string $report = '';

    private string $output = '';

    /**
     * @var array{passed: bool, results: list<array{file: string, passed: bool, summary: ?string}>, cases: array{passed: int, failed: int}}|null
     */
    private ?array $outcome = null;

    private bool $sent = false;

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
                                StatusColumn::make('label')->width(11)->colour(fn (array $row): string => $row['colour']),
                                Column::make('file')->colour('soft'),
                                Column::make('note')->colour('dim'),
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
                Action::make('retry')
                    ->label('Send the results again')
                    ->visible(fn (array $state): bool => $state['unsent'])
                    ->action(fn (): string => $this->deliver()),
            ]);
    }

    public function tick(): void
    {
        if ($this->running === null || $this->running->running()) {
            return;
        }

        $result = $this->running->wait();
        $this->running = null;
        $this->output = $this->tail($result->output().$result->errorOutput());
        $this->outcome = $this->suite->read($this->report, $result->successful());

        if (is_file($this->report)) {
            unlink($this->report);
        }

        $this->deliver();
    }

    /**
     * @return array{about: string, situation: string, colour: string, on: bool, ready: bool, unsent: bool, aside: string, rows: list<array{label: string, colour: string, file: string, note: string}>}
     */
    private function state(): array
    {
        $branch = $this->branch();
        $on = $branch !== null && $this->git->now()['branch'] === $branch;
        $problem = $this->suite->problem();
        $blocked = $branch === null || (! $on && $this->git->blocksReviewing($branch));

        return [
            'about' => $this->about(),
            'situation' => $this->situation($branch, $on, $problem),
            'colour' => $problem !== null || ($blocked && $this->outcome === null) ? 'rose' : 'amber',
            'on' => $on,
            'ready' => $this->running === null && $this->outcome === null && $problem === null && ! $blocked,
            'unsent' => $this->outcome !== null && ! $this->sent,
            'aside' => trans_choice(':count file|:count files', count($this->files())),
            'rows' => $this->rows(),
        ];
    }

    private function about(): string
    {
        $count = trans_choice(':count test file|:count test files', count($this->files()));

        if ($this->running !== null) {
            return "Running {$count} on your machine…";
        }

        if ($this->outcome === null) {
            return "Prover wrote {$count} for this build. Running them here runs only those files, and the results go back to the studio for Guard.";
        }

        $passed = count(array_filter($this->outcome['results'], fn (array $result): bool => $result['passed']));
        $total = count($this->outcome['results']);

        return "{$passed} of {$total} test files passed: {$this->outcome['cases']['passed']} tests passed, {$this->outcome['cases']['failed']} failed."
            .($this->sent ? ' Sent to the studio. Guard checks the build next.' : '');
    }

    private function situation(?string $branch, bool $on, ?string $problem): string
    {
        $now = $this->git->now();
        $changes = trans_choice(':count uncommitted change|:count uncommitted changes', $now['uncommitted']);

        return match (true) {
            $problem !== null => $problem,
            $this->running !== null => '',
            $this->outcome !== null => $this->sent ? '' : 'The results have not reached the studio yet.',
            $branch === null => 'The artisans have not pushed a branch for this yet.',
            $on => "On {$branch}.",
            $now['uncommitted'] > 0 => "You are on {$now['branch']} with {$changes}. Commit or stash them first: running the tests switches you to {$branch}.",
            default => "Running them switches you from {$now['branch']} to {$branch}.",
        };
    }

    /**
     * @return list<array{label: string, colour: string, file: string, note: string}>
     */
    private function rows(): array
    {
        if ($this->outcome !== null && $this->outcome['results'] !== []) {
            return array_map(fn (array $result): array => [
                'label' => $result['passed'] ? 'Passed' : 'Failed',
                'colour' => $result['passed'] ? 'green' : 'rose',
                'file' => $result['file'],
                'note' => (string) $result['summary'],
            ], $this->outcome['results']);
        }

        return array_map(fn (string $file): array => [
            'label' => $this->running === null ? 'Ready' : 'Running',
            'colour' => $this->running === null ? 'dim' : 'blue',
            'file' => $file,
            'note' => '',
        ], $this->files());
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

        $this->changes->catchUp($remote);
        $this->git->forget();
        $this->report = sys_get_temp_dir().'/studio-tests-'.bin2hex(random_bytes(8)).'.xml';
        $this->running = $this->suite->start($files, $this->report);
        $this->log('Running the tests', 'blue', trans_choice(':count test file|:count test files', count($files)));

        $aside = $this->changes->setAside($branch);

        return 'Running the tests on '.$branch.'.'.($aside === null ? '' : ' Your local '.$branch.' was from an earlier run, so it is kept as '.$aside.'.');
    }

    private function deliver(): string
    {
        if ($this->outcome === null) {
            return 'Nothing has run yet.';
        }

        $taken = $this->studio->submitTestRun($this->workflowId(), (string) $this->task['id'], [...$this->outcome, 'output' => $this->output]);

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
