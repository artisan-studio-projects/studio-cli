<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Concerns\HasSwiftContainer;
use Illuminate\Container\Container;

class Presence
{
    use HasSwiftContainer;

    private const string PLACEMENT = 'cli_workflow';

    private const string RESTING = 'started';

    /** @var array<string, string> */
    private const array SHARED = [
        'inbound' => 'handover',
        'outbound' => 'handover',
    ];

    public function isAvailable(): bool
    {
        return $this->containerIsAvailable();
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

        if (! $this->containerIsUp()) {
            $this->dismiss();

            return;
        }

        $this->containerHasOutput();
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

        $this->sendToContainer(['play' => $clip, 'loop' => $this->shouldHold($kind)]);
    }

    /**
     * @return list<string>
     */
    protected function containerPreload(): array
    {
        return glob($this->clipFolder().'/*.mov') ?: [];
    }

    public function dismiss(): void
    {
        $this->closeContainer();
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
        $path = $this->clipFolder().'/'.AvatarClips::stem($state).'.mov';

        return is_file($path) ? $path : null;
    }

    private function clipFolder(): string
    {
        return Container::getInstance()->make(AvatarClips::class)->folder();
    }
}
