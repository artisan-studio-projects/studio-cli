<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\StateFile;
use ArtisanStudio\StudioCli\Studio;

/*
|--------------------------------------------------------------------------
| Who speaks, and what they say
|--------------------------------------------------------------------------
|
| The avatar's name and words come from Artisan Studio. The CLI asks once,
| keeps the answer for a while, and keeps the last answer when the studio
| cannot be reached.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-says-'.bin2hex(random_bytes(4));
    mkdir($this->root, 0755, true);
});

it('says what the studio says, in the name it gives, asks once while it is fresh, and keeps the last answer when the studio is away', function (): void {
    $studio = Mockery::mock(Studio::class);
    $studio->shouldReceive('speech')->once()->andReturn(['speaker' => ['name' => 'Nova'], 'says' => ['fix' => ['fixing' => ['title' => 'On it.', 'line' => 'On a safety branch.', 'colour' => 'sky'], 'odd' => ['title' => 'No line.']]]]);
    $says = new Says($studio, $this->root);

    expect($says->line('fix', 'fixing'))->toBe(['title' => 'On it.', 'line' => 'On a safety branch.', 'colour' => 'sky'])
        ->and($says->name())->toBe('Nova')
        ->and($says->has('fix', 'odd'))->toBeFalse()
        ->and($says->line('fix', 'unknown'))->toBe(['title' => '', 'line' => '', 'colour' => 'sky'])
        ->and((new Says($studio, $this->root))->line('fix', 'fixing')['title'])->toBe('On it.');

    $away = Mockery::mock(Studio::class);
    $away->shouldReceive('speech')->andReturnNull();
    touch(StateFile::for('speech', $this->root), time() - 3600);

    expect((new Says($away, $this->root))->name())->toBe('Nova');
});

it('names the studio itself until it has said who is speaking, and never takes markup for a name', function (): void {
    $away = Mockery::mock(Studio::class);
    $away->shouldReceive('speech')->andReturnNull();

    expect((new Says($away, $this->root))->name())->toBe(Says::NOBODY_YET);

    $odd = Mockery::mock(Studio::class);
    $odd->shouldReceive('speech')->andReturn(['speaker' => ['name' => "<b>\e[31mX"], 'says' => []]);

    expect((new Says($odd, sys_get_temp_dir().'/studio-says-'.bin2hex(random_bytes(4))))->name())->toBe(Says::NOBODY_YET);
});
