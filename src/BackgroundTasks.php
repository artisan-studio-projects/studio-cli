<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Closure;
use Symfony\Component\Process\Process;

final class BackgroundTasks
{
    private const int TIMEOUT = 600;

    /** @var array<string, array{process: Process, label: string, journal: string, offset: int, steps: int, then: array{key: string, label: string, working: string, command: list<string>, timeout?: int}|null}> */
    private array $running = [];

    public function __construct(
        private readonly string $root,
        private readonly ActivityLog $log,
        private readonly ?Closure $whenFinished = null,
    ) {}

    /**
     * @param  list<string>  $command
     * @param  array{key: string, label: string, working: string, command: list<string>, timeout?: int}|null  $then
     */
    public function start(string $key, string $label, string $working, array $command, ?array $then = null, int $timeout = self::TIMEOUT): void
    {
        if ($this->isRunning($key)) {
            return;
        }

        $journal = sys_get_temp_dir().'/studio-task-'.$key.'-'.bin2hex(random_bytes(4)).'.log';
        $process = new Process([PHP_BINARY, 'artisan', ...$command, '--plain', '--no-ansi', '--no-interaction'], $this->root, [TaskJournal::ENV => $journal], timeout: $timeout);
        $process->start();

        $this->running[$key] = ['process' => $process, 'label' => $label, 'journal' => $journal, 'offset' => 0, 'steps' => 0, 'then' => $then];
        $this->log->add(['agent' => 'SAMI', 'label' => $label, 'detail' => $working, 'colour' => 'cyan', 'kind' => $key], $key);
    }

    public function isRunning(string $key): bool
    {
        return isset($this->running[$key]) && $this->running[$key]['process']->isRunning();
    }

    public function tick(): void
    {
        array_map($this->follow(...), array_keys($this->running));

        $finished = array_filter($this->running, fn (array $task): bool => ! $task['process']->isRunning());

        array_map($this->finish(...), array_keys($finished), $finished);
    }

    private function follow(string $key): void
    {
        $task = $this->running[$key];
        $read = TaskJournal::readFrom($task['journal'], $task['offset']);

        array_map(fn (string $line, int $index) => $this->log->add([
            'agent' => 'SAMI',
            'label' => $task['label'],
            'detail' => $line,
            'colour' => 'sky',
            'kind' => $key,
        ], $key.':'.($task['steps'] + $index)), $read['lines'], array_keys($read['lines']));

        $this->running[$key] = [...$task, 'offset' => $read['offset'], 'steps' => $task['steps'] + count($read['lines'])];
    }

    /**
     * @param  array{process: Process, label: string, journal: string, offset: int, steps: int, then: array{key: string, label: string, working: string, command: list<string>, timeout?: int}|null}  $task
     */
    private function finish(string $key, array $task): void
    {
        $this->follow($key);
        unset($this->running[$key]);
        @unlink($task['journal']);

        $succeeded = $task['process']->isSuccessful();
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $task['process']->getOutput()))));

        $this->log->add([
            'agent' => 'SAMI',
            'label' => $task['label'],
            'detail' => $lines === [] ? ($succeeded ? 'Done.' : 'Stopped before it finished.') : $lines[count($lines) - 1],
            'colour' => $succeeded ? 'green' : 'amber',
            'kind' => $key,
        ], $key);

        if ($this->whenFinished !== null) {
            ($this->whenFinished)($key);
        }

        if ($task['then'] !== null) {
            $this->start($task['then']['key'], $task['then']['label'], $task['then']['working'], $task['then']['command'], timeout: $task['then']['timeout'] ?? self::TIMEOUT);
        }
    }
}
