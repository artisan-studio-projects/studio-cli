<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal;

use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasComponents;

final class ScreenContainer
{
    use Concerns\HasCanvas;
    use Concerns\HasHeading;
    use Concerns\HasKeys;
    use Concerns\HasRail;
    use Concerns\HasSettings;
    use Concerns\HasState;
    use Concerns\HasTabs;
    use Concerns\RendersFrame;
    use HasComponents;

    public static function make(): self
    {
        return new self;
    }
}
