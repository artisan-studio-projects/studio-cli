<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

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

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? base_path();
        $this->tools = collect([new Pint, new PhpStan, new Rector, new ComposerAudit, new NodeAudit, new TypeCoverage, new FilaCheck, new Tests])
            ->keyBy(fn (Tool $tool): string => $tool->key())
            ->all();
    }

    public function name(string $key): string
    {
        return ($this->tools[$key] ?? null)?->name() ?? $key;
    }

    /**
     * @param  list<string>  $keys
     * @param  (Closure(string, string, array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string}>}): void)|null  $after
     * @param  (Closure(string): void)|null  $before
     * @return array<string, array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string}>}>
     */
    public function run(array $keys, ?Closure $after = null, ?Closure $before = null): array
    {
        return collect($keys)
            ->mapWithKeys(function (string $key) use ($after, $before): array {
                if ($before !== null) {
                    $before($key);
                }

                $result = $this->runOne($key);

                if ($after !== null) {
                    $after($key, ($this->tools[$key] ?? null)?->name() ?? $key, $result);
                }

                return [$key => $result];
            })
            ->all();
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
     * @return array{ran: bool, reason?: string, took?: int, findings?: list<array{where: string, rule: string, message: string}>}
     */
    private function runOne(string $key): array
    {
        $tool = $this->tools[$key] ?? null;
        $command = $tool?->command($this->root);

        if ($tool === null || $command === null) {
            return ['ran' => false, 'reason' => $tool === null ? 'This version of the studio CLI cannot run it yet.' : $tool->missing()];
        }

        $started = hrtime(true);
        $process = new Process($command, $this->root, $this->environment(), timeout: $tool->timeout());

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ['ran' => false, 'reason' => 'Took longer than '.intdiv($tool->timeout(), 60).' minutes, so it was stopped.'];
        } catch (Throwable $exception) {
            return ['ran' => false, 'reason' => 'Could not start: '.class_basename($exception).'.'];
        }

        $took = (int) ((hrtime(true) - $started) / 1_000_000);
        $findings = $tool->findings($process->getOutput(), $this->root);
        $summary = $tool->summary();

        return $findings === null
            ? ['ran' => false, 'reason' => 'Its output could not be read.', 'took' => $took]
            : ['ran' => true, 'took' => $took, 'findings' => $findings, ...($summary === null ? [] : ['summary' => $summary])];
    }
}
