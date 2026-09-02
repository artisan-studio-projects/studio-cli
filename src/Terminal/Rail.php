<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal;

use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasComponents;
use Illuminate\Container\Container;

final class Rail
{
    use Concerns\HasOwnState;
    use Concerns\HasRunner;
    use HasComponents;

    private const int NARROWEST = 24;

    private int $width = 40;

    private int $from = 130;

    private ?string $insteadOf = null;

    public static function make(): self
    {
        return new self;
    }

    public function stateFor(Tab $tab): mixed
    {
        return $this->stateUsing === null ? null : Container::getInstance()->call($this->stateUsing, ['tab' => $tab]);
    }

    public function width(int $width): self
    {
        $this->width = max(self::NARROWEST, $width);

        return $this;
    }

    public function from(int $columns): self
    {
        $this->from = $columns;

        return $this;
    }

    public function insteadOf(?string $tab): self
    {
        $this->insteadOf = $tab;

        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getFrom(): int
    {
        return $this->from;
    }

    public function getInsteadOf(): ?string
    {
        return $this->insteadOf;
    }

    /**
     * @return list<string>
     */
    public function render(Canvas $canvas, int $width, mixed $state): array
    {
        return $this->renderComponents($canvas, $width, $state);
    }
}
