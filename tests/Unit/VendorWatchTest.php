<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Terminal\VendorWatch;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-vendor-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/vendor/composer', 0755, true);
    file_put_contents($this->root.'/vendor/composer/installed.json', '{"packages":[]}');
    file_put_contents($this->root.'/vendor/composer/autoload_real.php', '<?php');
    file_put_contents($this->root.'/vendor/autoload.php', '<?php');
});

it('restarts the studio only once Composer has replaced the packages and finished', function (): void {
    $watch = new VendorWatch($this->root, settledSeconds: 1);

    expect($watch->hasChanged())->toBeFalse();

    file_put_contents($this->root.'/vendor/composer/installed.json', '{"packages":[{"name":"filament/filament"}]}');

    expect($watch->hasChanged())->toBeFalse();

    usleep(1_100_000);

    expect($watch->hasChanged())->toBeTrue();
});

it('waits while Composer is still writing, so the studio never starts on half a vendor folder', function (): void {
    $watch = new VendorWatch($this->root, settledSeconds: 1);
    unlink($this->root.'/vendor/composer/autoload_real.php');
    usleep(1_100_000);

    expect($watch->hasChanged())->toBeFalse();

    file_put_contents($this->root.'/vendor/composer/autoload_real.php', '<?php // new');

    expect($watch->hasChanged())->toBeFalse();

    usleep(1_100_000);

    expect($watch->hasChanged())->toBeTrue();
});
