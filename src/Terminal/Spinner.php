<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal;

/**
 * The spinner every screen shows beside something still running, one frame
 * per tenth of a second.
 */
final class Spinner
{
    /**
     * @var list<string>
     */
    public const array FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    public static function frame(): string
    {
        return self::FRAMES[intdiv((int) (microtime(true) * 1000), 100) % count(self::FRAMES)];
    }
}
