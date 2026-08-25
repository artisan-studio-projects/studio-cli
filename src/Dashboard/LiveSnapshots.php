<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Dashboard;

use ArtisanStudio\StudioCli\Studio;

final class LiveSnapshots implements SnapshotSource
{
    private const int WAITED_ON_SECONDS = 2;

    private ?DashboardSnapshot $snapshot = null;

    private int $snapshotAt = 0;

    public function __construct(private readonly Studio $studio) {}

    public function snapshot(): DashboardSnapshot
    {
        $waitedOn = $this->snapshot !== null && ($this->snapshot->workTogether !== null || $this->snapshot->filesWanted !== [] || $this->snapshot->searchesWanted !== []);

        if ($this->snapshot !== null && ! ($waitedOn ? time() - $this->snapshotAt >= self::WAITED_ON_SECONDS : $this->isStale($this->snapshotAt))) {
            return $this->snapshot;
        }

        $this->snapshotAt = time();
        $fetched = $this->studio->isLinked() ? $this->studio->snapshot() : null;

        return $this->snapshot = match (true) {
            $fetched !== null => DashboardSnapshot::fromApi($fetched),
            $this->studio->isLinked() && $this->snapshot !== null => $this->snapshot,
            default => DashboardSnapshot::fresh(),
        };
    }

    public function forget(): void
    {
        $this->snapshotAt = 0;
    }

    private function isStale(int $at): bool
    {
        return time() - $at >= max(1, (int) config('studio-cli.watch.poll_seconds', 10));
    }
}
