<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

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

        $advisories = collect((array) $json['advisories'])
            ->flatMap(fn (mixed $list, string $package): array => collect((array) $list)
                ->map(fn (mixed $advisory): array => $this->finding(
                    'composer.lock',
                    null,
                    (string) (($advisory['cve'] ?? null) ?: ($advisory['advisoryId'] ?? 'advisory')),
                    $package.' '.($advisory['affectedVersions'] ?? '').': '.($advisory['title'] ?? 'Security advisory').(isset($advisory['severity']) ? ' ('.$advisory['severity'].')' : ''),
                ))
                ->all());

        $abandoned = collect((array) ($json['abandoned'] ?? []))
            ->map(fn (mixed $replacement, string $package): array => $this->finding(
                'composer.lock',
                null,
                'abandoned',
                $package.' is abandoned'.(is_string($replacement) && $replacement !== '' ? '; use '.$replacement.' instead.' : '.'),
            ));

        return $advisories->merge($abandoned)->take(self::MOST_FINDINGS)->values()->all();
    }
}
