<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Contracts;

interface RunsInBackground
{
    public function start(): void;

    public function tick(): void;

    public function stop(): void;
}
