<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Contracts;

use ArtisanStudio\StudioCli\Terminal\ScreenContainer;

interface HasScreen
{
    public function screen(ScreenContainer $screen): ScreenContainer;
}
