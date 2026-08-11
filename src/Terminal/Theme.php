<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal;

final class Theme
{
    public const string DARK = 'dark';

    public const string LIGHT = 'light';

    private const array DARK_PALETTE = [
        'ink' => 'eef1fd',
        'soft' => 'b3bbd9',
        'dim' => '7a84ad',
        'band' => '131a29',
        'edge' => '2a3249',
        'track' => '1c2335',
        'cyan' => '1deced',
        'cyan-bg' => '0a3a40',
        'blue' => '3b82f6',
        'blue-bg' => '0f2552',
        'sky' => 'a5f3fc',
        'sky-bg' => '123b45',
        'green' => '6fdca6',
        'green-bg' => '123a28',
        'amber' => 'f5b454',
        'amber-bg' => '3d2c10',
        'rose' => 'ff8aa6',
        'rose-bg' => '421626',
        'glow-from' => '1deced',
        'glow-to' => '3b82f6',
        'glow-head' => '00fff0',
    ];

    private const array LIGHT_PALETTE = [
        'ink' => '0f172a',
        'soft' => '334155',
        'dim' => '64748b',
        'band' => 'e2e8f0',
        'edge' => 'cbd5e1',
        'track' => 'd5dce6',
        'cyan' => '0891b2',
        'cyan-bg' => 'cffafe',
        'blue' => '2563eb',
        'blue-bg' => 'dbeafe',
        'sky' => '0284c7',
        'sky-bg' => 'e0f2fe',
        'green' => '16a34a',
        'green-bg' => 'dcfce7',
        'amber' => 'd97706',
        'amber-bg' => 'fef3c7',
        'rose' => 'e11d48',
        'rose-bg' => 'ffe4e6',
        'glow-from' => '06b6d4',
        'glow-to' => '2563eb',
        'glow-head' => '1e40af',
    ];

    public static function mode(mixed $mode): string
    {
        return $mode === self::LIGHT ? self::LIGHT : self::DARK;
    }

    /**
     * @return array<string, string>
     */
    public static function palette(string $mode): array
    {
        return self::mode($mode) === self::LIGHT ? self::LIGHT_PALETTE : self::DARK_PALETTE;
    }

    public static function background(string $mode): string
    {
        return self::mode($mode) === self::LIGHT ? 'f1f5f9' : '000000';
    }
}
