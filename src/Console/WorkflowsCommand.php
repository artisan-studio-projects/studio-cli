<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BranchStatus;
use ArtisanStudio\StudioCli\Concerns\AnswersArtisans;
use ArtisanStudio\StudioCli\Concerns\OpensInStudio;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\SampleSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\Focus;
use ArtisanStudio\StudioCli\TaskReview;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\StatusColumn;
use ArtisanStudio\StudioCli\Terminal\Components\Section;
use ArtisanStudio\StudioCli\Terminal\Components\Table;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Contracts\RunsInBackground;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use ArtisanStudio\StudioCli\Terminal\Tab;
use ArtisanStudio\StudioCli\TestRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

class WorkflowsCommand extends Command implements ProvidesTab, RunsInBackground
{
    use AnswersArtisans;
    use OpensInStudio;

    private const array STATUSES = [
        'Active' => 'blue',
        'Planning' => 'cyan',
        'Queued' => 'cyan',
        'Paused' => 'amber',
        'Pending' => 'amber',
        'Completed' => 'green',
    ];

    private const array TASK_STATUSES = [
        'Done' => 'green',
        'In Progress' => 'blue',
        DashboardSnapshot::WAITING_FOR_YOU => 'amber',
        DashboardSnapshot::IN_REVIEW => 'blue',
        DashboardSnapshot::REVIEWED => 'green',
        DashboardSnapshot::FINISHED => 'sky',
        DashboardSnapshot::CHECKPOINT => 'cyan',
        'Review' => 'amber',
        'Sent back' => 'rose',
        'Open' => 'dim',
    ];

    private const array REVIEWABLE = [DashboardSnapshot::WAITING_FOR_YOU, DashboardSnapshot::IN_REVIEW];

    protected $signature = 'studio:workflows';

    private bool $listening = false;

    /**
     * @var array<string, TaskReview>
     */
    private array $reviews = [];

    /**
     * @var array<string, TestRun>
     */
    private array $testRuns = [];

    protected $description = 'Open Artisan Studio on the Workflows tab';

    public function tab(Tab $tab): Tab
    {
        return $tab->label('Workflows')
            ->state(fn (SnapshotSource $source): DashboardSnapshot => $source->snapshot())
            ->components([
                Section::make('Workflows')
                    ->aside(fn (DashboardSnapshot $data): string => "{$data->workflowsRunning} running · {$data->workflowsDone} of {$data->workflowsTotal} done")
                    ->components([
                        Table::make(fn (DashboardSnapshot $data): array => $data->workflows)
                            ->selectable()
                            ->emptyState('No workflows yet. Start one in Artisan Studio and it shows up here.')
                            ->columns([
                                Column::make('name')->label('Workflow')->url(fn (array $workflow): ?string => $workflow['url'] ?? null),
                                StatusColumn::make('status')->width(13)->bold()->colours($this->statuses()),
                                Column::make('updated')->width(9)->colour('dim')->formatStateUsing(fn (string $updated): string => $updated === '' ? '' : "{$updated} ago"),
                            ]),
                    ]),
            ])
            ->closes(fn (Focus $focus): Focus => $focus->follow(null))
            ->opens(
                fn (array $record, SnapshotSource $source, Focus $focus): array => $this->opened($record, $source, $focus),
                [
                    Text::make(fn (array $workflow): string => $workflow['name'])
                        ->colour('cyan')
                        ->bold()
                        ->description(fn (array $workflow): string => "   ● {$workflow['status']}")
                        ->descriptionColour(fn (array $workflow): string => $this->statuses()[$workflow['status']] ?? 'cyan')
                        ->link('View workflow', fn (array $workflow): ?string => $workflow['url']),
                    Section::make('Tasks')
                        ->aside(fn (array $workflow): string => 'artisans on '.($workflow['branch'] ?? 'no branch yet').' · you on '.(app(BranchStatus::class)->now()['branch'] ?: 'no git'))
                        ->components([
                            Table::make(fn (array $workflow): array => $workflow['tasks'])
                                ->selectable()
                                ->emptyState('No tasks planned for this workflow yet.')
                                ->columns([
                                    Column::make('ordinal')->label('')->width(4)->colour('dim'),
                                    Column::make('title')->label('Task'),
                                    Column::make('artisan')->width(12)->colour('sky'),
                                    StatusColumn::make('status')->width(19)->bold()->colours(self::TASK_STATUSES),
                                ]),
                        ]),
                    Text::make('Esc goes back to all workflows')->colour('dim'),
                ],
            )
            ->opens(
                fn (array $record, mixed $parent): array => $this->task($record, $parent),
                [
                    Text::make(fn (array $task): string => $task['title'])
                        ->colour('cyan')
                        ->bold()
                        ->description(fn (array $task): string => "   ● {$task['status']}")
                        ->descriptionColour(fn (array $task): string => self::TASK_STATUSES[$task['status']] ?? 'cyan')
                        ->button(fn (array $task): string => $this->reviewButton($task), 'row:enter')
                        ->link('View workflow', fn (array $task): ?string => $task['workflow']['url'] ?? null),
                    Text::make(fn (array $task): string => in_array($task['status'], [...self::REVIEWABLE, DashboardSnapshot::CHECKPOINT], true) ? '' : ($task['workflow']['name'] ?? '')." · task {$task['ordinal']} · {$task['artisan']}")
                        ->colour('dim')
                        ->wrap(),
                    Text::make(fn (array $task): string => ($task['tests'] ?? null) !== null ? $this->testsLine($task) : match ($task['status']) {
                        DashboardSnapshot::WAITING_FOR_YOU => "{$task['artisan']} has finished and is waiting for your review.\n".app(BranchStatus::class)->beforeReviewing($task['workflow']['branch'] ?? null),
                        DashboardSnapshot::IN_REVIEW => 'You are reviewing this. Your edits show in the review as you make them.',
                        DashboardSnapshot::FINISHED => "{$task['artisan']} finished this. The checkpoint comes once {$task['artisan']}'s other tasks are done.",
                        DashboardSnapshot::CHECKPOINT => "{$task['artisan']} has finished. Press Review in the studio to go through it here, or Continue to carry on.",
                        default => '',
                    })
                        ->colour(fn (array $task): string => match (true) {
                            $task['status'] === DashboardSnapshot::FINISHED => 'sky',
                            $task['status'] === DashboardSnapshot::CHECKPOINT => 'cyan',
                            $task['status'] === DashboardSnapshot::WAITING_FOR_YOU && app(BranchStatus::class)->blocksReviewing($task['workflow']['branch'] ?? null) => 'rose',
                            default => 'amber',
                        })
                        ->wrap(),
                    Section::make('What it does')->components([
                        Text::make(fn (array $task): string => $task['summary'] === '' ? 'No summary for this task yet.' : $task['summary'])->colour('soft')->wrap(),
                    ]),
                    Section::make('Files')
                        ->aside(fn (array $task): string => trans_choice(':count file|:count files', count($this->filesOf($task))))
                        ->components([
                            Table::make(fn (array $task): array => $this->filesOf($task))
                                ->emptyState('No files for this task yet.')
                                ->columns([
                                    Column::make('path')->label('File'),
                                    Column::make('kind')->width(10)->colour('dim'),
                                ]),
                        ]),
                    Text::make('Esc goes back to the tasks')->colour('dim'),
                ],
            )
            ->enters(fn (array $task): array => match (true) {
                $this->canRunTests($task) => [$this->testRunOf($task)->panel()],
                $this->canReview($task) => [$this->reviewOf($task)->panel()],
                default => [],
            });
    }

    public function start(): void
    {
        $this->artisansAskedAt = 0;

        if (! $this->listening) {
            Event::listen(StudioReported::class, $this->reviewRequested(...));
            $this->listening = true;
        }
    }

    public function tick(): void
    {
        $this->answerArtisans();

        collect($this->testRuns)->each(fn (TestRun $run) => $run->tick());
    }

    public function stop(): void
    {
        $this->stopAnswering();
    }

    private function reviewRequested(StudioReported $reported): void
    {
        $event = $reported->event;
        $workflow = (string) ($event['workflow'] ?? ((array) ($event['meta'] ?? []))['workflow'] ?? '');
        $details = ($event['type'] ?? null) === 'checkpoint' ? app(SnapshotSource::class)->workflow($workflow) : null;
        $waiting = collect($details['tasks'] ?? [])->first(fn (array $task): bool => $task['status'] === DashboardSnapshot::WAITING_FOR_YOU
            && (($event['task'] ?? null) === null || $task['id'] === (string) $event['task']));

        if ($details === null || $waiting === null) {
            return;
        }

        app(ScreenRequests::class)->show($this->tab(Tab::make())->getKey(), fn (Tab $tab): Tab => $tab->closeAll()
            ->openWhere(fn (array $record): bool => $record['id'] === $workflow)
            ->openWhere(fn (array $record): bool => $record['id'] === $waiting['id']), enter: ($event['history'] ?? false) !== true);

        if (app(BranchStatus::class)->blocksReviewing($details['branch'])) {
            app(ActivityLog::class)->add([
                'agent' => 'your app',
                'label' => 'Can\'t switch yet',
                'detail' => app(BranchStatus::class)->beforeReviewing($details['branch']),
                'colour' => 'rose',
                'kind' => 'checkpoint',
                'workflow' => $workflow,
            ], 'blocked-'.$workflow);
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function task(array $record, mixed $parent): array
    {
        $workflow = is_array($parent) ? $parent : [];

        return [...(collect(array_filter((array) ($workflow['tasks'] ?? []), is_array(...)))->firstWhere('id', $record['id']) ?? $record), 'workflow' => $workflow];
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function canReview(array $task): bool
    {
        return ($task['tests'] ?? null) === null
            && in_array($task['status'], self::REVIEWABLE, true)
            && ! app(SnapshotSource::class) instanceof SampleSnapshots
            && ! $this->cannotStart($task)
            && $this->reviewOf($task)->isOpen();
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function cannotStart(array $task): bool
    {
        $branch = $task['workflow']['branch'] ?? null;

        return $task['status'] === DashboardSnapshot::WAITING_FOR_YOU
            && ($branch === null || app(BranchStatus::class)->blocksReviewing($branch));
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function reviewButton(array $task): string
    {
        return match (true) {
            $this->canRunTests($task) => '⏎ Run tests',
            ! $this->canReview($task) => '',
            $task['status'] === DashboardSnapshot::IN_REVIEW => '⏎ Finish review',
            default => '⏎ Start review',
        };
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function reviewOf(array $task): TaskReview
    {
        return ($this->reviews[(string) $task['id']] ??= app(TaskReview::class, ['task' => $task]))->seeing($task);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function canRunTests(array $task): bool
    {
        return ($task['tests'] ?? null) !== null
            && ! app(SnapshotSource::class) instanceof SampleSnapshots
            && ($this->testRunOf($task)->isOpen() || ($task['status'] === DashboardSnapshot::WAITING_FOR_YOU && ! $this->cannotStart($task)));
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function testRunOf(array $task): TestRun
    {
        return ($this->testRuns[(string) $task['id']] ??= app(TestRun::class, ['task' => $task]))->seeing($task);
    }

    /**
     * @param  array<string, mixed>  $task
     * @return list<array{path: string, kind: string}>
     */
    private function filesOf(array $task): array
    {
        return ($task['tests'] ?? null) === null
            ? array_values((array) $task['files'])
            : array_values(array_map(fn (string $path): array => ['path' => $path, 'kind' => 'test'], (array) ($task['tests']['files'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function testsLine(array $task): string
    {
        $count = trans_choice(':count test file|:count test files', count($task['tests']['files'] ?? []));
        $branch = (string) ($task['workflow']['branch'] ?? '');
        $now = app(BranchStatus::class)->now();
        $changes = trans_choice(':count uncommitted change|:count uncommitted changes', $now['uncommitted']);

        return match (true) {
            $task['status'] === DashboardSnapshot::CHECKPOINT => "Prover wrote {$count}. Choose Run in my terminal in the studio to run them here, or Skip tests to carry on.",
            $task['status'] === DashboardSnapshot::WAITING_FOR_YOU && app(BranchStatus::class)->blocksReviewing($branch) => "Prover's {$count} are ready to run on your machine.\nYou are on {$now['branch']} with {$changes}. Commit or stash them first: running the tests switches you to {$branch}.",
            $task['status'] === DashboardSnapshot::WAITING_FOR_YOU => "Prover's {$count} are ready to run on your machine. Press Enter to run them.",
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{id: string, name: string, status: string, branch: ?string, url: ?string, tasks: list<array{id: string, ordinal: int, title: string, artisan: string, status: string, summary: string, files: list<array{path: string, kind: string}>, tests: array{state: string, files: list<string>}|null}>}
     */
    private function opened(array $record, SnapshotSource $source, Focus $focus): array
    {
        $id = (string) $record['id'];
        $focus->follow($id, (string) $record['name']);

        return $source->workflow($id) ?? [
            'id' => $id,
            'name' => (string) $record['name'],
            'status' => (string) $record['status'],
            'branch' => null,
            'url' => is_string($record['url'] ?? null) ? $record['url'] : null,
            'tasks' => [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statuses(): array
    {
        return [...self::STATUSES, ...(array) config('studio-cli.workflows.statuses', [])];
    }
}
