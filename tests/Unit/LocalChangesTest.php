<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\LocalChanges;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| What git says about the project
|--------------------------------------------------------------------------
|
| The commit the scan read, the files it walks, and the GitHub repository
| the project came from. Git is never allowed to stop and ask for a password.
|
*/

function aRepositoryAt(string $path): void
{
    mkdir($path, 0755, true);

    foreach ([
        ['init', '--quiet'],
        ['config', 'user.email', 'dev@example.test'],
        ['config', 'user.name', 'Developer'],
    ] as $arguments) {
        (new Process(['git', ...$arguments], $path))->run();
    }
}

function commitEverythingIn(string $path, string $message): void
{
    (new Process(['git', 'add', '--all'], $path))->run();
    (new Process(['git', 'commit', '--quiet', '--message', $message], $path))->run();
}

beforeEach(function (): void {
    $this->repo = sys_get_temp_dir().'/studio-local-'.bin2hex(random_bytes(4));

    aRepositoryAt($this->repo);

    mkdir($this->repo.'/app/Livewire', 0755, true);
    file_put_contents($this->repo.'/app/Livewire/Invites.php', '<?php // committed');

    commitEverythingIn($this->repo, 'feat: invites');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->repo));
});

it('lists committed files before new ones, so the walk\'s examples come from settled code', function (): void {
    mkdir($this->repo.'/app/Models', 0755, true);
    file_put_contents($this->repo.'/app/Models/Draft.php', '<?php // written today');
    file_put_contents($this->repo.'/.gitignore', "ignored.php\n");
    file_put_contents($this->repo.'/ignored.php', '<?php');

    expect((new LocalChanges($this->repo))->trackedFiles())->toBe(['app/Livewire/Invites.php', '.gitignore', 'app/Models/Draft.php']);
});

it('reads the commit the project is on', function (): void {
    $head = new Process(['git', 'rev-parse', 'HEAD'], $this->repo);
    $head->run();

    expect((new LocalChanges($this->repo))->currentSha())->toBe(trim($head->getOutput()))->toHaveLength(40);
});

it('tells git there is nobody to ask', function (): void {
    $asking = (new ReflectionMethod(new LocalChanges($this->repo), 'neverAsking'))
        ->invoke(new LocalChanges($this->repo));

    expect($asking['GIT_TERMINAL_PROMPT'])->toBe('0')
        ->and($asking['GIT_SSH_COMMAND'])->toContain('BatchMode=yes')
        ->and($asking)->toHaveKeys(['GIT_ASKPASS', 'SSH_ASKPASS']);
});

it('reads the GitHub repository the project was cloned from, over ssh or https', function (string $url, ?string $repository): void {
    (new Process(['git', 'remote', 'add', 'origin', $url], $this->repo))->run();

    expect((new LocalChanges($this->repo))->originRepository())->toBe($repository);
})->with([
    'ssh' => ['git@github.com:artisan-studio-projects/artisan-studio.git', 'artisan-studio-projects/artisan-studio'],
    'https' => ['https://github.com/artisan-studio-projects/artisan-studio', 'artisan-studio-projects/artisan-studio'],
    'elsewhere' => ['https://gitlab.com/someone/project.git', null],
]);

it('has no repository to name when there is no origin', function (): void {
    expect((new LocalChanges($this->repo))->originRepository())->toBeNull();
});
