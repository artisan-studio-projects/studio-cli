<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * One batch of flagged files, sent to the studio for its fixers. The
 * changed files come back in the answer; the studio keeps nothing.
 */
class FixScanFilesRequest extends Request implements HasBody
{
    use HasJsonBody;

    private const int TIMEOUT = 180;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $batch
     */
    public function __construct(
        private readonly string $project,
        private readonly array $batch,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/scan/fixes/files';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->batch;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return ['timeout' => self::TIMEOUT];
    }
}
