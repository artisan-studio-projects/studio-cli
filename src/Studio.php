<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Saloon\Requests\CheckoutRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\CommandResultRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\FinishTaskReviewRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ListPresenceStatesRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ListProjectsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ListWorkflowsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\NextCommandRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\PingRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ReportScanToolProgressRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SayGoodbyeRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowConventionDetectorsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanDetectorsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanFixesRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanToolsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowSnapshotRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowWorkflowRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\StartTaskReviewRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitBlueprintRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitConventionFactsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitReviewRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanFixReportRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanFlagsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanToolResultsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitTestRunRequest;
use ArtisanStudio\StudioCli\Saloon\StudioConnector;
use Closure;
use RuntimeException;
use Saloon\Http\Request;
use Throwable;

class Studio
{
    public const string CONNECTED = 'connected';

    public const string UNLINKED = 'unlinked';

    public const string REFUSED = 'refused';

    public const string FORBIDDEN = 'forbidden';

    public const string MISSING = 'missing';

    public const string UNREACHABLE = 'unreachable';

    public const string UNKNOWN = 'unknown';

    private ?string $token = null;

    private ?EventStream $stream = null;

    private string $connection = self::UNKNOWN;

    private bool $hasConnected = false;

    public function isLinked(): bool
    {
        return filled($this->token()) && filled($this->url());
    }

    public function connection(): string
    {
        return $this->isLinked() ? $this->connection : self::UNLINKED;
    }

    public function hasConnected(): bool
    {
        return $this->hasConnected;
    }

    private function answered(string $connection): void
    {
        $this->connection = $connection;
        $this->hasConnected = $this->hasConnected || $connection === self::CONNECTED;
    }

    public function withToken(string $token): self
    {
        $clone = clone $this;
        $clone->token = $token;

        return $clone;
    }

    /** @return list<array{slug: string, name: string, repo: string|null}> */
    public function projects(): array
    {
        try {
            $response = $this->connector()->send(new ListProjectsRequest);
        } catch (Throwable $unreachable) {
            $this->answered(self::UNREACHABLE);

            throw $unreachable;
        }

        $this->answered(match (true) {
            in_array($response->status(), [401, 403], true) => self::REFUSED,
            $response->successful() => self::CONNECTED,
            default => self::UNREACHABLE,
        });

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('The studio does not recognise that token.');
        }

        if ($response->status() === 404) {
            throw new RuntimeException(
                'Nothing at '.$this->url().'/api/v1/projects — is that the right studio?',
            );
        }

        if ($response->failed()) {
            throw new RuntimeException('The studio answered with '.$response->status().'.');
        }

        return $response->json('projects', []);
    }

    /**
     * @return array<mixed>|null
     */
    public function snapshot(): ?array
    {
        return $this->fetch(new ShowSnapshotRequest($this->project()));
    }

    /**
     * @return array<mixed>|null
     */
    public function workflow(string $workflow): ?array
    {
        return $this->fetch(new ShowWorkflowRequest($this->project(), $workflow));
    }

    public function startTaskReview(string $workflow, string $task): bool
    {
        return $this->fetch(new StartTaskReviewRequest($this->project(), $workflow, $task)) !== null;
    }

    /**
     * @param  array{outcome: string, note?: ?string, commit?: ?string, files?: list<array{path: string, status: string}>}  $review
     * @return array<mixed>|null
     */
    public function finishTaskReview(string $workflow, string $task, array $review): ?array
    {
        return $this->fetch(new FinishTaskReviewRequest($this->project(), $workflow, $task, $review));
    }

    /**
     * @param  array{passed: bool, output: string, results: list<array{file: string, passed: bool, summary: ?string}>, cases: array{passed: int, failed: int}}  $run
     * @return array<mixed>|null
     */
    public function submitTestRun(string $workflow, string $task, array $run): ?array
    {
        return $this->fetch(new SubmitTestRunRequest($this->project(), $workflow, $task, $run));
    }

    /**
     * @param  array{commit: ?string, models: array<int, array<string, mixed>>}  $blueprint
     * @return array<mixed>|null
     */
    public function submitBlueprint(array $blueprint): ?array
    {
        return $this->fetch(new SubmitBlueprintRequest($this->project(), $blueprint));
    }

    /**
     * @return array{checks: array<string, array<string, mixed>>}|null
     */
    public function conventionDetectors(): ?array
    {
        $detectors = $this->quietly(new ShowConventionDetectorsRequest($this->project()));

        return is_array($detectors['checks'] ?? null) ? $detectors : null;
    }

    /**
     * @return array{checks: array<string, array<string, mixed>>}|null
     */
    public function scanDetectors(): ?array
    {
        $detectors = $this->quietly(new ShowScanDetectorsRequest($this->project()));

        return is_array($detectors['checks'] ?? null) ? $detectors : null;
    }

    /**
     * @return list<string>|null
     */
    public function scanTools(): ?array
    {
        $answer = $this->quietly(new ShowScanToolsRequest($this->project()));

        return is_array($answer['tools'] ?? null) ? array_values(array_filter($answer['tools'], is_string(...))) : null;
    }

    /**
     * The rules the developer asked SAMI to fix, by key. Only keys: what fixing
     * one runs is decided on this machine.
     *
     * @return list<string>|null
     */
    public function scanFixes(): ?array
    {
        $answer = $this->quietly(new ShowScanFixesRequest($this->project()));

        return is_array($answer['fixes'] ?? null) ? array_values(array_filter($answer['fixes'], is_string(...))) : null;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<mixed>|null
     */
    public function submitScanFixReport(array $report): ?array
    {
        return $this->quietly(new SubmitScanFixReportRequest($this->project(), $report));
    }

    /**
     * Tells the studio where a tool is, so the app can follow each rule as it
     * installs, runs and finishes. Nothing waits on the answer.
     */
    public function reportToolProgress(string $tool, string $state, ?string $reason = null): void
    {
        $this->quietly(new ReportScanToolProgressRequest($this->project(), $tool, $state, $reason === null ? null : mb_substr($reason, 0, 200)));
    }

    /**
     * @param  array<string, mixed>  $results
     * @return array<mixed>|null
     */
    public function submitScanToolResults(array $results): ?array
    {
        return $this->quietly(new SubmitScanToolResultsRequest($this->project(), $results));
    }

    /**
     * @param  array<string, mixed>  $flags
     * @return array<mixed>|null
     */
    public function submitScanFlags(array $flags): ?array
    {
        return $this->quietly(new SubmitScanFlagsRequest($this->project(), $flags));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<mixed>|null
     */
    public function submitConventionFacts(array $facts): ?array
    {
        return $this->quietly(new SubmitConventionFactsRequest($this->project(), $facts));
    }

    /**
     * @return array<mixed>|null
     */
    private function quietly(Request $request): ?array
    {
        $response = rescue(fn () => $this->connector()->send($request), report: false);

        return $response !== null && $response->successful() ? $response->json() : null;
    }

    public function ping(?string $repository, bool $leaving = false): void
    {
        if ($repository === null || blank($this->url())) {
            return;
        }

        $request = new PingRequest($repository, $leaving);
        $request->config()->merge(['timeout' => 2, 'connect_timeout' => 1]);

        rescue(fn () => $this->connector()->send($request), report: false);
    }

    public function sayGoodbye(): void
    {
        if (! $this->isLinked()) {
            return;
        }

        $request = new SayGoodbyeRequest($this->project());
        $request->config()->merge(['timeout' => 2, 'connect_timeout' => 1]);

        rescue(fn () => $this->connector()->send($request), report: false);
    }

    /**
     * @return array<mixed>|null
     */
    private function fetch(Request $request): ?array
    {
        try {
            $response = $this->connector()->send($request);
        } catch (Throwable) {
            $this->answered(self::UNREACHABLE);

            return null;
        }

        $this->answered(match ($response->status()) {
            401 => self::REFUSED,
            403 => self::FORBIDDEN,
            404 => self::MISSING,
            default => $response->successful() ? self::CONNECTED : self::UNREACHABLE,
        });

        return $response->successful() ? $response->json() : null;
    }

    /** @return list<array<string, mixed>> */
    public function activeWorkflows(): array
    {
        return $this->connector()
            ->send(new ListWorkflowsRequest($this->project()))
            ->json('workflows', []);
    }

    /**
     * Hand a finished review back, and let the build carry on.
     *
     * @param  array<string, mixed>  $review
     */
    public function submitReview(string $workflow, array $review): bool
    {
        return $this->connector()
            ->send(new SubmitReviewRequest($this->project(), $workflow, $review))
            ->successful();
    }

    /**
     * The next thing the artisans need this machine to answer, if any.
     *
     * @return array{id: int, name: string, arguments: array<string, mixed>, workflow: string}|null
     */
    public function nextCommand(): ?array
    {
        $response = $this->connector()->send(new NextCommandRequest($this->project()));

        return $response->successful() ? $response->json('command') : null;
    }

    /**
     * Where to fetch this build's branch from, borrowed from the studio.
     *
     * @return array{branch: string, remote: string}|null
     */
    public function howToReach(string $workflow): ?array
    {
        $response = $this->connector()->send(new CheckoutRequest($this->project(), $workflow));

        return $response->successful() ? $response->json() : null;
    }

    /** @param  array{output: string, exit_code: int, error: ?string}  $result */
    public function answerCommand(int $command, array $result): bool
    {
        return $this->connector()
            ->send(new CommandResultRequest($this->project(), $command, $result))
            ->successful();
    }

    /** @param  Closure(array<string, mixed>): void  $onEvent */
    public function stream(Closure $onEvent): void
    {
        $this->stream ??= new EventStream($this->streamUrl(), $this->token());

        $this->stream->read($onEvent);
    }

    /**
     * @return list<array{state_key: string, desktop: array{url: string, updated: int|null}|null}>
     */
    public function presenceStates(): array
    {
        try {
            $response = $this->connector()->send(new ListPresenceStatesRequest);
        } catch (Throwable) {
            return [];
        }

        return $response->successful()
            ? array_values(array_map($this->presenceState(...), array_filter((array) $response->json('states', []), is_array(...))))
            : [];
    }

    /**
     * @param  array<mixed>  $state
     * @return array{state_key: string, desktop: array{url: string, updated: int|null}|null}
     */
    private function presenceState(array $state): array
    {
        $desktop = $state['desktop'] ?? null;

        return [
            'state_key' => (string) ($state['state_key'] ?? ''),
            'desktop' => is_array($desktop) && is_string($desktop['url'] ?? null)
                ? ['url' => $desktop['url'], 'updated' => is_numeric($desktop['updated'] ?? null) ? (int) $desktop['updated'] : null]
                : null,
        ];
    }

    public function listener(): EventStream
    {
        return new EventStream($this->streamUrl(), $this->token());
    }

    private function streamUrl(): string
    {
        return rtrim($this->url(), '/').'/api/v1/projects/'.$this->project().'/stream';
    }

    private function connector(): StudioConnector
    {
        return new StudioConnector($this->url(), $this->token());
    }

    public function url(): string
    {
        return (string) config('studio-cli.url');
    }

    private function token(): string
    {
        return $this->token ?? (string) config('studio-cli.token');
    }

    public function project(): string
    {
        return (string) config('studio-cli.project', '');
    }
}
