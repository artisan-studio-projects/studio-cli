<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use Symfony\Component\Process\Process;

/**
 * The safety branch SAMI's fixes land on, on this machine only.
 *
 * Nothing here pushes. Untracked files are left out of every commit unless a
 * fixer names them as its own, such as the assets a package update publishes.
 */
final class LocalBranch
{
    public const string PREFIX = 'sami/fixes-';

    /**
     * @var list<string>
     */
    public const array LOCK_FILES = ['composer.lock', 'pnpm-lock.yaml', 'package-lock.json', 'yarn.lock'];

    private const int LARGEST_PATCH = 100_000;

    private const int MOST_PATCHES = 500;

    public function __construct(private readonly string $root) {}

    /**
     * @return list<array{path: string, patch: string}>
     */
    public function changesSince(string $base): array
    {
        $diff = $this->git(['diff', '--no-color', '--no-ext-diff', '--unified=3', $base.'..HEAD', '--', ...$this->pathspecs([], self::LOCK_FILES)]);

        if ($diff === null || $diff === '') {
            return [];
        }

        return collect(preg_split('/^(?=diff --git )/m', $diff, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $patch): ?array => preg_match('~^diff --git a/.+? b/(.+)$~m', $patch, $file) === 1 && strlen($patch) <= self::LARGEST_PATCH ? ['path' => $file[1], 'patch' => rtrim($patch)."\n"] : null)
            ->filter()
            ->take(self::MOST_PATCHES)
            ->values()
            ->all();
    }

    public function isRepository(): bool
    {
        return $this->git(['rev-parse', '--is-inside-work-tree']) === 'true';
    }

    public function isClean(): bool
    {
        return $this->git(['status', '--porcelain', '--untracked-files=no']) === '';
    }

    public function head(): ?string
    {
        return $this->git(['rev-parse', 'HEAD']);
    }

    public function isOnFixes(?string $branch = null): bool
    {
        $current = $this->current();

        return $current !== null && str_starts_with($current, self::PREFIX) && ($branch === null || $current === $branch);
    }

    public function current(): ?string
    {
        $branch = $this->git(['branch', '--show-current']);

        return $branch === null || $branch === '' ? null : $branch;
    }

    public function start(string $stamp): ?string
    {
        $name = collect(range(1, 9))
            ->map(fn (int $try): string => self::PREFIX.$stamp.($try === 1 ? '' : '-'.$try))
            ->first(fn (string $name): bool => $this->git(['rev-parse', '--verify', '--quiet', 'refs/heads/'.$name]) === null);

        return $name !== null && $this->git(['switch', '-c', $name]) !== null ? $name : null;
    }

    /**
     * The tracked files changed and not yet committed: only these, when
     * given, and never those excepted.
     *
     * @param  list<string>  $only
     * @param  list<string>  $except
     * @return list<string>
     */
    public function changed(array $only = [], array $except = []): array
    {
        $files = $this->git(['diff', '--name-only', '--', ...$this->pathspecs($only, $except)]);

        return $files === null || $files === '' ? [] : explode("\n", $files);
    }

    /**
     * Files git does not track yet, and does not ignore.
     *
     * @return list<string>
     */
    public function untracked(): array
    {
        $files = $this->git(['ls-files', '--others', '--exclude-standard']);

        return $files === null || $files === '' ? [] : explode("\n", $files);
    }

    /**
     * Commits the changed files in scope, and the new ones named, as one
     * commit. Two fixers can run at once on the same branch, so staging and
     * committing hold a lock, and each stages only its own files.
     *
     * @param  list<string>  $only
     * @param  list<string>  $except
     * @param  list<string>  $new
     */
    public function commit(string $subject, string $body = '', array $only = [], array $except = [], array $new = []): ?string
    {
        if (! $this->isOnFixes()) {
            return null;
        }

        $gitDir = $this->git(['rev-parse', '--absolute-git-dir']);
        $lock = $gitDir === null ? false : fopen($gitDir.'/sami-fixes.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            return null;
        }

        try {
            $changed = $this->changed($only, $except);

            if (($changed === [] && $new === []) || ($changed !== [] && $this->git(['add', '--', ...$changed]) === null) || ($new !== [] && $this->git(['add', '--', ...$new]) === null)) {
                return null;
            }

            $committed = $this->git(['commit', '--quiet', '-m', $subject, ...($body === '' ? [] : ['-m', $body])]);

            return $committed === null ? null : $this->git(['rev-parse', 'HEAD']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  list<string>  $only
     * @param  list<string>  $except
     * @return list<string>
     */
    private function pathspecs(array $only, array $except): array
    {
        return [...$only, ...($only === [] && $except !== [] ? ['.'] : []), ...array_map(fn (string $path): string => ':(exclude)'.$path, $except)];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): ?string
    {
        $process = new Process(['git', ...$arguments], $this->root, timeout: 120);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }
}
