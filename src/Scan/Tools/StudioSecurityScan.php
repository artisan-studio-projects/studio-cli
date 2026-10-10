<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

/**
 * The studio's own security scan, with no install: today, keys and tokens
 * committed to the repository, from SAMI's own patterns over the tracked
 * files, plus gitleaks across the history when it is on the machine, the AI
 * agents that can read .env, and the Livewire safety checks (properties the
 * browser can change, actions that trust what it sends, uploads, raw HTML).
 * Only the kind of problem and where ever leaves, never the secret.
 */
class StudioSecurityScan extends StudioCheck
{
    public function key(): string
    {
        return 'studio-security';
    }

    public function name(): string
    {
        return 'Studio security scan';
    }

    protected function check(): string
    {
        return 'secrets';
    }

    protected function isReady(string $root): bool
    {
        return is_dir($root.'/.git') || is_file($root.'/.git');
    }

    public function missing(): string
    {
        return 'This project is not a git repository, so there are no committed files to check.';
    }
}
