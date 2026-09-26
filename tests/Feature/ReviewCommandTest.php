<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Console\ReviewCommand;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitReviewRequest;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

it('prints each change once, as it happens, rather than the whole list again', function (): void {
    $buffer = new BufferedOutput;
    $command = app(ReviewCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    $announce = new ReflectionMethod($command, 'announceWhatMoved');

    $component = ['path' => 'app/Livewire/MetricsDashboard.php', 'status' => 'added', 'added' => 42, 'removed' => 0];
    $route = ['path' => 'routes/web.php', 'status' => 'modified', 'added' => 2, 'removed' => 1];

    $announce->invoke($command, [$component['path'] => $component], []);
    $announce->invoke($command, [$component['path'] => $component, $route['path'] => $route], [$component['path'] => $component]);
    $announce->invoke($command, [$component['path'] => $component], [$component['path'] => $component, $route['path'] => $route]);

    $printed = $buffer->fetch();

    expect(substr_count($printed, 'app/Livewire/MetricsDashboard.php'))->toBe(1)
        ->and($printed)->toContain('+42')
        ->and($printed)->toContain('+2')
        ->and($printed)->toContain('−1')
        ->and($printed)->toContain('reverted');
});

it('writes the review as a conventional commit in the build\'s own scope', function (): void {
    $message = (new ReflectionMethod(ReviewCommand::class, 'commitMessage'))->invoke(
        app(ReviewCommand::class),
        ['agent' => 'pixel'],
        "Route should have pointed to livewire component not directly view without any shell\nAlso fixed import namespace",
        'project-metrics-dashboard',
    );

    expect($message)->toBe(
        "fix(project-metrics-dashboard): developer review of @pixel's work\n\n"
        ."Reason for change:\n"
        ."Route should have pointed to livewire component not directly view without any shell\n"
        .'Also fixed import namespace',
    );
});

it('reminds the developer to migrate when the artisan\'s commits brought migrations', function (): void {
    $buffer = new BufferedOutput;
    $command = app(ReviewCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    (new ReflectionProperty($command, 'components'))->setValue($command, new Factory($command->getOutput()));

    $remind = new ReflectionMethod($command, 'remindToMigrate');

    $remind->invoke($command, [
        ['path' => 'database/migrations/2026_09_24_000001_create_metric_snapshots_table.php', 'status' => 'added'],
        ['path' => 'database/migrations/2026_09_24_000002_add_phase_to_tasks_table.php', 'status' => 'added'],
        ['path' => 'app/Models/MetricSnapshot.php', 'status' => 'added'],
    ]);

    expect($buffer->fetch())->toContain('2 migrations landed')->toContain('php artisan migrate');

    $remind->invoke($command, [['path' => 'app/Models/MetricSnapshot.php', 'status' => 'added']]);

    expect($buffer->fetch())->not->toContain('migrate');
});

it('says in plain words what an artisan asked the developer\'s machine', function (): void {
    $command = app(ReviewCommand::class);
    $said = new ReflectionMethod($command, 'whatWasAsked');

    expect($said->invoke($command, 'query_database'))->toContain('looked something up in your database')
        ->and($said->invoke($command, 'describe_schema'))->toContain('database schema')
        ->and($said->invoke($command, 'something_new'))->toBe('An artisan asked your app something');
});
