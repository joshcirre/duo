<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use JoshCirre\Duo\DuoServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Additional setup
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            DuoServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Set environment to local for debug middleware to work
        $app['config']->set('app.env', 'local');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Configure Duo
        $app['config']->set('duo.auto_discover', false);
        $app['config']->set('duo.model_paths', [
            __DIR__.'/Fixtures/Models',
        ]);

        // Configure views
        $app['config']->set('view.paths', array_merge(
            $app['config']->get('view.paths'),
            [__DIR__.'/Fixtures/views']
        ));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }
}
