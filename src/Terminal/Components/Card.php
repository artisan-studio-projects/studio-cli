<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasColour;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasDescription;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasIcon;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasLabel;
use Closure;

final class Card extends Component
{
    use HasColour;
    use HasDescription;
    use HasIcon;
    use HasLabel;

    private string|Closure $value = '';

    private string|Closure|null $valueColour = null;

    public static function make(string|Closure $label): self
    {
        return (new self)->label($label);
    }

    public function value(string|Closure $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function valueColour(string|Closure $colour): self
    {
        $this->valueColour = $colour;

        return $this;
    }

    public function render(Canvas $canvas, int $width, mixed $state): array
    {
        $inside = $width - 4;
        $accent = $this->getColour($state);
        $icon = $this->iconOn($canvas, $accent);
        $label = $canvas->span($canvas->fit($this->getLabel($state), max(1, $inside - Canvas::visibleWidth($icon) - ($icon === '' ? 0 : 1))), $accent, bold: true);
        $heading = $icon === '' ? $label : $icon.$canvas->span(' ', 'ink').$label;
        $value = $canvas->span($canvas->fit((string) $this->evaluate($this->value, $state), $inside), (string) ($this->evaluate($this->valueColour, $state) ?? $accent), bold: true);
        $description = $canvas->span($canvas->fit((string) $this->getDescription($state), $inside), $this->getDescriptionColour($state));
        $side = $canvas->span('│', 'edge');

        return [
            $canvas->span('╭'.str_repeat('─', $width - 2).'╮', 'edge'),
            $side.$canvas->centred($heading, $width - 2).$side,
            $side.$canvas->centred($value, $width - 2).$side,
            $side.$canvas->centred($description, $width - 2).$side,
            $canvas->span('╰'.str_repeat('─', $width - 2).'╯', 'edge'),
        ];
    }
}
