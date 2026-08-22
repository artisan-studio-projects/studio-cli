<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Studio
    |--------------------------------------------------------------------------
    |
    | Where Artisan Studio lives, and the token it issued for this project.
    | Written by `artisan-studio:link`; the watcher only ever reads it.
    |
    */

    'url' => env('ARTISAN_STUDIO_URL', 'https://artisan-studio.app'),

    'token' => env('ARTISAN_STUDIO_TOKEN'),

    /*
     * Which project this checkout is, by slug. Written by `artisan-studio:link`
     * alongside the token.
     */
    'project' => env('ARTISAN_STUDIO_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | Preview worktree
    |--------------------------------------------------------------------------
    |
    | Where a workflow's files are mirrored to.
    |
    | A worktree rather than the working tree, and OUTSIDE the repository: the
    | branch you are on stays where you left it, several workflows can be open
    | at once, and — the point — what the artisans are writing is somewhere you
    | cannot mistake for your own work.
    |
    | Relative paths resolve against the project root.
    |
    */

    'worktree' => [
        'path' => env('ARTISAN_STUDIO_WORKTREE', '../artisan-studio-preview'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Watching
    |--------------------------------------------------------------------------
    |
    | The stream is held open and pushes as artisans work, so nothing here is a
    | poll interval. These are what to do when the connection drops.
    |
    | ★ NEVER FAIL SILENTLY. `artisan dev` restarts a crashed tab on its own, so
    | a watcher that exits quietly comes straight back and looks like it is
    | working. Every retry says so on screen.
    |
    */

    'watch' => [
        'reconnect_seconds' => (int) env('ARTISAN_STUDIO_RECONNECT', 5),
        'max_reconnect_seconds' => (int) env('ARTISAN_STUDIO_RECONNECT_MAX', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dev tab
    |--------------------------------------------------------------------------
    |
    | Registers the watcher as a tab in `artisan dev`, beside horizon, server,
    | logs and vite.
    |
    | Set to false to keep the command without the tab. You can also leave this
    | alone and call `DevCommands::except('Artisan Studio')` yourself — a
    | userland registration outranks this package's either way.
    |
    */

    'dev_tab' => [
        'enabled' => (bool) env('ARTISAN_STUDIO_DEV_TAB', true),

        'until_linked' => (bool) env('ARTISAN_STUDIO_DEV_TAB_UNLINKED', false),
        'name' => env('ARTISAN_STUDIO_DEV_TAB_NAME', 'Artisan Studio'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SAMI on the desktop
    |--------------------------------------------------------------------------
    |
    | She appears while the watcher runs, reacting to the same events the tab
    | prints — a build finishing, an artisan handing over, something failing.
    | Entirely optional: without the player binary or the clips she never
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

        'player' => env('STUDIO_CLI_PRESENCE_PLAYER', base_path('vendor/artisan-studio-projects/studio-cli/bin/samidesktop')),

        'clips' => env('STUDIO_CLI_PRESENCE_CLIPS', base_path('storage/app/sami')),

        'size' => env('STUDIO_CLI_PRESENCE_SIZE', 400),
    ],
];
