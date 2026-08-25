<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Dashboard;

interface SnapshotSource
{
    public function snapshot(): DashboardSnapshot;

    public function forget(): void;
}
