<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Symfony\Component\Process\Process;

/**
 * What git says about the project on this machine: the commit it is on, the
 * GitHub repository it was cloned from, and the files it holds.
 */
class LocalChanges
{
    public function __construct(private readonly string $root) {}

    public function currentSha(): string
    {
        return trim($this->git(['rev-parse', 'HEAD']));
    }

    public function originRepository(): ?string
    {
        $url = trim($this->git(['remote', 'get-url', 'origin'], timeout: 5));

        return preg_match('#github\.com[:/]([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $url, $match) === 1 ? $match[1] : null;
    }

    /**
     * @return list<string>
     */
    public function trackedFiles(): array
    {
        return collect(['--cached', '--others'])
            ->flatMap(fn (string $which): array => explode("\0", $this->git(['ls-files', '-z', $which, '--exclude-standard'], timeout: 60)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param  list<string>  $arguments */
    private function git(array $arguments, ?int $timeout = 30): string
    {
        $process = new Process(['git', ...$arguments], $this->root, $this->neverAsking(), timeout: $timeout);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    /**
     * Environment that makes git fail rather than ask for a password nobody
     * is there to type.
     *
     * @return array<string, string>
     */
    private function neverAsking(): array
    {
        return [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS' => 'true',
            'SSH_ASKPASS' => 'true',
            'GIT_SSH_COMMAND' => 'ssh -oBatchMode=yes -oStrictHostKeyChecking=accept-new',
        ];
    }
}
