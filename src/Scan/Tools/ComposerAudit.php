<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Scan\ManifestLines;

class ComposerAudit extends Tool
{
    public function key(): string
    {
        return 'composer-audit';
    }

    public function name(): string
    {
        return 'composer audit';
    }

    public function command(string $root): ?array
    {
        return is_file($root.'/composer.lock') ? ['composer', 'audit', '--format=json', '--no-interaction', '--locked'] : null;
    }

    public function missing(): string
    {
        return 'This project has no composer.lock.';
    }

    public function findings(string $output, string $root): ?array
    {
        $json = $this->json($output);

        if ($json === null || ! array_key_exists('advisories', $json)) {
            return null;
        }

        $manifest = new ManifestLines($root, 'composer.json');
        $requiredBy = self::requiredBy($root);

        $advisories = collect((array) $json['advisories'])
            ->flatMap(fn (mixed $list, string $package): array => collect((array) $list)
                ->flatMap(fn (mixed $advisory): array => array_map(
                    fn (string $direct): array => $this->finding(
                        $manifest->where($direct),
                        null,
                        (string) (($advisory['cve'] ?? null) ?: ($advisory['advisoryId'] ?? 'advisory')),
                        $package.' '.($advisory['affectedVersions'] ?? '').($direct !== $package ? ' via '.$direct : '').': '.($advisory['title'] ?? 'Security advisory').(isset($advisory['severity']) ? ' ('.$advisory['severity'].')' : ''),
                    ),
                    self::direct($package, $requiredBy, $manifest),
                ))
                ->all());

        $abandoned = collect((array) ($json['abandoned'] ?? []))
            ->flatMap(fn (mixed $replacement, string $package): array => array_map(
                fn (string $direct): array => $this->finding(
                    $manifest->where($direct),
                    null,
                    'abandoned',
                    $package.' is abandoned'.($direct !== $package ? ' (via '.$direct.')' : '').(is_string($replacement) && $replacement !== '' ? '; use '.$replacement.' instead.' : '.'),
                ),
                self::direct($package, $requiredBy, $manifest),
            ));

        return $advisories->merge($abandoned)->values()->all();
    }

    /**
     * Which locked packages require each package, from composer.lock.
     *
     * @return array<string, list<string>>
     */
    private static function requiredBy(string $root): array
    {
        $lock = json_decode((string) @file_get_contents($root.'/composer.lock'), true);

        return collect([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])])
            ->flatMap(fn (mixed $locked): array => array_map(
                fn (string $required): array => [strtolower($required), (string) ($locked['name'] ?? '')],
                array_keys((array) ($locked['require'] ?? [])),
            ))
            ->groupBy(0)
            ->map(fn ($pairs): array => $pairs->pluck(1)->filter()->unique()->values()->all())
            ->all();
    }

    /**
     * The packages composer.json names that bring a package in, walking up
     * the lock's requirements; the package itself when it is named there or
     * nothing leads to it.
     *
     * @param  array<string, list<string>>  $requiredBy
     * @param  list<string>  $seen
     * @return list<string>
     */
    private static function direct(string $package, array $requiredBy, ManifestLines $manifest, array $seen = []): array
    {
        if ($manifest->has($package) || in_array($package, $seen, true)) {
            return [$package];
        }

        $direct = collect($requiredBy[strtolower($package)] ?? [])
            ->flatMap(fn (string $parent): array => self::direct($parent, $requiredBy, $manifest, [...$seen, $package]))
            ->filter(fn (string $name): bool => $manifest->has($name))
            ->unique()
            ->values()
            ->all();

        return $direct !== [] ? $direct : [$package];
    }
}
