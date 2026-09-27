<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Concerns;

use ArtisanStudio\StudioCli\EventStream;
use ArtisanStudio\StudioCli\Studio;
use Closure;

trait ListensToStudio
{
    private ?EventStream $studioListener = null;

    private ?Closure $studioHeard = null;

    private bool $studioHeardSinceOpen = false;

    private int $studioRetryAt = 0;

    private int $studioRetryWait = 0;

    private string $studioConnection = '';

    /**
     * @param  Closure(array<string, mixed>): void  $onEvent
     */
    protected function startListening(Studio $studio, Closure $onEvent): void
    {
        $this->studioListener = $studio->listener();
        $this->studioHeard = function (array $event) use ($onEvent): void {
            $this->studioHeardSinceOpen = true;
            $onEvent($event);
        };
        $this->studioRetryWait = $this->firstStudioRetry();
        $this->openStudioListener();
    }

    protected function keepListening(): void
    {
        match (true) {
            $this->studioListener === null => null,
            $this->studioListener->pump() => $this->studioIsLive(),
            $this->studioRetryAt === 0 => $this->studioWasLost(),
            time() >= $this->studioRetryAt => $this->openStudioListener(),
            default => null,
        };
    }

    protected function stopListening(): void
    {
        $this->studioListener?->close();
        $this->studioListener = null;
        $this->studioConnection = '';
    }

    protected function studioConnection(): string
    {
        return $this->studioConnection;
    }

    protected function studioIsReachable(): bool
    {
        return $this->studioRetryAt === 0;
    }

    private function openStudioListener(): void
    {
        $this->studioRetryAt = 0;
        $this->studioHeardSinceOpen = false;
        $this->studioConnection = 'connecting…';

        if ($this->studioListener !== null && $this->studioHeard !== null) {
            $this->studioListener->open($this->studioHeard);
        }
    }

    private function studioIsLive(): void
    {
        $this->studioConnection = 'live';

        if ($this->studioHeardSinceOpen) {
            $this->studioRetryWait = $this->firstStudioRetry();
        }
    }

    private function studioWasLost(): void
    {
        $failure = $this->studioListener?->failure() ?? '';
        $this->studioRetryAt = time() + $this->studioRetryWait;
        $this->studioConnection = "lost the studio ({$failure}), trying again in {$this->studioRetryWait}s";
        $this->studioRetryWait = min($this->lastStudioRetry(), $this->studioRetryWait * 2);
    }

    private function firstStudioRetry(): int
    {
        return max(1, (int) config('studio-cli.watch.reconnect_seconds', 5));
    }

    private function lastStudioRetry(): int
    {
        return max($this->firstStudioRetry(), (int) config('studio-cli.watch.max_reconnect_seconds', 60));
    }
}
