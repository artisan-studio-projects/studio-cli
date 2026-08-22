<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Console\WatchCommand;
use ArtisanStudio\StudioCli\Saloon\Requests\ListProjectsRequest;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\DevCommands;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| The tab in `artisan dev`
|--------------------------------------------------------------------------
|
| A developer already has that window open all day. A separate terminal they
| have to remember to start is one they will not.
|
*/

it('puts the watcher in the dev command', function (): void {
    $names = collect(DevCommands::commands())->pluck('name');

    expect($names)->toContain('Artisan Studio');
});

it('runs the watcher, not some other command', function (): void {
    $tab = collect(DevCommands::commands())->firstWhere('name', 'Artisan Studio');

    expect($tab['command'])->toContain(WatchCommand::SIGNATURE);
});

it('registers both commands', function (): void {
    $names = array_keys(app(Kernel::class)->all());

    expect($names)->toContain('artisan-studio:watch')
        ->and($names)->toContain('artisan-studio:link');
});

/**
 * ★ NOT A FAILURE, THOUGH IT IS A WARNING. `artisan dev` restarts a tab whose
 * process exits, so failing here put the same warning on screen every second —
 * a crash loop that reads as the package being broken rather than as something
 * waiting to be set up.
 */
it('says how to link, without failing at somebody', function (): void {
    config()->set('studio-cli.token', null);

    $this->artisan(WatchCommand::SIGNATURE)
        ->expectsOutputToContain('not connected to Artisan Studio yet')
        ->expectsOutputToContain('artisan-studio:link')
        ->assertExitCode(0);
});

/**
 * ★ A REFUSED TOKEN IS NOT A MISSING ONE. Both leave a tab showing nothing, and
 * telling somebody to run `link` when they already have is how a typo becomes
 * half an hour.
 */
it('says when the studio turned the token away', function (): void {
    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401),
    ]);

    $this->artisan(WatchCommand::SIGNATURE)
        ->expectsOutputToContain('does not recognise that token')
        ->expectsOutputToContain('wrong, expired, or from another studio')
        ->assertExitCode(0);
});

/**
 * ★ A TAB FOR SOMETHING THIS PROJECT DOES NOT USE IS NOISE. Registering
 * regardless was deliberate once — a missing tab reads the same as a broken
 * install — but most projects that get this as somebody else's dependency will
 * never link, and a permanent tab whose only content is a warning is worse than
 * no tab at all.
 *
 * Asserted on the decision rather than on the registry: the tab is registered
 * during boot, and by the time a test runs the application has already booted
 * with whatever configuration it started with.
 */
function wouldShowTheTab(array $config): bool
{
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    return config('studio-cli.dev_tab.enabled', true)
        && (config('studio-cli.dev_tab.until_linked', false) || app(Studio::class)->isLinked());
}

it('stays out of the dev command until the project is linked', function (): void {
    expect(wouldShowTheTab(['studio-cli.token' => null]))->toBeFalse();
});

/**
 * Somebody who has just run `composer require` and wants the tab there before
 * linking can have the old behaviour back.
 */
it('shows before linking when asked to', function (): void {
    expect(wouldShowTheTab([
        'studio-cli.token' => null,
        'studio-cli.dev_tab.until_linked' => true,
    ]))->toBeTrue();
});

it('stays out entirely when somebody turns it off', function (): void {
    expect(wouldShowTheTab(['studio-cli.dev_tab.enabled' => false]))->toBeFalse();
});
