<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Mirror;
use ArtisanStudio\StudioCli\Workspace;

/*
|--------------------------------------------------------------------------
| Artisan output, written to the preview worktree
|--------------------------------------------------------------------------
|
| One direction, and only inside the worktree. A path arrives over the network,
| so a `..` in one would put an artisan's file anywhere on the machine.
|
*/

function aMirrorInto(string $directory): Mirror
{
    $workspace = new class($directory) extends Workspace
    {
        public function __construct(private readonly string $where)
        {
            parent::__construct($where);
        }

        public function whyItCannotWatch(): ?string
        {
            return null;
        }

        public function openWorktree(string $reference, string $branch): string
        {
            return $this->where;
        }

        public function fetch(): void {}
    };

    $mirror = new Mirror($workspace);
    $mirror->follow('art-213', 'sami/art-213');

    return $mirror;
}

beforeEach(function (): void {
    $this->preview = sys_get_temp_dir().'/studio-cli-'.bin2hex(random_bytes(4));

    mkdir($this->preview, 0755, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->preview));
});

it('writes a file event into the worktree', function (): void {
    $written = aMirrorInto($this->preview)->apply([
        'type' => 'file',
        'path' => 'app/Models/Invitation.php',
        'contents' => '<?php class Invitation {}',
    ]);

    expect($written)->toBe($this->preview.'/app/Models/Invitation.php')
        ->and(file_get_contents($written))->toBe('<?php class Invitation {}');
});

it('creates the directories a new file needs', function (): void {
    aMirrorInto($this->preview)->apply([
        'type' => 'file',
        'path' => 'tests/Feature/Deeply/Nested/ThingTest.php',
        'contents' => '<?php // a test',
    ]);

    expect(is_file($this->preview.'/tests/Feature/Deeply/Nested/ThingTest.php'))->toBeTrue();
});

it('leaves anything that is not a file event alone', function (): void {
    $mirror = aMirrorInto($this->preview);

    expect($mirror->apply(['type' => 'task', 'title' => 'Repair the sections']))->toBeNull()
        ->and($mirror->apply(['type' => 'test', 'passed' => 3]))->toBeNull()
        ->and($mirror->apply(['type' => 'idle']))->toBeNull();
});

it('refuses a path that climbs out of the worktree', function (string $path): void {
    expect(aMirrorInto($this->preview)->apply([
        'type' => 'file',
        'path' => $path,
        'contents' => 'nope',
    ]))->toBeNull();
})->with([
    '../../../etc/passwd',
    'app/../../outside.php',
    '/etc/passwd',
    '',
]);

it('refuses to write into the git directory, where a hook would be run', function (string $path): void {
    expect(aMirrorInto($this->preview)->apply([
        'type' => 'file',
        'path' => $path,
        'contents' => '#!/bin/sh',
    ]))->toBeNull();
})->with([
    '.git/hooks/pre-commit',
    '.git/config',
    '.GIT/hooks/post-checkout',
    '.git\\hooks\\pre-push',
]);

it('writes nothing for an event that carries no contents', function (): void {
    expect(aMirrorInto($this->preview)->apply([
        'type' => 'file',
        'path' => 'app/Models/Invitation.php',
    ]))->toBeNull();
});

it('writes nothing at all until it is following a workflow', function (): void {
    $workspace = new Workspace($this->preview);

    expect((new Mirror($workspace))->apply([
        'type' => 'file',
        'path' => 'app/Models/Invitation.php',
        'contents' => '<?php',
    ]))->toBeNull();
});

/**
 * ★ THE CLEAN-TREE CHECK MOVED TO WHERE THE WORKTREE IS MADE.
 *
 * It used to run before the watcher connected, which refused the dev tab to
 * anybody mid-change over a directory that was not going to be created — a
 * watch reads a stream and touches nothing until a build produces files. The
 * guard still holds, at the moment it actually protects something.
 */
it('refuses to open a worktree over uncommitted work', function (): void {
    $workspace = new class(sys_get_temp_dir()) extends Workspace
    {
        public function __construct(private readonly string $where)
        {
            parent::__construct($where);
        }

        public function whyItCannotWatch(): ?string
        {
            return 'You have uncommitted changes.';
        }

        public function openWorktree(string $reference, string $branch): string
        {
            throw new RuntimeException('should never be reached');
        }

        public function fetch(): void {}
    };

    expect(fn () => (new Mirror($workspace))->follow('run-1', 'main'))
        ->toThrow(RuntimeException::class, 'You have uncommitted changes.');
});
