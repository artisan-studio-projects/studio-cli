<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Saloon\Requests\ListProjectsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ListWorkflowsRequest;
use ArtisanStudio\StudioCli\Saloon\StudioConnector;
use Closure;
use RuntimeException;

class Studio
{
    private ?string $token = null;

    private ?EventStream $stream = null;

    public function isLinked(): bool
    {
        return filled($this->token()) && filled($this->url());
    }

    public function withToken(string $token): self
    {
        $clone = clone $this;
        $clone->token = $token;

        return $clone;
    }

    /** @return list<array{slug: string, name: string, repo: string|null}> */
    public function projects(): array
    {
        $response = $this->connector()->send(new ListProjectsRequest);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('The studio does not recognise that token.');
        }

        if ($response->status() === 404) {
            throw new RuntimeException(
                'Nothing at '.$this->url().'/api/v1/projects — is that the right studio?',
            );
        }

        if ($response->failed()) {
            throw new RuntimeException('The studio answered with '.$response->status().'.');
        }

        return $response->json('projects', []);
    }

    /** @return list<array<string, mixed>> */
    public function activeWorkflows(): array
    {
        return $this->connector()
            ->send(new ListWorkflowsRequest($this->project()))
            ->json('workflows', []);
    }

    /** @param  Closure(array<string, mixed>): void  $onEvent */
    public function stream(Closure $onEvent): void
    {
        $this->stream ??= new EventStream($this->streamUrl(), $this->token());

        $this->stream->read($onEvent);
    }

    private function streamUrl(): string
    {
        return rtrim($this->url(), '/').'/api/v1/projects/'.$this->project().'/stream';
    }

    private function connector(): StudioConnector
    {
        return new StudioConnector($this->url(), $this->token());
    }

    private function url(): string
    {
        return (string) config('studio-cli.url');
    }

    private function token(): string
    {
        return $this->token ?? (string) config('studio-cli.token');
    }

    private function project(): string
    {
        return (string) config('studio-cli.project', '');
    }
}
