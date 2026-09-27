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
    | Review
    |--------------------------------------------------------------------------
    |
    | `artisan-studio:review` waits while you edit and shows what you have
    | touched. This is how often it asks git — cheap enough to be frequent
    | (tens of milliseconds on a large repository), slow enough to be invisible.
    |
    */

    'review' => [
        'poll_seconds' => (int) env('ARTISAN_STUDIO_REVIEW_POLL', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme
    |--------------------------------------------------------------------------
    |
    | The colours every `studio` screen is drawn in, as hex. `mode` picks the
    | set: "dark" for a dark terminal, "light" for a light one.
    |
    | `background` is painted over the whole terminal: black in dark mode, a
    | light slate grey in light mode. Set it to "terminal" to draw on the
    | terminal's own background instead.
    |
    | Components ask for a colour by name, so changing one here recolours it
    | everywhere it is used. Leave a name out and its default is used; a value
    | that is not a hex is ignored the same way.
    |
    */

    'theme' => [
        'mode' => env('ARTISAN_STUDIO_THEME', 'dark'),

        'dark' => [
            'background' => '000000',

            'colours' => [
                'ink' => 'eef1fd',
                'soft' => 'b3bbd9',
                'dim' => '7a84ad',
                'band' => '131a29',
                'edge' => '2a3249',
                'track' => '1c2335',
                'cyan' => '1deced',
                'cyan-bg' => '0a3a40',
                'blue' => '3b82f6',
                'blue-bg' => '0f2552',
                'sky' => 'a5f3fc',
                'sky-bg' => '123b45',
                'green' => '6fdca6',
                'green-bg' => '123a28',
                'amber' => 'f5b454',
                'amber-bg' => '3d2c10',
                'rose' => 'ff8aa6',
                'rose-bg' => '421626',
                'glow-from' => '1deced',
                'glow-to' => '3b82f6',
                'glow-head' => '00fff0',
            ],
        ],

        'light' => [
            'background' => 'f1f5f9',

            'colours' => [
                'ink' => '0f172a',
                'soft' => '334155',
                'dim' => '64748b',
                'band' => 'e2e8f0',
                'edge' => 'cbd5e1',
                'track' => 'd5dce6',
                'cyan' => '0891b2',
                'cyan-bg' => 'cffafe',
                'blue' => '2563eb',
                'blue-bg' => 'dbeafe',
                'sky' => '0284c7',
                'sky-bg' => 'e0f2fe',
                'green' => '16a34a',
                'green-bg' => 'dcfce7',
                'amber' => 'd97706',
                'amber-bg' => 'fef3c7',
                'rose' => 'e11d48',
                'rose-bg' => 'ffe4e6',
                'glow-from' => '06b6d4',
                'glow-to' => '2563eb',
                'glow-head' => '1e40af',
            ],
        ],
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

        /*
        | Where her clips come from. "studio" downloads them from Artisan Studio
        | into `cache`, once, and again only when a clip changes there. "local"
        | plays the .mov files in `clips` just as they are.
        */

        'source' => env('STUDIO_CLI_PRESENCE_SOURCE', 'studio'),

        'cache' => env('STUDIO_CLI_PRESENCE_CACHE', storage_path('app/studio-cli/avatar')),

        'size' => env('STUDIO_CLI_PRESENCE_SIZE', 400),

        /*
        | Where she stands and how her window behaves. `position` is where she
        | first appears along the bottom of the screen: "left", "center",
        | "right", or a percentage of the way across, like "75%". Drag her
        | somewhere else and she comes back there next time, unless
        | `remember_where_dragged` is off.
        */

        'window' => [
            'position' => env('STUDIO_CLI_PRESENCE_POSITION', 'center'),
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
        | once her resting loop comes round, so the two join without a jump;
        | `swap_seconds` is how long both clips overlap at the cut.
        */

        'playback' => [
            'wait_for_loop' => true,
            'swap_seconds' => 0.08,
        ],
    ],
];
