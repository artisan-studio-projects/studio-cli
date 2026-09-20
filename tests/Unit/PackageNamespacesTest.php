<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Scan\PackageNamespaces;

/*
|--------------------------------------------------------------------------
| Which package a finding involves
|--------------------------------------------------------------------------
|
| The studio sets aside findings about a package a major version behind. It
| works out which packages a finding involves from what is read here: the
| namespaces each installed package autoloads, and the classes a flagged
| file's own classes extend or implement.
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

it('reads each installed package\'s namespaces, and never takes the project\'s own for a package', function (): void {
    expect($this->packages->namespaces())->toBe(['Laravel\\Ai\\' => 'laravel/ai', 'Illuminate\\' => 'laravel/framework']);
});

it('reads the classes a file\'s own classes build on, in full', function (): void {
    expect($this->packages->builtOn('app/Ai/Gateway.php:12'))->toBe(['Laravel\Ai\Gateway\Prism\PrismGateway'])
        ->and($this->packages->builtOn('app/Ai/Plain.php:5'))->toBe([])
        ->and($this->packages->builtOn('composer.lock'))->toBe([]);
});
