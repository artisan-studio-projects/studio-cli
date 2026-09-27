<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\EnvFile;

beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/env-file-'.uniqid();
    file_put_contents($this->path, "APP_NAME=Test\nARTISAN_STUDIO_TOKEN=\"old\"\nAPP_DEBUG=true\n");
    $this->env = new EnvFile($this->path);
});

afterEach(function (): void {
    @unlink($this->path);
});

it('replaces a value where it is, adds one that is missing, and leaves the rest alone', function (): void {
    $this->env->write(['ARTISAN_STUDIO_TOKEN' => 'new', 'ARTISAN_STUDIO_PROJECT' => '2']);

    expect(file_get_contents($this->path))->toBe("APP_NAME=Test\nARTISAN_STUDIO_TOKEN=\"new\"\nAPP_DEBUG=true\nARTISAN_STUDIO_PROJECT=\"2\"\n");
});

it('writes a value exactly as it is, dollar signs and backslashes included', function (): void {
    $this->env->write(['ARTISAN_STUDIO_TOKEN' => '12|a$1b\2"c']);

    expect(file_get_contents($this->path))->toContain('ARTISAN_STUDIO_TOKEN="12|a$1b\2\"c"');
});

it('takes a key out, line and all', function (): void {
    $this->env->forget(['ARTISAN_STUDIO_TOKEN', 'NOT_THERE']);

    expect(file_get_contents($this->path))->toBe("APP_NAME=Test\nAPP_DEBUG=true\n");
});
