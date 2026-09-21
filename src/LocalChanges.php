<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Symfony\Component\Process\Process;

/**
 * What the developer changed while the build waited.
 *
 * ★ UNTRACKED FILES COUNT. An artisan writes one fat component and the person
 * reviewing it extracts three Blade partials — three of their four files are
 * new, and a status that skips them submits a component referencing views the
 * studio never heard of. {@see Workspace::whyItCannotWatch()} ignores untracked
 * files deliberately, because they survive a checkout; this must not, because
 * they are the work.
 *
 * ★ AND `--untracked-files=all` OR GIT COLLAPSES THEM. A new directory is
 * reported as the directory — `?? resources/` — so an extraction into a folder
 * that did not exist arrives as one entry naming no file at all. The studio
 * would have been handed a directory where it expected three components.
 */
class LocalChanges
{
    public function __construct(private readonly string $root) {}

    /**
     * Every path touched since the last commit, newest state of each.
     *
     * @return list<array{path: string, status: string}>
     */
    public function sinceTheLastCommit(): array
    {
        $lines = array_filter(explode("\n", $this->git(['status', '--porcelain', '--untracked-files=all'])));

        return array_values(array_filter(array_map(
            fn (string $line): ?array => $this->readLine($line),
            $lines,
        )));
    }

    /** Stage everything and commit it, returning the new sha. */
    public function commitEverything(string $message): ?string
    {
        $this->git(['add', '--all']);

        $this->git(['commit', '--message', $message]);

        $sha = trim($this->git(['rev-parse', 'HEAD']));

        return $sha === '' ? null : $sha;
    }

    public function isClean(): bool
    {
        return $this->sinceTheLastCommit() === [];
    }

    public function currentBranch(): string
    {
        return trim($this->git(['rev-parse', '--abbrev-ref', 'HEAD']));
    }

    /**
     * Put the developer on the build's branch.
     *
     * Fetched first, because the branch was cut on GitHub and a clone that has
     * not heard of it cannot check it out. Tracking rather than detached: they
     * are about to commit to it, and a commit on a detached head is one a
     * closed terminal loses.
     */
    public function switchTo(string $branch): bool
    {
        $this->git(['fetch', '--quiet', '--prune']);

        if ($this->currentBranch() === $branch) {
            return true;
        }

        $this->git(['checkout', $branch]);

        if ($this->currentBranch() === $branch) {
            return true;
        }

        $this->git(['checkout', '-b', $branch, '--track', 'origin/'.$branch]);

        return $this->currentBranch() === $branch;
    }

    /** Bring the branch up to date, without ever merging over local work. */
    public function catchUp(): void
    {
        $this->git(['pull', '--ff-only', '--quiet']);
    }

    /**
     * One porcelain line as a path and what happened to it.
     *
     * The format is two status characters, a space, then the path — so the
     * columns are read by position rather than by splitting on whitespace,
     * which a path with a space in it would break.
     */
    private function readLine(string $line): ?array
    {
        if (strlen($line) < 4) {
            return null;
        }

        $code = substr($line, 0, 2);
        $path = trim(substr($line, 3));

        if ($path === '') {
            return null;
        }

        return [
            'path' => trim($this->whereItEndedUp($path), '"'),
            'status' => $this->statusOf($code),
        ];
    }

    /**
     * The name a path goes by now.
     *
     * A rename arrives as `old -> new`, and the new name is the one the studio
     * needs — the old one is covered by the deletion git records beside it.
     */
    private function whereItEndedUp(string $path): string
    {
        return str_contains($path, ' -> ')
            ? substr($path, strpos($path, ' -> ') + 4)
            : $path;
    }

    private function statusOf(string $code): string
    {
        if ($code === '??' || str_contains($code, 'A')) {
            return 'added';
        }

        return str_contains($code, 'D') ? 'deleted' : 'modified';
    }

    /** @param  list<string>  $arguments */
    private function git(array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->root);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }
}
