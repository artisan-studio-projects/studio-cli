<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Illuminate\Support\Facades\Process;

class Presence
{
    private mixed $process = null;

    private mixed $input = null;

    private mixed $output = null;

    private const string PLACEMENT = 'cli_workflow';

    private const string RESTING = 'started';

    /** @var array<string, string> */
    private const array SHARED = [
        'inbound' => 'handover',
        'outbound' => 'handover',
    ];

    public function isAvailable(): bool
    {
        return config('studio-cli.presence.enabled', true)
            && PHP_OS_FAMILY === 'Darwin'
            && is_executable($this->player());
    }

    public function arrive(): void
    {
        $this->react(['kind' => self::RESTING]);
    }

    public function settle(): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        if (! $this->isUp()) {
            $this->dismiss();

            return;
        }

        $this->hasFinishedSpeaking();
    }

    private function hasFinishedSpeaking(): bool
    {
        if (! is_resource($this->output)) {
            return false;
        }

        return trim((string) fread($this->output, 1024)) !== '';
    }

    private function sendAwayAnyStrays(): void
    {
        Process::run(['pkill', '-f', $this->player()]);
    }

    private function isUp(): bool
    {
        if (! is_resource($this->process)) {
            return false;
        }

        return proc_get_status($this->process)['running'];
    }

    /** @param  array<string, mixed>  $event */
    public function react(array $event): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $kind = (string) ($event['kind'] ?? 'file');

        $clip = $this->clipFor($kind);

        if ($clip === null) {
            return;
        }

        $line = $clip.($this->shouldHold($kind) ? ' --loop' : '')."\n";

        if ($this->isUp()) {
            fwrite($this->input, $line);

            return;
        }

        $this->standUp($line);
    }

    private function standUp(string $line): void
    {
        $this->sendAwayAnyStrays();

        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']];

        $process = proc_open(
            [$this->player(), '-', (string) $this->howTallSheStands()],
            $descriptors,
            $pipes,
        );

        if (! is_resource($process)) {
            return;
        }

        $this->process = $process;
        $this->input = $pipes[0];
        $this->output = $pipes[1];

        stream_set_blocking($this->output, false);

        fwrite($this->input, $line);
    }

    public function dismiss(): void
    {
        if (is_resource($this->input)) {
            fclose($this->input);
        }

        if (is_resource($this->output)) {
            fclose($this->output);
        }

        if (is_resource($this->process)) {
            proc_close($this->process);
        }

        $this->input = null;
        $this->output = null;
        $this->process = null;
    }

    private function clipFor(string $kind): ?string
    {
        $state = self::PLACEMENT.'::'.(self::SHARED[$kind] ?? $kind);

        return $this->clipPath($state)
            ?? $this->clipPath(self::PLACEMENT.'::'.self::RESTING);
    }

    private function shouldHold(string $kind): bool
    {
        return $kind === self::RESTING;
    }

    private function clipPath(string $state): ?string
    {
        $stem = strtr($state, ['::' => '_', '_' => '-']);

        $path = rtrim((string) config('studio-cli.presence.clips'), '/').'/'.$stem.'.mov';

        return is_file($path) ? $path : null;
    }

    private function howTallSheStands(): int
    {
        $wanted = (int) config('studio-cli.presence.size', 360);

        return max(120, min(900, $wanted));
    }

    private function player(): string
    {
        return (string) config('studio-cli.presence.player');
    }
}
