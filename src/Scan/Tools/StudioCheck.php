<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Console\CheckCommand;

/**
 * A check run through the CLI's own studio:check command, inside the
 * project, because the tool behind it prints nothing a machine can read.
 */
abstract class StudioCheck extends Tool
{
    /**
     * The studio:check argument for this tool.
     */
    abstract protected function check(): string;

    /**
     * Whether the project has what the check needs.
     */
    abstract protected function isReady(string $root): bool;

    public function command(string $root): ?array
    {
        return is_file($root.'/artisan') && $this->isReady($root) ? [PHP_BINARY, 'artisan', CheckCommand::SIGNATURE, $this->check(), '--no-ansi', '--no-interaction'] : null;
    }

    public function findings(string $output, string $root): ?array
    {
        $json = $this->json($output);

        if (! is_array($json['findings'] ?? null)) {
            return null;
        }

        return array_values(array_map(
            fn (array $finding): array => $this->finding((string) preg_replace('/:\d+$/', '', (string) ($finding['where'] ?? '')), preg_match('/:(\d+)$/', (string) ($finding['where'] ?? ''), $line) === 1 ? (int) $line[1] : null, (string) ($finding['rule'] ?? $this->key()), (string) ($finding['message'] ?? '')),
            array_filter($json['findings'], is_array(...)),
        ));
    }
}
