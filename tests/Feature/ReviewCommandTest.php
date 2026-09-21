<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Console\ReviewCommand;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitReviewRequest;
use ArtisanStudio\StudioCli\Studio;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| Pairing from your own terminal
|--------------------------------------------------------------------------
|
| The command holds a stream open and asks questions, so what is worth pinning
| here is the way in and the way out: it must refuse plainly when there is
| nothing to connect to, and hand a review back in the shape the studio parses.
|
*/

it('explains how to connect rather than failing', function (): void {
    config()->set('studio-cli.token', null);
    config()->set('studio-cli.project', null);

    $this->artisan(ReviewCommand::SIGNATURE)
        ->expectsOutputToContain('not connected to Artisan Studio yet')
        ->assertSuccessful();
});

it('sends a review the studio can read', function (): void {
    config()->set('studio-cli.token', 'a-token');
    config()->set('studio-cli.project', '7');

    Saloon::fake([
        SubmitReviewRequest::class => MockResponse::make(['review' => 1, 'resumed' => true], 200),
    ]);

    $sent = app(Studio::class)->submitReview('wf-1', [
        'agent' => 'pixel',
        'outcome' => 'updated',
        'note' => 'It was doing three jobs at once.',
        'files' => [['path' => 'app/Livewire/Invites.php', 'status' => 'modified']],
    ]);

    expect($sent)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        $body = $request->body()->all();

        return $request instanceof SubmitReviewRequest
            && $body['agent'] === 'pixel'
            && $body['outcome'] === 'updated'
            && $body['files'][0]['status'] === 'modified';
    });
});

it('says so when the studio will not take the review', function (): void {
    config()->set('studio-cli.token', 'a-token');
    config()->set('studio-cli.project', '7');

    Saloon::fake([
        SubmitReviewRequest::class => MockResponse::make(['message' => 'Nope.'], 422),
    ]);

    expect(app(Studio::class)->submitReview('wf-1', ['agent' => 'pixel', 'outcome' => 'accepted']))
        ->toBeFalse();
});
