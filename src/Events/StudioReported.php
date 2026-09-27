<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Events;

final readonly class StudioReported
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(public array $event) {}
}
