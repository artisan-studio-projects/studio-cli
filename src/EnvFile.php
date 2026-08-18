<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

final readonly class EnvFile
{
    public function __construct(private string $path) {}

    /**
     * @param  array<string, string>  $values
     */
    public function write(array $values): void
    {
        $this->save(collect($values)->reduce(fn (string $contents, string $value, string $key): string => $this->has($contents, $key)
            ? (string) preg_replace_callback($this->line($key), fn (): string => $key.'='.$this->quote($value), $contents)
            : rtrim($contents, "\n")."\n".$key.'='.$this->quote($value)."\n", $this->contents()));
    }

    /**
     * @param  list<string>  $keys
     */
    public function forget(array $keys): void
    {
        $this->save(collect($keys)->reduce(fn (string $contents, string $key): string => (string) preg_replace('/^'.preg_quote($key, '/').'=.*(\R|$)/m', '', $contents), $this->contents()));
    }

    private function contents(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }

    private function save(string $contents): void
    {
        file_put_contents($this->path, $contents);
    }

    private function has(string $contents, string $key): bool
    {
        return preg_match($this->line($key), $contents) === 1;
    }

    private function line(string $key): string
    {
        return '/^'.preg_quote($key, '/').'=.*$/m';
    }

    private function quote(string $value): string
    {
        return '"'.str_replace('"', '\"', $value).'"';
    }
}
