<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class SubmitBlueprintRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array{commit: ?string, models: array<int, array<string, mixed>>}  $blueprint
     */
    public function __construct(
        private readonly string $project,
        private readonly array $blueprint,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/blueprint';
    }

    /**
     * @return array{commit: ?string, models: array<int, array<string, mixed>>}
     */
    protected function defaultBody(): array
    {
        return $this->blueprint;
    }
}
