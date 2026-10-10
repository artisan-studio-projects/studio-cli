<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

/**
 * Laravel Vet's trust record: the packages in composer.lock that nobody has
 * vetted yet, or that changed since they were. Read from vet.json, without
 * Vet's own AI review.
 */
class Vet extends StudioCheck
{
    public function key(): string
    {
        return 'vet';
    }

    public function name(): string
    {
        return 'Laravel Vet';
    }

    protected function check(): string
    {
        return 'vet';
    }

    protected function isReady(string $root): bool
    {
        return is_file($root.'/vendor/bin/vet') && is_file($root.'/vet.json');
    }

    public function missing(): string
    {
        return 'Not set up in this project: it needs laravel/vet and a vet.json. Run vendor/bin/vet --init to record the packages you trust today.';
    }

    public function install(): array
    {
        return [
            'packages' => ['laravel/vet'],
            'files' => [],
            'about' => 'Adds Laravel Vet, which keeps a record of the package code you have vetted. After it installs, run vendor/bin/vet --init once to trust what you have today.',
        ];
    }
}
