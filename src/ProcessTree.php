<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Symfony\Component\Process\Process;

/**
 * A process and everything it started. A scan's command starts the tools,
 * which start workers of their own, so stopping only the command would leave
 * them running on the developer's machine.
 */
final class ProcessTree
{
    /**
     * The process and all it started, the newest first, so the workers go
     * before the command that is waiting for them.
     *
     * @return list<int>
     */
    public static function of(int $pid): array
    {
        $found = [];
        $queue = [$pid];

        while ($queue !== []) {
            $next = array_shift($queue);
            $found[] = $next;
            $queue = [...$queue, ...self::childrenOf($next)];
        }

        return array_reverse($found);
    }

    public static function stop(int $pid): void
    {
        $pids = self::of($pid);

        if (PHP_OS_FAMILY === 'Windows' || $pids === []) {
            return;
        }

        (new Process(['kill', '-TERM', ...array_map(strval(...), $pids)]))->run();
    }

    /**
     * @return list<int>
     */
    private static function childrenOf(int $pid): array
    {
        $process = new Process(['pgrep', '-P', (string) $pid]);
        $process->run();

        return array_values(array_map(intval(...), array_filter(explode("\n", trim($process->getOutput())))));
    }
}
