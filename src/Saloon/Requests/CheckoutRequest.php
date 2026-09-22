<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** A way onto the build's branch that does not ask for anybody's git credential. */
class CheckoutRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $project,
        private readonly string $workflow,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/workflows/'.$this->workflow.'/checkout';
    }
}
