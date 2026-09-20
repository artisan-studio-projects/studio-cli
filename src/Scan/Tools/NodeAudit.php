<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Scan\ManifestLines;
use Illuminate\Support\Collection;

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

            return array_values(collect($vulnerabilities)
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
                ->all());
        }

        $chains = collect((array) ($json['actions'] ?? []))
            ->flatMap(fn (mixed $action): array => (array) ($action['resolves'] ?? []))
            ->groupBy(fn (mixed $resolve): string => (string) ($resolve['id'] ?? ''))
            ->map(fn (Collection $resolves): array => $resolves->pluck('path')->filter()->all());

        return array_values(collect((array) ($json['advisories'] ?? []))
            ->flatMap(fn (mixed $advisory, int|string $id): array => array_map(
                fn (string $direct): array => [...$this->finding(
                    $manifest->where($direct),
                    null,
                    (string) (collect((array) ($advisory['cves'] ?? []))->first() ?? ($advisory['id'] ?? 'advisory')),
                    self::message((string) ($advisory['module_name'] ?? 'package'), (string) (collect((array) ($advisory['findings'] ?? []))->pluck('version')->filter()->first() ?? $advisory['vulnerable_versions'] ?? ''), $direct, (string) ($advisory['title'] ?? 'Vulnerable dependency'), (string) ($advisory['severity'] ?? 'unknown')),
                ), 'patch' => self::patch((array) $advisory)],
                self::pnpmDirect((string) ($advisory['module_name'] ?? 'package'), [...($chains[(string) ($advisory['id'] ?? $id)] ?? []), ...collect((array) ($advisory['findings'] ?? []))->flatMap(fn (mixed $found): array => (array) ($found['paths'] ?? []))->all()], $manifest),
            ))
            ->all());
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
     * @param  array<mixed>  $paths
     * @return list<string>
     */
    private static function pnpmDirect(string $package, array $paths, ManifestLines $manifest): array
    {
        $direct = array_values(collect($paths)
            ->filter(fn (mixed $path): bool => is_string($path))
            ->map(fn (string $path): string => explode('>', $path)[1] ?? '')
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->all());

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

        $direct = array_values(collect((array) ($entry['effects'] ?? []))
            ->flatMap(fn (string $effect): array => self::npmDirect($vulnerabilities, $effect, $manifest, [...$seen, $package]))
            ->unique()
            ->all());

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
     * What Artisan Studio needs to tell whether a version on the installed
     * major clears the advisory, or the fix is a major update.
     *
     * @param  array<mixed>  $advisory
     * @return array{package: string, patched: string, versions: list<string>}
     */
    private static function patch(array $advisory): array
    {
        return [
            'package' => (string) ($advisory['module_name'] ?? ''),
            'patched' => mb_substr((string) ($advisory['patched_versions'] ?? ''), 0, 500),
            'versions' => array_values(array_slice(collect((array) ($advisory['findings'] ?? []))->pluck('version')->filter(fn (mixed $version): bool => is_string($version) && $version !== '')->unique()->all(), 0, 50)),
        ];
    }
}
