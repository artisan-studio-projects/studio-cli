<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Dashboard;

use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Components\Component;
use ArtisanStudio\StudioCli\Terminal\Components\Text;

final readonly class NotConnected
{
    public function __construct(private Studio $studio) {}

    /**
     * @return list<Component>
     */
    public function panel(): array
    {
        $url = $this->studio->url();
        $project = $this->studio->project();

        $message = match ($this->studio->connection()) {
            Studio::UNLINKED => [
                "This project isn't linked to Artisan Studio yet.",
                "Link it once and these tabs fill with your project's health, deliverables and workflows.",
                'Press s for Settings, then Link with a token.',
            ],
            Studio::REFUSED => [
                "Artisan Studio doesn't recognise this project's token.",
                "{$url} turned away the token in your .env. It may have been revoked, or it came from another studio.",
                'Press s for Settings and link again with a fresh token from Settings → Studio CLI.',
            ],
            Studio::FORBIDDEN => [
                "This token can't open project {$project}.",
                "The token works, but project {$project} on {$url} belongs to someone else.",
                'Press s for Settings and switch to one of your own projects.',
            ],
            Studio::MISSING => [
                "Project {$project} isn't on {$url}.",
                'It may have been deleted, or it lives on another studio.',
                'Press s for Settings and pick your project again.',
            ],
            Studio::UNREACHABLE => $this->studio->hasConnected() ? null : [
                "Can't reach Artisan Studio.",
                "Nothing answered at {$url}. Is it running, and is that the right address?",
                'Trying again in the background. Press s to check the address.',
            ],
            default => null,
        };

        if ($message === null) {
            return [];
        }

        [$title, $detail, $hint] = $message;

        return [
            Text::make($title)->bold()->colour('rose'),
            Text::make($detail)->wrap()->colour('soft'),
            Text::make($hint)->colour('cyan'),
        ];
    }
}
