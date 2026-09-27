<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\StatusColumn;
use ArtisanStudio\StudioCli\Terminal\Components\Feed;
use ArtisanStudio\StudioCli\Terminal\Components\Section;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Settings;
use Illuminate\Support\Str;

final class TaskReview
{
    private const array OPEN = [DashboardSnapshot::WAITING_FOR_YOU, DashboardSnapshot::IN_REVIEW];

    private const array EDITS = [
        'added' => ['Added', 'green'],
        'modified' => ['Changed', 'amber'],
        'deleted' => ['Deleted', 'rose'],
    ];

    private bool $started;

    private bool $finished = false;

    private ?string $why = null;

    /**
     * @var array{sha: ?string, files: list<array{path: string, status: string}>}|null
     */
    private ?array $committed = null;

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
        private readonly Editor $editor,
    ) {
        $this->started = $task['status'] === DashboardSnapshot::IN_REVIEW;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function seeing(array $task): self
    {
        $this->task = $task;
        $this->started = $this->started || $task['status'] === DashboardSnapshot::IN_REVIEW;

        return $this;
    }

    public function isOpen(): bool
    {
        return ! $this->finished && in_array($this->task['status'], self::OPEN, true);
    }

    public function panel(): Settings
    {
        return $this->panel ??= Settings::make('Review · '.$this->title())
            ->state(fn (): array => $this->state())
            ->components([
                Text::make(fn (array $state): string => $state['about'])->colour('soft')->wrap(),
                Text::make(fn (array $state): string => $state['situation'])
                    ->colour(fn (array $state): string => $state['blocked'] ? 'rose' : 'amber')
                    ->description(fn (array $state): string => $state['tidy'])
                    ->descriptionColour('dim')
                    ->wrap(),
            ])
            ->belowActions([
                Section::make('Your edits')
                    ->aside(fn (array $state): string => $state['edits'] === [] ? '' : trans_choice(':count file|:count files', count($state['edits'])))
                    ->components([
                        Feed::make(fn (array $state): array => $state['edits'])
                            ->emptyState(fn (array $state): string => $state['started'] ? 'Nothing changed yet. Edit what you need in your editor and it shows up here.' : '')
                            ->columns([
                                StatusColumn::make('label')->width(12)->colour(fn (array $edit): string => $edit['colour']),
                                Column::make('path')->colour('soft'),
                                Column::make('added')->width(7)->colour('green'),
                                Column::make('removed')->width(7)->colour('rose'),
                            ]),
                    ]),
            ])
            ->actions([
                Action::make('start')
                    ->label('Start review')
                    ->visible(fn (array $state): bool => ! $state['started'] && $state['on'])
                    ->action(fn (): string => $this->start()),
                Action::make('switch')
                    ->label('Switch to '.$this->branch().' and start')
                    ->visible(fn (array $state): bool => ! $state['started'] && ! $state['on'] && ! $state['blocked'])
                    ->action(fn (): string => $this->start()),
                Action::make('send')
                    ->label('Save changes')
                    ->visible(fn (array $state): bool => $state['started'] && $state['edits'] !== [] && ! $state['unsent'])
                    ->asks('Why did you change it? What was wrong with it, in your own words.')
                    ->action(fn (string $why): string => $this->send($why))
                    ->goesBack(fn (): bool => $this->finished),
                Action::make('retry')
                    ->label('Try sending again')
                    ->visible(fn (array $state): bool => $state['unsent'])
                    ->action(fn (): string => $this->deliver())
                    ->goesBack(fn (): bool => $this->finished),
                Action::make('good')
                    ->label('Looks good')
                    ->visible(fn (array $state): bool => $state['on'] && $state['edits'] === [] && ! $state['unsent'])
                    ->action(fn (): string => $this->looksGood())
                    ->goesBack(fn (): bool => $this->finished),
            ]);
    }

    /**
     * @return array{started: bool, about: string, situation: string, tidy: string, blocked: bool, on: bool, edits: list<array{label: string, colour: string, path: string, added: string, removed: string}>, others: int, unsent: bool}
     */
    private function state(): array
    {
        $branch = $this->branch();
        $on = $branch !== null && $this->git->now()['branch'] === $branch;
        $others = count($this->othersWaiting());

        return [
            'started' => $this->started,
            'about' => $this->about($others),
            'situation' => $this->situation($branch, $on),
            'tidy' => $this->started ? '' : $this->tidy(),
            'blocked' => $this->started ? ! $on || $this->committed !== null : $branch === null || $this->git->blocksReviewing($branch),
            'on' => $on,
            'edits' => $this->started ? array_values(array_map($this->asEdit(...), $this->whereEachFileStands())) : [],
            'others' => $others,
            'unsent' => $this->committed !== null,
        ];
    }

    private function about(int $others): string
    {
        $more = $others === 0 ? '' : ' '.trans_choice(':count more of :artisan\'s is waiting too.|:count more of :artisan\'s are waiting too.', $others, ['artisan' => $this->artisan()]);

        return $this->started
            ? 'Try it, and change what you need in your editor. Your edits show here as you make them.'.$more
            : $this->artisan().' finished this and is waiting for you.'.$more;
    }

    private function situation(?string $branch, bool $on): string
    {
        return match (true) {
            $this->committed !== null => 'Committed as '.mb_substr((string) $this->committed['sha'], 0, 7).', but it has not reached the studio yet.',
            ! $this->started => $this->git->beforeReviewing($branch),
            ! $on => 'You are on '.$this->git->now()['branch'].' now. Switch back to '.$branch.' to carry on.',
            default => 'Reviewing on '.$branch.'.',
        };
    }

    private function start(): string
    {
        $branch = $this->branch();

        if ($branch === null || $this->git->blocksReviewing($branch)) {
            return $this->git->beforeReviewing($branch);
        }

        $remote = $this->remote();
        $before = $this->changes->currentSha();

        if (! $this->changes->switchTo($branch, $remote)) {
            return 'Could not switch to '.$branch.'. The task is still waiting.';
        }

        $this->changes->catchUp($remote);
        $this->git->forget();
        $this->started = $this->studio->startTaskReview($this->workflowId(), $this->taskId());
        $this->source->forget();

        return $this->started
            ? 'On '.$branch.'.'.$this->earlierRun($branch).$this->migrationsSince($before).($this->editor->name() === null || $this->changedFiles() === [] ? '' : ' '.$this->openFiles())
            : 'On '.$branch.', but the studio did not hear the review start. Try again.';
    }

    private function tidy(): string
    {
        $files = $this->changedFiles();
        $count = trans_choice(':count file|:count files', count($files));

        return match (true) {
            $files === [] => '',
            $this->editor->name() !== null => 'Close your editor tabs first. The review then opens just the '.$count.' this task changed, in '.$this->editor->name().'.',
            default => 'Close your editor tabs first, then open the '.$count.' this task changed: '.implode(', ', $files).'.',
        };
    }

    private function openFiles(): string
    {
        $files = $this->changedFiles();

        return $this->editor->open($files)
            ? 'Opened '.trans_choice('its :count file|its :count files', count($files)).' in '.$this->editor->name().'.'
            : $this->editor->name().' did not open them.';
    }

    /**
     * @return list<string>
     */
    private function changedFiles(): array
    {
        return array_values(array_map(
            fn (array $file): string => (string) ($file['path'] ?? ''),
            array_filter((array) ($this->task['files'] ?? []), fn (mixed $file): bool => is_array($file) && ($file['kind'] ?? '') !== 'read'),
        ));
    }

    private function send(string $why): string
    {
        $files = $this->changes->sinceTheLastCommit();

        if ($files === []) {
            return 'Nothing is changed any more, so there is nothing to send.';
        }

        $this->why = $why;
        $this->committed = ['sha' => $this->changes->commitEverything($this->commitMessage()), 'files' => $files];

        return $this->deliver();
    }

    private function deliver(): string
    {
        $branch = (string) $this->branch();
        $committed = $this->committed;

        if ($committed === null) {
            return 'Nothing is waiting to be sent.';
        }

        if (! $this->changes->publish($branch, $this->remote())) {
            return 'Committed here, but it could not be pushed to '.$branch.'. Try sending again.';
        }

        $done = $this->studio->finishTaskReview($this->workflowId(), $this->taskId(), [
            'outcome' => 'updated',
            'note' => $this->why,
            'commit' => $committed['sha'],
            'files' => $committed['files'],
        ]);

        if ($done === null) {
            return 'Pushed to '.$branch.', but the studio did not take the review. Try sending again.';
        }

        $this->log('Saved changes', 'blue', $this->title().' · '.(string) $this->why);
        $this->committed = null;
        $this->why = null;

        return $this->finish($done, 'Saved and sent to the studio.');
    }

    private function looksGood(): string
    {
        $done = $this->studio->finishTaskReview($this->workflowId(), $this->taskId(), ['outcome' => 'accepted']);

        if ($done === null) {
            return 'The studio would not take that. The task is still waiting.';
        }

        $this->log('Looks good', 'green', $this->title());

        return $this->finish($done, 'Marked as good.');
    }

    /**
     * @param  array<mixed>  $done
     */
    private function finish(array $done, string $said): string
    {
        $this->finished = true;
        $this->source->forget();
        $left = count($this->othersWaiting());

        return $said.match (true) {
            ($done['released'] ?? false) === true => ' That was the last of '.$this->artisan().'\'s, so the build carries on.',
            $left > 0 => ' '.trans_choice(':count more of :artisan\'s waits for you.|:count more of :artisan\'s wait for you.', $left, ['artisan' => $this->artisan()]),
            default => '',
        };
    }

    private function earlierRun(string $branch): string
    {
        $aside = $this->changes->setAside($branch);

        return $aside === null ? '' : ' Your local '.$branch.' was from an earlier run, so it is kept as '.$aside.'.';
    }

    private function migrationsSince(string $before): string
    {
        $landed = $before === $this->changes->currentSha() ? 0 : count(array_filter(
            $this->changes->whatLandedSince($before),
            fn (array $file): bool => str_starts_with($file['path'], 'database/migrations/') && $file['status'] !== 'deleted',
        ));

        return $landed === 0 ? '' : ' '.trans_choice(':count migration landed|:count migrations landed', $landed).': run php artisan migrate before you try it.';
    }

    private function commitMessage(): string
    {
        $scope = $this->changes->scopeOfTheLastCommit();
        $reason = trim((string) $this->why);

        return ($scope === null ? 'fix' : 'fix('.$scope.')').': developer review of "'.$this->title().'" by @'.Str::lower($this->artisan())
            .($reason === '' ? '' : "\n\nReason for change:\n".$reason);
    }

    /**
     * @return array<string, array{path: string, status: string, added: int, removed: int}>
     */
    private function whereEachFileStands(): array
    {
        if ($this->git->now()['branch'] !== $this->branch()) {
            return [];
        }

        $lines = $this->changes->lineChangesSinceTheLastCommit();

        return collect($this->changes->sinceTheLastCommit())
            ->mapWithKeys(fn (array $file): array => [$file['path'] => [
                ...$file,
                'added' => $lines[$file['path']]['added'] ?? 0,
                'removed' => $lines[$file['path']]['removed'] ?? 0,
            ]])
            ->all();
    }

    /**
     * @param  array{path: string, status: string, added: int, removed: int}  $file
     * @return array{label: string, colour: string, path: string, added: string, removed: string}
     */
    private function asEdit(array $file): array
    {
        [$label, $colour] = self::EDITS[$file['status']] ?? self::EDITS['modified'];

        return [
            'label' => $label,
            'colour' => $colour,
            'path' => $file['path'],
            'added' => $file['added'] > 0 ? '+'.$file['added'] : '',
            'removed' => $file['removed'] > 0 ? '−'.$file['removed'] : '',
        ];
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
     * @return list<array<string, mixed>>
     */
    private function othersWaiting(): array
    {
        return array_values(array_filter(
            (array) ($this->workflow()['tasks'] ?? []),
            fn (mixed $task): bool => is_array($task)
                && $task['id'] !== $this->taskId()
                && $task['artisan'] === $this->artisan()
                && in_array($task['status'], self::OPEN, true),
        ));
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

    private function taskId(): string
    {
        return (string) $this->task['id'];
    }

    private function title(): string
    {
        return (string) $this->task['title'];
    }

    private function artisan(): string
    {
        return (string) $this->task['artisan'];
    }
}
