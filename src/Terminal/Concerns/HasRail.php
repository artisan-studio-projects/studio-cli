<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use ArtisanStudio\StudioCli\Terminal\Rail;
use ArtisanStudio\StudioCli\Terminal\Tab;

trait HasRail
{
    private ?Rail $rail = null;

    private int $drawnWidth = 0;

    private int $railScroll = 0;

    public function scrollRail(int $lines): static
    {
        $this->railScroll = max(0, $this->railScroll + $lines);

        return $this;
    }

    public function resetRailScroll(): static
    {
        $this->railScroll = 0;

        return $this;
    }

    public function railAt(int $width, int $column): bool
    {
        $content = min($width, self::MAX_WIDTH);
        $left = intdiv($width - $content, 2);
        $this->drawnAt($width);

        return $this->shownRail() !== null && $column > $left + $this->bodyWidth($content) && $column <= $left + $content;
    }

    public function rail(?Rail $rail): static
    {
        $this->rail = $rail;

        return $this;
    }

    public function getRail(): ?Rail
    {
        return $this->rail;
    }

    public function shownRail(): ?Rail
    {
        return $this->rail !== null
            && $this->drawnWidth >= $this->rail->getFrom()
            && min($this->drawnWidth, self::MAX_WIDTH) - $this->rail->getWidth() >= self::MIN_WIDTH
                ? $this->rail
                : null;
    }

    private function drawnAt(int $width): void
    {
        $this->drawnWidth = $width;
    }

    /**
     * @return list<Tab|Rail>
     */
    private function panes(): array
    {
        return $this->rail === null ? $this->tabs : [...$this->tabs, $this->rail];
    }
}
