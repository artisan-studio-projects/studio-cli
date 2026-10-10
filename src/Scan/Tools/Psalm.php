<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Scan\Load;
use ArtisanStudio\StudioCli\Scan\PackageNamespaces;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Psalm with its Laravel plugin: static analysis from a second angle beside
 * PHPStan, for developers who want both. Read-only, like every scan tool.
 */
class Psalm extends Tool
{
    public const array CONFIGS = ['psalm.xml', 'psalm.xml.dist'];

    private const int TIMEOUT = 1200;

    /**
     * @var array{files: int, flagged: int}|null
     */
    private ?array $totals = null;

    public function key(): string
    {
        return 'psalm';
    }

    public function name(): string
    {
        return 'Psalm';
    }

    public function command(string $root): ?array
    {
        $bin = $this->bin($root, 'psalm');
        $configured = collect(self::CONFIGS)->contains(fn (string $config): bool => is_file($root.'/'.$config));

        return $bin === null || ! $configured ? null : [$bin, '--output-format=json', '--no-progress', '--show-info=false', '--no-suggestions', '--threads='.Load::workers()];
    }

    public function timeout(): int
    {
        return self::TIMEOUT;
    }

    public function missing(): string
    {
        return 'Not set up in this project: it needs Psalm, its Laravel plugin and a psalm.xml.';
    }

    public function install(): array
    {
        return [
            'packages' => ['vimeo/psalm', 'psalm/plugin-laravel'],
            'files' => ['psalm.xml' => <<<'XML'
                <?xml version="1.0"?>
                <psalm
                    errorLevel="5"
                    resolveFromConfigFile="true"
                    findUnusedCode="false"
                    findUnusedBaselineEntry="false"
                    strictBinaryOperands="false"
                    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                    xmlns="https://getpsalm.org/schema/config"
                    xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
                >
                    <projectFiles>
                        <directory name="app" />
                        <ignoreFiles>
                            <directory name="vendor" />
                        </ignoreFiles>
                    </projectFiles>
                    <plugins>
                        <pluginClass class="Psalm\LaravelPlugin\Plugin" />
                    </plugins>
                </psalm>

                XML],
            'about' => 'Adds Psalm and its Laravel plugin, and a psalm.xml that checks app/ at error level 5. For advanced users who want a second static analyser beside PHPStan.',
        ];
    }

    /**
     * Psalm prints its issues as one JSON list, even when there are none.
     */
    public function findings(string $output, string $root): ?array
    {
        $start = strpos($output, '[');
        $issues = $start === false ? null : json_decode(substr($output, $start), true);

        if (! is_array($issues)) {
            return null;
        }

        $packages = new PackageNamespaces($root);
        $findings = array_values(collect($issues)
            ->filter(fn (mixed $issue): bool => is_array($issue) && ($issue['severity'] ?? 'error') === 'error')
            ->map(function (array $issue) use ($root, $packages): array {
                $where = $this->relative((string) ($issue['file_path'] ?? $issue['file_name'] ?? ''), $root);
                $involved = $packages->involvedIn((string) ($issue['message'] ?? ''), $where);

                $finding = $this->finding($where, isset($issue['line_from']) ? (int) $issue['line_from'] : null, (string) ($issue['type'] ?? 'psalm'), (string) ($issue['message'] ?? ''));

                return $involved === [] ? $finding : [...$finding, 'packages' => $involved];
            })
            ->all());

        $flagged = count(array_unique(array_map(fn (array $finding): string => (string) preg_replace('/:\d+$/', '', $finding['where']), $findings)));
        $this->totals = ['files' => max($flagged, $this->analysed($root)), 'flagged' => $flagged];

        return $findings;
    }

    /**
     * How many PHP files Psalm read, and how many it found something in, so
     * the score can count the files that came back clean.
     *
     * @return array{files: int, flagged: int}|null
     */
    public function summary(): ?array
    {
        return $this->totals;
    }

    /**
     * The PHP files under the folders psalm.xml tells Psalm to read.
     */
    private function analysed(string $root): int
    {
        $config = collect(self::CONFIGS)->map(fn (string $name): string => $root.'/'.$name)->first(fn (string $path): bool => is_file($path));
        $xml = $config === null ? '' : (string) file_get_contents($config);
        $projectFiles = preg_match('/<projectFiles>(.*?)<\/projectFiles>/s', $xml, $block) === 1 ? (string) preg_replace('/<ignoreFiles>.*?<\/ignoreFiles>/s', '', $block[1]) : '';
        $folders = preg_match_all('/<directory\s+name="([^"]+)"/', $projectFiles, $names) > 0 ? $names[1] : ['app'];

        return array_sum(array_map(fn (string $folder): int => $this->phpFilesIn(str_starts_with($folder, '/') ? $folder : $root.'/'.$folder), $folders));
    }

    private function phpFilesIn(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        return iterator_count(new RegexIterator($files, '/\.php$/'));
    }
}
