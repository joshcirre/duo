<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use JoshCirre\Duo\Commands\DiscoverModelsCommand;
use JoshCirre\Duo\Commands\GenerateManifestCommand;
use JoshCirre\Duo\Http\Controllers\DuoSyncController;
use JoshCirre\Duo\Livewire\DuoSynth;

final class DuoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/duo.php',
            'duo'
        );

        $this->app->singleton(ModelRegistry::class);

        $this->app->instance(self::class, $this);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/duo.php' => config_path('duo.php'),
        ], 'duo-config');

        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js/vendor/duo'),
        ], 'duo-assets');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'duo');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/duo'),
        ], 'duo-views');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DiscoverModelsCommand::class,
                GenerateManifestCommand::class,
            ]);
        }

        if (config('duo.auto_discover', true)) {
            $this->discoverModels();
        }

        $this->registerRoutes();
        $this->registerLivewireHook();
        $this->registerBladeDirectives();
    }

    protected function registerRoutes(): void
    {
        Route::get('duo-sw.js', function () {
            $path = __DIR__.'/../dist/public/duo-sw.js';

            if (! file_exists($path)) {
                abort(404, 'Service worker file not found');
            }

            return response()
                ->file($path, [
                    'Content-Type' => 'application/javascript',
                    'Service-Worker-Allowed' => '/',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                ]);
        })->name('duo.service-worker');

        Route::prefix('api/duo')
            ->middleware(['web'])
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
     * Register Livewire render hook to inject Duo metadata into component HTML.
     *
     * Uses Livewire's EventBus to intercept rendered HTML and add data attributes.
     * No HTML transformation — just metadata so the JS interceptor can identify
     * Duo-enabled components and know which IndexedDB stores they use.
     */
    protected function registerLivewireHook(): void
    {
        if (! class_exists(\Livewire\Livewire::class)) {
            return;
        }

        try {
            $eventBus = app(\Livewire\EventBus::class);
        } catch (\Exception $e) {
            return;
        }

        // Register DuoSynth component hook for effects-based state channel
        \Livewire\Livewire::componentHook(DuoSynth::class);

        $provider = $this;

        $eventBus->on('render', function ($component, $view, $properties) use ($provider) {
            if (! in_array(WithDuo::class, class_uses_recursive($component))) {
                return null;
            }

            app()->instance('duo.has_enabled_component', true);

            return function ($html) use ($component, $provider) {
                if (str_contains($html, 'data-duo-enabled')) {
                    return $html;
                }

                $meta = $provider->extractDuoMetadata($component);
                $metaJson = htmlspecialchars(
                    json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ENT_QUOTES,
                    'UTF-8'
                );

                return preg_replace_callback(
                    '/^(<[^>]+?)>/',
                    fn ($m) => $m[1].' data-duo-enabled="true" data-duo-meta="'.$metaJson.'">',
                    $html,
                    1
                );
            };
        });
    }

    /**
     * Extract metadata about which Syncable models a component uses.
     * This JSON is sent to the client so the JS interceptor knows
     * how to map component properties to IndexedDB stores.
     */
    public function extractDuoMetadata($component): array
    {
        $registry = app(ModelRegistry::class);
        $models = [];

        $reflection = new \ReflectionClass($component);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== get_class($component)) {
                continue;
            }

            try {
                $value = $property->getValue($component);
            } catch (\Throwable) {
                continue;
            }

            $this->collectSyncableModels($value, $property->getName(), $models);
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== get_class($component)) {
                continue;
            }

            $hasComputed = false;
            foreach ($method->getAttributes() as $attr) {
                if (str_ends_with($attr->getName(), 'Computed')) {
                    $hasComputed = true;
                    break;
                }
            }

            if (! $hasComputed) {
                continue;
            }

            try {
                $value = $component->{$method->getName()};
                $this->collectSyncableModels($value, $method->getName(), $models);
            } catch (\Throwable) {
                continue;
            }
        }

        $componentMethods = $this->getPublicActionMethods($component);

        return [
            'enabled' => true,
            'component' => class_basename($component),
            'models' => $models,
            'methods' => $componentMethods,
        ];
    }

    protected function collectSyncableModels($value, string $name, array &$models): void
    {
        if ($value instanceof \Illuminate\Database\Eloquent\Model
            && in_array(Syncable::class, class_uses_recursive($value))) {
            $models[$name] = [
                'store' => str_replace('\\', '_', get_class($value)),
                'table' => $value->getTable(),
                'primaryKey' => $value->getKeyName(),
                'type' => 'model',
            ];

            return;
        }

        if ($value instanceof \Illuminate\Support\Collection || is_array($value)) {
            $items = $value instanceof \Illuminate\Support\Collection ? $value : collect($value);
            $first = $items->first();

            if ($first instanceof \Illuminate\Database\Eloquent\Model
                && in_array(Syncable::class, class_uses_recursive($first))) {
                $models[$name] = [
                    'store' => str_replace('\\', '_', get_class($first)),
                    'table' => $first->getTable(),
                    'primaryKey' => $first->getKeyName(),
                    'type' => 'collection',
                ];
            }
        }
    }

    /**
     * Get public action methods from a component (not lifecycle/framework methods).
     * Returns method names + parameter info so the JS interceptor can handle them.
     */
    protected function getPublicActionMethods($component): array
    {
        $reflection = new \ReflectionClass($component);
        $methods = [];

        $excluded = [
            'render', 'mount', 'hydrate', 'dehydrate', 'boot', 'booted',
            'updating', 'updated', 'rendering', 'rendered',
            '__construct', '__get', '__set', '__call', '__toString',
            'duoConfig',
        ];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== get_class($component)) {
                continue;
            }

            if (in_array($method->getName(), $excluded)) {
                continue;
            }

            $hasComputed = false;
            foreach ($method->getAttributes() as $attr) {
                if (str_ends_with($attr->getName(), 'Computed')) {
                    $hasComputed = true;
                    break;
                }
            }

            if ($hasComputed) {
                continue;
            }

            $params = [];
            foreach ($method->getParameters() as $param) {
                $type = $param->getType();
                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
                $isBuiltin = $type instanceof \ReflectionNamedType ? $type->isBuiltin() : true;
                $params[] = [
                    'name' => $param->getName(),
                    'type' => $typeName,
                    'isModel' => $typeName && ! $isBuiltin && class_exists($typeName)
                        && is_subclass_of($typeName, \Illuminate\Database\Eloquent\Model::class),
                ];
            }

            $methods[$method->getName()] = [
                'params' => $params,
            ];
        }

        return $methods;
    }

    protected function registerBladeDirectives(): void
    {
        \Blade::directive('duoMeta', function () {
            return '<?php
                echo \'<meta name="csrf-token" content="\' . csrf_token() . \'">\';
                if (app()->has(\'duo.has_enabled_component\') && app(\'duo.has_enabled_component\')) {
                    echo "\n" . \'<meta name="duo-cache" content="true" data-duo-version="2.0">\';
                }
            ?>';
        });
    }

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

    protected function usesDuoTrait(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        return in_array(Syncable::class, class_uses_recursive($class), true);
    }
}
