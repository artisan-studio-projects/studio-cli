<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\LiveSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowSnapshotRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| The studio's own numbers
|--------------------------------------------------------------------------
|
| What the tabs show comes from the linked studio. Made-up numbers only ever
| appear when asked for with --demo.
|
*/

beforeEach(function (): void {
    app()->forgetInstance(SnapshotSource::class);
    $this->plain = fn (string $text): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $text);
    $this->studio = [
        'project' => ['name' => 'Acme Shop', 'repository' => 'acme/shop'],
        'health' => ['percent' => 82, 'label' => 'Good', 'open' => 3],
        'scan' => ['status' => 'Scanned', 'files_done' => 900, 'files_total' => 900, 'scanned_at' => Carbon::now()->subHours(2)->toIso8601String()],
        'insights' => ['url' => 'https://studio.test/insights/rulesets', 'rulesets' => [['name' => 'Code Quality', 'open' => 3]]],
    ];
    Saloon::fake([ShowSnapshotRequest::class => MockResponse::make($this->studio)]);
});

it('shows the linked studio\'s own numbers, and nothing made up', function (): void {
    Artisan::call('studio', ['tab' => 'dashboard', '--once' => true, '--width' => 120, '--height' => 30]);
    $screen = ($this->plain)(Artisan::output());

    expect($screen)->toContain('Acme Shop')->toContain('acme/shop')->toContain('82%')->toContain('Good')
        ->not->toContain('68%');
});

it('shows made-up numbers only when asked for with --demo', function (): void {
    Artisan::call('studio', ['tab' => 'dashboard', '--demo' => true, '--once' => true, '--width' => 120, '--height' => 30]);

    expect(($this->plain)(Artisan::output()))->toContain('68%')->not->toContain('Acme Shop');

    Saloon::assertNothingSent();
});

it('asks the studio once, not on every redraw, and again the moment it is told the project changed', function (): void {
    $source = app(SnapshotSource::class);

    $first = $source->snapshot();
    $source->snapshot();
    Saloon::assertSentCount(1);

    event(new StudioReported(['type' => 'changed', 'kind' => 'project']));
    $source->snapshot();

    expect($source)->toBeInstanceOf(LiveSnapshots::class)
        ->and($first->health)->toBe(82)
        ->and($first->scanLabel())->toContain('2h ago');

    Saloon::assertSentCount(2);
});

it('says the token was turned away, rather than showing a project full of zeros', function (): void {
    Saloon::fake([ShowSnapshotRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);

    Artisan::call('studio', ['tab' => 'dashboard', '--once' => true, '--width' => 120, '--height' => 30]);

    expect(($this->plain)(Artisan::output()))
        ->toContain('This project needs a fresh token')
        ->toContain("https://studio.test doesn't know the token")
        ->toContain('Press s to paste the new one.')
        ->not->toContain('Health')->not->toContain('0%');
});

it('says the project is not on the studio, and on which one', function (): void {
    Saloon::fake([ShowSnapshotRequest::class => MockResponse::make(['message' => 'Not found.'], 404)]);

    Artisan::call('studio', ['tab' => 'dashboard', '--once' => true, '--width' => 120, '--height' => 30]);

    expect(($this->plain)(Artisan::output()))->toContain("Project 1 isn't on https://studio.test")->not->toContain('82%');
});

it('says the studio cannot be reached before it has ever answered', function (): void {
    Saloon::fake([ShowSnapshotRequest::class => MockResponse::make()->throw(new RuntimeException('Connection refused'))]);

    Artisan::call('studio', ['tab' => 'insights', '--once' => true, '--width' => 120, '--height' => 30]);

    expect(($this->plain)(Artisan::output()))->toContain("Can't reach Artisan Studio")->toContain('Nothing answered at https://studio.test')->not->toContain('Health 0%');
});

it('keeps the last real numbers when the studio drops out after answering', function (): void {
    $source = app(SnapshotSource::class);
    $source->snapshot();

    Saloon::fake([ShowSnapshotRequest::class => MockResponse::make()->throw(new RuntimeException('Connection refused'))]);
    $source->forget();

    Artisan::call('studio', ['tab' => 'dashboard', '--once' => true, '--width' => 120, '--height' => 30]);

    expect(($this->plain)(Artisan::output()))->toContain('82%')->not->toContain("Can't reach Artisan Studio");
});

it('drops the notice the moment the studio answers again', function (): void {
    Saloon::fake([ShowSnapshotRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);
    $source = app(SnapshotSource::class);
    $source->snapshot();

    Saloon::fake([ShowSnapshotRequest::class => MockResponse::make($this->studio)]);
    $source->forget();

    Artisan::call('studio', ['tab' => 'dashboard', '--once' => true, '--width' => 120, '--height' => 30]);

    expect(($this->plain)(Artisan::output()))->toContain('82%')->not->toContain("doesn't recognise");
});

it('asks nothing of a studio this project is not linked to', function (): void {
    config()->set('studio-cli.token', null);

    expect(app(SnapshotSource::class)->snapshot()->hasScanned())->toBeFalse();

    Saloon::assertNothingSent();
});

it('reads how long ago the project was scanned from the studio\'s timestamp', function (): void {
    $snapshot = DashboardSnapshot::fromApi($this->studio, Carbon::now());

    expect($snapshot->scannedAgo)->toBe('2h ago')
        ->and($snapshot->project)->toBe('Acme Shop')
        ->and($snapshot->rulesets)->toBe([['name' => 'Code Quality', 'open' => 3]]);
});
