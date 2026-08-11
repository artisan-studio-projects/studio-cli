<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Concerns;

use ArtisanStudio\StudioCli\Terminal\Canvas;

trait HasIcon
{
    protected ?string $emojiIcon = null;

    protected ?string $symbolIcon = null;

    public function icon(string $emoji, string $symbol): static
    {
        $this->emojiIcon = $emoji;
        $this->symbolIcon = $symbol;

        return $this;
    }

    protected function iconOn(Canvas $canvas, string $colour): string
    {
        return match (true) {
            $this->emojiIcon === null || $this->symbolIcon === null => '',
            $canvas->emoji => $canvas->span($this->emojiIcon, 'ink'),
            default => $canvas->span($this->symbolIcon, $colour, bold: true),
        };
    }
}
