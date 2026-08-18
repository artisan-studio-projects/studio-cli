<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** This terminal stopped watching, so the studio stops showing it as running. */
class SayGoodbyeRequest extends Request
{
    protected Method $method = Method::POST;

    public function __construct(private readonly string $project) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/goodbye';
    }
}
