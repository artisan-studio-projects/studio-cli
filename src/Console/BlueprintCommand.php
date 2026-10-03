<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Blueprint;
use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Console\Command;

class BlueprintCommand extends Command
{
    public const string SIGNATURE = 'studio:blueprint';

    protected $signature = self::SIGNATURE.'
        {--json : Print what would be sent, and send nothing}';

    protected $description = 'Send Artisan Studio the map of your models: names, tables, column types and relationships, never code or rows';

    public function handle(Blueprint $blueprint, Studio $studio, LocalChanges $changes): int
    {
        if (! $this->option('json') && ! $studio->isLinked()) {
            $this->components->warn('This project is not linked yet. Run php artisan studio:settings, then try again.');

            return self::SUCCESS;
        }

        $mapped = $blueprint->map();
        $sha = $changes->currentSha();

        $payload = [
            'commit' => $sha === '' ? null : $sha,
            'models' => $mapped['models'],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($mapped['skipped'] !== []) {
            $this->components->warn('Could not read '.implode(', ', array_map(class_basename(...), $mapped['skipped'])).', so they are left out.');
        }

        if ($mapped['models'] === []) {
            $this->components->error('No models were found under app/, so there is nothing to send.');

            return self::FAILURE;
        }

        if ($studio->submitBlueprint($payload) === null) {
            $this->components->error('The studio did not take the blueprint. Check php artisan studio:settings, then try again.');

            return self::FAILURE;
        }

        $this->components->info('Sent the map of '.trans_choice(':count model|:count models', count($mapped['models'])).'. SAMI labels them next.');

        return self::SUCCESS;
    }
}
