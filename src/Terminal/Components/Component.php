<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\EvaluatesClosures;

abstract class Component
{
    use EvaluatesClosures;

    /**
     * @return list<string>
     */
    abstract public function render(Canvas $canvas, int $width, mixed $state): array;
}
