<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * How hard a scan may lean on the developer's machine: a few tools at once,
 * another, at a lower priority than their editor and browser, and the
 * tools that start a worker for every core given a quarter of them.
 */
final class Load
{
    public const int AT_ONCE = 3;

    public const int NICENESS = 10;

    private static ?int $cores = null;

    /**
     * The machine's real cores, not its threads: a chip that shows 20 threads
     * has 10 cores, and a tool given a worker for every thread takes them all.
     */
    public static function cores(): int
    {
        return self::$cores ??= self::countCores();
    }

    /**
     * Six in ten of the cores, at least two: what the test run is given, which
     * has to stay quick without taking the machine.
     */
    public static function forTests(): int
    {
        return max(2, (int) round(self::cores() * 0.6));
    }

    /**
     * A quarter of the cores, at least two: what a tool that would take them
     * all is given, so a scan shows as a light load on the machine.
     */
    public static function workers(): int
    {
        return max(2, intdiv(self::cores(), 4));
    }

    /**
     * What goes before a command to run it at a lower priority, where the
     * machine has nice, so the developer's own work stays quick.
     *
     * @return list<string>
     */
    public static function gently(): array
    {
        $nice = PHP_OS_FAMILY === 'Windows' ? null : (new ExecutableFinder)->find('nice');

        return $nice === null ? [] : [$nice, '-n', (string) self::NICENESS];
    }

    private static function countCores(): int
    {
        $count = PHP_OS_FAMILY === 'Windows' ? 0 : self::asked(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'hw.physicalcpu'] : ['nproc']);

        return $count > 0 ? $count : 4;
    }

    /**
     * @param  list<string>  $command
     */
    private static function asked(array $command): int
    {
        $process = new Process($command, timeout: 5);
        $process->run();

        return $process->isSuccessful() ? (int) trim($process->getOutput()) : 0;
    }
}
