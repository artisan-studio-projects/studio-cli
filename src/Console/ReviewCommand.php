<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Errand;
use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Workspace;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\textarea;

/**
 * Pair with the artisans from your own terminal.
 *
 * ★ NOT A TAB. `artisan-studio:watch` runs inside `artisan dev` as a pane, and
 * a pane cannot be typed into — it prints. This is the same stream read by a
 * command that CAN ask you something, which is why it is its own process in
 * your own terminal rather than a second pane.
 *
 * It sits quiet until a slice lands. Then it offers the only two answers worth
 * having at that moment: carry on, or step in. Stepping in switches you to the
 * build's branch, waits while you work, and hands back what you changed and why
 * — so the next artisan starts from what is really on the branch rather than
 * from what the last one handed over.
 */
class ReviewCommand extends Command
{
    public const string SIGNATURE = 'artisan-studio:review';

    protected $signature = self::SIGNATURE.'
        {--no-presence : Follow the build without SAMI appearing}';

    protected $description = 'Pair with the artisans as each one finishes';

    protected bool $keepWatching = true;

    public function handle(Studio $studio, Workspace $workspace, LocalChanges $changes, Errand $errands, Presence $sami): int
    {
        if (! $studio->isLinked()) {
            $this->components->warn('This project is not connected to Artisan Studio yet.');
            $this->components->bulletList([
                'Run <options=bold>php artisan artisan-studio:link</> first.',
            ]);

            return self::SUCCESS;
        }

        if (! $workspace->isGitRepository()) {
            $this->components->error('This is not a git repository, so there is no branch to review on.');

            return self::SUCCESS;
        }

        $this->components->info('Pairing with Artisan Studio. You will be asked as each artisan finishes.');

        return $this->follow($studio, $changes, $errands, $sami);
    }

    private function follow(Studio $studio, LocalChanges $changes, Errand $errands, Presence $sami): int
    {
        $wait = max(1, (int) config('studio-cli.watch.reconnect_seconds', 5));
        $ceiling = max($wait, (int) config('studio-cli.watch.max_reconnect_seconds', 60));

        while ($this->keepWatching) {
            try {
                $this->runWhateverIsWaiting($studio, $errands);

                $studio->stream(function (array $event) use ($studio, $changes, $errands, $sami): void {
                    $this->runWhateverIsWaiting($studio, $errands);

                    if (($event['type'] ?? '') !== 'checkpoint') {
                        return;
                    }

                    $sami->react($event);

                    $this->offerToStepIn($studio, $changes, $event);
                });

                $wait = max(1, (int) config('studio-cli.watch.reconnect_seconds', 5));
            } catch (Throwable $failure) {
                $this->components->warn(sprintf(
                    'Lost the studio (%s). Trying again in %ds.',
                    $failure->getMessage(),
                    $wait,
                ));

                sleep($wait);

                $wait = min($ceiling, $wait * 2);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Answer whatever the artisans are blocked on.
     *
     * ★ MASON AND PROVER CANNOT SEE THIS MACHINE. Their questions — what the
     * schema looks like, whether the suite passes — need a running application,
     * and on a developer's build the running application is this one. An
     * artisan is stopped while its question is unanswered, so this runs before
     * the stream is opened and again on every event rather than on a timer.
     *
     * Everything is taken one at a time and answered in order; the studio marks
     * each taken as it hands it over, so a second terminal watching the same
     * project picks up different work rather than the same work twice.
     */
    private function runWhateverIsWaiting(Studio $studio, Errand $errands): void
    {
        while (($waiting = $studio->nextCommand()) !== null) {
            $name = (string) ($waiting['name'] ?? '');

            $this->line(sprintf('  <fg=gray>%s</> <fg=cyan>%s</>', date('H:i:s'), $name));

            $answer = $errands->run($name, (array) ($waiting['arguments'] ?? []));

            $studio->answerCommand((int) $waiting['id'], $answer);
        }
    }

    /**
     * Take over, because somebody already chose to.
     *
     * ★ THE DECISION IS NOT MADE HERE. The studio asks whether to review or to
     * carry on, and a terminal that asked again would be a second prompt for a
     * question already answered — and a chance to answer it differently. This
     * beat only ever arrives because Review was pressed, so the only thing left
     * to do is get out of the way and let somebody work.
     *
     * @param  array<string, mixed>  $event
     */
    private function offerToStepIn(Studio $studio, LocalChanges $changes, array $event): void
    {
        $agent = (string) ($event['agent'] ?? 'an artisan');
        $meta = (array) ($event['meta'] ?? []);
        $branch = (string) ($meta['branch'] ?? '');

        $this->newLine();
        $this->components->info(ucfirst($agent).' has finished — you asked to review it.');

        $this->stepIn($studio, $changes, $event, $branch);
    }

    /** @param  array<string, mixed>  $event */
    private function stepIn(Studio $studio, LocalChanges $changes, array $event, string $branch): void
    {
        $priorBranch = $changes->currentBranch();

        if (! $this->readyTheBranch($studio, $changes, $event, $branch)) {
            return;
        }

        $this->showWhatTheArtisanBuilt($changes, $priorBranch, $branch);

        $this->components->info('Ready. Edit what you need — I will check every '.$this->pollSeconds().'s.');

        $files = $this->waitForYouToFinish($changes);

        if ($files === []) {
            $this->components->warn('Nothing changed, so there is nothing to send. Carrying on.');

            $this->handBack($studio, $event, ['outcome' => 'accepted']);

            return;
        }

        $chosen = multiselect(
            label: 'Which of these should go to the studio?',
            options: $this->asOptions($files),
            default: array_column($files, 'path'),
            scroll: 15,
        );

        if ($chosen === []) {
            $this->components->warn('Nothing selected. Carrying on without a change.');

            $this->handBack($studio, $event, ['outcome' => 'accepted']);

            return;
        }

        $note = textarea(
            label: 'Why did you make this change?',
            hint: 'What was wrong with it, in your own words.',
        );

        $forward = textarea(
            label: 'Anything to pass to the next artisan?',
            hint: 'Optional. Leave empty if the change speaks for itself.',
        );

        $sha = $changes->commitEverything($this->commitMessage($event, $note));

        $this->handBack($studio, $event, [
            'outcome' => 'updated',
            'note' => $note,
            'forward' => $forward,
            'commit' => $sha,
            'files' => array_values(array_filter(
                $files,
                fn (array $file): bool => in_array($file['path'], $chosen, true),
            )),
        ]);
    }

    /**
     * Put the developer on the build's branch, or explain why not.
     *
     * A dirty tree is refused rather than carried across: a checkout that takes
     * uncommitted work onto somebody else's branch is how a change ends up in a
     * commit nobody meant to make.
     */
    /** @param  array<string, mixed>  $event */
    private function readyTheBranch(Studio $studio, LocalChanges $changes, array $event, string $branch): bool
    {
        $what = $this->whatIsBeingReviewed($event);

        if ($branch === '') {
            $this->components->warn('This build has no branch yet, so there is nothing to check out.');

            return false;
        }

        if ($changes->currentBranch() === $branch) {
            return true;
        }

        if (! $this->waitUntilTheTreeIsClean($changes)) {
            return false;
        }

        if (! $this->mayISwitch($branch, $what)) {
            $this->components->info('Left where you are. The build is still waiting.');

            return false;
        }

        if (! $changes->switchTo($branch, $this->whereToFetchFrom($studio, $event))) {
            $this->components->error('Could not check out '.$branch.'. The build is still waiting.');

            return false;
        }

        $changes->catchUp();

        return true;
    }

    /**
     * Show what the artisan actually committed, before asking for edits.
     *
     * Landing somebody on a branch is not the same as showing them what is on
     * it — their own tree has nothing to diff against yet, so "nothing changed
     * yet" was accurate and useless: the one thing worth seeing at a
     * checkpoint is what the artisan built, and until now nothing here ever
     * showed it.
     */
    private function showWhatTheArtisanBuilt(LocalChanges $changes, string $priorBranch, string $branch): void
    {
        if ($priorBranch === $branch) {
            return;
        }

        $files = $changes->whatLandedSince($priorBranch);
        $commits = $changes->commitsSince($priorBranch);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>What landed on this branch</>', '');

        if ($commits !== '') {
            foreach (explode("\n", $commits) as $line) {
                $this->line('  <fg=gray>'.$line.'</>');
            }
            $this->newLine();
        }

        if ($files === []) {
            $this->line('  <fg=gray>no file changes found against '.$priorBranch.'</>');

            return;
        }

        $this->renderChanged($files, showPrompt: false);
    }

    /**
     * Where to fetch this branch from, asked of the studio.
     *
     * Null when the studio cannot say, in which case the fetch falls back to
     * whatever remote the clone already has — which is right for anybody whose
     * git credential works without being asked for.
     *
     * @param  array<string, mixed>  $event
     */
    private function whereToFetchFrom(Studio $studio, array $event): ?string
    {
        $meta = (array) ($event['meta'] ?? []);
        $workflow = (string) ($meta['workflow'] ?? '');

        if ($workflow === '') {
            return null;
        }

        $reach = $studio->howToReach($workflow);

        return is_array($reach) ? ($reach['remote'] ?? null) : null;
    }

    /**
     * What this checkpoint is, in the words somebody would use for it.
     *
     * @param  array<string, mixed>  $event
     */
    private function whatIsBeingReviewed(array $event): string
    {
        $agent = ucfirst((string) ($event['agent'] ?? 'this artisan'));
        $meta = (array) ($event['meta'] ?? []);
        $build = trim((string) ($meta['workflow_name'] ?? ''));

        return $build === ''
            ? $agent."'s checkpoint"
            : $agent."'s checkpoint on ".$build;
    }

    /**
     * Wait for the working tree, rather than sending somebody back to a browser.
     *
     * ★ A DEAD END IS NOT AN ANSWER. Refusing over uncommitted work and asking
     * for Review to be pressed again means leaving the terminal, finding the
     * tab, and clicking a button to be told the same thing — when the only
     * thing that has to change is right here. So it says what is in the way and
     * keeps looking, and the moment the tree is clean it carries on by itself.
     */
    private function waitUntilTheTreeIsClean(LocalChanges $changes): bool
    {
        if ($changes->isClean()) {
            return true;
        }

        $this->components->warn('You have uncommitted changes, so I cannot switch branches yet.');
        $this->line('  <fg=gray>commit or stash them and I will carry on — ctrl-c to leave it</>');

        while (! $changes->isClean()) {
            if ($this->pressedEnter()) {
                $this->line('  <fg=gray>still not clean — checking again</>');
            }

            sleep($this->pollSeconds());
        }

        $this->newLine();
        $this->components->info('Clean now.');

        return true;
    }

    /**
     * Ask before moving somebody off the branch they are standing on.
     *
     * Even a clean tree is somebody's place of work, and a checkout that
     * happens unannounced is a surprise in an editor that has just reloaded
     * every open file.
     */
    private function mayISwitch(string $branch, string $what): bool
    {
        return confirm(
            label: 'Switch to '.$branch.' to review '.$what.'?',
            default: true,
            yes: 'Switch and review',
            no: 'Leave me here',
        );
    }

    /**
     * Hold until the developer says they are done, showing what they touched.
     *
     * @return list<array{path: string, status: string}>
     */
    private function waitForYouToFinish(LocalChanges $changes): array
    {
        $seen = [];

        while (true) {
            $files = $changes->sinceTheLastCommit();

            if ($files !== $seen) {
                $this->renderChanged($files);
                $seen = $files;
            }

            if ($this->pressedEnter()) {
                return $changes->sinceTheLastCommit();
            }

            sleep($this->pollSeconds());
        }
    }

    /** @param  list<array{path: string, status: string}>  $files */
    private function renderChanged(array $files, bool $showPrompt = true): void
    {
        $this->newLine();

        if ($files === []) {
            $this->line('  <fg=gray>nothing changed yet</>');

            if ($showPrompt) {
                $this->line('  <fg=gray>press enter when you are done</>');
            }

            return;
        }

        foreach ($files as $file) {
            $this->line(sprintf(
                '  <fg=%s>%s</> %s',
                match ($file['status']) {
                    'added' => 'green',
                    'deleted' => 'red',
                    'renamed' => 'blue',
                    default => 'yellow',
                },
                str_pad($file['status'], 9),
                $file['path'],
            ));
        }

        if ($showPrompt) {
            $this->line('  <fg=gray>press enter when you are done</>');
        }
    }

    /**
     * Whether enter is waiting on STDIN, without blocking if it is not.
     *
     * A blocking read would stop the poll, and the poll is what shows the
     * developer their own work being noticed.
     */
    private function pressedEnter(): bool
    {
        $read = [STDIN];
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, 0) !== 1) {
            return false;
        }

        return fgets(STDIN) !== false;
    }

    /**
     * @param  list<array{path: string, status: string}>  $files
     * @return array<string, string>
     */
    private function asOptions(array $files): array
    {
        $options = [];

        foreach ($files as $file) {
            $options[$file['path']] = $file['status'].'  '.$file['path'];
        }

        return $options;
    }

    /** @param  array<string, mixed>  $event */
    private function commitMessage(array $event, string $note): string
    {
        $agent = (string) ($event['agent'] ?? 'artisan');
        $first = trim(explode("\n", $note)[0] ?? '');

        return $first === ''
            ? 'review: changes after '.$agent
            : 'review: '.$first;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $review
     */
    private function handBack(Studio $studio, array $event, array $review): void
    {
        $meta = (array) ($event['meta'] ?? []);
        $workflow = (string) ($meta['workflow'] ?? '');

        if ($workflow === '') {
            $this->components->warn('That checkpoint did not say which build it belongs to, so I cannot release it.');

            return;
        }

        $sent = $studio->submitReview($workflow, array_merge(
            ['agent' => (string) ($event['agent'] ?? '')],
            $review,
        ));

        $this->components->{$sent ? 'info' : 'error'}(
            $sent ? 'Sent. The build is carrying on.' : 'The studio would not take that review.',
        );
    }

    private function pollSeconds(): int
    {
        return max(1, (int) config('studio-cli.review.poll_seconds', 10));
    }
}
