<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Workspace;

/*
|--------------------------------------------------------------------------
| Whether it is safe to touch this checkout
|--------------------------------------------------------------------------
|
| Adding a worktree is harmless in itself, but a developer with uncommitted
| changes is mid-thought, and a directory appearing beside their project is not
| what they asked for.
|
*/

function aRepository(): string
{
    $path = sys_get_temp_dir().'/studio-cli-repo-'.bin2hex(random_bytes(4));

    mkdir($path, 0755, true);

    foreach ([
        'git init --quiet',
        'git config user.email test@example.com',
        'git config user.name Test',
        'git commit --quiet --allow-empty -m first',
    ] as $command) {
        exec('cd '.escapeshellarg($path).' && '.$command.' 2>&1');
    }

    return $path;
}

afterEach(function (): void {
    if (isset($this->repo)) {
        exec('rm -rf '.escapeshellarg($this->repo));
    }
});

it('is happy with a clean repository', function (): void {
    $this->repo = aRepository();

    expect((new Workspace($this->repo))->whyItCannotWatch())->toBeNull();
});

it('refuses somewhere that is not a repository at all', function (): void {
    $this->repo = sys_get_temp_dir().'/studio-cli-bare-'.bin2hex(random_bytes(4));

    mkdir($this->repo, 0755, true);

    expect((new Workspace($this->repo))->whyItCannotWatch())->toContain('not a git repository');
});

it('refuses while there is uncommitted work', function (): void {
    $this->repo = aRepository();

    file_put_contents($this->repo.'/tracked.txt', 'first');
    exec('cd '.escapeshellarg($this->repo).' && git add . && git commit --quiet -m tracked');
    file_put_contents($this->repo.'/tracked.txt', 'changed since');

    expect((new Workspace($this->repo))->whyItCannotWatch())->toContain('uncommitted changes');
});

/**
 * A stray scratch file is not work in progress, and refusing over one would
 * make the check something to work around rather than trust.
 */
it('does not mind an untracked file lying around', function (): void {
    $this->repo = aRepository();

    file_put_contents($this->repo.'/scratch.txt', 'ignore me');

    expect((new Workspace($this->repo))->whyItCannotWatch())->toBeNull();
});

it('puts the preview outside the repository, one directory per workflow', function (): void {
    $this->repo = aRepository();

    config()->set('studio-cli.worktree.path', '../previews');

    $path = (new Workspace($this->repo))->worktreePathFor('art-213');

    expect($path)->toBe($this->repo.'/../previews/art-213');
});

it('takes an absolute preview path as given', function (): void {
    $this->repo = aRepository();

    config()->set('studio-cli.worktree.path', '/tmp/studio-previews');

    expect((new Workspace($this->repo))->worktreePathFor('art-213'))
        ->toBe('/tmp/studio-previews/art-213');
});

/**
 * Detached on purpose: nothing the mirror writes should be committable, and a
 * detached HEAD makes that a property of the checkout rather than a rule
 * somebody has to remember.
 */
it('opens a worktree detached from the branch', function (): void {
    $this->repo = aRepository();

    exec('cd '.escapeshellarg($this->repo).' && git branch sami/art-213');

    config()->set('studio-cli.worktree.path', $this->repo.'-preview');

    $workspace = new Workspace($this->repo);
    $path = $workspace->openWorktree('art-213', 'sami/art-213');

    expect(is_dir($path))->toBeTrue();

    exec('cd '.escapeshellarg($path).' && git symbolic-ref -q HEAD', $output, $status);

    expect($status)->not->toBe(0);

    $workspace->closeWorktree('art-213');
    exec('rm -rf '.escapeshellarg($this->repo.'-preview'));
});

it('hands back the worktree it already opened', function (): void {
    $this->repo = aRepository();

    exec('cd '.escapeshellarg($this->repo).' && git branch sami/art-213');

    config()->set('studio-cli.worktree.path', $this->repo.'-preview');

    $workspace = new Workspace($this->repo);

    expect($workspace->openWorktree('art-213', 'sami/art-213'))
        ->toBe($workspace->openWorktree('art-213', 'sami/art-213'));

    $workspace->closeWorktree('art-213');
    exec('rm -rf '.escapeshellarg($this->repo.'-preview'));
});
