<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal;

/**
 * Notices when Composer has replaced the project's packages under a running
 * studio. The studio keeps loading classes as it goes, and a class read from
 * the new files beside one already loaded from the old can crash it, so it
 * starts itself again instead, but only once Composer has finished: starting
 * on a half-written vendor folder crashes it too.
 */
final class VendorWatch
{
    /**
     * How long the packages must stay unchanged before the studio restarts.
     */
    public const int SETTLED_SECONDS = 10;

    private ?string $stamp;

    private ?string $seen;

    private float $seenSince;

    public function __construct(private readonly string $root, private readonly int $settledSeconds = self::SETTLED_SECONDS)
    {
        $this->stamp = $this->read();
        $this->seen = $this->stamp;
        $this->seenSince = microtime(true);
    }

    /**
     * Changed since the studio started, and settled: the same for a while,
     * with the autoloader in place.
     */
    public function hasChanged(): bool
    {
        $now = $this->read();

        if ($now !== $this->seen) {
            $this->seen = $now;
            $this->seenSince = microtime(true);
        }

        return $now !== null
            && $now !== $this->stamp
            && microtime(true) - $this->seenSince >= $this->settledSeconds
            && is_file($this->root.'/vendor/autoload.php');
    }

    private function read(): ?string
    {
        $installed = $this->root.'/vendor/composer/installed.json';
        $autoload = $this->root.'/vendor/composer/autoload_real.php';
        clearstatcache();

        return is_file($installed) && is_file($autoload) ? filemtime($installed).':'.filesize($installed).':'.filemtime($autoload) : null;
    }
}
