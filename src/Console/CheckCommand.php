<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Scan\AiAccess;
use ArtisanStudio\StudioCli\Scan\LivewireSafety;
use ArtisanStudio\StudioCli\Scan\Secrets;
use Illuminate\Console\Command;
use Throwable;

/**
 * The checks that have no machine-readable output of their own, run inside
 * the project so they read its real config and files, printed as the same
 * JSON every scan tool's findings use: file, line, rule and message.
 *
 * Nothing sensitive is ever printed: a config key and Laravel's message, not
 * its value; the kind of secret and where, never the secret.
 */
class CheckCommand extends Command
{
    public const string SIGNATURE = 'studio:check';

    private const string CONFIG_VALIDATOR = 'AshAllenDesign\ConfigValidator\Services\ConfigValidator';

    private const string PECK = 'Peck\Kernel';

    protected $signature = self::SIGNATURE.'
        {check : config, peck, secrets or vet}';

    protected $description = 'Run one of Artisan Studio\'s own checks read-only and print what it found as JSON';

    public function handle(): int
    {
        try {
            $findings = match ($this->argument('check')) {
                'config' => $this->config(),
                'peck' => $this->peck(),
                'secrets' => [...(new Secrets(base_path()))->findings(), ...(new AiAccess(base_path()))->findings(), ...(new LivewireSafety(base_path()))->findings()],
                'vet' => $this->vet(),
                default => null,
            };
        } catch (Throwable $exception) {
            $this->line((string) json_encode(['error' => class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 200)]));

            return self::FAILURE;
        }

        if ($findings === null) {
            $this->line((string) json_encode(['error' => 'Unknown check.']));

            return self::FAILURE;
        }

        $this->line((string) json_encode(['findings' => $findings], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * The project's own config-validation rules, or the package's defaults
     * for this Laravel version when the project has none.
     *
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function config(): array
    {
        $validator = app(self::CONFIG_VALIDATOR)->throwExceptionOnFailure(false);
        $validator->run([], is_dir(base_path('config-validation')) ? 'config-validation' : $this->defaultRules());

        return array_values(collect((array) $validator->errors())
            ->map(fn (mixed $messages, string $key): array => [
                'where' => 'config/'.strtok($key, '.').'.php',
                'rule' => $key,
                'message' => (string) (((array) $messages)[0] ?? 'The '.$key.' value is not valid.'),
            ])
            ->all());
    }

    private function defaultRules(): string
    {
        $stubs = 'vendor/ash-jc-allen/laravel-config-validator/stubs/config-validation';
        $major = (int) app()->version();
        $versions = collect(glob(base_path($stubs).'/laravel-*') ?: [])->map(fn (string $folder): int => (int) substr((string) strrchr($folder, '-'), 1))->sort()->values();

        return $stubs.'/laravel-'.($versions->contains($major) ? $major : $versions->last());
    }

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function peck(): array
    {
        $issues = (self::PECK)::default()->handle(['directory' => base_path(), 'onSuccess' => fn (): null => null, 'onFailure' => fn (): null => null]);

        return array_values(array_map(fn (object $issue): array => [
            'where' => ltrim(str_replace(base_path(), '', (string) $issue->file), '/').($issue->line > 0 ? ':'.$issue->line : ''),
            'rule' => 'misspelling',
            'message' => '"'.$issue->misspelling->word.'" may be misspelt'.($issue->misspelling->suggestions === [] ? '.' : '. Did you mean: '.implode(', ', array_slice($issue->misspelling->suggestions, 0, 3)).'?'),
        ], $issues));
    }

    /**
     * The packages Laravel Vet has no trust record for at the version now
     * locked: never vetted, or changed since they were.
     *
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function vet(): array
    {
        $vet = (array) json_decode((string) @file_get_contents(base_path('vet.json')), true);
        $lock = (array) json_decode((string) @file_get_contents(base_path('composer.lock')), true);
        $trusted = [...(array) ($vet['require'] ?? []), ...(array) ($vet['require-dev'] ?? [])];

        return array_values(collect([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])])
            ->filter(fn (mixed $package): bool => is_array($package) && is_string($package['name'] ?? null))
            ->map(function (array $package) use ($trusted): ?array {
                $version = ltrim((string) ($package['version'] ?? ''), 'v');
                $record = $trusted[$package['name']] ?? null;
                $vetted = is_array($record) ? ltrim((string) ($record['version'] ?? ''), 'v') : null;

                return $vetted === $version ? null : [
                    'where' => 'composer.lock',
                    'rule' => 'vet:'.$package['name'],
                    'message' => $vetted === null
                        ? $package['name'].' '.$version.' has not been vetted yet.'
                        : $package['name'].' changed from '.$vetted.' to '.$version.' since it was vetted.',
                ];
            })
            ->filter()
            ->all());
    }
}
