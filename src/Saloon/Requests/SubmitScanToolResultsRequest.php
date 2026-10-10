<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class SubmitScanToolResultsRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * Thousands of findings take the studio longer than a quick call: its own
     * work on them is not cut short by the usual 30 seconds.
     */
    public const int TIMEOUT = 180;

    /**
     * @param  array<string, mixed>  $results
     */
    public function __construct(
        private readonly string $project,
        private readonly array $results,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/scan/tools';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return ['timeout' => self::TIMEOUT];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->results;
    }
}
