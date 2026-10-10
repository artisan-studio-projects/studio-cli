<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\Workbench;
use Closure;
use PhpParser\Node\Stmt\ClassMethod;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Methods whose native return type is a generic class with nothing saying
 * what it holds, typed from what the project's own PHPStan proves they
 * return. `theirAssets(): Builder` returning `AvatarAsset::query()` gets
 * `@return Builder<AvatarAsset>`, so its callers stop reading `Model`.
 *
 * One PHPStan run over the project with {@see GenericReturnTypesRule} added
 * through a config of the CLI's own.
 */
final class InferredGenericReturns
{
    private const array CONFIGS = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

    private const int TIMEOUT = 900;

    /**
     * @param  Closure(): array<string, string|false>  $environment
     */
    public function __construct(
        private readonly string $root,
        private readonly Closure $environment,
    ) {}

    public function label(): string
    {
        return 'generic return types written from what the method returns';
    }

    /**
     * Writes a `@return` on every such method, into the bench.
     */
    public function apply(Workbench $bench): int
    {
        return collect($this->inferred())
            ->filter(fn (array $found): bool => $this->write($bench, $found['path'], $found['line'], $found['method'], $found['type']))
            ->count();
    }

    /**
     * @return list<array{path: string, line: int, method: string, type: string}>
     */
    public function inferred(): array
    {
        $project = collect(self::CONFIGS)->first(fn (string $file): bool => is_file($this->root.'/'.$file));

        if ($project === null || ! is_file($this->root.'/vendor/bin/phpstan')) {
            return [];
        }

        $config = sys_get_temp_dir().'/studio-returns-'.bin2hex(random_bytes(4)).'.neon';
        file_put_contents($config, "includes:\n    - {$this->root}/{$project}\n\nrules:\n    - ".GenericReturnTypesRule::class."\n");
        $process = new Process(['vendor/bin/phpstan', 'analyse', '--configuration='.$config, '--error-format=json', '--no-progress', '--memory-limit=4G'], $this->root, ($this->environment)(), timeout: self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable) {
            return [];
        } finally {
            @unlink($config);
        }

        $start = strpos($process->getOutput(), '{');
        $json = $start === false ? null : json_decode(substr($process->getOutput(), $start), true);

        return array_values(collect(is_array($json) ? (array) ($json['files'] ?? []) : [])
            ->flatMap(fn (mixed $file, string $path): array => collect((array) ($file['messages'] ?? []))
                ->filter(fn (mixed $message): bool => is_array($message) && ($message['identifier'] ?? null) === GenericReturnTypesRule::IDENTIFIER)
                ->map(fn (array $message): array => ['path' => $this->relative($path), 'line' => (int) ($message['line'] ?? 0), ...(array) json_decode((string) ($message['message'] ?? ''), true)])
                ->filter(fn (array $found): bool => is_string($found['method'] ?? null) && is_string($found['type'] ?? null) && $this->saysSomething($found['type']))
                ->map(fn (array $found): array => ['path' => (string) $found['path'], 'line' => (int) $found['line'], 'method' => (string) $found['method'], 'type' => (string) $found['type']])
                ->all())
            ->unique(fn (array $found): string => $found['path'].':'.$found['line'])
            ->all());
    }

    private function write(Workbench $bench, string $path, int $line, string $method, string $type): bool
    {
        $file = $bench->open($path);
        $node = $file === null ? null : collect($file->all(ClassMethod::class))->first(fn (ClassMethod $node): bool => $node->name->toString() === $method && $node->getStartLine() <= $line && $node->getEndLine() >= $line);

        if ($file === null || $node === null) {
            return false;
        }

        $written = $file->localise(ProvenTypes::writable(ProvenTypes::generalised($type)));
        $doc = $node->getDocComment();
        $indent = $this->indentOf($file->code, $node->getStartFilePos());

        if ($doc === null) {
            return $file->insert($node->getStartFilePos(), "/**\n{$indent} * @return {$written}\n{$indent} */\n{$indent}");
        }

        $text = $doc->getText();
        $closing = strrpos($text, '*/');

        if ($closing === false || ! str_contains($text, "\n")) {
            return false;
        }

        $lineStart = (int) strrpos(substr($text, 0, $closing), "\n") + 1;
        $before = trim((string) substr($text, (int) strrpos(substr($text, 0, max(0, $lineStart - 1)), "\n"), $lineStart - (int) strrpos(substr($text, 0, max(0, $lineStart - 1)), "\n")));
        $gap = $before === '*' || $before === '/**' || str_starts_with(ltrim($before, '* '), '@') ? '' : "{$indent} *\n";

        return $file->insert($doc->getStartFilePos() + $lineStart, "{$gap}{$indent} * @return {$written}\n");
    }

    /**
     * Whether the proven type tells callers more than the native one did: not
     * when what it holds is still only `Model` or `mixed`.
     */
    private function saysSomething(string $type): bool
    {
        return preg_match('/,\s*(?:mixed|Illuminate\\\\Database\\\\Eloquent\\\\Model)>$|<(?:mixed|Illuminate\\\\Database\\\\Eloquent\\\\Model)>$/', $type) !== 1;
    }

    private function indentOf(string $code, int $at): string
    {
        $lineStart = (int) strrpos(substr($code, 0, $at), "\n") + 1;

        return (string) preg_replace('/\S.*$/s', '', substr($code, $lineStart, $at - $lineStart));
    }

    private function relative(string $path): string
    {
        $root = rtrim($this->root, '/').'/';
        $path = (string) preg_replace('/ \(in context of .*\)$/', '', $path);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
