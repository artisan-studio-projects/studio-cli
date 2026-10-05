<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

class FilaCheck extends Tool
{
    private const string FOLDER = 'app/Filament';

    /**
     * @var array{rules: int, passed: int}|null
     */
    private ?array $totals = null;

    public function key(): string
    {
        return 'filacheck';
    }

    public function name(): string
    {
        return 'FilaCheck';
    }

    public function command(string $root): ?array
    {
        $bin = $this->bin($root, 'filacheck');

        return $bin === null || ! is_dir($root.'/'.self::FOLDER) ? null : [$bin, self::FOLDER];
    }

    public function missing(): string
    {
        return 'Not set up in this project: it needs laraveldaily/filacheck and an app/Filament folder.';
    }

    public function install(): array
    {
        return [
            'packages' => ['laraveldaily/filacheck'],
            'files' => [],
            'about' => 'Adds FilaCheck, static analysis for Filament 4 and 5 resources. The scan only reads with it and never runs its --fix.',
        ];
    }

    /**
     * @return array{rules: int, passed: int}|null
     */
    public function summary(): ?array
    {
        return $this->totals;
    }

    public function findings(string $output, string $root): ?array
    {
        $output = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->totals = match (true) {
            preg_match('/All (\d+) rules passed!/', $output, $all) === 1 => ['rules' => (int) $all[1], 'passed' => (int) $all[1]],
            preg_match('/Rules: (\d+) passed, (\d+) failed/', $output, $some) === 1 => ['rules' => (int) $some[1] + (int) $some[2], 'passed' => (int) $some[1]],
            default => null,
        };

        if ($this->totals === null) {
            return null;
        }

        return collect(preg_split('/\R/', $output) ?: [])
            ->reduce(fn (array $read, string $line): array => match (true) {
                preg_match('/^✗ (\S+) \(/u', $line, $rule) === 1 => [...$read, 'rule' => $rule[1]],
                preg_match('/^  (\S.*\.php)$/', $line, $file) === 1 => [...$read, 'file' => $this->relative($file[1], $root)],
                preg_match('/^    Line (\d+): (.+)$/', $line, $found) === 1 && $read['rule'] !== null && $read['file'] !== null => [
                    ...$read,
                    'findings' => [...$read['findings'], $this->finding($read['file'], (int) $found[1], $read['rule'], $found[2])],
                ],
                default => $read,
            }, ['rule' => null, 'file' => null, 'findings' => []])['findings'];
    }
}
