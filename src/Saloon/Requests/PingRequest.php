<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class PingRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $repository,
        private readonly bool $leaving = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/cli/ping';
    }

    /**
     * @return array{repository: string, leaving: bool}
     */
    protected function defaultBody(): array
    {
        return ['repository' => $this->repository, 'leaving' => $this->leaving];
    }

    /**
     * The screen asks while it redraws, so a slow answer is given up on soon and
     * the last one kept, rather than freezing the counters on it.
     */
    public const int TIMEOUT = 4;

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return ['timeout' => self::TIMEOUT];
    }
}
