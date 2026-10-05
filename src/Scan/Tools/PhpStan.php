<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

class PhpStan extends Tool
{
    public function key(): string
    {
        return 'phpstan';
    }

    public function name(): string
    {
        return 'PHPStan';
    }

    public const array CONFIGS = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

    public function command(string $root): ?array
    {
        $bin = $this->bin($root, 'phpstan');
        $configured = collect(self::CONFIGS)->contains(fn (string $config): bool => is_file($root.'/'.$config));

        return $bin === null || ! $configured ? null : [$bin, 'analyse', '--error-format=json', '--no-progress', '--no-interaction', '--memory-limit=2G'];
    }

    public function missing(): string
    {
        return 'Not set up in this project: it needs PHPStan or Larastan and a phpstan.neon.';
    }

    public function install(): array
    {
        return [
            'packages' => ['larastan/larastan'],
            'files' => ['phpstan.neon' => <<<'NEON'
                includes:
                    - vendor/larastan/larastan/extension.neon

                parameters:
                    paths:
                        - app
                    level: 5

                NEON],
            'about' => 'Adds Larastan, PHPStan for Laravel, and a phpstan.neon that checks app/ at level 5. Raise the level whenever you like.',
        ];
    }

    public function findings(string $output, string $root): ?array
    {
        $json = $this->json($output);

        if ($json === null || ! isset($json['totals'])) {
            return null;
        }

        return collect((array) ($json['files'] ?? []))
            ->flatMap(fn (mixed $file, string $path): array => collect((array) ($file['messages'] ?? []))
                ->map(fn (mixed $message): array => $this->finding(
                    $this->relative((string) preg_replace('/ \(in context of .*\)$/', '', $path), $root),
                    isset($message['line']) ? (int) $message['line'] : null,
                    (string) ($message['identifier'] ?? 'phpstan'),
                    (string) ($message['message'] ?? ''),
                ))
                ->all())
            ->take(self::MOST_FINDINGS)
            ->values()
            ->all();
    }
}
