<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Saloon\Requests\PingRequest;
use ArtisanStudio\StudioCli\Studio;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    config(['studio-cli.url' => 'https://studio.test', 'studio-cli.token' => null]);
});

it('pings the studio with the repository before there is a token, and leaves the connection unknown', function (): void {
    Saloon::fake([PingRequest::class => MockResponse::make([], 204)]);

    $studio = app(Studio::class);
    $studio->ping('artisan-studio-projects/artisan-studio');

    Saloon::assertSent(fn (PingRequest $request, $response): bool => $response->getPendingRequest()->body()->all() === ['repository' => 'artisan-studio-projects/artisan-studio']);
    expect($studio->hasConnected())->toBeFalse();
});

it('sends nothing when there is no repository to name', function (): void {
    Saloon::fake([PingRequest::class => MockResponse::make([], 204)]);

    app(Studio::class)->ping(null);

    Saloon::assertNothingSent();
});

it('keeps going when the studio cannot be reached', function (): void {
    Saloon::fake([PingRequest::class => MockResponse::make([], 500)->throw(fn (PendingRequest $pending) => new RuntimeException('down'))]);

    app(Studio::class)->ping('artisan-studio-projects/artisan-studio');

    expect(true)->toBeTrue();
});
