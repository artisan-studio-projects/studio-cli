<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use SimpleXMLElement;
use Throwable;

class Tests extends Tool
{
    private ?string $report = null;

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

    public function command(string $root): ?array
    {
        $this->report = sys_get_temp_dir().'/studio-tests-'.bin2hex(random_bytes(6)).'.xml';

        return match (true) {
            $this->bin($root, 'pest') !== null => ['vendor/bin/pest', '--parallel', ...($this->offers($root, ['vendor/bin/pest', '--help'], '--no-tia') ? ['--no-tia'] : []), '--log-junit', $this->report],
            $this->bin($root, 'paratest') !== null => ['vendor/bin/paratest', '--log-junit', $this->report],
            $this->bin($root, 'phpunit') !== null => ['vendor/bin/phpunit', '--log-junit', $this->report],
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
            ->take(self::MOST_FINDINGS)
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
