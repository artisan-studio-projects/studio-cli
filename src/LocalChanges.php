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

    /**
     * Lines added and removed per path since the last commit.
     *
     * A file nobody has committed yet has no before, so every line in it
     * counts as added.
     *
     * @return array<string, array{path: string, added: int, removed: int}>
     */
    public function lineChangesSinceTheLastCommit(): array
    {
        $tracked = array_filter(array_map(
            fn (string $line): ?array => $this->readNumstatLine($line),
            array_filter(explode("\n", $this->git(['diff', '--numstat', 'HEAD']))),
        ));

        $untracked = array_map(
            fn (string $path): array => ['path' => $path, 'added' => $this->linesIn($path), 'removed' => 0],
            array_filter(explode("\n", $this->git(['ls-files', '--others', '--exclude-standard']))),
        );

        return array_column([...$tracked, ...$untracked], null, 'path');
    }

    /** Stage everything and commit it, returning the new sha. */
    public function commitEverything(string $message): ?string
    {
        $this->git(['add', '--all']);

        $this->git(['commit', '--message', $message]);

        $sha = trim($this->git(['rev-parse', 'HEAD']));

        return $sha === '' ? null : $sha;
    }

    /**
     * Put this commit on the build's branch on GitHub.
     *
     * ★ A LOCAL COMMIT IS NOT A REVIEW. The next artisan commits through
     * GitHub, so a fix that only exists on this machine is a fix it never sees
     * — the build carried on from the branch as it was, and the developer's
     * change sat stranded behind it. With the studio's own credential when it
     * lends one, so a passphrase-protected key does not stop it.
     */
    public function publish(string $branch, ?string $remote = null): bool
    {
        return $this->succeeds(['push', $remote ?? 'origin', 'HEAD:refs/heads/'.$branch], timeout: 60);
    }

    /**
     * The scope the build's own commits use, so a review reads as part of
     * the same history rather than as something bolted on.
     */
    public function scopeOfTheLastCommit(): ?string
    {
        $subject = trim($this->git(['log', '-1', '--format=%s']));

        return preg_match('/^[a-z]+\(([^)]+)\)!?:/', $subject, $match) === 1 ? $match[1] : null;
    }

    public function isClean(): bool
    {
        return $this->sinceTheLastCommit() === [];
    }

    public function currentBranch(): string
    {
        return trim($this->git(['rev-parse', '--abbrev-ref', 'HEAD']));
    }

    public function currentSha(): string
    {
        return trim($this->git(['rev-parse', 'HEAD']));
    }

    /**
     * Put the developer on the build's branch.
     *
     * Fetched first, because the branch was cut on GitHub and a clone that has
     * not heard of it cannot check it out. Tracking rather than detached: they
     * are about to commit to it, and a commit on a detached head is one a
     * closed terminal loses.
     */
    public function switchTo(string $branch, ?string $remote = null): bool
    {
        $this->tryToFetch($remote);

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

    /**
     * What the artisan actually built on this branch, against where the
     * developer switched from.
     *
     * ★ SWITCHING SOMEBODY ONTO A BRANCH IS NOT SHOWING THEM WHAT IS ON IT.
     * The artisan's work already arrived as real commits before the developer
     * ever gets here — `sinceTheLastCommit()` only sees THEIR OWN edits after
     * that point, so "nothing changed yet" was true and useless: it answered
     * a question nobody asked while staying silent about the one that
     * mattered. A developer reviewing a checkpoint needs to see the artisan's
     * commits first, not be told to start editing blind.
     *
     * @return list<array{path: string, status: string}>
     */
    public function whatLandedSince(string $priorBranch): array
    {
        $range = $priorBranch.'...HEAD';
        $lines = array_filter(explode("\n", $this->git(['diff', '--name-status', $range])));

        return array_values(array_filter(array_map(
            fn (string $line): ?array => $this->readNameStatusLine($line),
            $lines,
        )));
    }

    /** The commit messages the artisan landed on this branch, newest first. */
    public function commitsSince(string $priorBranch): string
    {
        return trim($this->git(['log', '--oneline', $priorBranch.'..HEAD']));
    }

    /** One `git diff --name-status` line as a path and what happened to it. */
    private function readNameStatusLine(string $line): ?array
    {
        $parts = preg_split('/\t+/', trim($line));

        if (count($parts) < 2) {
            return null;
        }

        [$code, $path] = $parts;
        $path = end($parts);

        return ['path' => $path, 'status' => match (true) {
            str_starts_with($code, 'A') => 'added',
            str_starts_with($code, 'D') => 'deleted',
            str_starts_with($code, 'R') => 'renamed',
            default => 'modified',
        }];
    }

    /**
     * One `git diff --numstat` line as a path and its counts.
     *
     * A binary file reports `-` for both, which is nothing to count.
     *
     * @return array{path: string, added: int, removed: int}|null
     */
    private function readNumstatLine(string $line): ?array
    {
        $parts = explode("\t", trim($line), 3);

        if (count($parts) < 3) {
            return null;
        }

        return ['path' => $parts[2], 'added' => (int) $parts[0], 'removed' => (int) $parts[1]];
    }

    private function linesIn(string $path): int
    {
        $full = $this->root.'/'.$path;

        if (! is_file($full)) {
            return 0;
        }

        $contents = (string) file_get_contents($full);

        return $contents === '' ? 0 : substr_count(rtrim($contents, "\n"), "\n") + 1;
    }

    /**
     * Bring the branch up to date, without ever merging over local work.
     *
     * ★ FETCHED WITH THE STUDIO'S CREDENTIAL, LIKE A SWITCH. A plain `pull`
     * from `origin` failed without a word on a passphrase-protected key, so a
     * developer already on the branch reviewed the previous artisan's work
     * while the one they were asked about sat one commit ahead on GitHub.
     */
    public function catchUp(?string $remote = null): void
    {
        $this->tryToFetch($remote);

        $this->git(['merge', '--ff-only', '--quiet', 'origin/'.$this->currentBranch()], timeout: 20);
    }

    /**
     * Refresh what the clone knows, if it can do so without asking anybody.
     *
     * ★ WITH THE STUDIO'S CREDENTIAL, NOT THE DEVELOPER'S. A fetch from
     * `origin` over SSH stops dead on a passphrase-protected key, which is most
     * developers — and telling somebody to load a key before the tool works is
     * a broken tool. The studio already commits to this repository through its
     * own GitHub App, so it lends that for one fetch and nobody is asked for
     * anything.
     *
     * Still best-effort: without a remote to borrow it tries `origin` and
     * carries on either way, since the ref is often already in the clone.
     */
    private function tryToFetch(?string $remote = null): void
    {
        if ($remote === null) {
            $this->git(['fetch', '--quiet', '--prune'], timeout: 15);

            return;
        }

        $this->git(['fetch', '--quiet', $remote, '+refs/heads/*:refs/remotes/origin/*'], timeout: 30);
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
    private function succeeds(array $arguments, ?int $timeout = 30): bool
    {
        $process = new Process(['git', ...$arguments], $this->root, $this->neverAsking(), timeout: $timeout);
        $process->run();

        return $process->isSuccessful();
    }

    /** @param  list<string>  $arguments */
    private function git(array $arguments, ?int $timeout = 30): string
    {
        $process = new Process(['git', ...$arguments], $this->root, $this->neverAsking(), timeout: $timeout);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    /**
     * Environment that makes git fail rather than ask.
     *
     * ★ A PASSWORD PROMPT KILLED THE PAIRING SESSION. `git fetch` on an SSH
     * remote with a passphrase-protected key stops and waits for input — but
     * nothing is reading that input, so it sat there until the process timed
     * out and took the whole review down with it: "Lost the studio", over a
     * credential the fetch did not need.
     *
     * So git is told there is nobody to ask. A fetch that cannot authenticate
     * comes back empty and the refs already in the clone are used instead,
     * which is almost always enough — the branch was cut minutes ago by a
     * studio this machine has been talking to all along.
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
