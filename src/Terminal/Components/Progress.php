<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasColour;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasLabel;
use Closure;

final class Progress extends Component
{
    use HasColour;
    use HasLabel;

    private const int PERCENT_WIDTH = 6;

    private float|Closure $value = 0.0;

    public static function make(string|Closure $label): self
    {
        return (new self)->label($label);
    }

    public function value(float|Closure $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function render(Canvas $canvas, int $width, mixed $state): array
    {
        $fraction = (float) $this->evaluate($this->value, $state);
        $colour = $this->getColour($state);

        return [
            $canvas->span($canvas->fit($this->getLabel($state), $width), $colour),
            $canvas->segments($fraction, $width - self::PERCENT_WIDTH)
                .$canvas->span(str_pad((int) round($fraction * 100).'%', self::PERCENT_WIDTH, ' ', STR_PAD_LEFT), $colour, bold: true),
        ];
    }

    protected function defaultColour(): string
    {
        return 'cyan';
    }
}
