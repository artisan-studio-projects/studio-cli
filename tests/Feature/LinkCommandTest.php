<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Saloon\Requests\ListProjectsRequest;
use ArtisanStudio\StudioCli\Studio;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| Connecting a checkout to the studio
|--------------------------------------------------------------------------
|
| ★ A REFUSED TOKEN IS NOT AN EMPTY LIST. Saloon does not throw on a 401, and a
| body with no `projects` key falls through to the default — so a token the
| studio had never seen came back indistinguishable from a good one belonging to
| somebody with no projects, and the command said a made-up token was "valid but
| reaches no projects".
|
*/

it('says so when the studio does not recognise the token', function (): void {
    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401),
    ]);

    expect(fn () => app(Studio::class)->withToken('made-up')->projects())
        ->toThrow(RuntimeException::class, 'does not recognise that token');
});

it('says so when the studio is having a bad time', function (): void {
    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['message' => 'Server Error'], 500),
    ]);

    expect(fn () => app(Studio::class)->projects())
        ->toThrow(RuntimeException::class, 'answered with 500');
});

/**
 * A good token with nothing on it is a real state, and a different message.
 */
it('hands back nothing when the token works but has no projects', function (): void {
    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['projects' => []], 200),
    ]);

    expect(app(Studio::class)->projects())->toBe([]);
});

it('hands back the projects a token reaches', function (): void {
    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make([
            'projects' => [['id' => 8, 'name' => 'Artisan Studio']],
        ], 200),
    ]);

    expect(app(Studio::class)->projects())->toHaveCount(1);
});

/**
 * ★ A 404 IS USUALLY THE WRONG STUDIO, NOT A BROKEN ONE. The endpoint exists on
 * every studio that has this feature, so an address that answers and does not
 * have it is almost always somewhere else — a staging URL, a typo, or the
 * hosted default on a machine meant to point at a local one.
 */
it('names the address when there is nothing at it', function (): void {
    config()->set('studio-cli.url', 'https://not-a-studio.test');

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['message' => 'Not Found'], 404),
    ]);

    expect(fn () => app(Studio::class)->projects())
        ->toThrow(RuntimeException::class, 'is that the right studio?');
});
