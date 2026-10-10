<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\TypeCoverage;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Asks the project's own PHPStan what each untyped closure parameter is, by
 * running it once over the flagged files with {@see ClosureParamTypesRule}
 * added through a config of the CLI's own.
 */
final class InferredClosureTypes
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

    /**
     * @param  list<string>  $paths
     * @return array<string, array<int, array<string, string>>> path => line => parameter => type
     */
    public function in(array $paths): array
    {
        $paths = array_values(array_filter(array_unique($paths), fn (string $path): bool => is_file($this->root.'/'.$path)));

        if ($paths === [] || ! is_file($this->root.'/vendor/bin/phpstan')) {
            return [];
        }

        $config = sys_get_temp_dir().'/studio-types-'.bin2hex(random_bytes(4)).'.neon';
        file_put_contents($config, $this->config());
        $process = new Process(['vendor/bin/phpstan', 'analyse', ...$paths, '--configuration='.$config, '--error-format=json', '--no-progress', '--memory-limit=4G'], $this->root, ($this->environment)(), timeout: self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable) {
            return [];
        } finally {
            @unlink($config);
        }

        $start = strpos($process->getOutput(), '{');
        $json = $start === false ? null : json_decode(substr($process->getOutput(), $start), true);

        return collect(is_array($json) ? (array) ($json['files'] ?? []) : [])
            ->flatMap(fn (mixed $file, string $path): array => collect((array) ($file['messages'] ?? []))
                ->filter(fn (mixed $message): bool => is_array($message) && ($message['identifier'] ?? null) === ClosureParamTypesRule::IDENTIFIER)
                ->map(fn (array $message): array => ['path' => $this->relative($path), 'line' => (int) ($message['line'] ?? 0), ...(array) json_decode((string) ($message['message'] ?? ''), true)])
                ->all())
            ->filter(fn (array $inferred): bool => is_string($inferred['param'] ?? null) && is_string($inferred['type'] ?? null))
            ->groupBy('path')
            ->map(fn ($inPath) => $inPath->groupBy('line')->map(fn ($onLine): array => $onLine->pluck('type', 'param')->all())->all())
            ->all();
    }

    private function config(): string
    {
        $project = collect(self::CONFIGS)->first(fn (string $file): bool => is_file($this->root.'/'.$file));
        $includes = $project !== null
            ? '    - '.$this->root.'/'.$project
            : (is_file($this->root.'/vendor/larastan/larastan/extension.neon') ? '    - '.$this->root.'/vendor/larastan/larastan/extension.neon' : '');

        return ($includes === '' ? '' : "includes:\n{$includes}\n\n")
            .'rules:'."\n".'    - '.ClosureParamTypesRule::class."\n".'    - '.ArrowFunctionParamTypesRule::class."\n"
            .($project === null ? "\nparameters:\n    level: 0\n" : '');
    }

    private function relative(string $path): string
    {
        $root = rtrim($this->root, '/').'/';
        $path = (string) preg_replace('/ \(in context of .*\)$/', '', $path);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
