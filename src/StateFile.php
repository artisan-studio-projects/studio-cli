<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

/**
 * Where the CLI keeps what it remembers about a project between processes:
 * one file per kind, named after the project's root, in the temp directory.
 */
final class StateFile
{
    private static ?string $directory = null;

    public static function for(string $kind, string $root, string $suffix = '.json'): string
    {
        return (self::$directory ?? sys_get_temp_dir()).'/studio-'.$kind.'-'.hash('xxh128', $root).$suffix;
    }

    /**
     * Keeps the files somewhere else, so test processes running side by side
     * never share them. Null puts them back in the temp directory.
     */
    public static function keepIn(?string $directory): void
    {
        self::$directory = $directory;
    }
}
