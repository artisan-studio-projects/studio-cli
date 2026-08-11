<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Concerns;

use Closure;

trait HasUrl
{
    protected string|Closure|null $url = null;

    public function url(string|Closure|null $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getUrl(mixed ...$arguments): ?string
    {
        $url = $this->evaluate($this->url, ...$arguments);

        return $url === null || $url === '' ? null : (string) $url;
    }
}
