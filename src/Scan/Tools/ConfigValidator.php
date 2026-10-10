<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

/**
 * Your config checked with Laravel's own validation rules: the project's
 * config-validation folder, or the package's defaults for your Laravel
 * version. Findings name the config key and the rule, never its value.
 */
class ConfigValidator extends StudioCheck
{
    public function key(): string
    {
        return 'config-validator';
    }

    public function name(): string
    {
        return 'Config validator';
    }

    protected function check(): string
    {
        return 'config';
    }

    protected function isReady(string $root): bool
    {
        return is_dir($root.'/vendor/ash-jc-allen/laravel-config-validator');
    }

    public function missing(): string
    {
        return 'Not installed in this project: it needs ash-jc-allen/laravel-config-validator.';
    }

    public function install(): array
    {
        return [
            'packages' => ['ash-jc-allen/laravel-config-validator'],
            'files' => [],
            'about' => 'Adds the config validator. Until you write your own rules in config-validation/, it checks your config against its defaults for your Laravel version. Only config keys and messages are sent, never values.',
        ];
    }
}
