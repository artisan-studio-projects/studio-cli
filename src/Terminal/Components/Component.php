<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\EvaluatesClosures;

abstract class Component
{
    use EvaluatesClosures;

    private bool $tight = false;

    /**
     * Sits directly under the component before it, without the blank line
     * between them, like a caption under the cards it belongs to.
     */
    public function tight(bool $tight = true): static
    {
        $this->tight = $tight;

        return $this;
    }

    public function isTight(): bool
    {
        return $this->tight;
    }

    /**
     * @return list<string>
     */
    abstract public function render(Canvas $canvas, int $width, mixed $state): array;
}
