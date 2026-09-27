<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

final class ActivityLog
{
    private const int KEEP = 200;

    /**
     * @var list<array{when: string, agent: string, label: string, detail: string, colour: string, kind: string, workflow: ?string, key: ?string}>
     */
    private array $entries = [];

    /**
     * @param  array{agent: string, label: string, detail: string, colour: string, when?: string, kind?: string, workflow?: ?string}  $entry
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
                'workflow' => ($entry['workflow'] ?? '') === '' ? null : $entry['workflow'],
                'key' => $key,
            ],
            ...array_values(array_filter($this->entries, fn (array $each): bool => $key === null || $each['key'] !== $key)),
        ], 0, self::KEEP);
    }

    /**
     * @return list<array{when: string, agent: string, label: string, detail: string, colour: string, kind: string, workflow: ?string, key: ?string}>
     */
    public function entries(?string $workflow = null): array
    {
        return $workflow === null
            ? $this->entries
            : array_values(array_filter($this->entries, fn (array $entry): bool => $entry['workflow'] === $workflow));
    }

    /**
     * @param  list<string>  $kinds
     * @return list<array{when: string, agent: string, label: string, detail: string, colour: string, kind: string, workflow: ?string, key: ?string}>
     */
    public function ofKinds(array $kinds): array
    {
        return array_values(array_filter($this->entries, fn (array $entry): bool => in_array($entry['kind'], $kinds, true)));
    }
}
