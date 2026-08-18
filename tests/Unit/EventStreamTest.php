<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\EventStream;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Reading a server-sent event stream
|--------------------------------------------------------------------------
|
| A read returns whatever happened to be in the pipe — half a frame, three
| frames, a frame split mid-word — so the only thing that decides an event is
| the blank line at the end of it.
|
*/

/**
 * The fake plays one line per `output()` call, restoring the newline it strips —
 * so a blank one is the blank line that ends a frame, which is how a real stream
 * arrives too.
 */
function eventsFrom(string ...$lines): array
{
    $describe = Process::describe();

    foreach ($lines as $line) {
        $describe = $describe->output($line);
    }

    Process::fake(['*' => $describe->exitCode(0)]);

    $seen = [];

    (new EventStream('https://artisan-studio.app/stream', 'a-token'))
        ->read(function (array $event) use (&$seen): void {
            $seen[] = $event;
        });

    return $seen;
}

it('reads one event', function (): void {
    expect(eventsFrom('data: {"type":"file","path":"app/Models/Thing.php"}', ''))
        ->toBe([['type' => 'file', 'path' => 'app/Models/Thing.php']]);
});

it('reads several events out of one read', function (): void {
    $events = eventsFrom(
        'data: {"type":"task","title":"first"}', '',
        'data: {"type":"task","title":"second"}', '',
    );

    expect($events)->toHaveCount(2)
        ->and($events[0]['title'])->toBe('first')
        ->and($events[1]['title'])->toBe('second');
});

it('holds a frame that arrived in pieces until the rest of it does', function (): void {
    expect(eventsFrom('data: {"type":"file",', 'data: "path":"app/Models/Thing.php"}', ''))
        ->toBe([['type' => 'file', 'path' => 'app/Models/Thing.php']]);
});

it('hands over nothing for a frame that never finished', function (): void {
    expect(eventsFrom('data: {"type":"file","path":"half"}'))->toBe([]);
});

it('reads a stream that uses carriage returns', function (): void {
    expect(eventsFrom("data: {\"type\":\"idle\"}\r", "\r"))
        ->toBe([['type' => 'idle']]);
});

it('joins the data lines of a frame that spans several', function (): void {
    expect(eventsFrom('data: {"type":"file",', 'data: "path":"app/Thing.php"}', ''))
        ->toBe([['type' => 'file', 'path' => 'app/Thing.php']]);
});

/**
 * ★ A KEEPALIVE IS A TICK, NOT NOTHING. It is the studio saying the line is
 * still open — not news, so a reader that only cares about files ignores it by
 * kind, but a reader that needs to know time has passed now has a heartbeat.
 * Dropping it silently left the caller with no signal at all between events.
 *
 * A frame this package cannot read comes through the same way rather than being
 * printed at somebody.
 */
it('passes a keep-alive on as a tick, and says nothing about unreadable frames', function (): void {
    expect(eventsFrom(
        ': keep-alive', '',
        'data: not json at all', '',
        'event: ping', '',
    ))->toBe([
        ['kind' => 'tick'],
        ['kind' => 'tick'],
        ['kind' => 'tick'],
    ]);
});

it('remembers the last event id, so a reconnect resumes', function (): void {
    Process::fake([
        '*' => Process::describe()
            ->output('id: 42')
            ->output('data: {"type":"idle"}')
            ->output('')
            ->exitCode(0),
    ]);

    $stream = new EventStream('https://artisan-studio.app/stream', 'a-token');
    $stream->read(fn () => null);

    expect($stream->lastEventId())->toBe('42');
});

it('presents the token in a config file rather than on the command line', function (): void {
    Process::fake();

    (new EventStream('https://artisan-studio.app/stream', 'a-token'))->read(fn () => null);

    Process::assertRan(function ($process): bool {
        $command = (array) $process->command;

        return in_array('--config', $command, true);
    });
});

it('writes the auth header into the config file curl is given', function (): void {
    $seen = null;

    Process::fake(function ($process) use (&$seen) {
        $command = array_values((array) $process->command);
        $at = array_search('--config', $command, true);
        $seen = is_int($at) ? @file_get_contents((string) ($command[$at + 1] ?? '')) : null;

        return Process::result('');
    });

    (new EventStream('https://artisan-studio.app/stream', 'a-token'))->read(fn () => null);

    expect($seen)->toContain('Authorization: Bearer a-token')
        ->and($seen)->toContain('Accept: text/event-stream');
});

it('keeps the token out of the process arguments, where ps would show it', function (): void {
    Process::fake();

    (new EventStream('https://artisan-studio.app/stream', 'a-token'))->read(fn () => null);

    Process::assertRan(fn ($process): bool => ! str_contains(implode(' ', (array) $process->command), 'a-token'));
});

it('asks curl not to buffer, or nothing arrives until the end', function (): void {
    Process::fake();

    (new EventStream('https://artisan-studio.app/stream', 'a-token'))->read(fn () => null);

    Process::assertRan(fn ($process): bool => in_array('--no-buffer', (array) $process->command, true));
});

it('complains when the connection itself failed', function (): void {
    Process::fake([
        '*' => Process::result(errorOutput: 'could not resolve host', exitCode: 6),
    ]);

    expect(fn () => (new EventStream('https://artisan-studio.app/stream', 'a-token'))->read(fn () => null))
        ->toThrow(RuntimeException::class, 'could not resolve host');
});
