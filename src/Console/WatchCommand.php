<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\Concerns\InteractsWithAvatar;
use ArtisanStudio\StudioCli\Concerns\ListensToStudio;
use ArtisanStudio\StudioCli\Concerns\OpensInStudio;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\Focus;
use ArtisanStudio\StudioCli\LocalTime;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\StatusColumn;
use ArtisanStudio\StudioCli\Terminal\Components\Feed;
use ArtisanStudio\StudioCli\Terminal\Components\Section;
use ArtisanStudio\StudioCli\Terminal\Components\Table;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesRail;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Contracts\RunsInBackground;
use ArtisanStudio\StudioCli\Terminal\Rail;
use ArtisanStudio\StudioCli\Terminal\Tab;
use ArtisanStudio\StudioCli\Workspace;
use Illuminate\Console\Command;
use Throwable;

class WatchCommand extends Command implements ProvidesRail, ProvidesTab, RunsInBackground
{
    use InteractsWithAvatar;
    use ListensToStudio;
    use OpensInStudio;

    public const string SIGNATURE = 'studio:watch';

    private const array BEATS = [
        'self' => ['Working', 'cyan'],
        'inbound' => ['Picked up', 'sky'],
        'outbound' => ['Handed over', 'sky'],
        'done' => ['Done', 'green'],
        'summary' => ['Summary', 'blue'],
    ];

    private const array MILESTONES = ['done', 'summary', 'checkpoint'];

    private const array INSIGHTS = ['scan', 'insight', 'convention'];

    private const string WAITING = 'Nothing yet. Anything its artisans do shows up here as it happens.';

    private const string NO_MILESTONES = 'When a workflow finishes a step, or waits for you, it shows up here. Open a workflow to follow everything its artisans do.';

    private const string NO_INSIGHTS = 'Scans and new insights show up here as they happen.';

    protected $signature = self::SIGNATURE;

    protected $aliases = ['artisan-studio:watch'];

    protected $description = 'Open Artisan Studio on the Activity tab, following your workflows as they run';

    private ?string $problem = null;

    public function tab(Tab $tab): Tab
    {
        return $tab->label('Activity')
            ->state(fn (Focus $focus): array => $this->activity($focus->workflow() === null ? 'milestones' : 'workflow'))
            ->components([
                Section::make(fn (array $state): string => $state['heading'])
                    ->aside(fn (array $state): string => $state['problem'] === null ? $state['connection'] : '')
                    ->components([
                        Table::make(fn (array $state): array => $state['events'])
                            ->emptyState(fn (array $state): string => $state['problem'] ?? $state['empty'])
                            ->columns([
                                Column::make('when')->width(10)->colour('dim'),
                                Column::make('agent')->width(10)->colour('sky'),
                                StatusColumn::make('label')
                                    ->label('What happened')
                                    ->width(fn (int $width): int => min(24, intdiv($width, 4)))
                                    ->colour(fn (array $event): string => $event['colour']),
                                Column::make('detail')->colour('soft'),
                            ]),
                    ]),
            ]);
    }

    public function rail(Rail $rail): Rail
    {
        return $rail
            ->state(fn (mixed $tab = null): array => [...$this->activity($this->sectionFor($tab instanceof Tab ? $tab : null)), 'reachable' => $this->studioIsReachable()])
            ->components([
                Section::make(fn (array $state): string => $state['heading'])
                    ->aside(fn (array $state): string => $state['reachable'] ? $state['connection'] : '')
                    ->components([
                        Text::make(fn (array $state): string => $state['reachable'] ? '' : ucfirst($state['connection']))
                            ->colour('amber')
                            ->wrap(),
                        Feed::make(fn (array $state): array => $state['events'])
                            ->emptyState(fn (array $state): string => $state['problem'] ?? $state['empty'])
                            ->columns([
                                Column::make('when')->width(9)->colour('dim'),
                                Column::make('agent')->width(9)->colour('sky'),
                                StatusColumn::make('label')->colour(fn (array $event): string => $event['colour']),
                            ])
                            ->detail('detail'),
                    ]),
            ]);
    }

    private function sectionFor(?Tab $tab): string
    {
        return match (true) {
            $tab?->isOpen() === true && app(Focus::class)->workflow() !== null => 'workflow',
            $tab?->getKey() === 'insights' => 'insights',
            default => 'milestones',
        };
    }

    /**
     * @return array{events: list<array{when: string, agent: string, label: string, detail: string, colour: string, kind: string, workflow: ?string, key: ?string}>, problem: ?string, connection: string, heading: string, empty: string}
     */
    private function activity(string $section): array
    {
        $focus = app(Focus::class);
        $log = app(ActivityLog::class);

        [$heading, $events, $empty] = match ($section) {
            'workflow' => [$focus->name() ?? 'Activity', $log->entries($focus->workflow()), self::WAITING],
            'insights' => ['Insights', $log->ofKinds(self::INSIGHTS), self::NO_INSIGHTS],
            default => ['Activity', $this->named($log->ofKinds(self::MILESTONES)), self::NO_MILESTONES],
        };

        return ['events' => $events, 'problem' => $this->problem, 'connection' => $this->studioConnection(), 'heading' => $heading, 'empty' => $empty];
    }

    /**
     * @param  list<array{when: string, agent: string, label: string, detail: string, colour: string, kind: string, workflow: ?string, key: ?string}>  $events
     * @return list<array{when: string, agent: string, label: string, detail: string, colour: string, kind: string, workflow: ?string, key: ?string}>
     */
    private function named(array $events): array
    {
        $names = collect(app(SnapshotSource::class)->snapshot()->workflows)->pluck('name', 'id');

        return array_map(fn (array $event): array => [
            ...$event,
            'detail' => $names->has((string) $event['workflow']) ? $names[(string) $event['workflow']].' · '.$event['detail'] : $event['detail'],
        ], $events);
    }

    public function start(): void
    {
        $studio = app(Studio::class);
        $this->problem = $this->whyItCannotWatch($studio);

        if ($this->problem !== null) {
            return;
        }

        $this->avatarArrives();
        $this->startListening($studio, fn (array $event) => $this->heard($event));
    }

    public function tick(): void
    {
        $this->keepListening();
    }

    public function stop(): void
    {
        $this->stopListening();
        $this->avatarLeaves();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function heard(array $event): void
    {
        if (($event['kind'] ?? null) === 'tick') {
            $this->avatarSettles();

            return;
        }

        event(new StudioReported($event));

        if (($event['type'] ?? null) === 'changed') {
            return;
        }

        $happened = $this->describe($event);

        if ($happened !== null) {
            app(ActivityLog::class)->add($happened);
        }

        if (($event['history'] ?? false) !== true) {
            $this->avatarReacts($event);
        }
    }

    private function whyItCannotWatch(Studio $studio): ?string
    {
        if (! $studio->isLinked()) {
            return "This project is not connected to Artisan Studio yet.\nPress s for Settings and link it. It asks for a token from Artisan Studio, then writes it to your .env.";
        }

        try {
            $studio->projects();
        } catch (Throwable $refusal) {
            return $refusal->getMessage()."\nThe token in your .env is wrong, expired, or from another studio. Press s for Settings and link again with a fresh one.";
        }

        return app(Workspace::class)->isGitRepository()
            ? null
            : 'This is not a git repository, so there is no branch for a build to follow.';
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{agent: string, label: string, detail: string, colour: string, when: string, workflow: string}|null
     */
    private function describe(array $event): ?array
    {
        [$label, $detail, $colour] = match ($event['type'] ?? '') {
            'file' => ['Wrote', (string) ($event['path'] ?? ''), 'green'],
            'checkpoint' => ['Waiting for you', html_entity_decode(strip_tags((string) ($event['body'] ?? 'An artisan has finished and is waiting for you.')), ENT_QUOTES | ENT_HTML5), 'amber'],
            'beat' => $this->beat($event),
            'task' => [ucfirst((string) ($event['status'] ?? 'task')), (string) ($event['title'] ?? ''), 'cyan'],
            'test' => $this->testOutcome($event),
            'idle' => ['Idle', 'No active workflows', 'dim'],
            default => ['Update', (string) ($event['message'] ?? ''), 'soft'],
        };

        return $label === 'Update' && $detail === ''
            ? null
            : [
                'agent' => (string) ($event['agent'] ?? '—'),
                'label' => $label,
                'detail' => $detail,
                'colour' => $colour,
                'when' => $this->whenItHappened($event),
                'kind' => (string) ($event['kind'] ?? $event['type'] ?? ''),
                'workflow' => (string) ($event['workflow'] ?? ((array) ($event['meta'] ?? []))['workflow'] ?? ''),
            ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: string, 2: string}
     */
    private function beat(array $event): array
    {
        $kind = (string) ($event['kind'] ?? '');
        [$label, $colour] = self::BEATS[$kind] ?? [ucfirst($kind), 'soft'];

        return [$label, html_entity_decode(strip_tags((string) ($event['body'] ?? '')), ENT_QUOTES | ENT_HTML5), $colour];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function whenItHappened(array $event): string
    {
        return LocalTime::of(is_string($event['at'] ?? null) ? $event['at'] : null);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: string, 2: string}
     */
    private function testOutcome(array $event): array
    {
        $failed = (int) ($event['failed'] ?? 0);
        $passed = (int) ($event['passed'] ?? 0);

        return $failed > 0
            ? ['Tests failed', "{$failed} failed, {$passed} passed", 'rose']
            : ['Tests passed', "{$passed} passed", 'green'];
    }
}
