<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Contracts;

use ArtisanStudio\StudioCli\Terminal\Settings;

interface ProvidesSettings
{
    public function getName(): ?string;

    public function settings(Settings $settings): Settings;
}
