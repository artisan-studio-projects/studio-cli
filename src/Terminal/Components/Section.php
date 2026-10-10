<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasComponents;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasLabel;
use Closure;

final class Section extends Component
{
    use HasComponents;
    use HasLabel;

    private string|Closure|null $aside = null;

    private string|Closure $asideColour = 'dim';

    public static function make(string|Closure $heading): self
    {
        return (new self)->label($heading);
    }

    public function aside(string|Closure|null $aside): self
    {
        $this->aside = $aside;

        return $this;
    }

    public function asideColour(string|Closure $colour): self
    {
        $this->asideColour = $colour;

        return $this;
    }

    public function render(Canvas $canvas, int $width, mixed $state): array
    {
        $aside = (string) $this->evaluate($this->aside, $state);
        $label = $canvas->fit($this->getLabel($state), max(1, $width - ($aside === '' ? 0 : mb_strwidth($aside) + 1)));
        $inside = $this->renderComponents($canvas, $width, $state);

        return $inside === [] ? [] : [
            $canvas->spread($canvas->span($label, 'cyan', bold: true), $canvas->span($aside, (string) $this->evaluate($this->asideColour, $state)), $width),
            ...$inside,
        ];
    }
}
