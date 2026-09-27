<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class StartTaskReviewRequest extends Request
{
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $project,
        private readonly string $workflow,
        private readonly string $task,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/workflows/'.$this->workflow.'/tasks/'.$this->task.'/review/start';
    }
}
