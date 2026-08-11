<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Concerns;

use Closure;

trait HasRecords
{
    /**
     * @var array<int, array<string, mixed>>|Closure
     */
    private array|Closure $records = [];

    private string|Closure|null $emptyState = null;

    public function emptyState(string|Closure $message): static
    {
        $this->emptyState = $message;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(mixed $state): array
    {
        return array_values(array_filter((array) $this->evaluate($this->records, $state), is_array(...)));
    }

    private function getEmptyState(mixed $state): ?string
    {
        return $this->emptyState === null ? null : (string) $this->evaluate($this->emptyState, $state);
    }
}
