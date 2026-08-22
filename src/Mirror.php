<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use RuntimeException;

class Mirror
{
    private ?string $path = null;

    public function __construct(private readonly Workspace $workspace) {}

    /** @throws RuntimeException */
    public function follow(string $reference, string $branch): void
    {
        if ($reason = $this->workspace->whyItCannotWatch()) {
            throw new RuntimeException($reason);
        }

        $this->workspace->fetch();

        $this->path = $this->workspace->openWorktree($reference, $branch);
    }

    /** @param  array<string, mixed>  $event */
    public function apply(array $event): ?string
    {
        if (($event['type'] ?? '') !== 'file' || $this->path === null) {
            return null;
        }

        $relative = $this->safeRelativePath((string) ($event['path'] ?? ''));

        if ($relative === null || ! array_key_exists('contents', $event)) {
            return null;
        }

        $target = $this->path.'/'.$relative;

        if (! is_dir($directory = dirname($target))) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($target, (string) $event['contents']);

        return $target;
    }

    /**
     * A path the stream is allowed to write, or null.
     *
     * The worktree is a git checkout, so a file landing in `.git/` is a file git
     * will later read as configuration or run as a hook. Nothing an artisan
     * writes belongs there, and refusing the whole directory keeps a compromised
     * stream to writing junk rather than executing it.
     */
    private function safeRelativePath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        $first = explode('/', str_replace('\\', '/', $path))[0];

        return strtolower($first) === '.git' ? null : $path;
    }
}
