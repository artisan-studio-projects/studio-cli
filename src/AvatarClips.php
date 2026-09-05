<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class AvatarClips
{
    public const string STUDIO = 'studio';

    private const string MANIFEST = 'manifest.json';

    public function __construct(private Studio $studio) {}

    public static function stem(string $stateKey): string
    {
        return strtr($stateKey, ['::' => '_', '_' => '-']);
    }

    public function fromTheStudio(): bool
    {
        return config('studio-cli.presence.source', self::STUDIO) === self::STUDIO;
    }

    public function folder(): string
    {
        return rtrim((string) config($this->fromTheStudio() ? 'studio-cli.presence.cache' : 'studio-cli.presence.clips'), '/');
    }

    /**
     * @return array{downloaded: list<string>, kept: list<string>, failed: list<string>}
     */
    public function sync(): array
    {
        if (! $this->fromTheStudio() || ! $this->studio->isLinked()) {
            return ['downloaded' => [], 'kept' => [], 'failed' => []];
        }

        File::ensureDirectoryExists($this->folder());

        $manifest = $this->manifest();
        $wanted = collect($this->wanted());
        $stale = $wanted->filter(fn (array $desktop, string $stem): bool => ! is_file($this->path($stem)) || ($manifest[$stem] ?? null) !== $desktop['updated']);
        $downloaded = $stale->filter(fn (array $desktop, string $stem): bool => $this->download($desktop['url'], $this->path($stem)))->keys();

        $this->remember([...$manifest, ...$wanted->only($downloaded->all())->map(fn (array $desktop): ?int => $desktop['updated'])->all()]);

        return [
            'downloaded' => array_values($downloaded->all()),
            'kept' => array_values($wanted->keys()->diff($stale->keys())->all()),
            'failed' => array_values($stale->keys()->diff($downloaded)->all()),
        ];
    }

    /**
     * @return array<string, array{url: string, updated: int|null}>
     */
    private function wanted(): array
    {
        return collect($this->studio->presenceStates())
            ->mapWithKeys(fn (array $state): array => [self::stem($state['state_key']) => $state['desktop']])
            ->filter()
            ->all();
    }

    private function download(string $url, string $target): bool
    {
        $partial = $target.'.part';

        try {
            $fetched = Http::timeout(300)->sink($partial)->get($url)->successful();
        } catch (Throwable) {
            $fetched = false;
        }

        return $fetched ? rename($partial, $target) : ! @unlink($partial);
    }

    private function path(string $stem): string
    {
        return $this->folder().'/'.$stem.'.mov';
    }

    /**
     * @return array<string, int|null>
     */
    private function manifest(): array
    {
        $path = $this->folder().'/'.self::MANIFEST;

        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
    }

    /**
     * @param  array<string, int|null>  $manifest
     */
    private function remember(array $manifest): void
    {
        file_put_contents($this->folder().'/'.self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
