<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use JoshCirre\Duo\Commands\DiscoverModelsCommand;
use JoshCirre\Duo\Commands\GenerateManifestCommand;
use JoshCirre\Duo\Http\Controllers\DuoSyncController;
use JoshCirre\Duo\Http\Middleware\DuoDebugMiddleware;
use Livewire\Livewire;

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

        // Load views for Blade components
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'duo');

        // Publish views
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/duo'),
        ], 'duo-views');

        // Note: Service worker is served dynamically via route (no publishing needed!)
        // Note: Boost AI guidelines are automatically loaded from vendor directory (no publishing needed!)

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

        // Register Livewire mechanism for HTML transformation
        $this->registerLivewireMechanism();

        // Register Blade directives
        $this->registerBladeDirectives();
    }

    /**
     * Register Duo API routes
     */
    protected function registerRoutes(): void
    {
        // Serve service worker dynamically from package (no publishing needed!)
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

        // Register debug middleware globally for all routes (local only)
        if ($this->app->environment('local')) {
            $router = $this->app->make(\Illuminate\Routing\Router::class);
            $router->pushMiddlewareToGroup('web', DuoDebugMiddleware::class);
        }

        // API routes for sync
        // Note: Using 'web' middleware instead of 'api' because:
        // 1. This is a same-origin SPA, not a separate API client
        // 2. Users are already authenticated via session (Fortify)
        // 3. Session cookies persist across page refreshes (critical for offline-first)
        // 4. CSRF protection already in place via X-CSRF-TOKEN header
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
     * Register Livewire mechanism for transforming Duo component HTML.
     */
    protected function registerLivewireMechanism(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        // Enable query logging globally so we can capture ALL queries including render queries
        // This is safe because we only process queries for Duo-enabled components
        \DB::enableQueryLog();

        \Log::info('[Duo] Registering Livewire hooks with global query logging');

        // Check if EventBus exists
        try {
            $eventBus = app(\Livewire\EventBus::class);
            \Log::info('[Duo] EventBus resolved successfully: '.get_class($eventBus));
        } catch (\Exception $e) {
            \Log::error('[Duo] Failed to resolve EventBus: '.$e->getMessage());

            return;
        }

        // Hook into Livewire's render event to transform Blade source for Duo components
        $eventBus->on('render', function ($component, $view, $properties) {
            \Log::info('[Duo] Render event triggered for component: '.get_class($component));

            // Check if transformations are enabled (for debugging purposes in local environment)
            // This is set by DuoDebugMiddleware
            $transformationsEnabled = app()->bound('duo.transformations.enabled')
                ? app('duo.transformations.enabled')
                : true;
            if (! $transformationsEnabled) {
                \Log::info('[Duo] Transformations disabled via debug toggle - skipping');

                return null;
            }

            // Check if component uses WithDuo trait
            if (! in_array(\JoshCirre\Duo\WithDuo::class, class_uses_recursive($component))) {
                \Log::info('[Duo] Component does NOT use WithDuo trait');

                return null;
            }

            \Log::info('[Duo] Component DOES use Duo trait - will transform Blade source');

            // Set a flag to indicate this page has a Duo-enabled component
            // This will be used by @duoMeta to conditionally enable offline caching
            app()->instance('duo.has_enabled_component', true);

            // Get Blade source file path
            $bladePath = $view->getPath();
            if (! $bladePath || ! file_exists($bladePath)) {
                \Log::warning('[Duo] Could not find Blade source file');

                return null;
            }

            $bladeSource = file_get_contents($bladePath);
            \Log::info('[Duo] Read Blade source', [
                'path' => $bladePath,
                'length' => strlen($bladeSource),
            ]);

            // Get view data and computed properties
            $viewData = method_exists($view, 'getData') ? $view->getData() : [];
            $computedProperties = $this->getComputedProperties($component);
            $allData = array_merge($properties, $viewData, $computedProperties);

            \Log::info('[Duo] Combined data for transformation', ['keys' => array_keys($allData)]);

            // Transform the Blade source (add x-show wrappers for @if/@else)
            $transformer = new \JoshCirre\Duo\BladeToAlpineTransformer(
                $bladeSource,
                null, // No rendered HTML yet
                $allData,
                [],
                $component,
                null
            );

            $transformedBlade = $transformer->transformBladeSource();
            \Log::info('[Duo] Transformed Blade source', [
                'originalLength' => strlen($bladeSource),
                'transformedLength' => strlen($transformedBlade),
            ]);

            // Return a finisher callback that renders the transformed Blade and does HTML transformations
            return function ($html, $replaceHtml = null, $viewContext = null) use ($transformedBlade, $allData, $component) {
                \Log::info('[Duo] Finisher callback - rendering transformed Blade');

                try {
                    // Render the transformed Blade with the component data
                    $renderedHtml = \Blade::render($transformedBlade, $allData);

                    \Log::info('[Duo] Rendered transformed Blade', [
                        'length' => strlen($renderedHtml),
                    ]);

                    // Now do HTML-level transformations (wire:model, loops, etc.)
                    $componentMethods = $this->getComponentMethods($component);

                    $transformer = new \JoshCirre\Duo\BladeToAlpineTransformer(
                        $transformedBlade,
                        $renderedHtml,
                        $allData,
                        $componentMethods,
                        $component,
                        null
                    );

                    return $transformer->transformHtml();
                } catch (\Exception $e) {
                    \Log::error('[Duo] Transformation failed: '.$e->getMessage(), [
                        'exception' => $e,
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Return original HTML if transformation fails
                    return $html;
                }
            };
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

        return in_array(Syncable::class, class_uses_recursive($class), true);
    }

    /**
     * Get public methods from the Livewire component (simple signature analysis).
     */
    protected function getComponentMethods($component): array
    {
        $reflection = new \ReflectionClass($component);
        $methods = [];

        // Lifecycle and internal methods to exclude
        $excludedMethods = [
            'render', 'mount', 'hydrate', 'dehydrate', 'boot', 'booted',
            'updating', 'updated', 'rendering', 'rendered',
            '__construct', '__get', '__set', '__call', '__toString',
        ];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip if it's from parent classes (Livewire Component)
            if ($method->getDeclaringClass()->getName() !== get_class($component)) {
                continue;
            }

            // Skip excluded methods
            if (in_array($method->getName(), $excludedMethods)) {
                continue;
            }

            // Skip computed properties (methods with Computed attribute)
            $hasComputedAttribute = false;
            foreach ($method->getAttributes() as $attribute) {
                $attributeName = $attribute->getName();
                if ($attributeName === 'Livewire\Attributes\Computed' ||
                    str_ends_with($attributeName, '\Computed')) {
                    $hasComputedAttribute = true;
                    break;
                }
            }

            if ($hasComputedAttribute) {
                continue;
            }

            // Extract validation rules from the method
            $validationRules = $this->extractValidationFromMethod($method, $component);

            $methods[] = [
                'name' => $method->getName(),
                'parameters' => $method->getParameters(),
                'validation' => $validationRules,
            ];
        }

        return $methods;
    }

    /**
     * Extract validation rules from a method by parsing the source code
     */
    protected function extractValidationFromMethod(\ReflectionMethod $method, $component): array
    {
        $fileName = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        if (! $fileName || ! $startLine || ! $endLine) {
            return [];
        }

        // Read the method source
        $file = new \SplFileObject($fileName);
        $file->seek($startLine - 1);

        $methodSource = '';
        for ($i = $startLine; $i <= $endLine; $i++) {
            $methodSource .= $file->current();
            $file->next();
        }

        // Parse validation rules using regex
        $validationRules = [];

        // Match: $this->validate([...]) - use greedy match to get all content
        if (preg_match('/\$this->validate\(\s*\[(.+?)\]\s*\)/s', $methodSource, $matches)) {
            $rulesString = $matches[1];

            // Match: 'field' => ['rule1', 'rule2'] or 'field' => 'rule'
            // Improved regex to handle nested arrays
            if (preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*(\[[^\]]*\]|[\'"][^\'\"]*[\'"])/s', $rulesString, $ruleMatches, PREG_SET_ORDER)) {
                foreach ($ruleMatches as $match) {
                    $field = $match[1];
                    $rules = $match[2];

                    // Parse the rules
                    if (str_starts_with($rules, '[')) {
                        // Array of rules - extract each rule from the array
                        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $rules, $ruleItems);
                        $validationRules[$field] = $ruleItems[1];
                    } else {
                        // Single rule string
                        $rule = trim($rules, '\'"');
                        $validationRules[$field] = explode('|', $rule);
                    }
                }
            }
        }

        \Log::info('[Duo] Extracted validation rules', [
            'method' => $method->getName(),
            'rules' => $validationRules,
            'methodSource' => substr($methodSource, 0, 500),
        ]);

        return $validationRules;
    }

    /**
     * Get computed properties from a Livewire component.
     */
    protected function getComputedProperties($component): array
    {
        $computed = [];
        $reflection = new \ReflectionClass($component);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip if not from the current class
            if ($method->getDeclaringClass()->getName() !== get_class($component)) {
                continue;
            }

            // Check if method has Computed attribute
            $attributes = $method->getAttributes();
            $hasComputedAttribute = false;

            foreach ($attributes as $attribute) {
                $attributeName = $attribute->getName();
                if ($attributeName === 'Livewire\Attributes\Computed' ||
                    str_ends_with($attributeName, '\Computed')) {
                    $hasComputedAttribute = true;
                    break;
                }
            }

            if ($hasComputedAttribute) {
                $methodName = $method->getName();
                try {
                    // Access the computed property through the component
                    // Livewire allows accessing computed methods as properties
                    $propertyName = $methodName;

                    // Try to access it - Livewire's __get will call the method
                    if (property_exists($component, $propertyName) || method_exists($component, '__get')) {
                        $value = $component->$propertyName;

                        // Handle Eloquent relationships and collections
                        if ($value instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            // If it's a relationship, call get() to convert to collection
                            $value = $value->get();
                        }

                        // Keep models and collections as objects for transformation
                        // They will be converted to arrays during Alpine x-data generation
                        $computed[$propertyName] = $value;
                        \Log::info("[Duo] Extracted computed property: $propertyName", ['type' => gettype($value)]);
                    }
                } catch (\Exception $e) {
                    \Log::warning("[Duo] Failed to extract computed property $methodName: ".$e->getMessage());
                }
            }
        }

        return $computed;
    }

    /**
     * Capture SQL queries executed by a method.
     */
    protected function captureMethodQueries($component, string $methodName, array $parameters, array $componentData): array
    {
        try {
            // Enable query logging
            \DB::enableQueryLog();
            \DB::flushQueryLog();

            // Try to execute the method in a transaction we'll roll back
            \DB::beginTransaction();

            try {
                // Prepare dummy arguments based on parameter types
                $args = [];
                foreach ($parameters as $param) {
                    if ($param->hasType() && $param->getType()->getName() === 'int') {
                        $args[] = 999999; // Dummy ID that won't exist
                    } elseif ($param->hasType() && $param->getType()->getName() === 'string') {
                        $args[] = 'dummy_string';
                    } else {
                        $args[] = null;
                    }
                }

                // For methods without parameters, set component properties to dummy values
                if (empty($args)) {
                    // Clone component to avoid modifying the real one
                    $testComponent = clone $component;

                    // Set dummy values for properties that look like form inputs
                    foreach ($componentData as $key => $value) {
                        if (str_starts_with($key, 'new')) {
                            $testComponent->$key = 'dummy_value';
                        }
                    }

                    $testComponent->$methodName(...$args);
                } else {
                    // Call with dummy args
                    $testComponent = clone $component;
                    $testComponent->$methodName(...$args);
                }
            } catch (\Exception $e) {
                // Expected to fail, we just want to capture queries
                \Log::info("[Duo] Method $methodName threw exception (expected): ".$e->getMessage());
            }

            // Always rollback
            \DB::rollBack();

            // Get the queries
            $queries = \DB::getQueryLog();
            \Log::info("[Duo] Captured queries for $methodName", ['queries' => $queries]);

            return $queries;

        } catch (\Exception $e) {
            \Log::error("[Duo] Failed to capture queries for $methodName: ".$e->getMessage());

            return [];
        } finally {
            // Ensure we're not in a transaction
            if (\DB::transactionLevel() > 0) {
                \DB::rollBack();
            }
        }
    }

    /**
     * Capture SQL queries from the render method.
     */
    protected function captureRenderQueries($component): array
    {
        try {
            \DB::enableQueryLog();
            \DB::flushQueryLog();

            // Call render method
            $component->render();

            $queries = \DB::getQueryLog();

            return $queries;
        } catch (\Exception $e) {
            \Log::error('[Duo] Failed to capture render queries: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Extract ORDER BY from computed property methods by analyzing their queries
     */
    protected function extractOrderByFromComputedProperties($component, array $allData): ?array
    {
        $orderByInfo = [];
        $reflection = new \ReflectionClass($component);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip if not from the current class
            if ($method->getDeclaringClass()->getName() !== get_class($component)) {
                continue;
            }

            // Check if method has Computed attribute
            $attributes = $method->getAttributes();
            $hasComputedAttribute = false;

            foreach ($attributes as $attribute) {
                $attributeName = $attribute->getName();
                if ($attributeName === 'Livewire\Attributes\Computed' ||
                    str_ends_with($attributeName, '\Computed')) {
                    $hasComputedAttribute = true;
                    break;
                }
            }

            if ($hasComputedAttribute) {
                $methodName = $method->getName();

                // Enable query logging
                \DB::enableQueryLog();
                \DB::flushQueryLog();

                try {
                    // Execute the method to capture queries
                    $component->$methodName;

                    // Get the queries
                    $queries = \DB::getQueryLog();

                    // Extract orderBy from queries
                    $orderBy = $this->extractOrderBy($queries);

                    if ($orderBy) {
                        // Map this orderBy to the property name (collection name)
                        $orderByInfo[$methodName] = $orderBy;
                    }
                } catch (\Exception $e) {
                    \Log::warning("[Duo] Failed to extract orderBy for $methodName: ".$e->getMessage());
                }

                \DB::flushQueryLog();
            }
        }

        return $orderByInfo ?: null;
    }

    /**
     * Extract ORDER BY clause from SQL query.
     */
    protected function extractOrderBy(array $queries): ?array
    {
        foreach ($queries as $query) {
            $sql = $query['query'];

            // Look for ORDER BY clause: order by `created_at` desc OR "created_at" desc
            if (preg_match('/order\s+by\s+[`"]?(\w+)[`"]?\s+(asc|desc)/i', $sql, $matches)) {
                return [
                    'column' => $matches[1],
                    'direction' => strtolower($matches[2]),
                ];
            }
        }

        return null;
    }

    /**
     * Parse SQL queries to determine the primary operation type.
     */
    protected function parseQueriesForOperation(array $queries): string
    {
        $hasInsert = false;
        $hasUpdate = false;
        $hasDelete = false;
        $hasSelect = false;

        foreach ($queries as $query) {
            $sql = strtolower($query['query']);

            if (str_starts_with($sql, 'insert')) {
                $hasInsert = true;
            } elseif (str_starts_with($sql, 'update')) {
                $hasUpdate = true;
            } elseif (str_starts_with($sql, 'delete')) {
                $hasDelete = true;
            } elseif (str_starts_with($sql, 'select')) {
                $hasSelect = true;
            }
        }

        // Determine primary operation
        if ($hasInsert) {
            return 'insert';
        }
        if ($hasUpdate) {
            return 'update';
        }
        if ($hasDelete) {
            return 'delete';
        }
        if ($hasSelect) {
            return 'select';
        }

        return 'unknown';
    }

    /**
     * Extract columns being inserted/updated from SQL query.
     */
    protected function extractColumnsFromQuery(string $sql, array $bindings): array
    {
        $columns = [];

        // Parse INSERT query: insert into `todos` (`title`, `description`, `completed`, ...)
        if (preg_match('/insert\s+into\s+`?\w+`?\s*\(([^)]+)\)/i', $sql, $matches)) {
            $columnList = $matches[1];
            $columnList = str_replace(['`', ' '], '', $columnList);
            $columns = explode(',', $columnList);
        }

        // Parse UPDATE query: update `todos` set `title` = ?, `completed` = ?
        elseif (preg_match_all('/`?(\w+)`?\s*=\s*\?/i', $sql, $matches)) {
            $columns = $matches[1];
        }

        return $columns;
    }

    /**
     * Generate Alpine method implementations for Livewire methods.
     */
    protected function generateAlpineMethods(array $methods, array $data, array $renderQueries = []): string
    {
        if (empty($methods)) {
            return '';
        }

        // Determine the store name from the data
        $storeName = 'App_Models_Todo'; // Default for now
        $dataKey = 'todos'; // Default
        foreach ($data as $key => $value) {
            if (is_array($value) || (is_object($value) && method_exists($value, 'toArray'))) {
                $dataKey = $key;
                // Assume the key is plural, convert to singular and capitalize
                $singular = rtrim($key, 's');
                $storeName = 'App_Models_'.ucfirst($singular);
                break;
            }
        }

        // Extract ORDER BY from render queries
        $orderBy = $this->extractOrderBy($renderQueries);
        \Log::info('[Duo] Extracted ORDER BY', ['orderBy' => $orderBy]);

        // Extract form field properties (exclude arrays/collections which are the data lists)
        // Only include STRING properties as form fields (exclude booleans, integers, etc.)
        $formFields = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                // This is a string property, likely a form field
                if (! str_starts_with($key, '$') && $key !== 'id') {
                    $formFields[] = $key;
                }
            }
        }

        \Log::info('[Duo] Detected form fields', ['fields' => $formFields]);

        $alpineMethods = '';

        foreach ($methods as $method) {
            $methodName = $method['name'];
            $params = $method['parameters'];

            // Build parameter list
            $paramList = [];
            foreach ($params as $param) {
                $paramList[] = $param->getName();
            }
            $paramString = implode(', ', $paramList);

            // Skip methods with no parameters or non-model parameters (like toggleComparison)
            // Only generate CRUD methods for methods that accept model instances
            $hasModelParameter = false;
            foreach ($params as $param) {
                $type = $param->getType();
                if ($type && ! $type->isBuiltin()) {
                    $hasModelParameter = true;
                    break;
                }
            }

            // Skip if this method doesn't operate on a model (e.g., toggleComparison())
            if (! $hasModelParameter && (str_starts_with($methodName, 'toggle') || str_starts_with($methodName, 'update'))) {
                continue;
            }

            // Determine operation based on method naming convention
            if (str_starts_with($methodName, 'add') || str_starts_with($methodName, 'create')) {
                // CREATE operation - use detected form fields
                $primaryField = $formFields[0] ?? 'title'; // Use first field or default to 'title'

                // Build validation check (first field is required)
                $validation = "if (!this.{$primaryField} || this.{$primaryField}.trim().length < 3) {
                            alert('".ucfirst($primaryField)." must be at least 3 characters');
                            return;
                        }";

                // Build newItem object dynamically from form fields
                $newItemFields = 'id: Date.now()';
                $resetFields = '';
                foreach ($formFields as $field) {
                    $newItemFields .= ",\n                            {$field}: this.{$field}";
                    if ($field !== $primaryField) {
                        $newItemFields .= " ? this.{$field}.trim() : ''";
                    } else {
                        $newItemFields .= '.trim()';
                    }
                    $resetFields .= "\n                        this.{$field} = '';";
                }

                $alpineMethods .= "async {$methodName}({$paramString}) {
                        if (!window.duo) return;

                        // Simple client-side validation
                        {$validation}

                        const db = window.duo.getDatabase();
                        const syncQueue = window.duo.getSyncQueue();
                        const store = db.getStore('{$storeName}');
                        if (!store || !syncQueue) return;

                        const newItem = {
                            {$newItemFields},
                            completed: false,
                            created_at: new Date().toISOString(),
                            updated_at: new Date().toISOString(),
                        };

                        // Optimistically add to IndexedDB
                        await store.put({ ...newItem, _duo_pending_sync: true });
                        console.log('[Duo] Created item locally:', newItem);

                        // Enqueue for background sync
                        await syncQueue.enqueue({
                            storeName: '{$storeName}',
                            operation: 'create',
                            data: newItem
                        });
                        console.log('[Duo] Enqueued create operation for background sync');

                        // Reset form fields{$resetFields}

                        // Refresh UI immediately (optimistic update)
                        await this.duoSync();
                    },\n";
            } elseif (str_starts_with($methodName, 'toggle') || str_starts_with($methodName, 'update')) {
                // UPDATE operation
                $alpineMethods .= "async {$methodName}({$paramString}) {
                        if (!window.duo) return;
                        const db = window.duo.getDatabase();
                        const syncQueue = window.duo.getSyncQueue();
                        const store = db.getStore('{$storeName}');
                        if (!store || !syncQueue) return;

                        const item = await store.get({$paramString});
                        if (!item) return;

                        const updatedItem = {
                            ...item,
                            completed: !item.completed,
                            updated_at: new Date().toISOString(),
                        };

                        // Optimistically update in IndexedDB
                        await store.put({ ...updatedItem, _duo_pending_sync: true });
                        console.log('[Duo] Updated item locally:', {$paramString});

                        // Enqueue for background sync
                        await syncQueue.enqueue({
                            storeName: '{$storeName}',
                            operation: 'update',
                            data: updatedItem
                        });
                        console.log('[Duo] Enqueued update operation for background sync');

                        // Refresh UI immediately (optimistic update)
                        await this.duoSync();
                    },\n";
            } elseif (str_starts_with($methodName, 'delete') || str_starts_with($methodName, 'remove')) {
                // DELETE operation
                $alpineMethods .= "async {$methodName}({$paramString}) {
                        if (!window.duo) return;
                        const db = window.duo.getDatabase();
                        const syncQueue = window.duo.getSyncQueue();
                        const store = db.getStore('{$storeName}');
                        if (!store || !syncQueue) return;

                        // Get the item before deleting (need it for sync)
                        const item = await store.get({$paramString});
                        if (!item) return;

                        // Optimistically delete from IndexedDB
                        await store.delete({$paramString});
                        console.log('[Duo] Deleted item locally:', {$paramString});

                        // Enqueue for background sync
                        await syncQueue.enqueue({
                            storeName: '{$storeName}',
                            operation: 'delete',
                            data: item
                        });
                        console.log('[Duo] Enqueued delete operation for background sync');

                        // Refresh UI immediately (optimistic update)
                        await this.duoSync();
                    },\n";
            }
        }

        // Add duoSync() method at the end with proper sorting
        $sortCode = '';
        if ($orderBy) {
            $sortCode = "
                        // Sort items based on ORDER BY from SQL
                        items = items.sort((a, b) => {
                            const aVal = a['{$orderBy['column']}'];
                            const bVal = b['{$orderBy['column']}'];
                            if (aVal < bVal) return ".($orderBy['direction'] === 'asc' ? '-1' : '1').';
                            if (aVal > bVal) return '.($orderBy['direction'] === 'asc' ? '1' : '-1').';
                            return 0;
                        });';
        }

        $alpineMethods .= "async duoSync() {
                        // Load from IndexedDB to component
                        if (!window.duo) return;
                        const db = window.duo.getDatabase();
                        if (!db) return;
                        const store = db.getStore('{$storeName}');
                        if (!store) return;
                        let items = await store.toArray();
                        console.log('[Duo] Loading', items.length, 'items from IndexedDB');
                        {$sortCode}

                        this.{$dataKey} = items;
                    },\n";

        return $alpineMethods;
    }

    /**
     * Register Blade directives for Duo
     */
    protected function registerBladeDirectives(): void
    {
        // @duoMeta - Injects the cache meta tag for offline page caching and CSRF token
        // Only enables offline caching if the page has a component with the WithDuo trait
        \Blade::directive('duoMeta', function () {
            return '<?php
                echo \'<meta name="csrf-token" content="\' . csrf_token() . \'">\';
                // Only add duo-cache meta tag if page has a Duo-enabled component
                if (app()->has(\'duo.has_enabled_component\') && app(\'duo.has_enabled_component\')) {
                    echo "\n" . \'<meta name="duo-cache" content="true" data-duo-version="1.0">\';
                }
            ?>';
        });
    }
}
