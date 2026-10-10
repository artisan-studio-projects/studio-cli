<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use Symfony\Component\Process\Process;

/**
 * Secrets committed to the repository: keys and tokens in tracked files, and
 * a tracked .env. Only the kind of secret and where it is ever leaves this
 * class, never the secret. When gitleaks is installed, its wider rules and
 * the git history are read too, with its own redaction on.
 */
final class Secrets
{
    /**
     * @var array<string, array{name: string, pattern: string}>
     */
    public const array KINDS = [
        'aws-access-key' => ['name' => 'an AWS access key', 'pattern' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/'],
        'github-token' => ['name' => 'a GitHub token', 'pattern' => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/'],
        'stripe-secret-key' => ['name' => 'a live Stripe secret key', 'pattern' => '/\b(?:sk|rk)_live_[A-Za-z0-9]{20,}\b/'],
        'slack-token' => ['name' => 'a Slack token', 'pattern' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/'],
        'google-api-key' => ['name' => 'a Google API key', 'pattern' => '/\bAIza[0-9A-Za-z_\-]{35}\b/'],
        'anthropic-key' => ['name' => 'an Anthropic API key', 'pattern' => '/\bsk-ant-[A-Za-z0-9_\-]{20,}/'],
        'openai-key' => ['name' => 'an OpenAI API key', 'pattern' => '/\bsk-(?:proj-)?[A-Za-z0-9]{32,}\b/'],
        'private-key' => ['name' => 'a private key', 'pattern' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/'],
    ];

    private const int LARGEST_FILE = 1_000_000;

    public function __construct(private readonly string $root) {}

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    public function findings(): array
    {
        $own = $this->own();
        $seen = array_fill_keys(array_column($own, 'where'), true);

        return [...$own, ...array_values(array_filter($this->gitleaks(), fn (array $finding): bool => ! isset($seen[$finding['where']])))];
    }

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function own(): array
    {
        return array_values(collect($this->tracked())
            ->flatMap(function (string $path): array {
                if (preg_match('#(^|/)\.env(\.[\w-]+)?$#', $path) === 1 && preg_match('#\.(example|sample|dist|testing)$#', $path) !== 1) {
                    return [['where' => $path.':1', 'rule' => 'committed-env', 'message' => 'An environment file is committed to the repository. It usually holds every secret the app has.']];
                }

                $file = $this->root.'/'.$path;
                $code = is_file($file) && filesize($file) <= self::LARGEST_FILE ? (string) file_get_contents($file) : '';

                if ($code === '' || str_contains(substr($code, 0, 8000), "\0")) {
                    return [];
                }

                return collect(self::KINDS)
                    ->flatMap(fn (array $kind, string $rule): array => preg_match_all($kind['pattern'], $code, $matches, PREG_OFFSET_CAPTURE) > 0
                        ? array_map(fn (array $match): array => [
                            'where' => $path.':'.(substr_count(substr($code, 0, $match[1]), "\n") + 1),
                            'rule' => $rule,
                            'message' => 'Looks like '.$kind['name'].' committed to the repository. Rotate it, then move it to your .env.',
                        ], array_values(array_filter($matches[0], fn (array $match): bool => ! $this->isPlaceholder($match[0]))))
                        : [])
                    ->all();
            })
            ->all());
    }

    /**
     * A documented example or an obvious stand-in, like AWS's own
     * AKIAIOSFODNN7EXAMPLE, which docs and tests use on purpose.
     */
    private function isPlaceholder(string $secret): bool
    {
        return preg_match('/EXAMPLE|example|XXXXXXXX|xxxxxxxx|0000000000|1234567890/', $secret) === 1;
    }

    /**
     * @return list<string>
     */
    private function tracked(): array
    {
        $files = new Process(['git', 'ls-files'], $this->root);
        $files->run();

        return $files->isSuccessful() ? array_values(array_filter(explode("\n", trim($files->getOutput())))) : [];
    }

    /**
     * gitleaks' findings across the history, redacted by gitleaks itself.
     *
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function gitleaks(): array
    {
        $which = new Process(['which', 'gitleaks']);
        $which->run();

        if (! $which->isSuccessful()) {
            return [];
        }

        $report = sys_get_temp_dir().'/studio-gitleaks-'.bin2hex(random_bytes(4)).'.json';
        $scan = new Process([trim($which->getOutput()), 'git', '--redact', '--no-banner', '--exit-code', '0', '--report-format', 'json', '--report-path', $report], $this->root, timeout: 300);
        $scan->run();
        $found = json_decode((string) @file_get_contents($report), true);
        @unlink($report);

        return array_values(collect(is_array($found) ? $found : [])
            ->filter(fn (mixed $leak): bool => is_array($leak) && is_string($leak['File'] ?? null))
            ->map(fn (array $leak): array => [
                'where' => $leak['File'].':'.(int) ($leak['StartLine'] ?? 1),
                'rule' => 'gitleaks:'.(string) ($leak['RuleID'] ?? 'secret'),
                'message' => 'Looks like '.lcfirst((string) ($leak['Description'] ?? 'a secret')).' in your git history. Rotate it, then move it to your .env.',
            ])
            ->unique('where')
            ->all());
    }
}
