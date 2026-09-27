<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Columns;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\EvaluatesClosures;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasColour;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasLabel;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasUrl;
use Closure;
use Illuminate\Support\Str;

class Column
{
    use EvaluatesClosures;
    use HasColour;
    use HasLabel;
    use HasUrl;

    protected int|Closure|null $width = null;

    protected bool $bold = false;

    protected ?Closure $formatStateUsing = null;

    final public function __construct(protected string $name)
    {
        $this->label = Str::headline($name);
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function width(int|Closure|null $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function bold(bool $bold = true): static
    {
        $this->bold = $bold;

        return $this;
    }

    public function formatStateUsing(Closure $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    public function getWidth(int $tableWidth): ?int
    {
        $width = $this->evaluate($this->width, $tableWidth);

        return $width === null ? null : (int) $width;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function render(Canvas $canvas, array $record, mixed $state, int $width): string
    {
        $url = $this->getUrl($record, $state);
        $text = $canvas->fit($this->text($record, $state), $width - 1 - ($url === null ? 0 : mb_strwidth(Canvas::OPENS) + 1));

        return $canvas->cell($canvas->span($text, $this->getColour($record, $state), bold: $this->bold).$canvas->opens($url), $width);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function text(array $record, mixed $state): string
    {
        $value = $record[$this->name] ?? '';

        return (string) ($this->formatStateUsing === null ? $value : ($this->formatStateUsing)($value, $record, $state));
    }
}
