<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** Whatever the artisans need this machine to answer, one at a time. */
class NextCommandRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $project) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/commands/next';
    }
}
