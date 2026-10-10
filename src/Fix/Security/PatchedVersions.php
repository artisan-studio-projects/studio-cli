<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Security;

/**
 * From a pnpm audit's advisories, the least each vulnerable package must move
 * to be clear of all of them, and only within the major version it is on: a
 * 1.12.2 with advisories fixed in 1.15 and 1.20 goes to 1.20, never to 2.x.
 * For 0.x, where a minor version may break things, only within the minor.
 *
 * A package whose fix is in a newer major is not moved, and is listed for
 * the major updates instead.
 */
final class PatchedVersions
{
    /**
     * @param  array<string, mixed>  $audit  pnpm audit --json
     * @return array{patches: list<array{package: string, installed: string, required: string}>, majors: list<array{package: string, installed: string, needs: string}>}
     */
    public static function plan(array $audit): array
    {
        $needed = [];
        $majors = [];

        foreach ((array) ($audit['advisories'] ?? []) as $advisory) {
            $package = (string) ($advisory['module_name'] ?? '');
            $bounds = self::lowerBounds((string) ($advisory['patched_versions'] ?? ''));

            foreach ((array) ($advisory['findings'] ?? []) as $finding) {
                $installed = (string) ($finding['version'] ?? '');
                $fix = self::nearest($installed, $bounds);
                $key = $package."\0".$installed;

                if ($package === '' || $installed === '') {
                    continue;
                }

                if ($fix === null) {
                    $majors[$key] = ['package' => $package, 'installed' => $installed, 'needs' => (string) ($advisory['patched_versions'] ?? '')];

                    continue;
                }

                $needed[$key] = ['package' => $package, 'installed' => $installed, 'required' => isset($needed[$key]) && version_compare($needed[$key]['required'], $fix, '>') ? $needed[$key]['required'] : $fix];
            }
        }

        return ['patches' => array_values(array_diff_key($needed, $majors)), 'majors' => array_values($majors)];
    }

    /**
     * The package.json with direct dependencies raised to the patched range,
     * and pnpm overrides for the rest, each bound to the major it is on.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<array{package: string, installed: string, required: string}>  $patches
     * @return array<string, mixed>
     */
    public static function applied(array $manifest, array $patches): array
    {
        foreach ($patches as $patch) {
            $section = collect(['dependencies', 'devDependencies'])->first(fn (string $section): bool => isset($manifest[$section][$patch['package']])
                && self::sameLine(self::versionIn((string) $manifest[$section][$patch['package']]), $patch['required']));

            if ($section !== null) {
                $manifest[$section][$patch['package']] = '^'.$patch['required'];

                continue;
            }

            $manifest['pnpm']['overrides'][$patch['package'].'@>='.self::lineStart($patch['installed']).' <'.$patch['required']] ??= '^'.$patch['required'];
        }

        return $manifest;
    }

    /**
     * @return list<string>
     */
    private static function lowerBounds(string $patched): array
    {
        preg_match_all('/>=\s*v?(\d+\.\d+\.\d+)/', $patched, $bounds);

        return $bounds[1];
    }

    /**
     * The smallest patched version on the installed version's line.
     *
     * @param  list<string>  $bounds
     */
    private static function nearest(string $installed, array $bounds): ?string
    {
        return collect($bounds)
            ->filter(fn (string $bound): bool => self::sameLine($installed, $bound) && version_compare($bound, $installed, '>'))
            ->sort(fn (string $a, string $b): int => version_compare($a, $b))
            ->first();
    }

    private static function sameLine(?string $a, string $b): bool
    {
        if ($a === null) {
            return false;
        }

        [$aMajor, $aMinor] = array_map(intval(...), array_pad(explode('.', $a), 2, '0'));
        [$bMajor, $bMinor] = array_map(intval(...), array_pad(explode('.', $b), 2, '0'));

        return $aMajor === $bMajor && ($aMajor !== 0 || $aMinor === $bMinor);
    }

    private static function lineStart(string $installed): string
    {
        [$major, $minor] = array_map(intval(...), array_pad(explode('.', $installed), 2, '0'));

        return $major === 0 ? "0.{$minor}.0" : "{$major}.0.0";
    }

    private static function versionIn(string $range): ?string
    {
        return preg_match('/(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $range, $version) === 1 ? sprintf('%d.%d.%d', $version[1], $version[2] ?? 0, $version[3] ?? 0) : null;
    }
}
