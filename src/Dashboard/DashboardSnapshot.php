<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Dashboard;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

readonly class DashboardSnapshot
{
    public const string WAITING_FOR_YOU = 'Waiting for you';

    public const string IN_REVIEW = 'In review';

    public const string REVIEWED = 'Reviewed';

    public const string FINISHED = 'Finished';

    public const string CHECKPOINT = 'Checkpoint';

    private const int SAMPLE_FILES = 2172;

    private const int SAMPLE_SCAN_SECONDS = 90;

    private const int SAMPLE_WORKFLOW_ID = 101;

    /**
     * @param  list<array{name: string, open: int}>  $rulesets
     * @param  list<array{id: string, name: string, status: string, updated: string, url: ?string}>  $workflows
     */
    public function __construct(
        public string $project,
        public ?string $repository,
        public int $health,
        public string $healthLabel,
        public int $insightsOpen,
        public int $deliverablesPercent,
        public int $deliverablesDone,
        public int $deliverablesTotal,
        public int $workflowsRunning,
        public int $workflowsDone,
        public int $workflowsTotal,
        public int $credits,
        public string $scanStatus,
        public int $scanFilesDone,
        public int $scanFilesTotal,
        public ?string $scannedAgo,
        public array $rulesets,
        public ?string $insightsUrl,
        public array $workflows,
        public Carbon $takenAt,
    ) {}

    public static function fresh(?Carbon $at = null): self
    {
        return new self(
            project: (string) config('app.name', 'Your project'),
            repository: null,
            health: 0,
            healthLabel: 'Not scanned',
            insightsOpen: 0,
            deliverablesPercent: 0,
            deliverablesDone: 0,
            deliverablesTotal: 0,
            workflowsRunning: 0,
            workflowsDone: 0,
            workflowsTotal: 0,
            credits: 0,
            scanStatus: 'Not scanned yet',
            scanFilesDone: 0,
            scanFilesTotal: 0,
            scannedAgo: null,
            rulesets: [],
            insightsUrl: null,
            workflows: [],
            takenAt: $at ?? Carbon::now(),
        );
    }

    public static function sample(?Carbon $at = null, ?string $studio = null): self
    {
        $at ??= Carbon::now();
        $studio = rtrim($studio ?? (string) config('studio-cli.url'), '/');
        $progress = ($at->getTimestamp() % self::SAMPLE_SCAN_SECONDS) / self::SAMPLE_SCAN_SECONDS;
        $filesDone = (int) round($progress * self::SAMPLE_FILES);
        $found = (int) round($progress * 41);

        return new self(
            project: 'Artisan Studio',
            repository: 'artisan-studio-projects/artisan-studio',
            health: 68,
            healthLabel: 'Fair',
            insightsOpen: 36 + $found,
            deliverablesPercent: 40,
            deliverablesDone: 2,
            deliverablesTotal: 5,
            workflowsRunning: 1,
            workflowsDone: 3,
            workflowsTotal: 5,
            credits: 1240,
            scanStatus: 'Scanning',
            scanFilesDone: $filesDone,
            scanFilesTotal: self::SAMPLE_FILES,
            scannedAgo: null,
            rulesets: [
                ['name' => 'Security Vulnerabilities', 'open' => 4 + intdiv($found, 8)],
                ['name' => 'Performance Bottlenecks', 'open' => 9 + intdiv($found, 4)],
                ['name' => 'Code Quality', 'open' => 14 + intdiv($found, 3)],
                ['name' => 'Model Coverage', 'open' => 2],
                ['name' => 'Test Coverage Gaps', 'open' => 7],
                ['name' => 'Laravel Best Practices', 'open' => 0],
                ['name' => 'Livewire Components', 'open' => 5],
            ],
            insightsUrl: "{$studio}/insights",
            workflows: array_values(collect([
                ['name' => 'Insights dashboard with health trends', 'status' => 'Active', 'updated' => '2m'],
                ['name' => 'Linear ticket estimation', 'status' => 'Paused', 'updated' => '3h'],
                ['name' => 'Avatar studio voice picker', 'status' => 'Completed', 'updated' => '1d'],
                ['name' => 'Billing and credit packs', 'status' => 'Completed', 'updated' => '4d'],
                ['name' => 'GitHub App connection', 'status' => 'Completed', 'updated' => '1w'],
            ])->map(fn (array $workflow, int $index): array => [
                'id' => (string) (self::SAMPLE_WORKFLOW_ID + $index),
                ...$workflow,
                'url' => "{$studio}/workflow/".(self::SAMPLE_WORKFLOW_ID + $index),
            ])->all()),
            takenAt: $at,
        );
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromApi(array $data, ?Carbon $at = null): self
    {
        $at ??= Carbon::now();
        $scannedAt = data_get($data, 'scan.scanned_at');

        return new self(
            project: (string) data_get($data, 'project.name', config('app.name', 'Your project')),
            repository: is_string($repository = data_get($data, 'project.repository')) && $repository !== '' ? $repository : null,
            health: (int) data_get($data, 'health.percent', 0),
            healthLabel: (string) data_get($data, 'health.label', 'Not scanned'),
            insightsOpen: (int) data_get($data, 'health.open', 0),
            deliverablesPercent: (int) data_get($data, 'deliverables.percent', 0),
            deliverablesDone: (int) data_get($data, 'deliverables.done', 0),
            deliverablesTotal: (int) data_get($data, 'deliverables.total', 0),
            workflowsRunning: (int) data_get($data, 'workflows.running', 0),
            workflowsDone: (int) data_get($data, 'workflows.done', 0),
            workflowsTotal: (int) data_get($data, 'workflows.total', 0),
            credits: (int) data_get($data, 'credits', 0),
            scanStatus: (string) data_get($data, 'scan.status', 'Not scanned yet'),
            scanFilesDone: (int) data_get($data, 'scan.files_done', 0),
            scanFilesTotal: (int) data_get($data, 'scan.files_total', 0),
            scannedAgo: is_string($scannedAt) ? self::ago($scannedAt, $at).' ago' : null,
            rulesets: array_values(collect(array_filter((array) data_get($data, 'insights.rulesets', []), is_array(...)))
                ->map(fn (array $ruleset): array => ['name' => (string) ($ruleset['name'] ?? ''), 'open' => (int) ($ruleset['open'] ?? 0)])
                ->all()),
            insightsUrl: is_string($url = data_get($data, 'insights.url')) ? $url : null,
            workflows: array_values(collect(array_filter((array) data_get($data, 'workflows.list', []), is_array(...)))
                ->map(fn (array $workflow): array => [
                    'id' => (string) ($workflow['id'] ?? ''),
                    'name' => (string) ($workflow['name'] ?? ''),
                    'status' => (string) ($workflow['status'] ?? ''),
                    'updated' => is_string($workflow['updated_at'] ?? null) ? self::ago($workflow['updated_at'], $at) : '',
                    'url' => is_string($workflow['url'] ?? null) ? $workflow['url'] : null,
                ])
                ->all()),
            takenAt: $at,
        );
    }

    /**
     * @return array{id: string, name: string, status: string, branch: ?string, url: ?string, tasks: list<array{id: string, ordinal: int, title: string, artisan: string, status: string, summary: string, files: list<array{path: string, kind: string}>}>}|null
     */
    public function sampleWorkflow(string $id): ?array
    {
        $workflow = collect($this->workflows)->firstWhere('id', $id);

        if ($workflow === null) {
            return null;
        }

        $statuses = match ($workflow['status']) {
            'Completed' => ['Done', 'Done', 'Done', 'Done'],
            'Active' => ['Done', self::WAITING_FOR_YOU, self::WAITING_FOR_YOU, 'Open'],
            default => ['Done', 'Open', 'Open', 'Open'],
        };

        return [
            'id' => $workflow['id'],
            'name' => $workflow['name'],
            'status' => $workflow['status'],
            'branch' => 'workflow/'.str($workflow['name'])->slug(),
            'url' => $workflow['url'],
            'tasks' => array_map(fn (array $task, int $index): array => [
                'id' => $workflow['id'].'-'.($index + 1),
                'ordinal' => $index + 1,
                'title' => $task[0],
                'artisan' => $task[1],
                'status' => $statuses[$index],
                'summary' => $task[2],
                'files' => array_map(fn (string $path): array => ['path' => $path, 'kind' => 'write'], $task[3]),
            ], [
                ['Model, migration and factory', 'Mason', 'Where the numbers are kept, and a factory to make them in tests.', ['app/Models/HealthTrend.php', 'database/migrations/2026_09_26_000000_create_health_trends_table.php']],
                ['The page and its components', 'Pixel', 'The health trends card and the page it sits on.', ['resources/views/components/health-card.blade.php', 'resources/views/insights/trends.blade.php']],
                ['Wire the page to real data', 'Spark', 'The card reads the real trend instead of placeholders.', ['app/Livewire/HealthTrends.php']],
                ['Tests for all of it', 'Prover', 'Feature tests for the page and the numbers behind it.', ['tests/Feature/HealthTrendsTest.php']],
            ], [0, 1, 2, 3]),
        ];
    }

    /**
     * @param  array<mixed>  $data
     * @return array{id: string, name: string, status: string, branch: ?string, url: ?string, tasks: list<array{id: string, ordinal: int, title: string, artisan: string, status: string, summary: string, files: list<array{path: string, kind: string}>}>}
     */
    public static function workflowFromApi(array $data): array
    {
        return [
            'id' => (string) data_get($data, 'workflow.id', ''),
            'name' => (string) data_get($data, 'workflow.name', ''),
            'status' => (string) data_get($data, 'workflow.status', ''),
            'branch' => is_string($branch = data_get($data, 'workflow.branch')) && $branch !== '' ? $branch : null,
            'url' => is_string($url = data_get($data, 'workflow.url')) ? $url : null,
            'tasks' => array_values(collect(array_values(array_filter((array) data_get($data, 'tasks', []), is_array(...))))
                ->map(fn (array $task, int $position): array => [
                    'id' => (string) ($task['id'] ?? ''),
                    'ordinal' => $position + 1,
                    'title' => (string) ($task['title'] ?? ''),
                    'artisan' => (string) ($task['artisan'] ?? '—'),
                    'status' => match (true) {
                        ($task['review'] ?? null) === 'reviewing' => self::IN_REVIEW,
                        ($task['review'] ?? null) === 'reviewed' => self::REVIEWED,
                        ($task['waiting'] ?? false) === true => self::WAITING_FOR_YOU,
                        ($task['checkpoint'] ?? false) === true => self::CHECKPOINT,
                        ($task['finished'] ?? false) === true => self::FINISHED,
                        default => (string) ($task['status'] ?? ''),
                    },
                    'summary' => (string) ($task['summary'] ?? ''),
                    'files' => array_values(array_map(
                        fn (array $file): array => ['path' => (string) ($file['path'] ?? ''), 'kind' => (string) ($file['kind'] ?? '')],
                        array_filter((array) ($task['files'] ?? []), fn (mixed $file): bool => is_array($file) && ($file['kind'] ?? '') !== 'read'),
                    )),
                ])
                ->all()),
        ];
    }

    private static function ago(string $moment, Carbon $at): string
    {
        return Carbon::parse($moment)->diffForHumans($at, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'short' => true, 'parts' => 1]);
    }

    public function fingerprint(): string
    {
        return hash('xxh128', json_encode([...get_object_vars($this), 'takenAt' => null], JSON_THROW_ON_ERROR));
    }

    public function healthColour(): string
    {
        return match ($this->healthLabel) {
            'Good' => 'green',
            'Fair' => 'amber',
            'Not scanned' => 'dim',
            default => 'rose',
        };
    }

    public function scanFraction(): float
    {
        return $this->scanFilesTotal > 0 ? $this->scanFilesDone / $this->scanFilesTotal : 0.0;
    }

    public function scanLabel(): string
    {
        return $this->scanStatus
            .($this->scanFilesTotal > 0 ? ' · '.number_format($this->scanFilesDone).' of '.number_format($this->scanFilesTotal).' files' : '')
            .($this->scannedAgo === null ? '' : " · {$this->scannedAgo}");
    }

    public function mostOpen(): int
    {
        return max(1, (int) collect($this->rulesets)->max('open'));
    }

    public function totalOpen(): int
    {
        return (int) collect($this->rulesets)->sum('open');
    }
}
