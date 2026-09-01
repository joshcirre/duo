<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Point Duo model discovery at the workbench models
        config()->set('duo.model_paths', [__DIR__.'/../Models']);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register the workbench's Livewire 4 single-file components
        Livewire::addLocation(viewPath: __DIR__.'/../../resources/views/components');

        // Load views
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'workbench');
    }
}
