<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * The npm audit's advisories and the dependencies package.json lists, for the
 * studio to say which packages to patch and how far.
 */
class PlanNodePatchesRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  list<array{module_name: string, patched_versions: string, findings: list<array{version: string}>}>  $advisories
     * @param  array{dependencies?: array<string, string>, devDependencies?: array<string, string>}  $manifest
     */
    public function __construct(
        private readonly string $project,
        private readonly array $advisories,
        private readonly array $manifest,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/scan/fixes/patches';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['advisories' => $this->advisories, 'manifest' => (object) $this->manifest];
    }
}
