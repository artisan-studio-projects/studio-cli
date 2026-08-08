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
];
