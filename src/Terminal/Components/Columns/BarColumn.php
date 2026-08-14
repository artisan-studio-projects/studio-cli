<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Columns;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use Closure;

class BarColumn extends Column
{
    private ?Closure $fraction = null;

    private string $placeholder = '';

    private string $placeholderColour = 'dim';

    public function fraction(Closure $fraction): static
    {
        $this->fraction = $fraction;

        return $this;
    }

    public function placeholder(string $text, string $colour = 'dim'): static
    {
        $this->placeholder = $text;
        $this->placeholderColour = $colour;

        return $this;
    }

    public function render(Canvas $canvas, array $record, mixed $state, int $width): string
    {
        $fraction = $this->fraction === null ? null : ($this->fraction)($record, $state);

        return $fraction === null
            ? $canvas->cell($canvas->span($canvas->fit($this->placeholder, $width - 1), $this->placeholderColour), $width)
            : $canvas->cell($canvas->segments((float) $fraction, max(1, $width - 2), $this->getColour($record, $state)), $width);
    }
}
