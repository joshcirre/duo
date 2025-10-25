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
     * Register Livewire mechanism for transforming Duo component HTML.
     */
    protected function registerLivewireMechanism(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        \Log::info('[Duo] Registering EventBus listener for render event');

        // Hook into Livewire's render event to transform HTML for Duo components
        app(\Livewire\EventBus::class)->on('render', function ($component, $view, $properties) {
            \Log::info('[Duo] Render event triggered for component: ' . get_class($component));
            // Check if component uses Duo trait
            if (! in_array(\JoshCirre\Duo\Concerns\Duo::class, class_uses_recursive($component))) {
                \Log::info('[Duo] Component does NOT use Duo trait');
                return null;
            }

            \Log::info('[Duo] Component DOES use Duo trait - will transform HTML');

            // Return a finisher callback that will transform the HTML
            return function ($html, $replaceHtml = null, $viewContext = null) use ($properties, $view) {
                \Log::info('[Duo] Finisher callback called with HTML length: ' . strlen($html));

                // Get view data (which includes 'todos')
                $viewData = method_exists($view, 'getData') ? $view->getData() : [];

                // Combine view data and component properties
                $allData = array_merge($properties, $viewData);
                \Log::info('[Duo] Combined data', ['keys' => array_keys($allData)]);

                // Replace wire:model with name attributes for local-first form handling
                $html = preg_replace(
                    '/wire:model="([^"]+)"/i',
                    'name="$1" data-duo-model="$1"',
                    $html
                );

                // Replace wire:click with duo-action
                $html = preg_replace(
                    '/wire:click="([^"]+)"/i',
                    'data-duo-action="$1" data-duo-trigger="click"',
                    $html
                );

                // Replace wire:submit with duo-action
                $html = preg_replace(
                    '/wire:submit(?:\.prevent)?="([^"]+)"/i',
                    'data-duo-action="$1" data-duo-trigger="submit"',
                    $html
                );

                // Prepare component data for Alpine (encode as JSON for x-data)
                // Use allData which includes both component properties and view data (like todos)
                $alpineData = htmlspecialchars(json_encode($allData), ENT_QUOTES, 'UTF-8');

                // Add duo-enabled and Alpine x-data to the root element
                $html = preg_replace(
                    '/^(<[^>]+?)>/',
                    '$1 data-duo-enabled="true" x-data="window.Duo?.alpine?.(\$el, ' . $alpineData . ') || {}">',
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
            'property_types' => array_map('gettype', $properties)
        ]);

        // Get the Blade template path
        $bladePath = $view->getPath();
        \Log::info('[Duo] Blade template path: ' . $bladePath);

        if (!file_exists($bladePath)) {
            \Log::info('[Duo] Blade template file not found');
            return $html;
        }

        // Read the Blade template source
        $bladeSource = file_get_contents($bladePath);
        \Log::info('[Duo] Blade source length: ' . strlen($bladeSource));

        // Find array properties (these are likely from forelse/foreach loops)
        // Note: Check for both arrays and Collections (Eloquent results)
        $arrayProperties = [];
        foreach ($properties as $key => $value) {
            $isArrayLike = is_array($value) || (is_object($value) && method_exists($value, 'toArray'));

            if ($isArrayLike) {
                // Convert Collections to arrays
                $arrayValue = is_array($value) ? $value : $value->toArray();

                if (!empty($arrayValue)) {
                    $arrayProperties[$key] = $arrayValue;
                }
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
        if (empty($items)) {
            return $html;
        }

        // Find @forelse or @foreach block in Blade source
        // Pattern: @forelse($todos as $todo) ... @empty ... @endforelse
        $foreachPattern = '/@(?:forelse|foreach)\(\$' . $propName . '\s+as\s+\$(\w+)\)(.*?)@(?:empty|endforelse)/s';

        if (!preg_match($foreachPattern, $bladeSource, $bladeMatch)) {
            \Log::info('[Duo] Could not find @forelse or @foreach for: ' . $propName);
            return $html;
        }

        $itemVarName = $bladeMatch[1]; // e.g., "todo"
        $bladeTemplate = $bladeMatch[2]; // The content between @forelse and @empty

        \Log::info('[Duo] Found Blade loop', [
            'propName' => $propName,
            'itemVarName' => $itemVarName,
            'template_length' => strlen($bladeTemplate)
        ]);

        // Transform Blade template to Alpine
        $alpineTemplate = $this->transformBladeToAlpine($bladeTemplate, $itemVarName);

        // Find the @empty content
        $emptyPattern = '/@(?:forelse|foreach)\(\$' . $propName . '\s+as\s+\$\w+\).*?@empty(.*?)@endforelse/s';
        $emptyContent = '';
        if (preg_match($emptyPattern, $bladeSource, $emptyMatch)) {
            $emptyContent = trim($emptyMatch[1]);
            \Log::info('[Duo] Found @empty content, length: ' . strlen($emptyContent));
        }

        // Find the rendered loop container in HTML (the space-y-3 div or similar)
        // We'll look for the rendered content and replace it
        $containerPattern = '/(<div class="space-y-3">)\s*(<!--\[if BLOCK\]><!\[endif\]-->)?\s*(.*)\s*(<!--\[if ENDBLOCK\]><!\[endif\]-->)\s*(<\/div>)/s';

        if (preg_match($containerPattern, $html, $containerMatch)) {
            \Log::info('[Duo] Found rendered container in HTML');

            // Build the Alpine x-for template
            $replacement = $containerMatch[1] . "\n" .
                "    <template x-for=\"{$itemVarName} in {$propName}\" :key=\"{$itemVarName}.id\">\n" .
                "        " . trim($alpineTemplate) . "\n" .
                "    </template>\n";

            // Add empty state if we found @empty content
            if ($emptyContent) {
                $replacement .= "    " . trim($emptyContent) . "\n";
                // Add x-show to make it conditional
                $replacement = str_replace(
                    trim($emptyContent),
                    preg_replace('/^<(\w+)/', '<$1 x-show="!' . $propName . ' || ' . $propName . '.length === 0"', trim($emptyContent)),
                    $replacement
                );
            }

            $replacement .= $containerMatch[5]; // closing </div>

            $html = str_replace($containerMatch[0], $replacement, $html);
            \Log::info('[Duo] Successfully transformed Blade loop to Alpine');
        } else {
            \Log::info('[Duo] Could not find rendered container in HTML');
        }

        return $html;
    }

    /**
     * Transform Blade template syntax to Alpine.js bindings.
     */
    protected function transformBladeToAlpine(string $bladeTemplate, string $itemVarName): string
    {
        \Log::info('[Duo] Transforming Blade to Alpine', ['itemVarName' => $itemVarName]);

        $template = $bladeTemplate;

        // 1. Transform wire:click="method({{ $todo->id }})" to Alpine @click
        // Dispatch custom event to window for global listeners
        $template = preg_replace(
            '/wire:click="(\w+)\(\{\{\s*\$' . $itemVarName . '->id\s*\}\}\)"/i',
            '@click.prevent="window.dispatchEvent(new CustomEvent(\'duo-action\', { detail: { method: \'$1\', params: [' . $itemVarName . '.id] } }))"',
            $template
        );

        // Also handle wire:submit
        $template = preg_replace(
            '/wire:submit(?:\.prevent)?="(\w+)\(\{\{\s*\$' . $itemVarName . '->id\s*\}\}\)"/i',
            '@submit.prevent="window.dispatchEvent(new CustomEvent(\'duo-action\', { detail: { method: \'$1\', params: [' . $itemVarName . '.id] } }))"',
            $template
        );

        // 2. Transform {{ $todo->completed ? 'checked' : '' }} to :checked="todo.completed"
        $template = preg_replace(
            '/\{\{\s*\$' . $itemVarName . '->completed\s*\?\s*[\'"]checked[\'"]\s*:\s*[\'"][\'"]\s*\}\}/',
            ':checked="' . $itemVarName . '.completed"',
            $template
        );

        // 3. Transform class="{{ $todo->completed ? 'classes' : '' }}" to :class
        $template = preg_replace_callback(
            '/class="([^"]*)\{\{\s*\$' . $itemVarName . '->(\w+)\s*\?\s*[\'"]([^\'"]+)[\'"]\s*:\s*[\'"][\'"]\s*\}\}([^"]*)"/i',
            function ($matches) use ($itemVarName) {
                $staticClasses = trim($matches[1] . ' ' . $matches[4]);
                $conditionalClasses = $matches[3];
                $property = $matches[2];
                return 'class="' . $staticClasses . '" :class="{ \'' . $conditionalClasses . '\': ' . $itemVarName . '.' . $property . ' }"';
            },
            $template
        );

        // 4. Transform {{ $todo->created_at->diffForHumans() }} FIRST (before general property transform)
        $template = preg_replace(
            '/\{\{\s*\$' . $itemVarName . '->created_at->diffForHumans\(\)\s*\}\}/',
            '<span x-text="new Date(' . $itemVarName . '.created_at).toLocaleString()"></span>',
            $template
        );

        // 5. Transform {{ $todo->title }} to x-text="todo.title" (for text content)
        $template = preg_replace(
            '/\{\{\s*\$' . $itemVarName . '->(\w+)\s*\}\}/',
            '<span x-text="' . $itemVarName . '.$1"></span>',
            $template
        );

        // 6. Transform @if($todo->description) to x-show="todo.description"
        $template = preg_replace(
            '/@if\(\$' . $itemVarName . '->(\w+)\)(.*?)@endif/s',
            '<template x-if="' . $itemVarName . '.$1">$2</template>',
            $template
        );

        \Log::info('[Duo] Blade to Alpine transformation complete', ['result_length' => strlen($template)]);

        return $template;
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
