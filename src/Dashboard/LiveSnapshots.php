<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Dashboard;

use ArtisanStudio\StudioCli\Studio;

final class LiveSnapshots implements SnapshotSource
{
    private ?DashboardSnapshot $snapshot = null;

    private int $snapshotAt = 0;

    /**
     * @var array<string, array{at: int, workflow: array{id: string, name: string, status: string, branch: ?string, url: ?string, tasks: list<array{id: string, ordinal: int, title: string, artisan: string, status: string, summary: string, files: list<array{path: string, kind: string}>, tests: array{state: string, files: list<string>}|null}>}|null}>
     */
    private array $workflows = [];

    public function __construct(private readonly Studio $studio) {}

    public function snapshot(): DashboardSnapshot
    {
        if ($this->snapshot !== null && ! $this->isStale($this->snapshotAt)) {
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

    public function workflow(string $id): ?array
    {
        if (isset($this->workflows[$id]) && ! $this->isStale($this->workflows[$id]['at'])) {
            return $this->workflows[$id]['workflow'];
        }

        $fetched = $this->studio->isLinked() ? $this->studio->workflow($id) : null;
        $this->workflows[$id] = ['at' => time(), 'workflow' => $fetched === null ? ($this->workflows[$id]['workflow'] ?? null) : DashboardSnapshot::workflowFromApi($fetched)];

        return $this->workflows[$id]['workflow'];
    }

    public function forget(): void
    {
        $this->snapshotAt = 0;
        $this->workflows = [];
    }

    private function isStale(int $at): bool
    {
        return time() - $at >= max(1, (int) config('studio-cli.watch.poll_seconds', 10));
    }
}
