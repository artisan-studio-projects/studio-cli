<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

final class ActivityLog
{
    private const int KEEP = 200;

    /**
     * @var list<array{when: string, agent: string, label: string, detail: string, colour: string, kind: string, key: ?string}>
     */
    private array $entries = [];

    /**
     * @param  array{agent: string, label: string, detail: string, colour: string, when?: string, kind?: string}  $entry
     */
    public function add(array $entry, ?string $key = null): void
    {
        $this->entries = array_slice([
            [
                'when' => $entry['when'] ?? LocalTime::of(),
                'agent' => $entry['agent'],
                'label' => $entry['label'],
                'detail' => $entry['detail'],
                'colour' => $entry['colour'],
                'kind' => $entry['kind'] ?? '',
                'key' => $key,
            ],
            ...array_values(array_filter($this->entries, fn (array $each): bool => $key === null || $each['key'] !== $key)),
        ], 0, self::KEEP);
    }

    /**
     * @return list<array{when: string, agent: string, label: string, detail: string, colour: string, kind: string, key: ?string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @param  list<string>  $kinds
     * @return list<array{when: string, agent: string, label: string, detail: string, colour: string, kind: string, key: ?string}>
     */
    public function ofKinds(array $kinds): array
    {
        return array_values(array_filter($this->entries, fn (array $entry): bool => in_array($entry['kind'], $kinds, true)));
    }
}
