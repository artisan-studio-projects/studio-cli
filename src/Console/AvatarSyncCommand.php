<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\AvatarClips;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Console\Command;

class AvatarSyncCommand extends Command
{
    public const string SIGNATURE = 'studio:avatar-sync';

    protected $signature = self::SIGNATURE;

    protected $description = 'Download your avatar\'s clips from Artisan Studio, where they have changed';

    public function handle(AvatarClips $clips, Studio $studio): int
    {
        if (! $clips->fromTheStudio()) {
            $this->components->info('Her clips come from '.$clips->folder().', so there is nothing to download.');

            return self::SUCCESS;
        }

        if (! $studio->isLinked()) {
            $this->components->warn('This project is not linked yet. Run php artisan studio:settings, then try again.');

            return self::SUCCESS;
        }

        $synced = $clips->sync();

        $this->components->twoColumnDetail('Downloaded', $synced['downloaded'] === [] ? 'nothing new' : implode(', ', $synced['downloaded']));
        $this->components->twoColumnDetail('Already up to date', $synced['kept'] === [] ? '—' : implode(', ', $synced['kept']));

        if ($synced['failed'] !== []) {
            $this->components->warn('Could not download '.implode(', ', $synced['failed']).'. They will be tried again next time.');
        }

        return self::SUCCESS;
    }
}
