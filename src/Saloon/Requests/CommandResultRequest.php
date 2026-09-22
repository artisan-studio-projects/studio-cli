<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/** What this machine found, for the artisan waiting on it. */
class CommandResultRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /** @param  array{output: string, exit_code: int, error: ?string}  $result */
    public function __construct(
        private readonly string $project,
        private readonly int $command,
        private readonly array $result,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/commands/'.$this->command.'/result';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return $this->result;
    }
}
