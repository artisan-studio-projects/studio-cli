<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use ArtisanStudio\StudioCli\Terminal\Contracts\RunsInBackground;

trait HasRunner
{
    private ?RunsInBackground $runner = null;

    public function runsWith(?RunsInBackground $runner): static
    {
        $this->runner = $runner;

        return $this;
    }

    public function getRunner(): ?RunsInBackground
    {
        return $this->runner;
    }
}
