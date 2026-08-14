<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Concerns;

use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;

trait HasColumns
{
    private const int FLEXIBLE_AT_LEAST = 8;

    /**
     * @var list<Column>
     */
    private array $columns = [];

    /**
     * @param  list<Column>  $columns
     */
    public function columns(array $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * @return list<int>
     */
    private function columnWidths(int $width, int $reserved = 0): array
    {
        $fixed = collect($this->columns)->map(fn (Column $column): ?int => $column->getWidth($width));
        $flexible = max(self::FLEXIBLE_AT_LEAST, $width - $reserved - (int) $fixed->sum());

        return array_values($fixed->map(fn (?int $each): int => $each ?? $flexible)->all());
    }
}
