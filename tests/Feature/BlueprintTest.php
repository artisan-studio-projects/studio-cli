<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\Blueprint;
use ArtisanStudio\StudioCli\Console\BlueprintCommand;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitBlueprintRequest;
use ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\Broken;
use ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\Customer;
use ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\Invoice;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    config([
        'database.default' => 'blueprint',
        'database.connections.blueprint' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);

    DB::statement('create table customers (id integer primary key not null, name varchar(120) not null)');
    DB::statement("create table invoices (id integer primary key not null, customer_id integer not null, status varchar(20) not null default 'draft', total decimal(8,2) not null default 0, settled tinyint(1) not null default 0, lines text null, paid_at datetime null)");

    $this->blueprint = new Blueprint(dirname(__DIR__).'/Fixtures/Blueprint', 'ArtisanStudio\\StudioCli\\Tests\\Fixtures\\Blueprint\\', app(ModelInspector::class));

    $this->app->instance(Blueprint::class, $this->blueprint);
    $this->app->instance(LocalChanges::class, new LocalChanges(sys_get_temp_dir()));

    $this->sent = fn (): ?PendingRequest => collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response): PendingRequest => $response->getPendingRequest())
        ->first(fn (PendingRequest $request): bool => $request->getRequest() instanceof SubmitBlueprintRequest);
});

it('finds the models, and only the models', function (): void {
    expect($this->blueprint->models()->all())->toBe([Broken::class, Customer::class, Invoice::class]);
});

it('maps names, plain types and relationships, and leaves out a model it cannot read', function (): void {
    $mapped = $this->blueprint->map();

    expect($mapped['skipped'])->toBe([Broken::class])
        ->and($mapped['models'])->toBe([
            [
                'class' => Customer::class,
                'table' => 'customers',
                'columns' => [
                    ['name' => 'id', 'type' => 'integer', 'nullable' => false],
                    ['name' => 'name', 'type' => 'string', 'nullable' => false],
                ],
                'relationships' => [
                    ['name' => 'invoices', 'type' => 'HasMany', 'related' => Invoice::class],
                ],
            ],
            [
                'class' => Invoice::class,
                'table' => 'invoices',
                'columns' => [
                    ['name' => 'id', 'type' => 'integer', 'nullable' => false],
                    ['name' => 'customer_id', 'type' => 'integer', 'nullable' => false],
                    ['name' => 'status', 'type' => 'enum', 'nullable' => false],
                    ['name' => 'total', 'type' => 'number', 'nullable' => false],
                    ['name' => 'settled', 'type' => 'boolean', 'nullable' => false],
                    ['name' => 'lines', 'type' => 'json', 'nullable' => true],
                    ['name' => 'paid_at', 'type' => 'datetime', 'nullable' => true],
                ],
                'relationships' => [
                    ['name' => 'customer', 'type' => 'BelongsTo', 'related' => Customer::class],
                ],
            ],
        ]);
});

it('prints what it would send, and sends nothing', function (): void {
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['accepted' => 2], 202)]);

    $this->artisan(BlueprintCommand::SIGNATURE, ['--json' => true])
        ->expectsOutputToContain('"class": "'.str_replace('\\', '\\\\', Invoice::class).'"')
        ->doesntExpectOutputToContain('draft')
        ->assertSuccessful();

    expect(($this->sent)())->toBeNull();
});

it('sends the map to the studio', function (): void {
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['accepted' => 2], 202)]);

    $this->artisan(BlueprintCommand::SIGNATURE)
        ->expectsOutputToContain('Could not read Broken')
        ->expectsOutputToContain('Sent the map of 2 models')
        ->assertSuccessful();

    $request = ($this->sent)();

    expect($request->getUrl())->toBe('https://studio.test/api/v1/projects/1/blueprint')
        ->and($request->body()->all())->toBe(['commit' => null, 'models' => $this->blueprint->map()['models']])
        ->and((string) $request->body())->not->toContain('draft')->not->toContain('(20)');
});

it('says so when the studio does not take it', function (): void {
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['message' => 'Forbidden.'], 403)]);

    $this->artisan(BlueprintCommand::SIGNATURE)
        ->expectsOutputToContain('The studio did not take the blueprint')
        ->assertFailed();
});

it('sends nothing from a project that is not linked', function (): void {
    config(['studio-cli.token' => null]);
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['accepted' => 2], 202)]);

    $this->artisan(BlueprintCommand::SIGNATURE)
        ->expectsOutputToContain('not linked yet')
        ->assertSuccessful();

    expect(($this->sent)())->toBeNull();
});

it('maps and sends the blueprint when the studio asks for it down the stream', function (): void {
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['accepted' => 2], 202)]);

    event(new StudioReported(['type' => 'blueprint', 'kind' => 'project']));

    expect(($this->sent)()?->body()->all()['models'])->toBe($this->blueprint->map()['models'])
        ->and(app(ActivityLog::class)->ofKinds(['blueprint'])[0])
        ->toMatchArray(['label' => 'Blueprint', 'detail' => 'Sent the map of 2 models', 'colour' => 'green']);
});

it('only maps the blueprint when asked', function (): void {
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['accepted' => 2], 202)]);

    event(new StudioReported(['type' => 'changed', 'kind' => 'project']));

    expect(($this->sent)())->toBeNull()
        ->and(app(ActivityLog::class)->ofKinds(['blueprint']))->toBe([]);
});

it('says so in the activity feed when the studio does not take it', function (): void {
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['message' => 'Server Error'], 500)]);

    event(new StudioReported(['type' => 'blueprint', 'kind' => 'project']));

    expect(app(ActivityLog::class)->ofKinds(['blueprint'])[0])->toMatchArray(['colour' => 'amber']);
});
