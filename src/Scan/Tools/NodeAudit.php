<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

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

        $lockfile = is_file($root.'/pnpm-lock.yaml') ? 'pnpm-lock.yaml' : 'package-lock.json';

        if (isset($json['vulnerabilities'])) {
            return collect((array) $json['vulnerabilities'])
                ->map(fn (mixed $vulnerability, string $package): array => $this->finding(
                    $lockfile,
                    null,
                    (string) (collect((array) ($vulnerability['via'] ?? []))->first(fn (mixed $via): bool => is_array($via))['source'] ?? $package),
                    $package.' '.($vulnerability['range'] ?? '').': '.(collect((array) ($vulnerability['via'] ?? []))->first(fn (mixed $via): bool => is_array($via))['title'] ?? 'Vulnerable dependency').' ('.($vulnerability['severity'] ?? 'unknown').')',
                ))
                ->take(self::MOST_FINDINGS)
                ->values()
                ->all();
        }

        return collect((array) ($json['advisories'] ?? []))
            ->map(fn (mixed $advisory): array => $this->finding(
                $lockfile,
                null,
                (string) (collect((array) ($advisory['cves'] ?? []))->first() ?? ($advisory['id'] ?? 'advisory')),
                ($advisory['module_name'] ?? 'package').' '.($advisory['vulnerable_versions'] ?? '').': '.($advisory['title'] ?? 'Vulnerable dependency').' ('.($advisory['severity'] ?? 'unknown').')',
            ))
            ->take(self::MOST_FINDINGS)
            ->values()
            ->all();
    }
}
