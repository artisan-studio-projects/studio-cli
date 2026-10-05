<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

class Rector extends Tool
{
    public function key(): string
    {
        return 'rector';
    }

    public function name(): string
    {
        return 'Rector';
    }

    public function command(string $root): ?array
    {
        $bin = $this->bin($root, 'rector');

        return $bin === null || ! is_file($root.'/rector.php') ? null : [$bin, 'process', '--dry-run', '--output-format=json', '--no-progress-bar'];
    }

    public function missing(): string
    {
        return 'Not set up in this project: it needs rector/rector and a rector.php.';
    }

    public function install(): array
    {
        return [
            'packages' => ['rector/rector'],
            'files' => ['rector.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                use Rector\Config\RectorConfig;

                return RectorConfig::configure()
                    ->withPaths([__DIR__.'/app', __DIR__.'/database', __DIR__.'/routes', __DIR__.'/tests'])
                    ->withPhpSets()
                    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true);

                PHP],
            'about' => 'Adds Rector and a rector.php for app, database, routes and tests. The scan only ever runs it with --dry-run.',
        ];
    }

    public function findings(string $output, string $root): ?array
    {
        $json = $this->json($output);

        if ($json === null || ! isset($json['totals'])) {
            return null;
        }

        return collect((array) ($json['file_diffs'] ?? []))
            ->flatMap(fn (mixed $diff): array => collect((array) ($diff['applied_rectors'] ?? []))
                ->map(fn (mixed $rector): array => $this->finding(
                    $this->relative((string) ($diff['file'] ?? ''), $root),
                    null,
                    class_basename((string) $rector),
                    'Rector would change this file with '.class_basename((string) $rector).'.',
                ))
                ->all())
            ->filter(fn (array $finding): bool => $finding['where'] !== '')
            ->take(self::MOST_FINDINGS)
            ->values()
            ->all();
    }
}
