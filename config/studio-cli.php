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

    /*
    |--------------------------------------------------------------------------
    | The avatar on the desktop
    |--------------------------------------------------------------------------
    |
    | The avatar appears while the watcher runs, reacting to the same events
    | the tab prints: a build finishing, a fix landing, something failing.
    | Entirely optional: without the player binary or the clips it never
    | appears, and the watcher is unchanged.
    |
    | ★ A WINDOW RATHER THAN THE TAB ITSELF. Terminals either render character
    | cells, which turn a face to mush, or an image protocol their renderer
    | repaints when it feels like it. A transparent window has neither limit and
    | works whichever terminal the tab is running in.
    |
    */
    'presence' => [
        'enabled' => env('STUDIO_CLI_PRESENCE', true),

        'player' => env('STUDIO_CLI_PRESENCE_PLAYER', base_path('vendor/artisan-studio-projects/studio-cli/bin/avatardesktop')),

        'clips' => env('STUDIO_CLI_PRESENCE_CLIPS', base_path('storage/app/avatar')),

        /*
        | Where the avatar's clips come from. "studio" downloads them from Artisan Studio
        | into `cache`, once, and again only when a clip changes there. "local"
        | plays the .mov files in `clips` just as they are.
        */

        'source' => env('STUDIO_CLI_PRESENCE_SOURCE', 'studio'),

        'cache' => env('STUDIO_CLI_PRESENCE_CACHE', storage_path('app/studio-cli/avatar')),

        'size' => env('STUDIO_CLI_PRESENCE_SIZE', 400),

        /*
        | Where the avatar stands and how its window behaves. `position` is
        | where it first appears along the bottom of the screen: "left",
        | "center", "right", or a percentage of the way across, like "75%".
        | Drag it somewhere else and it comes back there next time, unless
        | `remember_where_dragged` is off. "right" stands it under the
        | Activity column.
        */

        'window' => [
            'position' => env('STUDIO_CLI_PRESENCE_POSITION', 'right'),
            'margin' => 24,
            'remember_where_dragged' => true,
            'draggable' => true,
            'always_on_top' => true,
            'follow_terminal' => true,
            'follow_every_seconds' => 1.0,
            'hide_when_away' => true,
            'terminal_min_width' => 400,
            'terminal_min_height' => 300,
        ],

        /*
        | How clips follow each other. `wait_for_loop` lets a reaction start
        | once the resting loop comes round, so the two join without a jump;
        | `swap_seconds` is how long both clips overlap at the cut.
        */

        'playback' => [
            'wait_for_loop' => true,
            'swap_seconds' => 0.08,
        ],
    ],
];
