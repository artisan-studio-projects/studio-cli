<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\LocalBranch;

/*
|--------------------------------------------------------------------------
| Where fixes and builds are made
|--------------------------------------------------------------------------
|
| The branch prefixes and commit scope come from the config, so a project
| can name them its own way. A prefix that is not a plain name is refused,
| since an empty one would match every branch.
|
*/

it('makes fixes and builds on the branches the config names, and scopes their commits the same way', function (): void {
    config(['studio-cli.branches' => ['fixes' => 'team/fixes-', 'build' => 'team/build-', 'commit_scope' => 'team', 'lock' => 'team-fixes.lock']]);

    expect(LocalBranch::isStudioBranch('team/fixes-2026-10-20'))->toBeTrue()
        ->and(LocalBranch::isStudioBranch('team/build-coverage'))->toBeTrue()
        ->and(LocalBranch::isStudioBranch('fixes/2026-10-20'))->toBeFalse()
        ->and(LocalBranch::commitScope())->toBe('team');
});

it('refuses a prefix that is empty or not a plain name, rather than taking every branch for its own', function (string $prefix): void {
    config(['studio-cli.branches.fixes' => $prefix]);

    expect(fn (): bool => LocalBranch::isStudioBranch('main'))->toThrow(RuntimeException::class);
})->with(['empty' => '', 'parent folder' => '../main', 'spaces' => 'my fixes ']);
