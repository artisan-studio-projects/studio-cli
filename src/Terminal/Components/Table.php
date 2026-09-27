<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasColumns;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasRecords;
use Closure;

final class Table extends Component
{
    use HasColumns;
    use HasRecords;

    private bool $selectable = false;

    private ?int $cursor = null;

    /**
     * @param  array<int, array<string, mixed>>|Closure  $records
     */
    public static function make(array|Closure $records): self
    {
        $table = new self;
        $table->records = $records;

        return $table;
    }

    public function selectable(bool $selectable = true): self
    {
        $this->selectable = $selectable;

        return $this;
    }

    public function isSelectable(): bool
    {
        return $this->selectable;
    }

    public function cursor(?int $row): self
    {
        $this->cursor = $row;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecords(mixed $state): array
    {
        return $this->records($state);
    }

    public function render(Canvas $canvas, int $width, mixed $state): array
    {
        $records = $this->records($state);
        $empty = $this->getEmptyState($state);

        if ($records === [] && $empty !== null) {
            return array_map(fn (string $line): string => $canvas->span($canvas->fit($line, $width), 'dim'), explode("\n", $empty));
        }

        $inside = $width - 2;
        $lead = $this->selectable ? 3 : 1;
        $widths = $this->columnWidths($inside, reserved: $lead + 1);
        $side = $canvas->span('│', 'edge');
        $header = collect($this->columns)
            ->map(fn (Column $column, int $index): string => $canvas->cell($canvas->span($canvas->fit($column->getLabel($state), $widths[$index] - 1), 'dim', 'band'), $widths[$index], 'band'))
            ->implode('');

        return [
            $canvas->span('╭'.str_repeat('─', $inside).'╮', 'edge'),
            $side.$canvas->cell($canvas->span(str_repeat(' ', $lead), 'ink', 'band').$header, $inside, 'band').$side,
            ...collect($records)
                ->map(fn (array $record, int $row): string => $side.$canvas->cell(
                    ($row === $this->cursor ? $canvas->span(' ▸ ', 'cyan', bold: true) : $canvas->span(str_repeat(' ', $lead), 'ink'))
                        .collect($this->columns)->map(fn (Column $column, int $index): string => $column->render($canvas, $record, $state, $widths[$index]))->implode(''),
                    $inside,
                ).$side)
                ->values()
                ->all(),
            $canvas->span('╰'.str_repeat('─', $inside).'╯', 'edge'),
        ];
    }
}
