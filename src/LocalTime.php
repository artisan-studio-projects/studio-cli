<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class LocalTime
{
    public static function of(?string $moment = null): string
    {
        return ($moment === null ? Carbon::now() : Carbon::parse($moment))->setTimezone(self::zone())->format('H:i:s');
    }

    public static function zone(): string
    {
        $link = @readlink('/etc/localtime');
        $machine = is_string($link) && str_contains($link, 'zoneinfo/') ? Str::after($link, 'zoneinfo/') : null;

        return array_values(array_filter([getenv('TZ') ?: null, $machine], fn (?string $zone): bool => $zone !== null && in_array($zone, timezone_identifiers_list(), true)))[0] ?? date_default_timezone_get();
    }
}
