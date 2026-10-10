<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Scan\PackageNamespaces;

/*
|--------------------------------------------------------------------------
| Which package a finding involves
|--------------------------------------------------------------------------
|
| The studio sets aside findings about a package a major version behind,
| so each PHPStan finding names the packages it involves: the ones whose
| classes its message names, and the ones its class is built on.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-packages-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/Ai', 0755, true);
    mkdir($this->root.'/vendor/composer', 0755, true);
    file_put_contents($this->root.'/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    file_put_contents($this->root.'/vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => 'laravel/ai', 'autoload' => ['psr-4' => ['Laravel\\Ai\\' => 'src/']]],
        ['name' => 'laravel/framework', 'autoload' => ['psr-4' => ['Illuminate\\' => 'src/Illuminate/']]],
        ['name' => 'acme/app-helpers', 'autoload' => ['psr-4' => ['App\\Helpers\\' => 'src/']]],
    ]]));
    file_put_contents($this->root.'/app/Ai/Gateway.php', <<<'PHP'
        <?php

        namespace App\Ai;

        use Laravel\Ai\Gateway\Prism\PrismGateway;

        class Gateway extends PrismGateway {}
        PHP);
    file_put_contents($this->root.'/app/Ai/Plain.php', "<?php\n\nnamespace App\\Ai;\n\nclass Plain {}\n");
    $this->packages = new PackageNamespaces($this->root);
});

it('names the package of each class a message names', function (): void {
    expect($this->packages->involvedIn('Call to an undefined method Laravel\Ai\Responses\TextResponse::usage() on Illuminate\Support\Collection.', 'app/Ai/Plain.php:9'))
        ->toBe(['laravel/ai', 'laravel/framework']);
});

it('names the package a class extends, even when the message names none', function (): void {
    expect($this->packages->involvedIn('Method App\Ai\Gateway::text() should return string but returns mixed.', 'app/Ai/Gateway.php:12'))
        ->toBe(['laravel/ai']);
});

it('never takes the project\'s own classes for a package', function (): void {
    expect($this->packages->involvedIn('Access to an undefined property App\Ai\Plain::$name.', 'app/Ai/Plain.php:5'))->toBe([])
        ->and($this->packages->packageOf('App\Helpers\Money'))->toBeNull();
});
