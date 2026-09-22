<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Errand;

/*
|--------------------------------------------------------------------------
| The studio names a question; this machine decides what runs
|--------------------------------------------------------------------------
|
| Everything here arrives over the network. A studio that could post a shell
| string to somebody's laptop would be a remote execution hole wearing a
| build's clothes, so nothing from the wire is interpreted — an unknown name
| runs nothing, and a query that is not plainly a read runs nothing either.
|
*/

function whatItWouldRun(Errand $errand, string $name, array $arguments = []): ?array
{
    $decide = new ReflectionMethod($errand, 'whatThatMeans');

    return $decide->invoke($errand, $name, $arguments);
}

beforeEach(function (): void {
    $this->errand = new Errand(sys_get_temp_dir());
});

it('refuses a name it does not know', function (): void {
    expect(whatItWouldRun($this->errand, 'rm_minus_rf'))->toBeNull();

    $answer = $this->errand->run('rm_minus_rf');

    expect($answer['exit_code'])->toBe(1)
        ->and($answer['error'])->toContain('does not know how to answer');
});

it('will not run a query that writes', function (): void {
    foreach (['drop table users', 'DELETE FROM users', 'update users set admin = 1', 'insert into x values (1)'] as $sql) {
        expect(whatItWouldRun($this->errand, 'query_database', ['sql' => $sql]))->toBeNull();
    }
});

it('runs a plain select', function (): void {
    expect(whatItWouldRun($this->errand, 'query_database', ['sql' => 'select * from users limit 1']))
        ->toBe(['php', 'artisan', 'db:query', 'select * from users limit 1']);
});

it('only runs tests that live under tests/', function (): void {
    $command = whatItWouldRun($this->errand, 'run_tests', ['paths' => [
        'tests/Feature/InvitesTest.php',
        '../../etc/passwd',
        'app/Models/User.php',
        'tests/../../escape.php',
        'tests/Feature/Other; rm -rf /',
    ]]);

    expect($command)->toBe(['php', 'artisan', 'test', '--compact', 'tests/Feature/InvitesTest.php']);
});

it('asks for the schema without being told how', function (): void {
    expect(whatItWouldRun($this->errand, 'describe_schema'))
        ->toBe(['php', 'artisan', 'db:show', '--counts']);
});

it('keeps the tail of a very long output', function (): void {
    $trim = new ReflectionMethod($this->errand, 'trimmed');

    $trimmed = $trim->invoke($this->errand, str_repeat('a', 120000).'THE-FAILURE-IS-HERE');

    expect($trimmed)->toContain('THE-FAILURE-IS-HERE')
        ->and($trimmed)->toStartWith('…trimmed…')
        ->and(strlen($trimmed))->toBeLessThan(120000);
});
