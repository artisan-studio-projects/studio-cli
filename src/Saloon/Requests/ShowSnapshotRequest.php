<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ShowSnapshotRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $project) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/snapshot';
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
