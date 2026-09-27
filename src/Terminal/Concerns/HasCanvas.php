<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Theme;

trait HasCanvas
{
    private bool $trueColour = true;

    private bool $emoji = true;

    private ?string $background = '000000';

    /**
     * @var array<string, mixed>
     */
    private array $palette = [];

    private string $theme = Theme::DARK;

    public function theme(string $theme): static
    {
        $this->theme = Theme::mode($theme);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $colours
     */
    public function palette(array $colours): static
    {
        $this->palette = $colours;

        return $this;
    }

    public function trueColour(bool $trueColour = true): static
    {
        $this->trueColour = $trueColour;

        return $this;
    }

    public function emoji(bool $emoji = true): static
    {
        $this->emoji = $emoji;

        return $this;
    }

    public function background(?string $background): static
    {
        $this->background = $background;

        return $this;
    }

    public function canvas(): Canvas
    {
        return new Canvas($this->trueColour, $this->emoji, $this->background, $this->palette, $this->theme);
    }

    public function takeOver(): string
    {
        return $this->canvas()->takeOver();
    }

    public function clearBelow(): string
    {
        return $this->canvas()->clearBelow();
    }

    public function eraseToEdge(): string
    {
        return $this->canvas()->eraseToEdge();
    }
}
