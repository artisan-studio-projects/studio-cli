<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasColumns;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasRecords;
use Closure;

final class Feed extends Component
{
    use HasColumns;
    use HasRecords;

    private string|Closure|null $detail = null;

    /**
     * @param  array<int, array<string, mixed>>|Closure  $records
     */
    public static function make(array|Closure $records): self
    {
        $feed = new self;
        $feed->records = $records;

        return $feed;
    }

    public function detail(string|Closure $detail): self
    {
        $this->detail = $detail;

        return $this;
    }

    public function render(Canvas $canvas, int $width, mixed $state): array
    {
        $records = $this->records($state);
        $empty = $this->getEmptyState($state);

        if ($records === []) {
            return $empty === null || $empty === '' ? [] : array_map(fn (string $line): string => $canvas->span($line, 'dim'), $canvas->wrap($empty, $width));
        }

        $widths = $this->columnWidths($width);

        return array_values(collect($records)
            ->flatMap(fn (array $record): array => [
                collect($this->columns)->map(fn (Column $column, int $index): string => $column->render($canvas, $record, $state, $widths[$index]))->implode(''),
                ...$this->detailLines($canvas, $record, $width, $widths[0] ?? 0),
            ])
            ->all());
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function detailLines(Canvas $canvas, array $record, int $width, int $indent): array
    {
        $detail = (string) ($this->detail instanceof Closure ? ($this->detail)($record) : ($record[(string) $this->detail] ?? ''));

        return $this->detail === null || $detail === ''
            ? []
            : array_map(
                fn (string $line): string => $canvas->span(str_repeat(' ', $indent), 'ink').$canvas->span($line, 'soft'),
                $canvas->wrap($detail, $width - $indent),
            );
    }
}
