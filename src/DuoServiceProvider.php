<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use JoshCirre\Duo\Commands\DiscoverModelsCommand;
use JoshCirre\Duo\Commands\GenerateManifestCommand;
use JoshCirre\Duo\Compiler\DuoBladeCompiler;
use JoshCirre\Duo\Livewire\DuoSynth;
use Livewire\EventBus;
use Livewire\Livewire;

final class DuoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/duo.php',
            'duo'
        );

        $this->app->singleton(ModelRegistry::class);
        $this->app->singleton(DuoOperationCollector::class);

        $this->app->instance(self::class, $this);

        // Both of these must happen before LivewireServiceProvider::boot():
        // - The Duo precompiler has to run ahead of the Livewire precompilers
        //   (wire:key compilation, morph markers) so it sees the author-written
        //   Blade — wire:* attributes and @forelse blocks — untouched.
        // - DuoSynth must be registered before ComponentHookRegistry::boot(),
        //   which only wires up mount/hydrate listeners for hooks known at
        //   that point. Registering later means dehydrate never fires on
        //   real requests (the testing harness hides this).
        $this->app->booting(function () {
            $this->registerBladePrecompiler();

            if (class_exists(Livewire::class)) {
                Livewire::componentHook(DuoSynth::class);
            }
        });
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
        // Serve the service worker from the tracked source file: dist/ is not
        // part of the composer package, and the Vite plugin only copies the
        // worker into public/ on a production build.
        Route::get('duo-sw.js', function () {
            $path = __DIR__.'/../resources/js/duo/service-worker.js';

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
    }

    /**
     * Register Livewire render hook to decorate the component root.
     *
     * Uses the Livewire EventBus to intercept rendered HTML and add the
     * metadata attributes the client needs, plus the Alpine `duo` scope
     * when the template was compiled by the Duo precompiler.
     */
    protected function registerLivewireHook(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        try {
            $eventBus = app(EventBus::class);
        } catch (\Exception $e) {
            return;
        }

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

                return $provider->decorateComponentRoot($html, $provider->extractDuoMetadata($component));
            };
        });
    }

    /**
     * Add Duo attributes to the root element of a rendered component.
     *
     * Always adds data-duo-enabled and data-duo-meta. When the template was
     * compiled by the Duo precompiler (its bindings reference the `duo` Alpine
     * scope), the root also receives x-data="duo" so that scope exists before
     * Alpine walks the tree — no client-side patching after the fact.
     *
     * An author-supplied x-data on the root is preserved by wrapping it:
     * x-data="{ open: false }" becomes x-data="duo({ open: false })".
     *
     * @param  array<string, mixed>  $meta
     */
    public function decorateComponentRoot(string $html, array $meta): string
    {
        $metaJson = htmlspecialchars(
            json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ENT_QUOTES,
            'UTF-8'
        );

        if (! preg_match('/^\s*<[a-zA-Z][a-zA-Z0-9-]*/', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $tagStart = $m[0][1];
        $tagNameEnd = $tagStart + strlen($m[0][0]);
        $tagEnd = $this->findOpenTagEnd($html, $tagNameEnd);

        if ($tagEnd === -1) {
            return $html;
        }

        $openTag = substr($html, $tagStart, $tagEnd - $tagStart);
        $attributes = ' data-duo-enabled="true" data-duo-meta="'.$metaJson.'"';

        if ($this->usesDuoScope($html)) {
            if (preg_match('/\sx-data\s*=\s*("|\')(.*?)\1/s', $openTag, $xd)) {
                $existing = trim($xd[2]);
                $wrapped = $existing === '' || $existing === '{}' ? 'duo' : 'duo('.$existing.')';
                $openTag = str_replace($xd[0], ' x-data='.$xd[1].$wrapped.$xd[1], $openTag);
            } else {
                $attributes .= ' x-data="duo"';
            }
        }

        // Insert attributes right after the tag name so they precede any
        // trailing "/" of a self-closing root.
        $openTag = substr($openTag, 0, strlen($m[0][0])).$attributes.substr($openTag, strlen($m[0][0]));

        return substr($html, 0, $tagStart).$openTag.substr($html, $tagEnd);
    }

    /**
     * Whether the rendered HTML contains bindings produced by the Duo precompiler.
     */
    protected function usesDuoScope(string $html): bool
    {
        return str_contains($html, 'data-duo-collection')
            || str_contains($html, 'duo.form.')
            || str_contains($html, 'duo.call(')
            || str_contains($html, 'duo.submit(');
    }

    /**
     * Find the position of the ">" that closes the open tag starting at $from,
     * skipping over ">" characters inside quoted attribute values.
     */
    protected function findOpenTagEnd(string $html, int $from): int
    {
        $length = strlen($html);
        $quote = null;

        for ($i = $from; $i < $length; $i++) {
            $char = $html[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '>') {
                return $i;
            }
        }

        return -1;
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
            'component' => method_exists($component, 'getName')
                ? $component->getName()
                : class_basename($component),
            'models' => $models,
            'methods' => $componentMethods,
        ];
    }

    protected function collectSyncableModels($value, string $name, array &$models): void
    {
        $registry = app(ModelRegistry::class);

        if ($value instanceof Model
            && in_array(Syncable::class, class_uses_recursive($value))) {
            $registryMeta = $registry->get(get_class($value));
            $models[$name] = [
                'store' => str_replace('\\', '_', get_class($value)),
                'table' => $value->getTable(),
                'primaryKey' => $value->getKeyName(),
                'type' => 'model',
                'schema' => $registryMeta['schema'] ?? [],
                'fillable' => $registryMeta['fillable'] ?? [],
            ];

            return;
        }

        if ($value instanceof Collection || is_array($value)) {
            $items = $value instanceof Collection ? $value : collect($value);
            $first = $items->first();

            if ($first instanceof Model
                && in_array(Syncable::class, class_uses_recursive($first))) {
                $registryMeta = $registry->get(get_class($first));
                $models[$name] = [
                    'store' => str_replace('\\', '_', get_class($first)),
                    'table' => $first->getTable(),
                    'primaryKey' => $first->getKeyName(),
                    'type' => 'collection',
                    'schema' => $registryMeta['schema'] ?? [],
                    'fillable' => $registryMeta['fillable'] ?? [],
                ];
            } elseif ($items->isEmpty()) {
                // Empty collection — try to find the model from the registry
                // by matching the table name to the property name (e.g., "notes" → notes table)
                foreach ($registry->all() as $class => $meta) {
                    if ($meta['table'] === $name) {
                        $models[$name] = [
                            'store' => str_replace('\\', '_', $class),
                            'table' => $meta['table'],
                            'primaryKey' => $meta['primaryKey'],
                            'type' => 'collection',
                            'schema' => $meta['schema'],
                            'fillable' => $meta['fillable'],
                        ];
                        break;
                    }
                }
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
                        && is_subclass_of($typeName, Model::class),
                ];
            }

            $methods[$method->getName()] = [
                'params' => $params,
                ...$this->analyzeMethod($method),
            ];
        }

        return $methods;
    }

    /**
     * Analyze a component method's source to describe what it does to the model.
     *
     * Reads the PHP source between the method's start and end lines and applies
     * a few conservative patterns. The result drives the client's *optimistic*
     * behavior only — the server's DuoOperationCollector reports what actually
     * happened, so a wrong guess here is corrected on the round trip.
     *
     * - operation: 'create' | 'update' | 'delete' | null
     * - toggles:   columns written as the negation of their current value,
     *              e.g. 'completed' => ! $todo->completed
     * - mapping:   component property → column, from 'title' => $this->newTitle
     * - defaults:  column → literal, from 'completed' => false
     *
     * @return array{operation: string|null, toggles: array<int, string>, mapping: array<string, string>, defaults: array<string, mixed>}
     */
    protected function analyzeMethod(\ReflectionMethod $method): array
    {
        $analysis = [
            'operation' => null,
            'toggles' => [],
            'mapping' => [],
            'defaults' => [],
        ];

        try {
            $filename = $method->getFileName();
            if (! $filename || ! file_exists($filename)) {
                return $analysis;
            }

            $source = file_get_contents($filename);
            $lines = array_slice(
                explode("\n", $source),
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            );
            $body = implode("\n", $lines);

            if (preg_match('/->delete\s*\(/', $body) || preg_match('/::destroy\s*\(/', $body)) {
                $analysis['operation'] = 'delete';
            } elseif (preg_match('/->create\s*\(/', $body) || preg_match('/::create\s*\(/', $body)) {
                $analysis['operation'] = 'create';
            } elseif (preg_match('/->update\s*\(/', $body) || preg_match('/->save\s*\(/', $body)) {
                $analysis['operation'] = 'update';
            }

            // Only the attribute arrays handed to Eloquent describe the write;
            // scanning the whole body would pick up validate() rules and the like.
            $arguments = implode("\n", [
                ...$this->extractCallArguments($body, 'create'),
                ...$this->extractCallArguments($body, 'update'),
                ...$this->extractCallArguments($body, 'fill'),
                ...$this->extractCallArguments($body, 'forceFill'),
            ]);

            // 'completed' => ! $todo->completed
            if (preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*!\s*\$\w+(?:->|\[[\'"])(\w+)/', $arguments, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if ($match[1] === $match[2]) {
                        $analysis['toggles'][] = $match[1];
                    }
                }
            }

            // 'title' => $this->newTodoTitle
            if (preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*\$this->(\w+)\b(?!\s*->|\s*\()/', $arguments, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $analysis['mapping'][$match[2]] = $match[1];
                }
            }

            // 'completed' => false
            if (preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*(true|false|null|-?\d+(?:\.\d+)?|\'[^\']*\'|"[^"]*")\s*(?:,|\]|$)/m', $arguments, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $analysis['defaults'][$match[1]] = json_decode(
                        str_starts_with($match[2], "'") ? json_encode(trim($match[2], "'")) : $match[2],
                        true
                    );
                }
            }

            return $analysis;
        } catch (\Throwable) {
            return $analysis;
        }
    }

    /**
     * Return the argument text of every `->method(` / `::method(` call in $body,
     * matching parentheses so nested arrays and calls are captured whole.
     *
     * @return array<int, string>
     */
    protected function extractCallArguments(string $body, string $method): array
    {
        $arguments = [];
        $offset = 0;

        while (preg_match('/(?:->|::)'.preg_quote($method, '/').'\s*\(/', $body, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1] + strlen($match[0][0]);
            $depth = 1;
            $length = strlen($body);

            for ($i = $start; $i < $length; $i++) {
                $char = $body[$i];

                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;

                    if ($depth === 0) {
                        $arguments[] = substr($body, $start, $i - $start);
                        break;
                    }
                }
            }

            $offset = $start;
        }

        return $arguments;
    }

    /**
     * Register the Forte-based Blade precompiler that transforms
     * wire:* attributes to Alpine.js equivalents in Duo templates.
     */
    protected function registerBladePrecompiler(): void
    {
        $compiler = new DuoBladeCompiler;

        \Blade::precompiler(function (string $template) use ($compiler) {
            return $compiler->compile($template);
        });
    }

    protected function registerBladeDirectives(): void
    {
        \Blade::directive('duoMeta', function () {
            // The <head> renders before any Livewire component in the body, so
            // there is no reliable way to know at this point whether the page
            // contains a Duo component. Adding @duoMeta to a layout is the
            // opt-in: emit the cache meta unconditionally.
            return '<?php
                echo \'<meta name="csrf-token" content="\' . csrf_token() . \'">\';
                echo "\n" . \'<meta name="duo-cache" content="true" data-duo-version="2.0">\';
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
