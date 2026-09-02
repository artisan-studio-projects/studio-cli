<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Contracts;

use ArtisanStudio\StudioCli\Terminal\Rail;

interface ProvidesRail
{
    public function getName(): ?string;

    public function rail(Rail $rail): Rail;
}
