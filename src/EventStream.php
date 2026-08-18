<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class EventStream
{
    private string $buffer = '';

    private ?string $lastEventId = null;

    private ?InvokedProcess $listening = null;

    private ?string $listeningHeaders = null;

    private string $failure = '';

    public function __construct(
        private readonly string $url,
        private readonly string $token,
    ) {}

    /** @param  Closure(array<string, mixed>): void  $onEvent */
    public function read(Closure $onEvent): void
    {
        $this->buffer = '';

        $headers = $this->headerFile();

        try {
            $process = Process::forever()->start(
                $this->command($headers),
                function (string $type, string $output) use ($onEvent): void {
                    if ($type === 'out') {
                        $this->consume($output, $onEvent);
                    }
                },
            );

            $result = $process->wait();
        } finally {
            @unlink($headers);
        }

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'the connection failed');
        }
    }

    /** @param  Closure(array<string, mixed>): void  $onEvent */
    public function open(Closure $onEvent): void
    {
        $this->close();
        $this->buffer = '';
        $this->failure = '';
        $this->listeningHeaders = $this->headerFile();

        $this->listening = Process::forever()->start(
            $this->command($this->listeningHeaders),
            function (string $type, string $output) use ($onEvent): void {
                if ($type === 'out') {
                    $this->consume($output, $onEvent);
                }
            },
        );
    }

    public function pump(): bool
    {
        if ($this->listening === null) {
            return false;
        }

        if ($this->listening->running()) {
            return true;
        }

        $result = $this->listening->wait();
        $this->failure = $result->successful() ? 'the studio closed the connection' : (trim($result->errorOutput()) ?: 'the connection failed');
        $this->forgetListening();

        return false;
    }

    public function failure(): string
    {
        return $this->failure;
    }

    public function close(): void
    {
        try {
            if ($this->listening?->running()) {
                $this->listening->signal(15);
            }
        } catch (Throwable) {
        }

        $this->forgetListening();
    }

    private function forgetListening(): void
    {
        if ($this->listeningHeaders !== null) {
            @unlink($this->listeningHeaders);
        }

        $this->listening = null;
        $this->listeningHeaders = null;
    }

    private function headerFile(): string
    {
        $path = sys_get_temp_dir().'/studio-cli-'.bin2hex(random_bytes(16)).'.conf';

        $lines = [
            'header = "Accept: text/event-stream"',
            'header = "Authorization: Bearer '.$this->escape($this->token).'"',
        ];

        if ($this->lastEventId !== null) {
            $lines[] = 'header = "Last-Event-ID: '.$this->escape($this->lastEventId).'"';
        }

        $handle = fopen($path, 'x');

        throw_unless($handle, RuntimeException::class, 'Could not write the request headers to a temporary file.');

        chmod($path, 0600);
        fwrite($handle, implode("\n", $lines)."\n");
        fclose($handle);

        return $path;
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);
    }

    public function lastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /** @param  Closure(array<string, mixed>): void  $onEvent */
    private function consume(string $chunk, Closure $onEvent): void
    {
        $this->buffer .= $chunk;

        while (preg_match('/\r?\n\r?\n/', $this->buffer, $match, PREG_OFFSET_CAPTURE)) {
            $separator = $match[0][0];
            $at = (int) $match[0][1];

            $frame = substr($this->buffer, 0, $at);
            $this->buffer = substr($this->buffer, $at + strlen($separator));

            $onEvent($this->decode($frame) ?? ['kind' => 'tick']);
        }
    }

    /** @return array<string, mixed>|null */
    private function decode(string $frame): ?array
    {
        $data = [];

        foreach (preg_split('/\r\n|\n/', $frame) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $value = ltrim($value, ' ');

            match ($field) {
                'data' => $data[] = $value,
                'id' => $this->lastEventId = $value,
                default => null,
            };
        }

        if ($data === []) {
            return null;
        }

        $decoded = json_decode(implode("\n", $data), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    private function command(string $headers): array
    {
        return [
            'curl',
            '--silent',
            '--show-error',
            '--no-buffer',
            '--fail-with-body',
            '--config', $headers,
            $this->url,
        ];
    }
}
