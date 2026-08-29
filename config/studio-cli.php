<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Studio
    |--------------------------------------------------------------------------
    |
    | Where Artisan Studio lives, and the token it issued for this project.
    | Written by `studio:settings`; the studio only ever reads it.
    |
    */

    'url' => env('ARTISAN_STUDIO_URL', 'https://artisan-studio.app'),

    'token' => env('ARTISAN_STUDIO_TOKEN'),

    /*
     * Which project this checkout is, by slug. Written by `studio:settings`
     * alongside the token.
     */
    'project' => env('ARTISAN_STUDIO_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | Branches and commits
    |--------------------------------------------------------------------------
    |
    | Fixes and builds are made on branches of their own, named with these
    | prefixes, and their commits carry `commit_scope`, as in feat(scope):.
    | Only branches with these prefixes are ever committed to or sent for a
    | pull request. Artisan Studio opens pull requests only from branches it
    | knows, so change a prefix only once your studio accepts it. `lock` is
    | the file in .git that keeps two fixers from committing at once.
    |
    */

    'branches' => [
        'fixes' => env('STUDIO_CLI_FIX_BRANCH_PREFIX', 'sami/fixes-'),
        'build' => env('STUDIO_CLI_BUILD_BRANCH_PREFIX', 'sami/build-'),
        'commit_scope' => env('STUDIO_CLI_COMMIT_SCOPE', 'sami'),
        'lock' => 'sami-fixes.lock',
    ],
];
