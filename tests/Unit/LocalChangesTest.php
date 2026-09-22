<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\LocalChanges;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| What the developer changed while the build waited
|--------------------------------------------------------------------------
|
| An artisan writes one fat component and the person reviewing it extracts
| three Blade partials. Three of those four files are UNTRACKED, and a review
| that reads only tracked changes submits a component referencing views the
| studio never heard of.
|
*/

function aRepositoryAt(string $path): void
{
    mkdir($path, 0755, true);

    foreach ([
        ['init', '--quiet'],
        ['config', 'user.email', 'pair@artisan.test'],
        ['config', 'user.name', 'Pair'],
    ] as $arguments) {
        (new Process(['git', ...$arguments], $path))->run();
    }
}

function commitEverythingIn(string $path, string $message): void
{
    (new Process(['git', 'add', '--all'], $path))->run();
    (new Process(['git', 'commit', '--quiet', '--message', $message], $path))->run();
}

beforeEach(function (): void {
    $this->repo = sys_get_temp_dir().'/studio-review-'.bin2hex(random_bytes(4));

    aRepositoryAt($this->repo);

    mkdir($this->repo.'/app/Livewire', 0755, true);
    file_put_contents($this->repo.'/app/Livewire/Invites.php', '<?php // pixel wrote this');

    commitEverythingIn($this->repo, 'pixel: the wireframe');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->repo));
});

it('sees nothing when the developer has not touched anything', function (): void {
    expect((new LocalChanges($this->repo))->sinceTheLastCommit())->toBe([])
        ->and((new LocalChanges($this->repo))->isClean())->toBeTrue();
});

it('sees an extraction as the new files it really is', function (): void {
    file_put_contents($this->repo.'/app/Livewire/Invites.php', '<?php // thinned out');

    mkdir($this->repo.'/resources/views/livewire', 0755, true);
    file_put_contents($this->repo.'/resources/views/livewire/row.blade.php', 'row');
    file_put_contents($this->repo.'/resources/views/livewire/form.blade.php', 'form');

    $changed = collect((new LocalChanges($this->repo))->sinceTheLastCommit())
        ->keyBy('path')
        ->map(fn (array $file): string => $file['status']);

    expect($changed['app/Livewire/Invites.php'])->toBe('modified')
        ->and($changed['resources/views/livewire/row.blade.php'])->toBe('added')
        ->and($changed['resources/views/livewire/form.blade.php'])->toBe('added')
        ->and($changed)->toHaveCount(3);
});

it('sees a file the developer deleted', function (): void {
    unlink($this->repo.'/app/Livewire/Invites.php');

    expect((new LocalChanges($this->repo))->sinceTheLastCommit())
        ->toBe([['path' => 'app/Livewire/Invites.php', 'status' => 'deleted']]);
});

it('commits everything it found and answers with the sha', function (): void {
    file_put_contents($this->repo.'/app/Livewire/InviteRow.php', '<?php // extracted');

    $changes = new LocalChanges($this->repo);
    $sha = $changes->commitEverything('review: extracted the row');

    expect($sha)->toHaveLength(40)
        ->and($changes->isClean())->toBeTrue();
});

it('reads a path with a space in it', function (): void {
    file_put_contents($this->repo.'/app/Livewire/Invite Row.php', '<?php');

    expect((new LocalChanges($this->repo))->sinceTheLastCommit())
        ->toBe([['path' => 'app/Livewire/Invite Row.php', 'status' => 'added']]);
});

/*
|--------------------------------------------------------------------------
| Git is never allowed to ask a question
|--------------------------------------------------------------------------
|
| A fetch against an SSH remote with a passphrase-protected key stops and
| waits for input that nobody is reading — so it sat there until the process
| timed out and took the pairing session down with it: "Lost the studio", over
| a credential the fetch did not even need.
|
*/

it('tells git there is nobody to ask', function (): void {
    $asking = (new ReflectionMethod(new LocalChanges($this->repo), 'neverAsking'))
        ->invoke(new LocalChanges($this->repo));

    expect($asking['GIT_TERMINAL_PROMPT'])->toBe('0')
        ->and($asking['GIT_SSH_COMMAND'])->toContain('BatchMode=yes')
        ->and($asking)->toHaveKeys(['GIT_ASKPASS', 'SSH_ASKPASS']);
});

it('carries on when a remote it cannot reach refuses it', function (): void {
    $changes = new LocalChanges($this->repo);

    (new Process(['git', 'remote', 'add', 'origin', 'git@example.invalid:nobody/nothing.git'], $this->repo))->run();

    $started = microtime(true);

    (new ReflectionMethod($changes, 'tryToFetch'))->invoke($changes);

    expect(microtime(true) - $started)->toBeLessThan(16.0)
        ->and($changes->isClean())->toBeTrue();
});

it('counts the lines each file gained and lost, a new file as wholly added', function (): void {
    file_put_contents($this->repo.'/app/Livewire/Invites.php', "<?php\n// thinned out\n// and more\n");
    file_put_contents($this->repo.'/app/Livewire/MetricsDashboard.php', "<?php\n\nclass MetricsDashboard {}\n");

    $lines = (new LocalChanges($this->repo))->lineChangesSinceTheLastCommit();

    expect($lines['app/Livewire/Invites.php'])->toMatchArray(['added' => 3, 'removed' => 1])
        ->and($lines['app/Livewire/MetricsDashboard.php'])->toMatchArray(['added' => 3, 'removed' => 0]);
});

it('puts the review on the build branch where the next artisan will see it', function (): void {
    $remote = $this->repo.'-remote.git';
    (new Process(['git', 'init', '--bare', '--quiet', $remote]))->run();

    file_put_contents($this->repo.'/app/Livewire/MetricsDashboard.php', '<?php // the developer added this');
    commitEverythingIn($this->repo, 'fix(metrics): the component that was missing');

    $pushed = (new LocalChanges($this->repo))->publish('sami/sandbox-metrics', $remote);

    $onGitHub = new Process(['git', 'log', '-1', '--format=%s', 'sami/sandbox-metrics'], $remote);
    $onGitHub->run();

    exec('rm -rf '.escapeshellarg($remote));

    expect($pushed)->toBeTrue()
        ->and(trim($onGitHub->getOutput()))->toBe('fix(metrics): the component that was missing');
});

it('reads the scope the build commits under', function (): void {
    file_put_contents($this->repo.'/app/Livewire/Invites.php', '<?php // pixel again');
    commitEverythingIn($this->repo, 'feat(project-metrics-dashboard): @pixel created the layout');

    expect((new LocalChanges($this->repo))->scopeOfTheLastCommit())->toBe('project-metrics-dashboard');
});
