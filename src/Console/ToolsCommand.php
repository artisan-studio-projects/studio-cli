<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ToolsCommand extends Command
{
    public const string SIGNATURE = 'studio:tools';

    protected $signature = self::SIGNATURE.'
        {--json : Print what would be sent, and send nothing}
        {--plain : Print one plain line with the outcome}';

    protected $description = 'Run your project\'s own checking tools read-only and send Artisan Studio what they found, as file, line, rule and message, never code';

    public function handle(Studio $studio, TaskJournal $journal, ToolStatus $status): int
    {
        if (! $studio->isLinked()) {
            return $this->outcome('This project is not linked yet. Run php artisan studio:settings, then try again.', self::SUCCESS);
        }

        $asked = $studio->scanTools();

        if ($asked === null) {
            return $this->outcome('The studio did not say which tools to run. Check php artisan studio:settings.', self::FAILURE);
        }

        $status->asked($asked);

        $toolbox = new Toolbox;
        $checks = array_values(array_diff($asked, [TestsCommand::TESTS]));
        $this->step($journal, 'Asked the studio which tools you switched on: '.($checks === [] ? 'none' : implode(', ', $checks)).'.');

        $status->running($checks);
        array_map(fn (string $key): mixed => $studio->reportToolProgress($key, 'waiting'), $checks);

        $results = $toolbox->run($checks, function (string $key, string $name, array $result) use ($journal, $studio, $status): void {
            $status->ran($key, $result);
            $studio->reportToolProgress($key, $result['ran'] ? 'done' : 'skipped', $result['ran'] ? null : ($result['reason'] ?? null));
            $this->step($journal, $result['ran']
                ? sprintf('%s found %s in %ss. Read-only: nothing was changed.', $name, count($result['findings'] ?? []) === 1 ? '1 thing' : count($result['findings'] ?? []).' things', round(($result['took'] ?? 0) / 1000, 1))
                : $name.' was not checked: '.($result['reason'] ?? 'it did not run.'));
        }, fn (string $key): mixed => $studio->reportToolProgress($key, 'running'));

        $payload = ['commit' => $this->commit(), 'packages' => $toolbox->packages(), 'tools' => $results];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->step($journal, 'Sending what the tools found: file, line, rule and a one-line message for each, and your packages\' names and versions. No code.');
        $answer = $studio->submitScanToolResults($payload);

        if ($answer === null) {
            return $this->outcome('The studio did not take the results. Check php artisan studio:settings.', self::FAILURE);
        }

        $ran = count(array_filter($results, fn (array $result): bool => $result['ran']));
        $missing = array_column($toolbox->missing($checks), 'name');

        return $this->outcome(sprintf('Ran %d of %d tools: %d findings sent.', $ran, count($results), (int) ($answer['findings'] ?? 0)).($missing === []
            ? ''
            : ' Not set up yet: '.implode(', ', $missing).'. Press ⏎ on the Dashboard to install them, or run php artisan '.InstallToolsCommand::SIGNATURE.'.'), self::SUCCESS);
    }

    private function commit(): ?string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    private function step(TaskJournal $journal, string $line): void
    {
        $journal->note($line);

        if (! $this->option('plain') && ! $this->option('json')) {
            $this->components->twoColumnDetail($line);
        }
    }

    private function outcome(string $line, int $code): int
    {
        match (true) {
            (bool) $this->option('plain') => $this->line($line),
            $code === self::SUCCESS => $this->components->info($line),
            default => $this->components->error($line),
        };

        return $code;
    }
}
