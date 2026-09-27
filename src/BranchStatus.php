<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

class BranchStatus
{
    private const int FRESH_FOR = 5;

    /**
     * @var array{branch: string, uncommitted: int}|null
     */
    private ?array $last = null;

    private int $at = 0;

    public function __construct(private readonly LocalChanges $changes, private readonly Workspace $workspace) {}

    /**
     * @return array{branch: string, uncommitted: int}
     */
    public function now(): array
    {
        if ($this->last !== null && time() - $this->at < self::FRESH_FOR) {
            return $this->last;
        }

        $this->at = time();

        return $this->last = $this->workspace->isGitRepository()
            ? ['branch' => $this->changes->currentBranch(), 'uncommitted' => count($this->changes->sinceTheLastCommit())]
            : ['branch' => '', 'uncommitted' => 0];
    }

    public function forget(): self
    {
        $this->last = null;

        return $this;
    }

    public function beforeReviewing(?string $branch): string
    {
        $now = $this->now();
        $changes = trans_choice(':count uncommitted change|:count uncommitted changes', $now['uncommitted']);

        return match (true) {
            $now['branch'] === '' => 'Reviewing needs this folder to be a git repository, and it is not one.',
            $branch === null => 'The artisans have not pushed a branch for this yet.',
            $now['branch'] === $branch && $now['uncommitted'] > 0 => "You are on {$branch} already, with {$changes}. The review would count them as your edits.",
            $now['branch'] === $branch => "You are on {$branch} already.",
            $now['uncommitted'] > 0 => "You are on {$now['branch']} with {$changes}. Commit or stash them first: the review switches you to {$branch}.",
            default => "Starting the review switches you from {$now['branch']} to {$branch}.",
        };
    }

    public function blocksReviewing(?string $branch): bool
    {
        $now = $this->now();

        return $branch !== null && $now['branch'] !== $branch && $now['uncommitted'] > 0;
    }
}
