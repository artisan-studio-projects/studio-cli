<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Symfony\Component\Process\Process;

class Workspace
{
    public function __construct(private readonly string $root) {}

    public function whyItCannotWatch(): ?string
    {
        if (! $this->isGitRepository()) {
            return 'This is not a git repository, so there is no branch for a build to follow.';
        }

        if ($this->hasUncommittedChanges()) {
            return 'You have uncommitted changes. Commit or stash them first — a preview should not land on top of work in progress.';
        }

        return null;
    }

    public function fetch(): void
    {
        $this->git(['fetch', '--quiet', '--prune']);
    }

    public function isGitRepository(): bool
    {
        return trim($this->git(['rev-parse', '--is-inside-work-tree'])) === 'true';
    }

    private function hasUncommittedChanges(): bool
    {
        return trim($this->git(['status', '--porcelain', '--untracked-files=no'])) !== '';
    }

    /** @param  list<string>  $arguments */
    private function git(array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->root);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }
}
