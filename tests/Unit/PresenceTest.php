<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Workspace;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| SAMI reacting to a watched build
|--------------------------------------------------------------------------
|
| She mirrors the tab: every appearance is an event the watcher already
| printed, so she never says something it did not and never appears when
| nothing happened. All of it optional — no player, no clips, or not a Mac and
| she simply never shows up.
|
*/

beforeEach(function (): void {
    $this->clips = sys_get_temp_dir().'/sami-clips-'.uniqid();
    mkdir($this->clips);

    /**
     * A stand-in player that records what it was told rather than opening a
     * window: she is driven down a pipe now, so the thing worth asserting is
     * the line that reaches her, not that a process was started.
     */
    $this->said = $this->clips.'/said.txt';
    $this->player = $this->clips.'/player';

    file_put_contents($this->player, "#!/bin/bash\ncat >> ".escapeshellarg($this->said)."\n");
    chmod($this->player, 0755);

    config()->set('studio-cli.presence.enabled', true);
    config()->set('studio-cli.presence.player', $this->player);
    config()->set('studio-cli.presence.clips', $this->clips);

    $this->sami = new Presence(new Workspace(getcwd()));
});

afterEach(function (): void {
    array_map('unlink', glob($this->clips.'/*') ?: []);
    @rmdir($this->clips);
});

function aClipFor(string $state): void
{
    touch(test()->clips.'/'.$state.'.mov');
}

/** What she was told to play, once the pipe has been closed. */
function whatSheWasTold(): string
{
    test()->sami->dismiss();

    usleep(200_000);

    return (string) @file_get_contents(test()->said);
}

it('plays the clip a kind of event calls for', function (): void {
    aClipFor('cli-workflow_done');

    test()->sami->react(['kind' => 'done']);

    expect(whatSheWasTold())->toContain('cli-workflow_done.mov');
});

/**
 * ★ IDLE IS THE FALLBACK, NOT AN ERROR. A studio mid-build has clips for some
 * states and not others — that is the normal condition — so a state nobody has
 * filmed yet plays her idle rather than failing or skipping the event.
 */
it('falls back to idle when that state has no clip', function (): void {
    aClipFor('cli-workflow_started');

    test()->sami->react(['kind' => 'done']);

    expect(whatSheWasTold())->toContain('cli-workflow_started.mov');
});

it('stays away when there are no clips at all', function (): void {
    test()->sami->react(['kind' => 'done']);

    expect(whatSheWasTold())->toBe('');
});

it('stays away when she is turned off', function (): void {
    aClipFor('cli-workflow_started');
    config()->set('studio-cli.presence.enabled', false);

    test()->sami->react(['kind' => 'done']);

    expect(whatSheWasTold())->toBe('');
});

it('stays away when the player was never built', function (): void {
    aClipFor('cli-workflow_started');
    config()->set('studio-cli.presence.player', '/nowhere/samidesktop');

    test()->sami->react(['kind' => 'done']);

    expect(whatSheWasTold())->toBe('');
});

/**
 * An unrecognised kind is still an event worth being present for — she plays
 * her idle rather than deciding the build is not happening.
 */
it('is present for an event she has no reaction to', function (): void {
    aClipFor('cli-workflow_started');

    test()->sami->react(['kind' => 'something-new']);

    expect(whatSheWasTold())->toContain('cli-workflow_started.mov');
});

/*
|--------------------------------------------------------------------------
| Building the player
|--------------------------------------------------------------------------
|
| ★ COMPILED ON THE MACHINE, NOT SHIPPED. A binary in a Composer package is
| something nobody can read and everybody has to trust — and it would be the
| wrong architecture for half of them anyway.
|
*/

it('refuses to build anywhere but a Mac', function (): void {
    if (PHP_OS_FAMILY === 'Darwin') {
        expect(true)->toBeTrue();

        return;
    }

    $this->artisan('artisan-studio:build-presence')
        ->expectsOutputToContain('macOS only')
        ->assertExitCode(1);
})->skip(PHP_OS_FAMILY === 'Darwin', 'Only meaningful off a Mac.');

it('compiles a player that runs', function (): void {
    $target = sys_get_temp_dir().'/samidesktop-'.uniqid();
    config()->set('studio-cli.presence.player', $target);

    $this->artisan('artisan-studio:build-presence')->assertExitCode(0);

    expect(is_executable($target))->toBeTrue();

    @unlink($target);
})->skip(PHP_OS_FAMILY !== 'Darwin', 'Needs a Mac and a Swift compiler.');

/**
 * ★ NAMED THE WAY THE STUDIO NAMES CLIPS. States are composite keys —
 * `section_state` — and `AvatarState::fileStem()` turns one into a filename by
 * swapping the `::` variant marker to an underscore and every other underscore
 * to a dash. A clip dropped in by hand has to match what the studio would have
 * called it, or the two halves disagree about the same file.
 */
it('looks for a clip under the name the studio would give it', function (): void {
    aClipFor('cli-workflow_handover');

    test()->sami->react(['kind' => 'inbound']);

    expect(whatSheWasTold())->toContain('cli-workflow_handover.mov');
});

/**
 * A variant — `workflow_done::retry` — keeps its marker as the single
 * underscore, which is free once the others have become dashes.
 */
/**
 * ★ SWAPPED SIMULTANEOUSLY, NOT IN SEQUENCE. Replacing `::` and then every `_`
 * turns the underscore just written back into a dash, so `cli_workflow::done`
 * came out `cli-workflow-done` where the studio calls it `cli-workflow_done`.
 */
it('names a variant the way the studio would', function (): void {
    aClipFor('cli-workflow_done');

    $path = (fn (): ?string => $this->clipPath('cli_workflow::done'))->call(test()->sami);

    expect($path)->not->toBeNull()
        ->and($path)->toContain('cli-workflow_done.mov');
});

/**
 * ★ A SIZE IS A PREFERENCE, NOT A FREE HAND. Too small she is a smudge, too
 * large and she is a person standing in front of the work — and neither is
 * obvious until the tab is already running.
 */
it('keeps her a sensible size whatever the config says', function (): void {
    config()->set('studio-cli.presence.size', 5000);

    expect((fn (): int => $this->howTallSheStands())->call(test()->sami))->toBe(900);
});

it('will not shrink her into a smudge', function (): void {
    config()->set('studio-cli.presence.size', 10);

    expect((fn (): int => $this->howTallSheStands())->call(test()->sami))->toBe(120);
});

/**
 * ★ ONE WINDOW, MANY CLIPS. Every reaction used to be its own process — the old
 * one killed, a new one launched — and between the two there was a moment with
 * no window on screen, which read as a flicker every time she changed what she
 * was doing. Both clips reaching the same player is what makes a swap a cut.
 */
it('swaps clips without standing her up again', function (): void {
    aClipFor('cli-workflow_started');
    aClipFor('cli-workflow_done');

    test()->sami->arrive();
    test()->sami->react(['kind' => 'done']);

    $told = whatSheWasTold();

    expect($told)->toContain('cli-workflow_started.mov')
        ->and($told)->toContain('cli-workflow_done.mov');
});

/**
 * Resting loops, because it is what she does between things. A reaction plays
 * once — it is something she says and finishes.
 */
it('holds the resting clip and plays a reaction once', function (): void {
    aClipFor('cli-workflow_started');
    aClipFor('cli-workflow_done');

    test()->sami->arrive();
    test()->sami->react(['kind' => 'done']);

    $lines = array_values(array_filter(explode("\n", whatSheWasTold())));

    expect($lines[0])->toEndWith('--loop')
        ->and($lines[1])->not->toEndWith('--loop');
});

/**
 * ★ THE KIND IS THE STATE. This package used to carry a table of beat kind to
 * state name — the studio's own list, written down twice. Film a new state
 * there and the watcher went on knowing nothing about it; rename one and it
 * asked for a clip that no longer existed. A `done` beat plays
 * `cli_workflow::done`, derived rather than declared, so the studio can add a
 * state without this package changing at all.
 */
it('plays the state a beat kind names, without being told the list', function (): void {
    aClipFor('cli-workflow_summary');

    test()->sami->react(['kind' => 'summary']);

    expect(whatSheWasTold())->toContain('cli-workflow_summary.mov');
});

/**
 * Work arriving at an artisan and work leaving one are the same handover seen
 * from either side, so both play one clip rather than the same thing filmed
 * twice.
 */
it('treats both sides of a handover as one moment', function (): void {
    aClipFor('cli-workflow_handover');

    test()->sami->react(['kind' => 'inbound']);
    test()->sami->react(['kind' => 'outbound']);

    $told = whatSheWasTold();

    expect(substr_count($told, 'cli-workflow_handover.mov'))->toBe(2);
});

/**
 * A kind nobody has filmed for is still a moment worth being present for.
 */
it('rests through a kind that has no state of its own', function (): void {
    aClipFor('cli-workflow_started');

    test()->sami->react(['kind' => 'something-the-studio-added-later']);

    expect(whatSheWasTold())->toContain('cli-workflow_started.mov');
});
