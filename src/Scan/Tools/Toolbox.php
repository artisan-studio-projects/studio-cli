<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Scan\LastFindings;
use ArtisanStudio\StudioCli\Scan\Load;
use Closure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class Toolbox
{
    private const string AGENT_VARIABLES = '/^(CLAUDE|AI_AGENT|CODEX|CURSOR|GEMINI_CLI|OPENCODE|WARP_CLI_AGENT|AMP_|REPL_ID)/i';

    private const int MOST_PACKAGES = 5000;

    private readonly string $root;

    /** @var array<string, Tool> */
    private readonly array $tools;

    private readonly LastFindings $lastFindings;

    private int $atOnce = Load::AT_ONCE;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? base_path();
        $this->lastFindings = new LastFindings($this->root);
        $this->tools = collect([new Pint, new PhpStan, new Rector, new ComposerAudit, new NodeAudit, new TypeCoverage, new FilaCheck, new Psalm, new PestArch, new Peck, new ConfigValidator, new StudioSecurityScan, new Vet, new Tests])
            ->keyBy(fn (Tool $tool): string => $tool->key())
            ->all();
    }

    /**
     * How many checks may run at the same time.
     */
    public function limitedTo(int $atOnce): static
    {
        $this->atOnce = max(1, $atOnce);

        return $this;
    }

    public function tool(string $key): ?Tool
    {
        return $this->tools[$key] ?? null;
    }

    public function name(string $key): string
    {
        return ($this->tools[$key] ?? null)?->name() ?? $key;
    }

    /**
     * @param  list<string>  $keys
     * @param  (Closure(string, string, array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string, packages?: list<string>, major?: bool}>}): void)|null  $after
     * @param  (Closure(string): void)|null  $before
     * @return array<string, array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string, packages?: list<string>, major?: bool}>}>
     */
    public function run(array $keys, ?Closure $after = null, ?Closure $before = null): array
    {
        return collect($keys)
            ->mapWithKeys(function (string $key) use ($after, $before): array {
                if ($before !== null) {
                    $before($key);
                }

                $result = $this->kept($key, $this->runOne($key));

                if ($after !== null) {
                    $after($key, ($this->tools[$key] ?? null)?->name() ?? $key, $result);
                }

                return [$key => $result];
            })
            ->all();
    }

    /**
     * The checks one after another, or {@see Load::AT_ONCE} at a time, each
     * reported the moment it finishes, so a scan never leans on the machine
     * with every tool together.
     *
     * @param  list<string>  $keys
     * @param  (Closure(string, string, array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string, packages?: list<string>, major?: bool}>}): void)|null  $after
     * @param  (Closure(string): void)|null  $before
     * @return array<string, array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string, packages?: list<string>, major?: bool}>}>
     */
    public function runTogether(array $keys, ?Closure $after = null, ?Closure $before = null): array
    {
        $started = [];
        $results = [];
        $queue = array_values($keys);

        while (count($results) < count($keys)) {
            while ($queue !== [] && count($started) - count($results) < $this->atOnce) {
                $key = array_shift($queue);

                if ($before !== null) {
                    $before($key);
                }

                $started[$key] = $this->start($key);
            }

            $landed = array_filter($started, fn (array $run, string $key): bool => ! isset($results[$key]) && (! isset($run['process']) || ! $run['process']->isRunning() || (hrtime(true) - $run['started']) / 1_000_000_000 > $this->tools[$key]->timeout()), ARRAY_FILTER_USE_BOTH);

            array_map(function (string $key, array $run) use (&$results, $after): void {
                $results[$key] = $this->kept($key, $this->finish($key, $run));

                if ($after !== null) {
                    $after($key, ($this->tools[$key] ?? null)?->name() ?? $key, $results[$key]);
                }
            }, array_keys($landed), $landed);

            if ($landed === []) {
                usleep(100_000);
            }
        }

        return array_merge(array_fill_keys($keys, []), $results);
    }

    /**
     * Runs the checks at the same time rather than one after another, and
     * returns everything each found, uncapped.
     *
     * @param  list<string>  $keys
     * @return array<string, array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string, packages?: list<string>, major?: bool}>}>
     */
    public function checkTogether(array $keys): array
    {
        $started = array_combine($keys, array_map($this->start(...), $keys));

        return array_map(fn (string $key): array => $this->finish($key, $started[$key]), array_combine($keys, $keys));
    }

    /**
     * Keeps everything a check found on this machine, and sends all of it.
     *
     * @param  array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string, packages?: list<string>, major?: bool}>}  $result
     * @return array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string, packages?: list<string>, major?: bool}>}
     */
    private function kept(string $key, array $result): array
    {
        if (isset($result['findings'])) {
            $this->lastFindings->keep($key, $result['findings']);
        }

        return $result;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{name: string, packages: list<string>, files: array<string, string>, about: string}>
     */
    public function missing(array $keys): array
    {
        return collect($keys)
            ->map(fn (string $key): ?Tool => $this->tools[$key] ?? null)
            ->filter(fn (?Tool $tool): bool => $tool !== null && $tool->install() !== null && ! $tool->isSetUp($this->root))
            ->mapWithKeys(fn (Tool $tool): array => [$tool->key() => ['name' => $tool->name(), ...$tool->install()]])
            ->all();
    }

    /**
     * @return array<string, string|false>
     */
    public function environment(): array
    {
        return [
            ...collect(array_keys(getenv()))
                ->filter(fn (string $name): bool => preg_match(self::AGENT_VARIABLES, $name) === 1)
                ->mapWithKeys(fn (string $name): array => [$name => false])
                ->all(),
            ...array_fill_keys($this->appSettings(), false),
            'PAO_DISABLE' => '1',
            'XDEBUG_MODE' => 'off',
        ];
    }

    /**
     * @return list<string>
     */
    public function appSettings(): array
    {
        $env = (string) @file_get_contents($this->root.'/.env');

        return preg_match_all('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=/m', $env, $names) > 0 ? array_values(array_unique($names[1])) : [];
    }

    /**
     * @return array<string, string>
     */
    public function packages(): array
    {
        $lock = json_decode((string) @file_get_contents($this->root.'/composer.lock'), true);

        return collect([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])])
            ->filter(fn (mixed $package): bool => is_array($package) && is_string($package['name'] ?? null))
            ->mapWithKeys(fn (array $package): array => [$package['name'] => mb_substr((string) ($package['version'] ?? ''), 0, 100)])
            ->take(self::MOST_PACKAGES)
            ->all();
    }

    /**
     * @return array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string, packages?: list<string>, major?: bool}>}
     */
    public function check(string $key): array
    {
        return $this->runOne($key);
    }

    public function canFix(string $key): bool
    {
        return ($this->tools[$key] ?? null)?->canFix($this->root) === true;
    }

    /**
     * Runs the tool's own fixer, with the same environment as its checks.
     *
     * @param  list<string>  $paths  Only these files, when given.
     * @return array{ran: bool, reason?: string, took?: int}
     */
    public function fix(string $key, array $paths = []): array
    {
        $tool = $this->tools[$key] ?? null;
        $command = $tool?->fixCommand($this->root);

        if ($tool === null || $command === null) {
            return ['ran' => false, 'reason' => $tool === null ? 'This version of the studio CLI cannot fix it yet.' : $tool->missing()];
        }

        $started = hrtime(true);
        $process = new Process([...$command, ...$paths], $this->root, $this->environment(), timeout: $tool->timeout());

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ['ran' => false, 'reason' => 'Took longer than '.intdiv($tool->timeout(), 60).' minutes, so it was stopped.'];
        } catch (Throwable $exception) {
            return ['ran' => false, 'reason' => 'Could not start: '.class_basename($exception).'.'];
        }

        $took = (int) ((hrtime(true) - $started) / 1_000_000);

        return $process->isSuccessful() || $tool->fixesEvenWhenFailing()
            ? ['ran' => true, 'took' => $took]
            : ['ran' => false, 'reason' => 'It stopped with an error: '.$this->lastWords($process->getErrorOutput() ?: $process->getOutput()), 'took' => $took];
    }

    /**
     * A tool says what went wrong at the end, after its progress dots, so the
     * reason is its last lines that say something.
     */
    private function lastWords(string $output): string
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", (string) preg_replace('/\e\[[0-9;]*m/', '', $output))), fn (string $line): bool => preg_match('/[a-z]/', $line) === 1));

        return mb_substr(implode(' ', array_slice($lines, -3)), 0, 200);
    }

    /**
     * @return array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string, packages?: list<string>, major?: bool}>}
     */
    private function runOne(string $key): array
    {
        return $this->finish($key, $this->start($key));
    }

    /**
     * @return array{process: Process, started: int}|array{ran: false, reason: string}
     */
    private function start(string $key): array
    {
        $tool = $this->tools[$key] ?? null;
        $command = $tool?->command($this->root);

        if ($tool === null || $command === null) {
            return ['ran' => false, 'reason' => $tool === null ? 'This version of the studio CLI cannot run it yet.' : $tool->missing()];
        }

        $process = new Process([...Load::gently(), ...$command], $this->root, [...$this->environment(), ...$tool->environment()], timeout: $tool->timeout());

        try {
            $process->start();
        } catch (Throwable $exception) {
            return ['ran' => false, 'reason' => 'Could not start: '.class_basename($exception).'.'];
        }

        return ['process' => $process, 'started' => hrtime(true)];
    }

    /**
     * @param  array{process: Process, started: int}|array{ran: false, reason: string}  $run
     * @return array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string, packages?: list<string>, major?: bool}>}
     */
    private function finish(string $key, array $run): array
    {
        if (! isset($run['process'])) {
            return $run;
        }

        $tool = $this->tools[$key];
        $process = $run['process'];

        try {
            $process->wait();
        } catch (ProcessTimedOutException) {
            return ['ran' => false, 'reason' => 'Took longer than '.intdiv($tool->timeout(), 60).' minutes, so it was stopped.'];
        } catch (Throwable $exception) {
            return ['ran' => false, 'reason' => 'Could not finish: '.class_basename($exception).'.'];
        }

        $took = (int) ((hrtime(true) - $run['started']) / 1_000_000);
        $findings = $tool->findings($process->getOutput(), $this->root);
        $summary = $tool->summary();

        return $findings === null
            ? ['ran' => false, 'reason' => 'Its output could not be read.', 'took' => $took]
            : ['ran' => true, 'took' => $took, 'findings' => $findings, ...($summary === null ? [] : ['summary' => $summary])];
    }
}
