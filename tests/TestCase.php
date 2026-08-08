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
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('studio-cli.url', 'https://studio.test');
        $app['config']->set('studio-cli.token', 'test-token');
        $app['config']->set('studio-cli.project', '1');
    }
}
