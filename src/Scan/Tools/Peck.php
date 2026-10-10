<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use Symfony\Component\Process\Process;

/**
 * Peck: typos in names, comments and file names. It spellchecks with aspell,
 * which has to be installed on the machine.
 */
class Peck extends StudioCheck
{
    public function key(): string
    {
        return 'peck';
    }

    public function name(): string
    {
        return 'Peck';
    }

    protected function check(): string
    {
        return 'peck';
    }

    protected function isReady(string $root): bool
    {
        return is_file($root.'/vendor/bin/peck') && is_file($root.'/peck.json') && $this->hasAspell();
    }

    public function missing(): string
    {
        return $this->hasAspell()
            ? 'Not set up in this project: it needs peckphp/peck and a peck.json.'
            : 'Peck spellchecks with aspell, which is not on this machine. Install it with brew install aspell (or apt install aspell), then scan again.';
    }

    public function install(): array
    {
        return [
            'packages' => ['peckphp/peck'],
            'files' => ['peck.json' => (string) json_encode(['preset' => 'laravel', 'ignore' => ['words' => [], 'paths' => []]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"],
            'about' => 'Adds Peck, which finds typos in your names, comments and file names, and a peck.json with its Laravel preset. It needs aspell on your machine.',
        ];
    }

    private function hasAspell(): bool
    {
        $which = new Process(['which', 'aspell']);
        $which->run();

        return $which->isSuccessful();
    }
}
