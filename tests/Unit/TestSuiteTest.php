<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\TestSuite;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->folder = sys_get_temp_dir().'/studio-suite-'.uniqid();
    mkdir($this->folder, 0777, true);
    $this->phpunit = fn (string $name, string $database): int|false => file_put_contents(
        $this->folder.'/'.$name,
        '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/><env name="DB_DATABASE" value="'.$database.'"/></php></phpunit>',
    );
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->folder);
});

it('runs tests only against an in-memory or test database', function (string $file, string $database, bool $safe): void {
    ($this->phpunit)($file, $database);

    expect((new TestSuite($this->folder))->problem() === null)->toBe($safe);
})->with([
    'in memory' => ['phpunit.xml', ':memory:', true],
    'a test database' => ['phpunit.xml', 'shop_testing', true],
    'the distributed config' => ['phpunit.xml.dist', ':memory:', true],
    'the app\'s own database' => ['phpunit.xml', 'shop', false],
]);

it('refuses when nothing says which database the tests use', function (): void {
    expect((new TestSuite($this->folder))->problem())->toContain('Your tests would run against your own database');
});

it('reads the testing database from .env.testing, with phpunit.xml overriding it', function (): void {
    file_put_contents($this->folder.'/.env.testing', "APP_ENV=testing\nDB_DATABASE=\"shop_test\"\n");
    $fromDotEnv = (new TestSuite($this->folder))->problem();
    ($this->phpunit)('phpunit.xml', 'shop');

    expect($fromDotEnv)->toBeNull()
        ->and((new TestSuite($this->folder))->problem())->not->toBeNull();
});

it('reads each file\'s result from the report, counting a dataset\'s cases once', function (): void {
    $report = $this->folder.'/report.xml';
    file_put_contents($report, <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
          <testsuite name="Tests" tests="4" failures="0" errors="1" skipped="1">
            <testsuite name="Tests\Unit\PriceTest" file="tests/Unit/PriceTest.php" tests="3" failures="0" errors="0" skipped="1">
              <testsuite name="Tests\Unit\PriceTest::it rounds" file="tests/Unit/PriceTest.php::it rounds" tests="2" failures="0" errors="0" skipped="0">
                <testcase name="it rounds with data set #1"/>
                <testcase name="it rounds with data set #2"/>
              </testsuite>
              <testcase name="it is skipped"><skipped/></testcase>
            </testsuite>
            <testsuite name="Tests\Feature\CheckoutTest" file="tests/Feature/CheckoutTest.php" tests="1" failures="0" errors="1" skipped="0">
              <testcase name="it checks out"><error type="Error">Call to undefined method Cart::total()
        at app/Cart.php:12</error></testcase>
            </testsuite>
          </testsuite>
        </testsuites>
        XML);

    expect((new TestSuite($this->folder))->read($report, false))->toBe([
        'passed' => false,
        'results' => [
            ['file' => 'tests/Unit/PriceTest.php', 'passed' => true, 'summary' => null, 'failures' => []],
            ['file' => 'tests/Feature/CheckoutTest.php', 'passed' => false, 'summary' => 'it checks out: Call to undefined method Cart::total()', 'failures' => ['it checks out: Call to undefined method Cart::total()']],
        ],
        'cases' => ['passed' => 2, 'failed' => 1],
    ]);
});

it('says the run failed when Pest left no report behind', function (): void {
    expect((new TestSuite($this->folder))->read($this->folder.'/missing.xml', false))
        ->toBe(['passed' => false, 'results' => [], 'cases' => ['passed' => 0, 'failed' => 0]]);
});

it('only ever runs files under tests/', function (): void {
    expect((new TestSuite($this->folder))->runnable(['tests/Feature/CartTest.php', 'app/Models/Cart.php', 'tests/../.env', 'tests/Feature/Cart Test.php']))
        ->toBe(['tests/Feature/CartTest.php']);
});
