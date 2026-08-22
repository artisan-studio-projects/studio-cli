<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Mirror;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Workspace;
use Illuminate\Console\Command;
use Throwable;

class WatchCommand extends Command
{
    public const string SIGNATURE = 'artisan-studio:watch';

    protected $signature = self::SIGNATURE.'
        {--once : Print the current state and exit, rather than staying open}
        {--no-mirror : Follow the workflow without writing any files}';

    protected $description = 'Watch an Artisan Studio workflow from this machine';

    protected bool $keepWatching = true;

    public function handle(Studio $studio, Workspace $workspace, Mirror $mirror, Presence $sami): int
    {
        if (! $studio->isLinked()) {
            return $this->explainHowToLink();
        }

        if (($refused = $this->tokenWasRefused($studio)) !== null) {
            return $refused;
        }

        $this->sendHerAwayOnExit($sami);

        if (! $workspace->isGitRepository()) {
            $this->components->error('This is not a git repository, so there is nowhere to put a preview worktree.');

            return self::SUCCESS;
        }

        return $this->option('once')
            ? $this->reportOnce($studio)
            : $this->follow($studio, $mirror, $sami);
    }

    private function explainHowToLink(): int
    {
        $this->components->warn('This project is not connected to Artisan Studio yet.');

        $this->components->bulletList([
            'Run <options=bold>php artisan artisan-studio:link</> in another terminal.',
            'It asks for a token, then writes it to your <options=bold>.env</>.',
            'Get a token from Artisan Studio under Settings, then restart this tab.',
        ]);

        return self::SUCCESS;
    }

    private function tokenWasRefused(Studio $studio): ?int
    {
        try {
            $studio->projects();
        } catch (Throwable $refusal) {
            $this->components->error($refusal->getMessage());

            $this->components->bulletList([
                'The token in your <options=bold>.env</> is wrong, expired, or from another studio.',
                'Run <options=bold>php artisan artisan-studio:link</> again with a fresh one.',
            ]);

            return self::SUCCESS;
        }

        return null;
    }

    private function reportOnce(Studio $studio): int
    {
        $workflows = $studio->activeWorkflows();

        if ($workflows === []) {
            $this->components->info('No active workflows.');

            return self::SUCCESS;
        }

        foreach ($workflows as $workflow) {
            $this->line(sprintf(
                '  <fg=cyan>%s</> %s',
                $workflow['reference'] ?? '—',
                $workflow['title'] ?? '',
            ));
        }

        return self::SUCCESS;
    }

    private function follow(Studio $studio, Mirror $mirror, Presence $sami): int
    {
        $wait = max(1, (int) config('studio-cli.watch.reconnect_seconds', 5));
        $ceiling = max($wait, (int) config('studio-cli.watch.max_reconnect_seconds', 60));

        $sami->arrive();

        $this->components->info('Watching Artisan Studio. Nothing here writes to your branch.');

        while ($this->keepWatching) {
            try {
                $studio->stream(function (array $event) use ($mirror, $sami): void {
                    if (($event['kind'] ?? null) === 'tick') {
                        $sami->settle();

                        return;
                    }

                    $this->render($event);

                    $sami->react($event);

                    if (! $this->option('no-mirror')) {
                        $mirror->apply($event);
                    }
                });

                $wait = max(1, (int) config('studio-cli.watch.reconnect_seconds', 5));
            } catch (Throwable $failure) {
                $this->components->warn(sprintf(
                    'Lost the studio (%s). Trying again in %ds.',
                    $failure->getMessage(),
                    $wait,
                ));

                sleep($wait);

                $wait = min($ceiling, $wait * 2);
            }
        }

        return self::SUCCESS;
    }

    private function sendHerAwayOnExit(Presence $sami): void
    {
        $dismiss = function () use ($sami): void {
            $sami->dismiss();
        };

        register_shutdown_function($dismiss);

        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, function () use ($dismiss): void {
                $dismiss();

                $this->keepWatching = false;
            });
        }
    }

    /** @param  array<string, mixed>  $event */
    private function render(array $event): void
    {
        $at = date('H:i:s');
        $agent = str_pad((string) ($event['agent'] ?? '—'), 8);

        $line = match ($event['type'] ?? '') {
            'file' => sprintf('<fg=green>wrote</> %s', $event['path'] ?? ''),
            'task' => sprintf('<fg=cyan>%s</> %s', $event['status'] ?? '', $event['title'] ?? ''),
            'test' => $this->testLine($event),
            'idle' => '<fg=gray>no active workflows</>',
            default => (string) ($event['message'] ?? ''),
        };

        if ($line === '') {
            return;
        }

        $this->line("  <fg=gray>{$at}</> <fg=magenta>{$agent}</> {$line}");
    }

    /** @param  array<string, mixed>  $event */
    private function testLine(array $event): string
    {
        $failed = (int) ($event['failed'] ?? 0);

        return $failed > 0
            ? sprintf('<fg=red>%d failed</>, %d passed', $failed, (int) ($event['passed'] ?? 0))
            : sprintf('<fg=green>%d passed</>', (int) ($event['passed'] ?? 0));
    }
}
