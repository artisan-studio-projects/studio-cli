<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Scan\Load;
use SimpleXMLElement;
use Throwable;

class Tests extends Tool
{
    /**
     * A file this far below fully covered is a finding.
     */
    public const int WELL_COVERED = 50;

    private ?string $report = null;

    private ?string $clover = null;

    private bool $covering = false;

    /** @var array{tests: int, failed: int, skipped: int}|null */
    private ?array $totals = null;

    public function key(): string
    {
        return 'tests';
    }

    public function name(): string
    {
        return 'Your tests';
    }

    public function timeout(): int
    {
        return 1800;
    }

    public function missing(): string
    {
        return 'This project has no Pest or PHPUnit to run its tests with.';
    }

    /**
     * Measures code coverage in the same run, when the machine has a
     * driver for it.
     */
    public function withCoverage(): static
    {
        $this->covering = true;

        return $this;
    }

    public function canCover(): bool
    {
        return extension_loaded('pcov') || extension_loaded('xdebug');
    }

    public function environment(): array
    {
        return $this->covering && ! extension_loaded('pcov') && extension_loaded('xdebug') ? ['XDEBUG_MODE' => 'coverage'] : [];
    }

    public function command(string $root): ?array
    {
        $this->report = sys_get_temp_dir().'/studio-tests-'.bin2hex(random_bytes(6)).'.xml';
        $this->clover = $this->covering && $this->canCover() ? sys_get_temp_dir().'/studio-coverage-'.bin2hex(random_bytes(6)).'.xml' : null;
        $coverage = $this->clover === null ? [] : ['--coverage-clover', $this->clover];

        return match (true) {
            $this->bin($root, 'pest') !== null => ['vendor/bin/pest', '--parallel', '--processes='.Load::forTests(), ...($this->offers($root, ['vendor/bin/pest', '--help'], '--no-tia') ? ['--no-tia'] : []), '--log-junit', $this->report, ...$coverage],
            $this->bin($root, 'paratest') !== null => ['vendor/bin/paratest', '--processes='.Load::forTests(), '--log-junit', $this->report, ...$coverage],
            $this->bin($root, 'phpunit') !== null => ['vendor/bin/phpunit', '--log-junit', $this->report, ...$coverage],
            default => null,
        };
    }

    public function findings(string $output, string $root): ?array
    {
        $report = $this->report === null ? null : $this->read($this->report);
        $this->totals = null;

        if ($report === null) {
            return null;
        }

        $this->totals = [
            'tests' => $this->sum($report, 'tests'),
            'failed' => $this->sum($report, 'failures') + $this->sum($report, 'errors'),
            'skipped' => $this->sum($report, 'skipped'),
        ];

        return collect($report->xpath('//testcase[failure or error]') ?: [])
            ->map(fn (SimpleXMLElement $case): array => $this->finding(
                $this->where($case, $root),
                null,
                isset($case->error) ? 'error' : 'failed',
                trim((string) $case['name']).' failed.',
            ))
            ->values()
            ->all();
    }

    /**
     * @return array{tests: int, failed: int, skipped: int}|null
     */
    public function summary(): ?array
    {
        return $this->totals;
    }

    /**
     * What the run's coverage report says: the share of statements covered,
     * and each file under {@see self::WELL_COVERED}%.
     *
     * @return array{ran: bool, reason?: string, findings?: list<array{where: string, rule: string, message: string}>, summary?: array{coverage: int}}
     */
    public function coverage(string $root): array
    {
        if (! $this->canCover()) {
            return ['ran' => false, 'reason' => 'Code coverage needs PCOV or Xdebug on this machine. Install PCOV with pecl install pcov, then scan again.'];
        }

        $clover = $this->clover === null ? null : $this->read($this->clover);

        if ($clover === null) {
            return ['ran' => false, 'reason' => 'Your tests did not write a coverage report.'];
        }

        $project = $clover->xpath('/coverage/project/metrics')[0] ?? null;
        $percent = fn (SimpleXMLElement $metrics): int => (int) $metrics['statements'] === 0 ? 100 : (int) floor(100 * (int) $metrics['coveredstatements'] / (int) $metrics['statements']);

        return [
            'ran' => true,
            'findings' => collect($clover->xpath('//file') ?: [])
                ->map(fn (SimpleXMLElement $file): array => ['path' => $this->relative((string) $file['name'], $root), 'percent' => $percent($file->metrics)])
                ->filter(fn (array $file): bool => $file['percent'] < self::WELL_COVERED)
                ->map(fn (array $file): array => $this->finding($file['path'], null, $file['percent'] === 0 ? 'uncovered' : 'low-coverage', $file['percent'] === 0 ? 'No test runs this file.' : 'Tests run '.$file['percent'].'% of this file.'))
                ->values()
                ->all(),
            'summary' => ['coverage' => $project === null ? 0 : $percent($project)],
        ];
    }

    private function read(string $path): ?SimpleXMLElement
    {
        try {
            $xml = is_file($path) ? simplexml_load_string((string) file_get_contents($path)) : false;
        } catch (Throwable) {
            $xml = false;
        } finally {
            @unlink($path);
        }

        return $xml instanceof SimpleXMLElement ? $xml : null;
    }

    private function sum(SimpleXMLElement $report, string $attribute): int
    {
        return (int) collect($report->xpath('/testsuites/testsuite') ?: [])->sum(fn (SimpleXMLElement $suite): int => (int) $suite[$attribute]);
    }

    private function where(SimpleXMLElement $case, string $root): string
    {
        $file = $this->relative(explode('::', (string) $case['file'])[0], $root);
        $body = (string) ($case->failure ?? $case->error ?? '');
        $line = preg_match('~'.preg_quote($file, '~').':(\d+)~', $body, $match) === 1 ? ':'.$match[1] : '';

        return $file === '' ? 'tests' : $file.$line;
    }
}
