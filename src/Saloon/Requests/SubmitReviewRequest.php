<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * What the developer decided at a checkpoint, on its way back to the studio.
 *
 * The only thing this package sends rather than reads. Everything else here
 * watches; this releases a build, so it is deliberately a POST and deliberately
 * the one place a review can come from.
 */
class SubmitReviewRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /** @param  array<string, mixed>  $review */
    public function __construct(
        private readonly string $project,
        private readonly string $workflow,
        private readonly array $review,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/workflows/'.$this->workflow.'/review';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return $this->review;
    }
}
