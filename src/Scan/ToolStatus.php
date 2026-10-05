<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use Illuminate\Support\Carbon;

final class ToolStatus
{
    public const string RUNNING = 'running';

    public const string RAN = 'ran';

    public const string STOPPED = 'stopped';

    public const string OFF = 'off';

    private const int CHECK_EVERY_SECONDS = 10;

    /**
     * @var array{key: string, missing: array<string, array{name: string, packages: list<string>, files: array<string, string>, about: string}>}|null
     */
    private ?array $checked = null;

    public function __construct(private readonly string $root) {}

    /**
     * @param  list<string>  $tools
     */
    public function asked(array $tools): void
    {
        $this->write(['asked' => array_values($tools)]);
    }

    public function testsRunning(): void
    {
        $this->write(['tests' => ['state' => self::RUNNING, 'at' => Carbon::now()->toIso8601String()]]);
    }

    public function testsOff(): void
    {
        $this->write(['tests' => ['state' => self::OFF, 'at' => Carbon::now()->toIso8601String()]]);
    }

    /**
     * @param  array{ran: bool, reason?: string, took?: int, summary?: array<string, int>}  $result
     */
    public function testsFinished(array $result): void
    {
        $this->write(['tests' => [
            'state' => $result['ran'] ? self::RAN : self::STOPPED,
            'at' => Carbon::now()->toIso8601String(),
            'tests' => (int) ($result['summary']['tests'] ?? 0),
            'failed' => (int) ($result['summary']['failed'] ?? 0),
            'skipped' => (int) ($result['summary']['skipped'] ?? 0),
            'took' => (int) ($result['took'] ?? 0),
            'reason' => (string) ($result['reason'] ?? ''),
        ]]);
    }

    /**
     * @param  list<string>  $tools
     */
    public function pick(array $tools): void
    {
        $this->write(['picked' => array_values($tools)]);
    }

    /**
     * @return list<string>
     */
    public function picked(): array
    {
        $picked = $this->read()['picked'] ?? [];

        return is_array($picked) ? array_values(array_filter($picked, is_string(...))) : [];
    }

    /**
     * @param  list<string>  $tools
     */
    public function running(array $tools): void
    {
        $this->write(['run' => ['tools' => array_values($tools), 'done' => [], 'outcomes' => (array) ($this->run()['outcomes'] ?? [])]]);
    }

    /**
     * @param  array{ran: bool, reason?: string, findings?: list<mixed>}  $result
     */
    public function ran(string $tool, array $result = ['ran' => true]): void
    {
        $run = $this->run();

        if ($run !== null) {
            $this->write(['run' => [
                ...$run,
                'done' => array_values(array_unique([...$run['done'], $tool])),
                'outcomes' => [...($run['outcomes'] ?? []), $tool => ['ran' => $result['ran'], 'findings' => count($result['findings'] ?? []), 'reason' => (string) ($result['reason'] ?? '')]],
            ]]);
        }
    }

    /**
     * @return array{ran: bool, findings: int, reason: string}|null
     */
    public function outcome(string $tool): ?array
    {
        $outcome = $this->run()['outcomes'][$tool] ?? null;

        return is_array($outcome) ? $outcome : null;
    }

    /**
     * @return array{tools: list<string>, done: list<string>, outcomes?: array<string, array{ran: bool, findings: int, reason: string}>}|null
     */
    public function run(): ?array
    {
        $run = $this->read()['run'] ?? null;

        return is_array($run) && is_array($run['tools'] ?? null) && is_array($run['done'] ?? null) ? $run : null;
    }

    public function asks(string $tool): bool
    {
        return in_array($tool, (array) ($this->read()['asked'] ?? []), true);
    }

    /**
     * @return array{state: string, at: string, tests?: int, failed?: int, skipped?: int, took?: int, reason?: string}|null
     */
    public function tests(): ?array
    {
        $tests = $this->read()['tests'] ?? null;

        return is_array($tests) && isset($tests['state']) ? $tests : null;
    }

    /**
     * @return array<string, array{name: string, packages: list<string>, files: array<string, string>, about: string}>
     */
    public function missing(): array
    {
        $asked = $this->read()['asked'] ?? [];
        $asked = is_array($asked) ? array_values(array_filter($asked, is_string(...))) : [];
        $key = implode(',', $asked).'|'.@filemtime($this->root.'/composer.lock').'|'.intdiv(time(), self::CHECK_EVERY_SECONDS);

        if ($this->checked === null || $this->checked['key'] !== $key) {
            $this->checked = ['key' => $key, 'missing' => $asked === [] ? [] : (new Toolbox($this->root))->missing($asked)];
        }

        return $this->checked['missing'];
    }

    public function forget(): void
    {
        @unlink($this->path());
        $this->checked = null;
    }

    public function path(): string
    {
        return sys_get_temp_dir().'/studio-tools-'.hash('xxh128', $this->root).'.json';
    }

    /**
     * @return array{asked?: list<string>, picked?: list<string>, tests?: array<string, mixed>, run?: array{tools: list<string>, done: list<string>, outcomes?: array<string, array{ran: bool, findings: int, reason: string}>}}
     */
    private function read(): array
    {
        $json = is_file($this->path()) ? file_get_contents($this->path()) : false;
        $status = $json === false ? null : json_decode($json, true);

        return is_array($status) ? $status : [];
    }

    /**
     * @param  array{asked?: list<string>, picked?: list<string>, tests?: array<string, mixed>, run?: array{tools: list<string>, done: list<string>, outcomes?: array<string, array{ran: bool, findings: int, reason: string}>}}  $changes
     */
    private function write(array $changes): void
    {
        @file_put_contents($this->path(), (string) json_encode([...$this->read(), ...$changes]), LOCK_EX);
    }
}
