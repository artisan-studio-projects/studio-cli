<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Contracts;

use ArtisanStudio\StudioCli\Terminal\Tab;

interface ProvidesTab
{
    public function getName(): ?string;

    public function tab(Tab $tab): Tab;
}
