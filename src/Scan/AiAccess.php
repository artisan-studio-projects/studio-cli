<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

/**
 * Whether the AI coding agents a project uses can read its .env: every
 * secret there can end up in a prompt. Read from the agents' own settings,
 * never from the .env itself.
 */
final class AiAccess
{
    public function __construct(private readonly string $root) {}

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    public function findings(): array
    {
        return array_values(array_filter([$this->claudeCode(), $this->cursor()]));
    }

    /**
     * @return array{where: string, rule: string, message: string}|null
     */
    private function claudeCode(): ?array
    {
        if (! is_dir($this->root.'/.claude') && ! is_file($this->root.'/CLAUDE.md')) {
            return null;
        }

        $denied = collect(['.claude/settings.json', '.claude/settings.local.json'])
            ->flatMap(fn (string $file): array => (array) (json_decode((string) @file_get_contents($this->root.'/'.$file), true)['permissions']['deny'] ?? []))
            ->contains(fn (mixed $rule): bool => is_string($rule) && preg_match('/^Read\(.*\.env/', $rule) === 1);

        return $denied ? null : [
            'where' => is_file($this->root.'/.claude/settings.json') ? '.claude/settings.json' : 'CLAUDE.md',
            'rule' => 'ai-reads-env:claude-code',
            'message' => 'Claude Code can read your .env, so its secrets can end up in a prompt. Deny it in .claude/settings.json: "permissions": {"deny": ["Read(./.env)", "Read(./.env.*)"]}.',
        ];
    }

    /**
     * @return array{where: string, rule: string, message: string}|null
     */
    private function cursor(): ?array
    {
        if (! is_dir($this->root.'/.cursor') && ! is_file($this->root.'/.cursorrules')) {
            return null;
        }

        $ignored = preg_match('/^\s*\/?\.env/m', (string) @file_get_contents($this->root.'/.cursorignore')) === 1;

        return $ignored ? null : [
            'where' => '.cursorignore',
            'rule' => 'ai-reads-env:cursor',
            'message' => 'Cursor can read your .env, so its secrets can end up in a prompt. Add .env and .env.* to .cursorignore.',
        ];
    }
}
