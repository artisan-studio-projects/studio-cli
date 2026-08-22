<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Tests;

use ArtisanStudio\StudioCli\StudioCliServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Saloon\Laravel\SaloonServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SaloonServiceProvider::class,
            StudioCliServiceProvider::class,
        ];
    }

    /**
     * Linked, because that is the state nearly every test is about.
     *
     * The dev tab only registers for a project that has been linked — an
     * unlinked one would never use it — so a suite that left this out would be
     * testing a package nobody had set up. The unlinked case has its own test.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('studio-cli.url', 'https://studio.test');
        $app['config']->set('studio-cli.token', 'test-token');
        $app['config']->set('studio-cli.project', '1');
    }
}
