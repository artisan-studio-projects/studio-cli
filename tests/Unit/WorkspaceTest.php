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
