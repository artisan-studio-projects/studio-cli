<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Scan\Load;
use ArtisanStudio\StudioCli\Scan\Tools\Psalm;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Scan\ToolStatus;

/*
|--------------------------------------------------------------------------
| A scan must never take over a developer's machine
|--------------------------------------------------------------------------
|
| One tool after another, at a lower priority, and the tools that would use
| every core given half of them. A tool waiting its turn says so.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-load-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/vendor/bin', 0755, true);
    $this->log = $this->root.'/log.txt';
    $this->fake = function (string $name, int $microseconds = 300_000) {
        $script = "#!/usr/bin/env php\n<?php\nif ((\$argv[1] ?? '') === '--help') { echo '--parallel'; exit(0); }\nfile_put_contents('{$this->log}', 'start {$name} '.microtime(true).\"\\n\", FILE_APPEND);\nusleep({$microseconds});\nfile_put_contents('{$this->log}', 'end {$name} '.microtime(true).\"\\n\", FILE_APPEND);\necho '{}';\n";
        file_put_contents($this->root.'/vendor/bin/'.$name, $script);
        chmod($this->root.'/vendor/bin/'.$name, 0755);
    };
    array_map($this->fake, ['pint', 'rector', 'psalm']);
    file_put_contents($this->root.'/rector.php', '<?php');
    file_put_contents($this->root.'/psalm.xml', '<psalm/>');
    $this->moments = function (): array {
        $moments = [];

        foreach (file($this->log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$when, $name, $at] = explode(' ', $line);
            $moments[$name][$when] = (float) $at;
        }

        return $moments;
    };
});

it('runs the tools one after another when limited to one, each starting only when the one before has finished', function (): void {
    (new Toolbox($this->root))->limitedTo(1)->runTogether(['pint', 'rector', 'psalm']);
    $moments = ($this->moments)();

    expect(array_keys($moments))->toBe(['pint', 'rector', 'psalm'])
        ->and($moments['rector']['start'])->toBeGreaterThanOrEqual($moments['pint']['end'])
        ->and($moments['psalm']['start'])->toBeGreaterThanOrEqual($moments['rector']['end']);
});

it('runs a few at once by default, and then they overlap', function (): void {
    expect(Load::AT_ONCE)->toBe(3);

    array_map(fn (string $name): mixed => ($this->fake)($name, 3_000_000), ['pint', 'rector', 'psalm']);
    (new Toolbox($this->root))->runTogether(['pint', 'rector', 'psalm']);
    $moments = ($this->moments)();

    expect($moments['rector']['start'])->toBeLessThan($moments['pint']['end'])
        ->and($moments['psalm']['start'])->toBeLessThan($moments['pint']['end']);
});

it('says a tool has begun only when it does, so one waiting its turn is not shown as running', function (): void {
    $status = app(ToolStatus::class);
    @unlink($status->path());
    $status->running(['pint', 'rector']);

    expect($status->startedAt('pint'))->toBeNull()
        ->and($status->startedAt('rector'))->toBeNull();

    $status->started('pint');

    expect($status->startedAt('pint'))->toBeFloat()
        ->and($status->startedAt('rector'))->toBeNull();

    @unlink($status->path());
});

it('runs every tool at a lower priority, and gives the tools that start a worker per core a quarter of them', function (): void {
    $start = new ReflectionMethod(Toolbox::class, 'start');
    $run = $start->invoke(new Toolbox($this->root), 'rector');

    expect(Load::gently())->toContain('-n')->toContain((string) Load::NICENESS)
        ->and($run['process']->getCommandLine())->toContain('nice')
        ->and(Load::workers())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(max(1, Load::cores()))
        ->and((new Psalm)->command($this->root))->toContain('--threads='.Load::workers());

    $run['process']->wait();
});
