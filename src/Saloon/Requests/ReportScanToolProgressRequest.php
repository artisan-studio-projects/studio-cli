<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class ReportScanToolProgressRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $project,
        private readonly string $tool,
        private readonly string $state,
        private readonly ?string $reason = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/scan/tools/progress';
    }

    /**
     * @return array{tool: string, state: string, reason?: string}
     */
    protected function defaultBody(): array
    {
        return array_filter(['tool' => $this->tool, 'state' => $this->state, 'reason' => $this->reason], fn (?string $value): bool => $value !== null);
    }
}
