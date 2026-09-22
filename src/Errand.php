<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Symfony\Component\Process\Process;

/**
 * Answer a question the studio cannot answer for itself.
 *
 * ★ THE STUDIO NAMES A QUESTION, NOT A COMMAND. What arrives is one of four
 * identifiers; what runs is decided HERE, on the machine it runs on. A studio
 * that could post a shell string to a developer's laptop would be a remote
 * execution hole wearing a build's clothes, so nothing from the wire is ever
 * interpreted — an unknown name runs nothing at all.
 *
 * Everything here reads. Nothing writes files, migrates, or installs: the
 * artisans write through the branch, where a person can see it.
 */
class Errand
{
    public function __construct(private readonly string $root) {}

    /**
     * Run what the studio asked for, and say what happened.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{output: string, exit_code: int, error: ?string}
     */
    public function run(string $name, array $arguments = []): array
    {
        $command = $this->whatThatMeans($name, $arguments);

        if ($command === null) {
            return [
                'output' => '',
                'exit_code' => 1,
                'error' => 'This version of the CLI does not know how to answer "'.$name.'".',
            ];
        }

        $process = new Process($command, $this->root, timeout: 300);
        $process->run();

        return [
            'output' => $this->trimmed($process->getOutput().$process->getErrorOutput()),
            'exit_code' => (int) $process->getExitCode(),
            'error' => null,
        ];
    }

    /**
     * The actual command for a question, or null when it is not one of ours.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<string>|null
     */
    private function whatThatMeans(string $name, array $arguments): ?array
    {
        $raw = trim((string) ($arguments['raw'] ?? ''));

        if ($raw !== '' && $this->isOneOfBoostsReadTools($raw)) {
            return ['sh', '-c', $raw];
        }

        return match ($name) {
            'describe_schema' => ['php', 'artisan', 'db:show', '--counts'],
            'query_database' => $this->aReadOnlyQuery($arguments),
            'run_tests' => $this->theTestsItNamed($arguments),
            'read_errors' => ['php', 'artisan', 'pail', '--timeout=1'],
            default => null,
        };
    }

    /**
     * Whether a raw command is one of Boost's read-only MCP tools.
     *
     * ★ THE ARTISANS ALREADY SPEAK PRECISELY. Mason asks for a schema by
     * invoking `Laravel\Boost\Mcp\Tools\DatabaseSchema` with a filter — far
     * more exact than any four-word vocabulary invented for it, and the same
     * call it makes against a Cloud sandbox. Rewriting that into `db:show`
     * throws away the filter and answers a different question.
     *
     * So the command is run as sent — but ONLY when it is one of the two tools
     * that read. An allow-list of exact class names, checked on the whole
     * string: nothing else is executed, and a command carrying a second
     * statement fails the match rather than being split and partly trusted.
     */
    private function isOneOfBoostsReadTools(string $raw): bool
    {
        if (! str_starts_with($raw, 'php artisan tinker --execute ')) {
            return false;
        }

        $flattened = str_replace('\\', '', $raw);

        $namesATool = str_contains($flattened, 'BoostMcpToolsDatabaseSchema')
            || str_contains($flattened, 'BoostMcpToolsDatabaseQuery');

        return $namesATool && ! $this->smugglesAWrite($raw);
    }

    /**
     * Whether a read command carries something that writes alongside it.
     *
     * Checked on the whole string rather than by splitting it: a command with a
     * second statement fails outright instead of being parsed into parts, some
     * of which get trusted.
     *
     * ★ `--execute` IS NOT `exec(`. Matching the bare word refused every
     * command this is meant to allow, because `php artisan tinker --execute`
     * contains it — a list that rejects its own happy path is worse than no
     * list, since it looks like a security check and behaves like an outage.
     * The flag is dropped before the scan, and the dangerous names carry their
     * opening bracket.
     */
    private function smugglesAWrite(string $raw): bool
    {
        $body = str_replace('--execute', '', $raw);

        foreach (['unlink(', 'exec(', 'shell_exec(', 'system(', 'passthru(', 'proc_open(',
            'file_put_contents(', 'DB::statement', '->delete(', '->update(', '->save(',
            'drop ', 'truncate', 'migrate', '&&', '||', ';rm', '| sh', '`'] as $danger) {
            if (stripos($body, $danger) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * A SELECT, or nothing.
     *
     * ★ ONLY READS. An artisan looking at shape has no business writing, and a
     * query arriving over the network is not a thing to trust — so anything
     * that is not plainly a SELECT is refused here rather than sanitised.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<string>|null
     */
    private function aReadOnlyQuery(array $arguments): ?array
    {
        $sql = trim((string) ($arguments['sql'] ?? ''));

        if ($sql === '' || ! preg_match('/^select\s/i', $sql)) {
            return null;
        }

        return ['php', 'artisan', 'db:query', $sql];
    }

    /**
     * Pest, on the paths the studio named.
     *
     * A path is checked before it is passed: it has to sit under `tests/` and
     * carry nothing a shell would find interesting, because the list arrives
     * over the network like everything else here.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    private function theTestsItNamed(array $arguments): array
    {
        $paths = array_values(array_filter(
            (array) ($arguments['paths'] ?? []),
            fn ($path): bool => is_string($path) && $this->isATestPath($path),
        ));

        return ['php', 'artisan', 'test', '--compact', ...$paths];
    }

    private function isATestPath(string $path): bool
    {
        return str_starts_with($path, 'tests/')
            && ! str_contains($path, '..')
            && preg_match('/^[A-Za-z0-9_\-.\/]+$/', $path) === 1;
    }

    /**
     * Enough output to diagnose something, and no more.
     *
     * A failing suite can print megabytes, and all of it would travel to the
     * studio and into a model's context — so the tail is kept, which is where
     * the failures are.
     */
    private function trimmed(string $output): string
    {
        $limit = 100000;

        return strlen($output) <= $limit
            ? $output
            : "…trimmed…\n".substr($output, -$limit);
    }
}
