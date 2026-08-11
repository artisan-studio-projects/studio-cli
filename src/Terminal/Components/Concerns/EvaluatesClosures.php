<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Concerns;

use Closure;

trait EvaluatesClosures
{
    protected function evaluate(mixed $value, mixed ...$arguments): mixed
    {
        return $value instanceof Closure ? $value(...$arguments) : $value;
    }
}
