<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Fix\Security\PatchedVersions;
use ArtisanStudio\StudioCli\Scan\ManifestLines;

class NodeAudit extends Tool
{
    public function key(): string
    {
        return 'node-audit';
    }

    public function name(): string
    {
        return 'pnpm or npm audit';
    }

    public function command(string $root): ?array
    {
        return match (true) {
            is_file($root.'/pnpm-lock.yaml') => ['pnpm', 'audit', '--json'],
            is_file($root.'/package-lock.json') => ['npm', 'audit', '--json'],
            default => null,
        };
    }

    public function missing(): string
    {
        return 'This project has no pnpm-lock.yaml or package-lock.json.';
    }

    public function findings(string $output, string $root): ?array
    {
        $json = $this->json($output);

        if ($json === null) {
            return null;
        }

        $manifest = new ManifestLines($root, 'package.json');

        if (isset($json['vulnerabilities'])) {
            $vulnerabilities = (array) $json['vulnerabilities'];

            return collect($vulnerabilities)
                ->flatMap(fn (mixed $vulnerability, string $package): array => collect((array) ($vulnerability['via'] ?? []))
                    ->filter(fn (mixed $via): bool => is_array($via))
                    ->flatMap(fn (array $via): array => array_map(
                        fn (string $direct): array => $this->marked($this->finding(
                            $manifest->where($direct),
                            null,
                            (string) ($via['source'] ?? $package),
                            self::message($package, (string) ($vulnerability['range'] ?? ''), $direct, (string) ($via['title'] ?? 'Vulnerable dependency'), (string) ($via['severity'] ?? $vulnerability['severity'] ?? 'unknown')),
                        ), ($vulnerability['fixAvailable']['isSemVerMajor'] ?? false) === true),
                        self::npmDirect($vulnerabilities, $package, $manifest),
                    ))
                    ->all())
                ->values()
                ->all();
        }

        $chains = collect((array) ($json['actions'] ?? []))
            ->flatMap(fn (mixed $action): array => (array) ($action['resolves'] ?? []))
            ->groupBy(fn (mixed $resolve): string => (string) ($resolve['id'] ?? ''))
            ->map(fn ($resolves): array => $resolves->pluck('path')->filter()->all());

        return collect((array) ($json['advisories'] ?? []))
            ->flatMap(fn (mixed $advisory, int|string $id): array => array_map(
                fn (string $direct): array => $this->marked($this->finding(
                    $manifest->where($direct),
                    null,
                    (string) (collect((array) ($advisory['cves'] ?? []))->first() ?? ($advisory['id'] ?? 'advisory')),
                    self::message((string) ($advisory['module_name'] ?? 'package'), (string) (collect((array) ($advisory['findings'] ?? []))->pluck('version')->filter()->first() ?? $advisory['vulnerable_versions'] ?? ''), $direct, (string) ($advisory['title'] ?? 'Vulnerable dependency'), (string) ($advisory['severity'] ?? 'unknown')),
                ), self::needsMajor((array) $advisory)),
                self::pnpmDirect((string) ($advisory['module_name'] ?? 'package'), [...($chains[(string) ($advisory['id'] ?? $id)] ?? []), ...collect((array) ($advisory['findings'] ?? []))->flatMap(fn (mixed $found): array => (array) ($found['paths'] ?? []))->all()], $manifest),
            ))
            ->values()
            ->all();
    }

    /**
     * What a finding says: the package, the version or range, the direct
     * dependency that brings it in when that is another package, and the
     * advisory with its severity last, the way Insights reads it.
     */
    private static function message(string $package, string $version, string $direct, string $title, string $severity): string
    {
        return $package.' '.trim($version).($direct !== $package ? ' via '.$direct : '').': '.$title.' ('.$severity.')';
    }

    /**
     * The direct dependencies a pnpm advisory reaches the project through,
     * from its paths (".>vite>rollup" is through vite).
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function pnpmDirect(string $package, array $paths, ManifestLines $manifest): array
    {
        $direct = collect($paths)
            ->map(fn (string $path): string => explode('>', $path)[1] ?? '')
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();

        return $direct !== [] ? $direct : [$package];
    }

    /**
     * The direct dependencies an npm vulnerability reaches the project
     * through, following its effects up to the packages the project names.
     *
     * @param  array<string, mixed>  $vulnerabilities
     * @param  list<string>  $seen
     * @return list<string>
     */
    private static function npmDirect(array $vulnerabilities, string $package, ManifestLines $manifest, array $seen = []): array
    {
        $entry = (array) ($vulnerabilities[$package] ?? []);

        if ($manifest->has($package) || ($entry['isDirect'] ?? false) === true || in_array($package, $seen, true)) {
            return [$package];
        }

        $direct = collect((array) ($entry['effects'] ?? []))
            ->flatMap(fn (string $effect): array => self::npmDirect($vulnerabilities, $effect, $manifest, [...$seen, $package]))
            ->unique()
            ->values()
            ->all();

        return $direct !== [] ? $direct : [$package];
    }

    /**
     * @param  array{where: string, rule: string, message: string}  $finding
     * @return array{where: string, rule: string, message: string, major?: bool}
     */
    private function marked(array $finding, bool $major): array
    {
        return $major ? [...$finding, 'major' => true] : $finding;
    }

    /**
     * An advisory no version on the installed major clears: the fix is a
     * major update, which is the developer's call, not a quick patch.
     *
     * @param  array<mixed>  $advisory
     */
    public static function needsMajor(array $advisory): bool
    {
        $plan = PatchedVersions::plan(['advisories' => [$advisory]]);

        return $plan['patches'] === [] && $plan['majors'] !== [];
    }
}
