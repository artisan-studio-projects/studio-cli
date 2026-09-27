<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\AvatarClips;
use ArtisanStudio\StudioCli\Saloon\Requests\ListPresenceStatesRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| Her clips, from the studio
|--------------------------------------------------------------------------
|
| The studio keeps them on its own storage; a machine keeps a copy, fetched
| once and again only when the studio says a clip has changed.
|
*/

beforeEach(function (): void {
    $this->cache = sys_get_temp_dir().'/avatar-cache-'.uniqid();
    config()->set('studio-cli.presence.source', 'studio');
    config()->set('studio-cli.presence.cache', $this->cache);

    $this->states = fn (int $updated): MockResponse => MockResponse::make(['states' => [
        ['state_key' => 'cli_workflow::done', 'desktop' => ['url' => 'https://bucket.test/sami/videos/cli.workflow_done.mov', 'updated' => $updated]],
        ['state_key' => 'cli_workflow::started', 'desktop' => ['url' => 'https://bucket.test/sami/videos/cli.workflow_started.mov', 'updated' => 100]],
        ['state_key' => 'cli_workflow::working', 'desktop' => null],
    ]]);
});

afterEach(function (): void {
    File::deleteDirectory($this->cache);
});

it('downloads what is missing, and leaves alone what has not changed', function (): void {
    Saloon::fake([ListPresenceStatesRequest::class => ($this->states)(100)]);
    Http::fake(['bucket.test/*' => Http::response('a clip')]);

    $first = app(AvatarClips::class)->sync();
    $second = app(AvatarClips::class)->sync();

    expect($first)->toBe(['downloaded' => ['cli-workflow_done', 'cli-workflow_started'], 'kept' => [], 'failed' => []])
        ->and($second)->toBe(['downloaded' => [], 'kept' => ['cli-workflow_done', 'cli-workflow_started'], 'failed' => []])
        ->and(file_get_contents($this->cache.'/cli-workflow_done.mov'))->toBe('a clip')
        ->and(is_file($this->cache.'/cli-workflow_working.mov'))->toBeFalse();

    Http::assertSentCount(2);
});

it('downloads a clip again once the studio says it changed', function (): void {
    $take = 'first take';
    Http::fake(['bucket.test/*' => function () use (&$take) {
        return Http::response($take);
    }]);
    Saloon::fake([ListPresenceStatesRequest::class => ($this->states)(100)]);
    app(AvatarClips::class)->sync();

    $take = 'second take';
    Saloon::fake([ListPresenceStatesRequest::class => ($this->states)(200)]);
    $again = app(AvatarClips::class)->sync();

    expect($again['downloaded'])->toBe(['cli-workflow_done'])
        ->and(file_get_contents($this->cache.'/cli-workflow_done.mov'))->toBe('second take');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'cli.workflow_done.mov'));
});

it('leaves no half a clip behind when a download fails, and tries it again next time', function (): void {
    Saloon::fake([ListPresenceStatesRequest::class => ($this->states)(100)]);
    Http::fake(['bucket.test/*cli.workflow_done.mov' => Http::response('', 500), 'bucket.test/*' => Http::response('a clip')]);

    $synced = app(AvatarClips::class)->sync();

    expect($synced['failed'])->toBe(['cli-workflow_done'])
        ->and(glob($this->cache.'/cli-workflow_done*'))->toBe([]);
});

it('never downloads when her clips come from a folder on this machine', function (): void {
    config()->set('studio-cli.presence.source', 'local');
    config()->set('studio-cli.presence.clips', '/some/folder');
    Http::fake();

    expect(app(AvatarClips::class)->sync())->toBe(['downloaded' => [], 'kept' => [], 'failed' => []])
        ->and(app(AvatarClips::class)->folder())->toBe('/some/folder');

    Http::assertNothingSent();
});

it('names a cached clip the way the player looks for it', function (): void {
    expect(AvatarClips::stem('cli_workflow::done'))->toBe('cli-workflow_done')
        ->and(AvatarClips::stem('cli_workflow::handover'))->toBe('cli-workflow_handover');
});
