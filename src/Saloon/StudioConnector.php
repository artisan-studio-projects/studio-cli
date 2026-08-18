<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

class StudioConnector extends Connector
{
    use AcceptsJson;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public function resolveBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->token);
    }

    /** @return array<string, mixed> */
    protected function defaultConfig(): array
    {
        return [
            'connect_timeout' => 5,
            'timeout' => 30,
        ];
    }
}
