<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Dashboard;

final class SampleSnapshots implements SnapshotSource
{
    public function snapshot(): DashboardSnapshot
    {
        return DashboardSnapshot::sample();
    }

    public function workflow(string $id): ?array
    {
        return DashboardSnapshot::sample()->sampleWorkflow($id);
    }

    public function forget(): void {}
}
