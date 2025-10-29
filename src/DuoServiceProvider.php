<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use JoshCirre\Duo\Commands\DiscoverModelsCommand;
use JoshCirre\Duo\Commands\GenerateManifestCommand;
use JoshCirre\Duo\Http\Controllers\DuoSyncController;
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

        // Hook into Livewire's render event to transform HTML for Duo components
        $eventBus->on('render', function ($component, $view, $properties) {
            \Log::info('[Duo] Render event triggered for component: '.get_class($component));
            // Check if component uses WithDuo trait
            if (! in_array(\JoshCirre\Duo\WithDuo::class, class_uses_recursive($component))) {
                \Log::info('[Duo] Component does NOT use WithDuo trait');

                return null;
            }

            \Log::info('[Duo] Component DOES use Duo trait - will transform HTML');

            // Return a finisher callback that will transform the HTML
            return function ($html, $replaceHtml = null, $viewContext = null) use ($properties, $view, $component) {
                // Get ALL queries that have been logged so far (includes render queries)
                $allQueries = \DB::getQueryLog();

                // Filter to just SELECT queries (render queries)
                $renderQueries = array_filter($allQueries, function ($q) {
                    return stripos($q['query'], 'select') === 0;
                });

                \Log::info('[Duo] Render queries', ['queries' => $renderQueries]);
                \Log::info('[Duo] Finisher callback called with HTML length: '.strlen($html));

                // Get view data (which includes 'todos')
                $viewData = method_exists($view, 'getData') ? $view->getData() : [];

                // Get computed properties from the component
                $computedProperties = $this->getComputedProperties($component);
                \Log::info('[Duo] Computed properties found', ['properties' => array_keys($computedProperties)]);

                // Combine view data, component properties, and computed properties
                $allData = array_merge($properties, $viewData, $computedProperties);
                \Log::info('[Duo] Combined data', ['keys' => array_keys($allData)]);

                // Detect public methods on the component (simple signature analysis)
                $componentMethods = $this->getComponentMethods($component);
                \Log::info('[Duo] Component methods detected', ['count' => count($componentMethods)]);

                // Replace wire:model with x-model for Alpine
                $html = preg_replace(
                    '/wire:model="([^"]+)"/i',
                    'x-model="$1"',
                    $html
                );

                // Replace wire:submit with Alpine @submit (for forms outside loops)
                $html = preg_replace(
                    '/wire:submit(?:\.prevent)?="([^"]+)"/i',
                    '@submit.prevent="$1"',
                    $html
                );

                // Replace wire:click with Alpine @click (for buttons outside loops)
                $html = preg_replace(
                    '/wire:click="([^"]+)"/i',
                    '@click.prevent="$1"',
                    $html
                );

                // Prepare component data for Alpine
                $alpineDataJson = json_encode($allData);

                // Generate Alpine method implementations
                $alpineMethods = $this->generateAlpineMethods($componentMethods, $allData, $renderQueries);

                // Build the x-data object by combining data properties and methods
                // We'll build it as a proper JavaScript object instead of using spread
                $dataProperties = [];
                foreach ($allData as $key => $value) {
                    $dataProperties[] = $key.': '.json_encode($value);
                }
                $dataPropertiesString = implode(",\n                    ", $dataProperties);

                // Inject Duo sync methods and component methods directly into x-data
                // This gives the component access to its reactive data via 'this'
                // Note: $alpineMethods now includes duoSync() with proper sorting
                $xDataContent = '{
                    '.$dataPropertiesString.',
                    duoLoading: true,
                    duoReady: false,
                    timeAgo(dateString) {
                        const date = new Date(dateString);
                        const now = new Date();
                        const seconds = Math.floor((now - date) / 1000);

                        const intervals = {
                            year: 31536000,
                            month: 2592000,
                            week: 604800,
                            day: 86400,
                            hour: 3600,
                            minute: 60,
                            second: 1
                        };

                        for (const [unit, secondsInUnit] of Object.entries(intervals)) {
                            const interval = Math.floor(seconds / secondsInUnit);
                            if (interval >= 1) {
                                return interval === 1 ? `1 ${unit} ago` : `${interval} ${unit}s ago`;
                            }
                        }

                        return \'just now\';
                    },
                    '.$alpineMethods.'
                    async syncServerToIndexedDB() {
                        try {
                            console.log(\'[Duo] syncServerToIndexedDB started\');

                            // Wait for window.duo to be available (with timeout)
                            let attempts = 0;
                            const maxAttempts = 100; // 10 seconds total
                            while (!window.duo && attempts < maxAttempts) {
                                await new Promise(resolve => setTimeout(resolve, 100));
                                attempts++;
                                if (attempts % 10 === 0) {
                                    console.log(\'[Duo] Still waiting for window.duo...\', attempts, \'/\', maxAttempts);
                                }
                            }

                            // Sync initial server data to IndexedDB (server is source of truth on load)
                            if (!window.duo) {
                                console.error(\'[Duo] window.duo not available after\', maxAttempts * 100, \'ms\');
                                return;
                            }

                            console.log(\'[Duo] window.duo is available!\');

                            const db = window.duo.getDatabase();
                            if (!db) {
                                console.warn(\'[Duo] Database not available\');
                                return;
                            }

                            const store = db.getStore(\'App_Models_Todo\');
                            if (!store) {
                                console.warn(\'[Duo] Store not found\');
                                return;
                            }

                            // Get todos from the initial server data
                            const serverTodos = this.todos || [];
                            console.log(\'[Duo] Server has\', serverTodos.length, \'todos:\', serverTodos);

                            if (serverTodos.length === 0) {
                                console.log(\'[Duo] No server todos to sync\');
                                return;
                            }

                            console.log(\'[Duo] Clearing IndexedDB...\');
                            await store.clear();

                            console.log(\'[Duo] Writing\', serverTodos.length, \'todos to IndexedDB...\');
                            await store.bulkPut(serverTodos.map(item => ({
                                ...item,
                                _duo_synced_at: Date.now(),
                                _duo_pending_sync: false
                            })));

                            console.log(\'[Duo] ✅ Server data synced to IndexedDB successfully\');
                        } catch (error) {
                            console.error(\'[Duo] Error syncing server data to IndexedDB:\', error);
                        }
                    },
                    async init() {
                        console.log(\'[Duo] Component init() called\');
                        this.duoLoading = true;

                        // First sync server data to IndexedDB, then load it back
                        await this.syncServerToIndexedDB();
                        await this.duoSync();

                        // Mark as ready (removes loading state)
                        this.duoLoading = false;
                        this.duoReady = true;

                        console.log(\'[Duo] Component initialization complete\');
                    }
                }';

                // Add duo-enabled and Alpine x-data to the root element
                $html = preg_replace(
                    '/^(<[^>]+?)>/',
                    '$1 data-duo-enabled="true" x-data="'.htmlspecialchars($xDataContent, ENT_QUOTES, 'UTF-8').'">',
                    $html,
                    1
                );

                // Transform forelse loops to Alpine x-for for reactive rendering
                $html = $this->transformBladeLoopsToAlpine($html, $allData, $view);

                return $html;
            };
        });
    }

    /**
     * Transform Blade-rendered loops to Alpine x-for templates for reactive rendering.
     */
    protected function transformBladeLoopsToAlpine(string $html, array $properties, $view): string
    {
        \Log::info('[Duo] transformBladeLoopsToAlpine called', [
            'properties_count' => count($properties),
            'property_keys' => array_keys($properties),
            'property_types' => array_map('gettype', $properties),
        ]);

        // Get the Blade template path
        $bladePath = $view->getPath();
        \Log::info('[Duo] Blade template path: '.$bladePath);

        if (! file_exists($bladePath)) {
            \Log::info('[Duo] Blade template file not found');

            return $html;
        }

        // Read the Blade template source
        $bladeSource = file_get_contents($bladePath);
        \Log::info('[Duo] Blade source length: '.strlen($bladeSource));

        // Find array properties (these are likely from forelse/foreach loops)
        // Note: Check for both arrays and Collections (Eloquent results)
        $arrayProperties = [];
        foreach ($properties as $key => $value) {
            $isArrayLike = is_array($value) || (is_object($value) && method_exists($value, 'toArray'));

            if ($isArrayLike) {
                // Convert Collections to arrays
                $arrayValue = is_array($value) ? $value : $value->toArray();

                // Include ALL array properties, even if empty
                // Empty arrays still need transformation for reactive updates
                $arrayProperties[$key] = $arrayValue;
            }
        }

        \Log::info('[Duo] Found array properties', ['count' => count($arrayProperties), 'keys' => array_keys($arrayProperties)]);

        if (empty($arrayProperties)) {
            \Log::info('[Duo] No array properties found - skipping transformation');

            return $html; // No arrays to transform
        }

        // For each array property, parse the Blade template and transform to Alpine
        foreach ($arrayProperties as $propName => $items) {
            $html = $this->convertBladeLoopToAlpine($html, $bladeSource, $propName, $items);
        }

        return $html;
    }

    /**
     * Convert a Blade loop to Alpine x-for by parsing the Blade template source.
     */
    protected function convertBladeLoopToAlpine(string $html, string $bladeSource, string $propName, array $items): string
    {
        // Note: We must transform even if items is empty
        // This ensures reactive updates work when items are added later

        // Find @forelse or @foreach block in Blade source
        // Pattern supports both:
        // - @foreach($todos as $todo) ... @endforeach (direct or computed)
        // - @forelse($todos as $todo) ... @empty ... @endforelse (direct or computed)
        // - Both $todos and $this->todos are supported
        $foreachPattern = '/@(?:forelse|foreach)\(\$(?:this->)?'.$propName.'\s+as\s+\$(\w+)\)(.*?)@(?:empty|endforelse|endforeach)/s';

        if (! preg_match($foreachPattern, $bladeSource, $bladeMatch)) {
            \Log::info('[Duo] Could not find @forelse or @foreach for: '.$propName);

            return $html;
        }

        $itemVarName = $bladeMatch[1]; // e.g., "todo"
        $bladeTemplate = $bladeMatch[2]; // The content between @forelse and @empty

        \Log::info('[Duo] Found Blade loop', [
            'propName' => $propName,
            'itemVarName' => $itemVarName,
            'template_length' => strlen($bladeTemplate),
        ]);

        // Find the @empty content
        $emptyPattern = '/@(?:forelse|foreach)\(\$'.$propName.'\s+as\s+\$\w+\).*?@empty(.*?)@endforelse/s';
        $emptyContent = '';
        if (preg_match($emptyPattern, $bladeSource, $emptyMatch)) {
            $emptyContent = trim($emptyMatch[1]);
            \Log::info('[Duo] Found @empty content, length: '.strlen($emptyContent));
        }

        // Find the rendered loop container in HTML
        // Look for the parent div that wraps the foreach content
        // We need to match ONLY the div that contains the BLOCK comments
        // IMPORTANT: Make BLOCK comments required to avoid matching wrong divs
        $containerPattern = '/(<div[^>]*class="[^"]*(?:flex|space-y|grid)[^"]*"[^>]*>)\s*<!--\[if BLOCK\]><!\[endif\]-->\s*(.*?)\s*<!--\[if ENDBLOCK\]><!\[endif\]-->\s*(<\/div>)/s';

        if (preg_match($containerPattern, $html, $containerMatch)) {
            \Log::info('[Duo] Found rendered container in HTML', [
                'matched_html_length' => strlen($containerMatch[0]),
                'matched_opening_tag' => $containerMatch[1],
            ]);

            // Transform Blade template to Alpine
            $alpineTemplate = $this->transformBladeToAlpine($bladeTemplate, $itemVarName);

            // Add x-cloak and x-show="duoReady" to the container div
            $containerOpenTag = preg_replace('/^(<\w+)/', '$1 x-cloak x-show="duoReady"', $containerMatch[1]);

            // Build the Alpine x-for template
            $replacement = $containerOpenTag."\n".
                "    <template x-for=\"{$itemVarName} in {$propName}\" :key=\"{$itemVarName}.id\">\n".
                '        '.trim($alpineTemplate)."\n".
                "    </template>\n";

            // Add empty state if we found @empty content
            if ($emptyContent) {
                $replacement .= '    '.trim($emptyContent)."\n";
                // Add x-show to make it conditional
                $replacement = str_replace(
                    trim($emptyContent),
                    preg_replace('/^<(\w+)/', '<$1 x-show="!'.$propName.' || '.$propName.'.length === 0"', trim($emptyContent)),
                    $replacement
                );
            }

            $replacement .= $containerMatch[3]; // closing </div>

            // Use preg_replace instead of str_replace for more reliable matching
            // This replaces only the FIRST occurrence to avoid affecting other similar divs
            $html = preg_replace($containerPattern, $replacement, $html, 1);
            \Log::info('[Duo] Successfully transformed Blade loop to Alpine', [
                'replacement_length' => strlen($replacement),
            ]);
        } else {
            \Log::info('[Duo] Could not find rendered container in HTML');
        }

        // Transform Livewire forms to Alpine
        $html = $this->transformFormsToAlpine($html);

        return $html;
    }

    /**
     * Transform Blade template syntax to Alpine.js bindings.
     */
    protected function transformBladeToAlpine(string $bladeTemplate, string $itemVarName): string
    {
        \Log::info('[Duo] Transforming Blade to Alpine', ['itemVarName' => $itemVarName]);

        $template = $bladeTemplate;

        // 0. Transform Alpine bindings that use Blade variables (e.g., :checked="$todo->completed")
        // This handles Flux components that already use Alpine syntax
        $template = preg_replace(
            '/:(\w+)="\$'.$itemVarName.'->(\w+)"/i',
            ':$1="'.$itemVarName.'.$2"',
            $template
        );

        // 1. Transform wire:click="method({{ $todo->id }})" to Alpine @click with direct method call
        $template = preg_replace(
            '/wire:click="(\w+)\(\{\{\s*\$'.$itemVarName.'->id\s*\}\}\)"/i',
            '@click.prevent="$1('.$itemVarName.'.id)"',
            $template
        );

        // Also handle wire:submit with direct method call
        $template = preg_replace(
            '/wire:submit(?:\.prevent)?="(\w+)\(\{\{\s*\$'.$itemVarName.'->id\s*\}\}\)"/i',
            '@submit.prevent="$1('.$itemVarName.'.id)"',
            $template
        );

        // Handle wire:submit without parameters (e.g., for addTodo)
        $template = preg_replace(
            '/wire:submit(?:\.prevent)?="(\w+)"/i',
            '@submit.prevent="$1()"',
            $template
        );

        // 2. Transform {{ $todo->completed ? 'checked' : '' }} to :checked="todo.completed"
        $template = preg_replace(
            '/\{\{\s*\$'.$itemVarName.'->completed\s*\?\s*[\'"]checked[\'"]\s*:\s*[\'"][\'"]\s*\}\}/',
            ':checked="'.$itemVarName.'.completed"',
            $template
        );

        // 3. Transform class="{{ $todo->completed ? 'classes' : '' }}" to :class
        $template = preg_replace_callback(
            '/class="([^"]*)\{\{\s*\$'.$itemVarName.'->(\w+)\s*\?\s*[\'"]([^\'"]+)[\'"]\s*:\s*[\'"][\'"]\s*\}\}([^"]*)"/i',
            function ($matches) use ($itemVarName) {
                $staticClasses = trim($matches[1].' '.$matches[4]);
                $conditionalClasses = $matches[3];
                $property = $matches[2];

                return 'class="'.$staticClasses.'" :class="{ \''.$conditionalClasses.'\': '.$itemVarName.'.'.$property.' }"';
            },
            $template
        );

        // 4. Transform {{ $todo->created_at->diffForHumans() }} FIRST (before general property transform)
        // Handle any date field with diffForHumans()
        $template = preg_replace(
            '/\{\{\s*\$'.$itemVarName.'->(\w+)->diffForHumans\(\)\s*\}\}/',
            '<span x-text="timeAgo('.$itemVarName.'.$1)"></span>',
            $template
        );

        // 5. Transform {{ $todo->title }} to x-text="todo.title" (for text content)
        $template = preg_replace(
            '/\{\{\s*\$'.$itemVarName.'->(\w+)\s*\}\}/',
            '<span x-text="'.$itemVarName.'.$1"></span>',
            $template
        );

        // 6. Transform @if($todo->description) to x-show="todo.description"
        $template = preg_replace(
            '/@if\(\$'.$itemVarName.'->(\w+)\)(.*?)@endif/s',
            '<template x-if="'.$itemVarName.'.$1">$2</template>',
            $template
        );

        \Log::info('[Duo] Blade to Alpine transformation complete', ['result_length' => strlen($template)]);

        return $template;
    }

    /**
     * Transform Livewire forms to use Alpine instead.
     * Converts wire:submit and wire:model to Alpine equivalents.
     */
    protected function transformFormsToAlpine(string $html): string
    {
        \Log::info('[Duo] Transforming forms to Alpine');

        // Transform wire:submit="methodName" to @submit.prevent="methodName()"
        $html = preg_replace(
            '/wire:submit(?:\.prevent)?="(\w+)"/i',
            'x-on:submit.prevent="$1()"',
            $html
        );

        // Transform wire:model="propertyName" to x-model="propertyName"
        $html = preg_replace(
            '/wire:model(?:\.\w+)?="(\w+)"/i',
            'x-model="$1"',
            $html
        );

        // Transform wire:click="methodName" to @click.prevent="methodName()"
        // (for buttons and other clickable elements outside of loops)
        $html = preg_replace(
            '/wire:click(?:\.prevent)?="(\w+)"/i',
            'x-on:click.prevent="$1()"',
            $html
        );

        \Log::info('[Duo] Forms transformed to Alpine');

        return $html;
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

            $methods[] = [
                'name' => $method->getName(),
                'parameters' => $method->getParameters(),
            ];
        }

        return $methods;
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

                        if ($value instanceof \Illuminate\Database\Eloquent\Collection) {
                            // Convert collection to array for JSON serialization
                            $value = $value->toArray();
                        } elseif ($value instanceof \Illuminate\Database\Eloquent\Model) {
                            // Convert single model to array
                            $value = $value->toArray();
                        }

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
        $formFields = [];
        foreach ($data as $key => $value) {
            if (! is_array($value) && ! (is_object($value) && method_exists($value, 'toArray'))) {
                // This is a scalar property, likely a form field
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
        \Blade::directive('duoMeta', function () {
            return '<?php echo \'<meta name="csrf-token" content="\' . csrf_token() . \'">\' . "\n" . \'<meta name="duo-cache" content="true" data-duo-version="1.0">\'; ?>';
        });
    }
}
