<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use SimpleXMLElement;

class TestSuite
{
    private const int TIMEOUT = 600;

    public function __construct(private readonly string $root) {}

    public function problem(): ?string
    {
        $database = $this->testingEnvironment()['DB_DATABASE'] ?? null;

        return match (true) {
            $database === ':memory:' => null,
            is_string($database) && str_contains(strtolower($database), 'test') => null,
            default => 'Your tests would run against your own database: phpunit.xml does not point DB_DATABASE at :memory: or a test database. Point it at one first, so running them cannot wipe your data.',
        };
    }

    /**
     * @param  list<string>  $files
     */
    public function start(array $files, string $report): InvokedProcess
    {
        return Process::path($this->root)
            ->timeout(self::TIMEOUT)
            ->start(['php', 'artisan', 'test', '--compact', '--log-junit', $report, ...$this->runnable($files)]);
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    public function runnable(array $files): array
    {
        return array_values(array_filter(
            $files,
            fn (string $file): bool => str_starts_with($file, 'tests/')
                && ! str_contains($file, '..')
                && preg_match('/^[A-Za-z0-9_\-.\/]+$/', $file) === 1,
        ));
    }

    /**
     * @return array{passed: bool, results: list<array{file: string, passed: bool, summary: ?string}>, cases: array{passed: int, failed: int}}
     */
    public function read(string $report, bool $succeeded): array
    {
        $xml = is_file($report) ? simplexml_load_file($report) : false;
        $suites = $xml === false ? [] : ($xml->xpath('//testsuite[@file and not(ancestor::testsuite[@file])]') ?: []);

        $results = array_values(array_map($this->fileResult(...), $suites));
        $failed = array_sum(array_map(fn (SimpleXMLElement $suite): int => (int) $suite['failures'] + (int) $suite['errors'], $suites));
        $ran = array_sum(array_map(fn (SimpleXMLElement $suite): int => (int) $suite['tests'] - (int) $suite['skipped'], $suites));

        return [
            'passed' => $succeeded && $failed === 0,
            'results' => $results,
            'cases' => ['passed' => max(0, $ran - $failed), 'failed' => $failed],
        ];
    }

    /**
     * @return array{file: string, passed: bool, summary: ?string}
     */
    private function fileResult(SimpleXMLElement $suite): array
    {
        $failing = $suite->xpath('.//testcase[failure or error]') ?: [];
        $first = $failing[0] ?? null;

        return [
            'file' => ltrim(str_replace($this->root.'/', '', (string) $suite['file']), '/'),
            'passed' => $failing === [],
            'summary' => $first === null ? null : mb_substr(trim((string) $first['name'].': '.Str::before(trim((string) ($first->failure ?? $first->error)), "\n")), 0, 300),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function testingEnvironment(): array
    {
        return [...$this->fromDotEnv('.env.testing'), ...$this->fromPhpunit()];
    }

    /**
     * @return array<string, string>
     */
    private function fromPhpunit(): array
    {
        $file = collect(['phpunit.xml', 'phpunit.xml.dist'])
            ->map(fn (string $name): string => $this->root.'/'.$name)
            ->first(fn (string $path): bool => is_file($path));
        $xml = $file === null ? false : simplexml_load_file($file);

        return collect($xml === false ? [] : ($xml->xpath('//php/env[@name] | //php/server[@name]') ?: []))
            ->mapWithKeys(fn (SimpleXMLElement $variable): array => [(string) $variable['name'] => (string) $variable['value']])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function fromDotEnv(string $name): array
    {
        $file = $this->root.'/'.$name;

        return collect(is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [])
            ->filter(fn (string $line): bool => preg_match('/^\s*[A-Z_][A-Z0-9_]*\s*=/', $line) === 1)
            ->mapWithKeys(fn (string $line): array => [trim(Str::before($line, '=')) => trim(Str::after($line, '='), " \t\"'")])
            ->all();
    }
}
