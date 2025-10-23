<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use JoshCirre\Duo\Commands\DiscoverModelsCommand;
use JoshCirre\Duo\Commands\GenerateManifestCommand;
use JoshCirre\Duo\Http\Controllers\DuoSyncController;

final class DuoServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/duo.php',
            'duo'
        );

        $this->app->singleton(ModelRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__.'/../config/duo.php' => config_path('duo.php'),
        ], 'duo-config');

        // Publish JavaScript assets
        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js/vendor/duo'),
        ], 'duo-assets');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                DiscoverModelsCommand::class,
                GenerateManifestCommand::class,
            ]);
        }

        // Auto-discover models if enabled
        if (config('duo.auto_discover', true)) {
            $this->discoverModels();
        }

        // Register API routes
        $this->registerRoutes();
    }

    /**
     * Register Duo API routes
     */
    protected function registerRoutes(): void
    {
        Route::prefix('api/duo')
            ->middleware(['api'])
            ->group(function () {
                Route::get('{table}', [DuoSyncController::class, 'index']);
                Route::get('{table}/{id}', [DuoSyncController::class, 'show']);
                Route::post('{table}', [DuoSyncController::class, 'store']);
                Route::put('{table}/{id}', [DuoSyncController::class, 'update']);
                Route::patch('{table}/{id}', [DuoSyncController::class, 'update']);
                Route::delete('{table}/{id}', [DuoSyncController::class, 'destroy']);
            });
    }

    /**
     * Discover models that use the Duo trait.
     */
    protected function discoverModels(): void
    {
        $registry = $this->app->make(ModelRegistry::class);
        $paths = config('duo.model_paths', [app_path('Models')]);

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $files = glob($path.'/*.php');

            foreach ($files as $file) {
                $class = $this->getClassFromFile($file);

                if ($class && $this->usesDuoTrait($class)) {
                    $registry->register($class);
                }
            }
        }
    }

    /**
     * Get the fully qualified class name from a file path.
     */
    protected function getClassFromFile(string $file): ?string
    {
        $namespace = null;
        $class = null;

        $handle = fopen($file, 'r');
        if (! $handle) {
            return null;
        }

        while (($line = fgets($handle)) !== false) {
            if (str_starts_with($line, 'namespace ')) {
                $namespace = trim(str_replace(['namespace ', ';'], '', $line));
            }

            if (str_starts_with($line, 'class ') || str_starts_with($line, 'final class ')) {
                $parts = explode(' ', $line);
                foreach ($parts as $i => $part) {
                    if ($part === 'class' && isset($parts[$i + 1])) {
                        $class = $parts[$i + 1];
                        break;
                    }
                }
                break;
            }
        }
        fclose($handle);

        if ($namespace && $class) {
            return $namespace.'\\'.$class;
        }

        return null;
    }

    /**
     * Check if a class uses the Duo trait.
     */
    protected function usesDuoTrait(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        return in_array(Concerns\Syncable::class, class_uses_recursive($class), true);
    }
}
