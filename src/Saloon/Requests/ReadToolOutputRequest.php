<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * What a checking tool the studio describes printed, for the studio to read
 * into findings: only the keys or lines it names, and the small files it reads.
 */
class ReadToolOutputRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $read
     */
    public function __construct(
        private readonly string $project,
        private readonly string $tool,
        private readonly array $read,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/scan/tools/'.$this->tool.'/read';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->read;
    }
}
