<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Dashboard;

interface SnapshotSource
{
    public function snapshot(): DashboardSnapshot;

    /**
     * @return array{id: string, name: string, status: string, branch: ?string, url: ?string, tasks: list<array{id: string, ordinal: int, title: string, artisan: string, status: string, summary: string, files: list<array{path: string, kind: string}>, tests: array{state: string, files: list<string>}|null}>}|null
     */
    public function workflow(string $id): ?array;

    public function forget(): void;
}
