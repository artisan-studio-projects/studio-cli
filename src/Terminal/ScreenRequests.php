<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal;

use Closure;

final class ScreenRequests
{
    /**
     * @var list<array{tab: string, then: ?Closure, enter: bool}>
     */
    private array $waiting = [];

    public function show(string $tab, ?Closure $then = null, bool $enter = false): void
    {
        $this->waiting[] = ['tab' => $tab, 'then' => $then, 'enter' => $enter];
    }

    /**
     * @return array{tab: string, then: ?Closure, enter: bool}|null
     */
    public function take(): ?array
    {
        return array_shift($this->waiting);
    }
}
