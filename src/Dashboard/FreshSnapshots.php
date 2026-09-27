<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Dashboard;

final class FreshSnapshots implements SnapshotSource
{
    public function snapshot(): DashboardSnapshot
    {
        return DashboardSnapshot::fresh();
    }

    public function workflow(string $id): ?array
    {
        return null;
    }

    public function forget(): void {}
}
