<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Scan\Livewire\Components;

/**
 * Everything the studio security scan checks in a project's Livewire
 * components, read from one pass over its classes and views.
 */
final class LivewireSafety
{
    public function __construct(private readonly string $root) {}

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    public function findings(): array
    {
        $components = new Components($this->root);

        return [
            ...(new LivewireLocks($this->root, $components))->findings(),
            ...(new LivewireActions($this->root, $components))->findings(),
            ...(new LivewireViews($this->root, $components))->findings(),
            ...(new LivewireProject($this->root, $components))->findings(),
        ];
    }
}
