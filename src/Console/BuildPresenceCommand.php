<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class BuildPresenceCommand extends Command
{
    public const string SIGNATURE = 'artisan-studio:build-presence';

    protected $signature = self::SIGNATURE;

    protected $description = 'Compile the desktop player that shows SAMI while a build is watched';

    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->components->error('The desktop player is macOS only — she uses AppKit for the transparent window.');

            return self::FAILURE;
        }

        if (! $this->hasSwift()) {
            $this->components->error('No Swift compiler found.');
            $this->components->bulletList(['Install Xcode command line tools: xcode-select --install']);

            return self::FAILURE;
        }

        $source = dirname(__DIR__).'/SamiDesktop.swift';
        $target = (string) config('studio-cli.presence.player');

        if (! is_dir($directory = dirname($target))) {
            mkdir($directory, 0755, true);
        }

        $this->components->task('Compiling the player', fn (): bool => $this->compile($source, $target));

        if (! is_executable($target)) {
            $this->components->error('The compiler ran but produced nothing usable.');

            return self::FAILURE;
        }

        $this->components->info('SAMI can appear now. She shows up while `artisan-studio:watch` is running.');
        $this->components->bulletList([
            'Her clips go in '.config('studio-cli.presence.clips').' — one .mov per state.',
            'Turn her off any time with STUDIO_CLI_PRESENCE=false.',
        ]);

        return self::SUCCESS;
    }

    private function compile(string $source, string $target): bool
    {
        $slices = [];

        foreach (['x86_64', 'arm64'] as $architecture) {
            $slice = sys_get_temp_dir().'/samidesktop-'.$architecture;

            $built = Process::run([
                'swiftc', '-O',
                '-target', $architecture.'-apple-macos11',
                '-o', $slice, $source,
            ]);

            if (! $built->successful()) {
                return false;
            }

            $slices[] = $slice;
        }

        $joined = Process::run(['lipo', '-create', ...$slices, '-output', $target]);

        foreach ($slices as $slice) {
            @unlink($slice);
        }

        return $joined->successful();
    }

    private function hasSwift(): bool
    {
        return Process::run(['which', 'swiftc'])->successful();
    }
}
