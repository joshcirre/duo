<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

use Illuminate\Support\Collection;
use Stillat\BladeParser\Document\Document;
use Stillat\BladeParser\Nodes\DirectiveNode;
use Stillat\BladeParser\Nodes\Structures\ForElse;

/**
 * Transforms Blade templates to Alpine.js equivalents for Duo offline functionality.
 *
 * This class uses the Stillat Blade Parser to properly parse Blade syntax
 * and transform it into Alpine.js code that works with IndexedDB.
 */
class BladeToAlpineTransformer
{
    protected Document $bladeDocument;

    protected string $renderedHtml;

    protected array $componentData;

    protected array $componentMethods;

    protected object $component;

    public function __construct(
        string $bladeSource,
        string $renderedHtml,
        array $componentData,
        array $componentMethods,
        object $component
    ) {
        $this->bladeDocument = Document::fromText($bladeSource);
        $this->bladeDocument->resolveStructures();
        $this->renderedHtml = $renderedHtml;
        $this->componentData = $componentData;
        $this->componentMethods = $componentMethods;
        $this->component = $component;
    }

    /**
     * Transform the component to use Alpine.js
     */
    public function transform(): string
    {
        \Log::info('[Duo] Starting Blade-to-Alpine transformation using Blade Parser', [
            'componentData' => array_map(function ($v) {
                return is_object($v) ? get_class($v) : gettype($v).': '.json_encode($v);
            }, $this->componentData),
        ]);

        $html = $this->renderedHtml;

        // Step 1: Replace wire:model with x-model
        $html = $this->replaceWireModel($html);

        // Step 2: Replace wire:submit with @submit
        $html = $this->replaceWireSubmit($html);

        // Step 3: Transform @foreach/@forelse loops to Alpine x-for
        $html = $this->transformLoops($html);

        // Step 4: Add Alpine x-data to root element
        $html = $this->addAlpineXData($html);

        \Log::info('[Duo] Blade-to-Alpine transformation complete');

        return $html;
    }

    /**
     * Replace wire:model with x-model
     */
    protected function replaceWireModel(string $html): string
    {
        return preg_replace(
            '/wire:model(?:\.live)?="([^"]+)"/i',
            'x-model="$1"',
            $html
        );
    }

    /**
     * Replace wire:submit with @submit.prevent
     */
    protected function replaceWireSubmit(string $html): string
    {
        return preg_replace(
            '/wire:submit(?:\.prevent)?="([^"]+)"/i',
            '@submit.prevent="$1"',
            $html
        );
    }

    /**
     * Transform @foreach and @forelse loops to Alpine x-for
     */
    protected function transformLoops(string $html): string
    {
        // Find all foreach and forelse directives
        $foreachDirectives = $this->bladeDocument->findDirectivesByName('foreach');
        $forelseDirectives = $this->bladeDocument->findDirectivesByName('forelse');

        $allLoopDirectives = array_merge(
            $foreachDirectives->all(),
            $forelseDirectives->all()
        );

        \Log::info('[Duo] Found loop directives', [
            'foreach' => count($foreachDirectives),
            'forelse' => count($forelseDirectives),
        ]);

        // Transform each loop
        foreach ($allLoopDirectives as $directive) {
            $html = $this->transformSingleLoop($directive, $html);
        }

        return $html;
    }

    /**
     * Transform a single loop directive to Alpine x-for
     */
    protected function transformSingleLoop(DirectiveNode $directive, string $html): string
    {
        // Parse the loop arguments (e.g., "$todos as $todo")
        $loopInfo = $this->parseLoopArguments($directive->arguments->content ?? '');

        if (! $loopInfo) {
            \Log::warning('[Duo] Could not parse loop arguments', [
                'arguments' => $directive->arguments->content ?? 'null',
            ]);

            return $html;
        }

        $collectionName = $loopInfo['collection'];
        $itemVarName = $loopInfo['itemVar'];

        // Check if we have data for this collection
        if (! isset($this->componentData[$collectionName]) || empty($this->componentData[$collectionName])) {
            \Log::info('[Duo] No data for collection: '.$collectionName);

            return $html;
        }

        // Check if this collection contains Duo-synced models
        $sampleItem = $this->componentData[$collectionName][0] ?? null;
        if (! $this->isSyncableModel($sampleItem)) {
            \Log::info('[Duo] Collection does not contain syncable models, skipping transformation', [
                'collection' => $collectionName,
                'itemType' => is_object($sampleItem) ? get_class($sampleItem) : gettype($sampleItem),
            ]);

            return $html;
        }

        // Extract wire:key pattern from Blade source
        $wireKeyPattern = $this->extractWireKeyPattern($directive, $itemVarName);

        \Log::info('[Duo] Transforming loop with syncable models', [
            'collection' => $collectionName,
            'itemVar' => $itemVarName,
            'isForelse' => $directive->structure instanceof ForElse,
            'wireKeyPattern' => $wireKeyPattern,
            'modelClass' => get_class($sampleItem),
        ]);

        $sampleItem = $this->componentData[$collectionName][0] ?? null;

        // Find the rendered loop in HTML using wire:key
        return $this->transformRenderedLoop($html, $collectionName, $itemVarName, $sampleItem, $directive, $wireKeyPattern);
    }

    /**
     * Parse loop arguments like "$todos as $todo" or "$this->todos as $todo"
     */
    protected function parseLoopArguments(string $arguments): ?array
    {
        // Remove parentheses if present
        $arguments = trim($arguments, '()');

        // Match: $collection as $item or $this->collection as $item
        if (preg_match('/\$(?:this->)?(\w+)\s+as\s+\$(\w+)/', $arguments, $matches)) {
            return [
                'collection' => $matches[1],
                'itemVar' => $matches[2],
            ];
        }

        return null;
    }

    /**
     * Transform the rendered loop HTML to use Alpine x-for
     */
    protected function transformRenderedLoop(
        string $html,
        string $collectionName,
        string $itemVarName,
        mixed $sampleItem,
        DirectiveNode $directive,
        ?string $keyPattern
    ): string {
        // Find the first element with wire:key (this is a rendered loop item)
        // Build a pattern based on the extracted key pattern from Blade source
        if ($keyPattern === 'numeric') {
            // Match wire:key with just numbers (no prefix)
            $wireKeyPattern = '/<(\w+)([^>]*wire:key="(\d+)"[^>]*)>/';
        } elseif ($keyPattern !== null) {
            // Match wire:key with the specific prefix (e.g., "server-", "post-")
            $wireKeyPattern = '/<(\w+)([^>]*wire:key="'.preg_quote($keyPattern, '/').'[^"]*"[^>]*)>/';
        } else {
            // Fallback to any wire:key if pattern couldn't be extracted
            $wireKeyPattern = '/<(\w+)([^>]*wire:key="[^"]*"[^>]*)>/';
        }

        if (! preg_match($wireKeyPattern, $html, $match, PREG_OFFSET_CAPTURE)) {
            // Fallback to any wire:key
            if (! preg_match('/<(\w+)([^>]*wire:key="[^"]*"[^>]*)>/', $html, $match, PREG_OFFSET_CAPTURE)) {
                \Log::info('[Duo] No wire:key elements found for: '.$collectionName);

                return $html;
            }
        }

        \Log::info('[Duo] Found wire:key element for '.$collectionName, [
            'matchedTag' => substr($match[0][0], 0, 100),
            'pattern' => $keyPattern,
        ]);

        $tagName = $match[1][0];
        $attributes = $match[2][0];
        $openTagFull = $match[0][0];
        $openTagPos = $match[0][1];

        // Find the matching closing tag
        $closeTagEnd = $this->findMatchingClosingTag($html, $openTagPos + strlen($openTagFull), $tagName);

        if ($closeTagEnd === null) {
            \Log::warning('[Duo] Could not find matching closing tag for wire:key element');

            return $html;
        }

        // Extract the full element and its innerHTML
        $fullElement = substr($html, $openTagPos, $closeTagEnd - $openTagPos);
        $innerHTMLStart = $openTagPos + strlen($openTagFull);
        $innerHTMLLength = $closeTagEnd - $innerHTMLStart - strlen('</'.$tagName.'>');
        $innerHTML = substr($html, $innerHTMLStart, $innerHTMLLength);

        // Convert the innerHTML to use Alpine bindings
        $alpineTemplate = $this->convertToAlpineBindings($innerHTML, $itemVarName, $sampleItem);

        // Remove wire:key from attributes (the template x-for will handle :key)
        $alpineAttributes = preg_replace('/\s*wire:key="[^"]*"/', '', $attributes);

        // Build the Alpine x-for template
        $alpineItem = "<{$tagName}{$alpineAttributes}>\n        {$alpineTemplate}\n    </{$tagName}>";

        // Find the container that holds this loop
        $containerInfo = $this->findLoopContainer($html, $openTagPos);

        if (! $containerInfo) {
            \Log::warning('[Duo] Could not find loop container');

            return $html;
        }

        // Check for empty state (if @forelse)
        $emptyStateHtml = '';
        if ($directive->structure instanceof ForElse) {
            /** @var ForElse $structure */
            $structure = $directive->structure;
            if ($structure->hasEmptyClause()) {
                $emptyStateHtml = $this->extractEmptyState($html, $containerInfo['content']);
            }
        }

        // Build the Alpine loop wrapper
        // Extract tag name from opening tag
        preg_match('/^<(\w+)/', $containerInfo['openTag'], $tagMatches);
        $containerTag = $tagMatches[1] ?? 'div';

        // Don't add x-show - the loop will be empty until data loads, which is fine
        $containerOpen = $containerInfo['openTag'];

        $alpineLoop = $containerOpen."\n";

        if ($emptyStateHtml) {
            $alpineLoop .= "    <div x-show=\"{$collectionName}.length === 0\">{$emptyStateHtml}</div>\n";
        }

        $alpineLoop .= "    <template x-for=\"{$itemVarName} in {$collectionName}\" :key=\"{$itemVarName}.id\">\n        {$alpineItem}\n    </template>\n</{$containerTag}>";

        // Replace the entire container
        $html = substr_replace(
            $html,
            $alpineLoop,
            $containerInfo['startPos'],
            $containerInfo['endPos'] - $containerInfo['startPos']
        );

        \Log::info('[Duo] Successfully transformed loop to Alpine x-for', [
            'collection' => $collectionName,
            'hasEmptyState' => ! empty($emptyStateHtml),
            'alpineLoop' => substr($alpineLoop, 0, 500),
        ]);

        return $html;
    }

    /**
     * Convert rendered HTML to use Alpine bindings
     */
    protected function convertToAlpineBindings(string $html, string $itemVarName, mixed $sampleItem): string
    {
        if (! $sampleItem) {
            return $html;
        }

        // Convert model to array if needed
        if (is_object($sampleItem) && method_exists($sampleItem, 'toArray')) {
            $sampleItem = $sampleItem->toArray();
        }

        // Replace actual values with Alpine x-text bindings
        foreach ($sampleItem as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $escapedValue = preg_quote(htmlspecialchars((string) $value), '/');
                $html = preg_replace(
                    '/>(\s*)'.$escapedValue.'(\s*)</s',
                    ' x-text="'.$itemVarName.'.'.$key.'">$1$2<',
                    $html,
                    1
                );
            }
        }

        // Replace wire:click with Alpine @click
        // Match wire:click="method(...)" and replace entire parameter with item variable
        $html = preg_replace(
            '/wire:click="([a-zA-Z_]\w*)\(.*?\)"/',
            '@click="$1('.$itemVarName.')"',
            $html
        );

        return $html;
    }

    /**
     * Find the matching closing tag for an opening tag
     */
    protected function findMatchingClosingTag(string $html, int $startPos, string $tagName): ?int
    {
        $depth = 1;
        $pos = $startPos;
        $openPattern = '/<'.$tagName.'[>\s]/';
        $closePattern = '/<\/'.$tagName.'>/';

        while ($depth > 0 && $pos < strlen($html)) {
            $nextOpen = preg_match($openPattern, $html, $openMatches, PREG_OFFSET_CAPTURE, $pos);
            $nextClose = preg_match($closePattern, $html, $closeMatches, PREG_OFFSET_CAPTURE, $pos);

            $openPos = $nextOpen ? $openMatches[0][1] : PHP_INT_MAX;
            $closePos = $nextClose ? $closeMatches[0][1] : PHP_INT_MAX;

            if ($openPos < $closePos) {
                $depth++;
                $pos = $openPos + strlen($openMatches[0][0]);
            } elseif ($closePos < PHP_INT_MAX) {
                $depth--;
                if ($depth === 0) {
                    return $closePos + strlen($closeMatches[0][0]);
                }
                $pos = $closePos + strlen($closeMatches[0][0]);
            } else {
                break;
            }
        }

        return null;
    }

    /**
     * Find the container div that holds the loop
     */
    protected function findLoopContainer(string $html, int $firstItemPos): ?array
    {
        // Search backwards for a div with space-y, grid, or flex class
        $beforeFirstItem = substr($html, 0, $firstItemPos);

        if (! preg_match_all('/<div([^>]*class="[^"]*(?:space-y|grid|flex)[^"]*"[^>]*)>/', $beforeFirstItem, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // Get the last match (closest to the first item)
        $lastMatch = end($matches[0]);
        $openTag = $lastMatch[0];
        $startPos = $lastMatch[1];

        // Find the matching closing tag
        $endPos = $this->findMatchingClosingTag($html, $startPos + strlen($openTag), 'div');

        if ($endPos === null) {
            return null;
        }

        $content = substr($html, $startPos + strlen($openTag), $endPos - $startPos - strlen($openTag) - strlen('</div>'));

        return [
            'openTag' => $openTag,
            'startPos' => $startPos,
            'endPos' => $endPos,
            'content' => $content,
        ];
    }

    /**
     * Check if a model uses the Syncable trait (is registered with Duo)
     */
    protected function isSyncableModel($item): bool
    {
        if (! is_object($item)) {
            return false;
        }

        // Check if the item uses the Syncable trait
        $traits = class_uses_recursive(get_class($item));

        return in_array('JoshCirre\\Duo\\Syncable', $traits);
    }

    /**
     * Extract wire:key pattern from the Blade source
     * Returns a pattern identifier (e.g., "numeric", "server-", "post-", etc.)
     */
    protected function extractWireKeyPattern(DirectiveNode $directive, string $itemVarName): ?string
    {
        // Get the Blade source content
        $bladeSource = $this->bladeDocument->toString();

        // Get the directive's position in the source
        $startPosition = $directive->position->startOffset ?? null;
        $endPosition = $directive->position->endOffset ?? null;

        if ($startPosition === null || $endPosition === null) {
            return null;
        }

        // Extract the directive's body (a reasonable chunk after the directive)
        $bodyLength = min(2000, strlen($bladeSource) - $startPosition);
        $directiveBody = substr($bladeSource, $startPosition, $bodyLength);

        // Look for wire:key attribute in the body
        if (preg_match('/wire:key=["\']([^"\']+)["\']/', $directiveBody, $matches)) {
            $wireKeyExpression = $matches[1];

            // Analyze the pattern
            // Example patterns:
            // "{{ $todo->id }}" -> numeric
            // "server-{{ $todo->id }}" -> server-
            // "post-{{ $post->id }}" -> post-

            // Extract any prefix before the Blade expression
            if (preg_match('/^([^{]+)/', $wireKeyExpression, $prefixMatch)) {
                $prefix = $prefixMatch[1];
                if (! empty($prefix)) {
                    return $prefix; // e.g., "server-", "post-"
                }
            }

            // No prefix, just the variable
            return 'numeric';
        }

        return null;
    }

    /**
     * Extract empty state HTML from @forelse loop
     */
    protected function extractEmptyState(string $html, string $containerContent): string
    {
        // Look for BLOCK comment markers
        if (preg_match_all('/<!--\[if BLOCK\]><!\[endif\]-->(.*?)<!--\[if ENDBLOCK\]><!\[endif\]-->/s', $containerContent, $blocks)) {
            if (count($blocks[1]) > 1) {
                // First block might be empty state if it doesn't have wire:key
                $potentialEmptyState = $blocks[1][0];
                if (! str_contains($potentialEmptyState, 'wire:key')) {
                    return trim($potentialEmptyState);
                }
            }
        }

        return '';
    }

    /**
     * Add Alpine x-data to the root element
     */
    protected function addAlpineXData(string $html): string
    {
        // Generate Alpine x-data content
        $xDataContent = $this->generateAlpineXData();

        // Check if x-data already exists and merge
        if (preg_match('/^<[^>]+x-data="([^"]*)"/', $html, $existingXData)) {
            // Merge with existing x-data using spread operator
            $mergedXData = '{ ...('.$existingXData[1].'), ...('.$xDataContent.') }';
            $html = preg_replace(
                '/^(<[^>]+?)x-data="[^"]*"/',
                '$1 data-duo-enabled="true" x-data="'.htmlspecialchars($mergedXData, ENT_QUOTES, 'UTF-8').'"',
                $html,
                1
            );
        } else {
            // Add new x-data
            $html = preg_replace(
                '/^(<[^>]+?)>/',
                '$1 data-duo-enabled="true" x-data="'.htmlspecialchars($xDataContent, ENT_QUOTES, 'UTF-8').'">',
                $html,
                1
            );
        }

        return $html;
    }

    /**
     * Generate Alpine x-data content as a JavaScript object string
     */
    protected function generateAlpineXData(): string
    {
        $parts = [];

        // Add component properties (data)
        foreach ($this->componentData as $key => $value) {
            if (! str_starts_with($key, '_')) {
                // Convert Collections to arrays for JSON encoding
                if (is_object($value) && method_exists($value, 'toArray')) {
                    $value = $value->toArray();
                }
                $parts[] = $key.': '.json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        // Add duoLoading and duoReady flags
        $parts[] = 'duoLoading: true';
        $parts[] = 'duoReady: false';

        // Add errors object for validation
        $parts[] = 'errors: {}';

        // Add component methods (as actual JavaScript functions, not strings)
        foreach ($this->componentMethods as $methodInfo) {
            $methodName = $methodInfo['name'];
            $parts[] = $this->generateAlpineMethod($methodName, $methodInfo);
        }

        // Add Duo-specific methods
        $duoMethods = $this->generateDuoMethods();
        foreach ($duoMethods as $methodCode) {
            $parts[] = $methodCode;
        }

        $xDataString = '{
                    '.implode(",\n                    ", $parts).'
                }';

        \Log::info('[Duo] Generated x-data', [
            'length' => strlen($xDataString),
            'preview' => substr($xDataString, 0, 1000),
        ]);

        return $xDataString;
    }

    /**
     * Generate an Alpine method from a Livewire method
     */
    protected function generateAlpineMethod(string $methodName, array $methodInfo): string
    {
        $parameters = $methodInfo['parameters'] ?? [];
        $paramNames = array_map(fn ($p) => $p->getName(), $parameters);
        $paramString = implode(', ', $paramNames);

        // Detect method type based on name and parameters
        if (str_starts_with($methodName, 'create') || str_starts_with($methodName, 'store')) {
            return $this->generateCreateMethod($methodName, $paramNames);
        }

        if (str_starts_with($methodName, 'update') || str_starts_with($methodName, 'edit')) {
            return $this->generateUpdateMethod($methodName, $paramNames);
        }

        if (str_starts_with($methodName, 'delete') || str_starts_with($methodName, 'destroy')) {
            return $this->generateDeleteMethod($methodName, $paramNames);
        }

        // Default method - just placeholder for now
        return "{$methodName}({$paramString}) {
            console.warn('[Duo] Method {$methodName} not yet implemented for offline use');
        }";
    }

    /**
     * Generate a create method for Alpine
     */
    protected function generateCreateMethod(string $methodName, array $paramNames): string
    {
        // Detect the model from the method name (e.g., createTodo -> Todo)
        $modelName = $this->extractModelFromMethodName($methodName);
        $storeName = $this->getStoreName($modelName);

        // Detect form fields (scalar values, not collections)
        $formFields = [];
        foreach ($this->componentData as $key => $value) {
            if (! str_starts_with($key, '_') && ! $this->isCollection($value) && ! is_array($value)) {
                $formFields[] = $key;
            }
        }

        // Find this method in componentMethods to get validation rules
        $validationRules = [];
        foreach ($this->componentMethods as $methodInfo) {
            if ($methodInfo['name'] === $methodName) {
                $validationRules = $methodInfo['validation'] ?? [];
                break;
            }
        }

        \Log::info('[Duo] Detected form fields for '.$methodName, [
            'formFields' => $formFields,
            'validationRules' => $validationRules,
            'componentData' => array_keys($this->componentData),
        ]);

        $formFieldsList = implode(', ', array_map(fn ($f) => "'{$f}'", $formFields));

        // Generate validation code
        $validationCode = $this->generateValidationCode($validationRules, $formFields);

        return "async {$methodName}() {
            if (!window.duo) {
                console.error('[Duo] Duo client not initialized');
                return;
            }

            const db = window.duo.getDatabase();
            if (!db) {
                console.error('[Duo] Database not initialized');
                return;
            }

            const store = db.getStore('{$storeName}');
            if (!store) {
                console.error('[Duo] Store not found: {$storeName}');
                return;
            }

            // Collect form data from Alpine state
            const formFields = [{$formFieldsList}];
            const formData = {};
            formFields.forEach(field => {
                if (this[field] !== undefined) {
                    formData[field] = this[field];
                }
            });

            console.log('[Duo] Form data collected:', formData);

            // Validate form data
            this.errors = {};
            console.log('[Duo] Running validation...');
{$validationCode}

            // Stop if there are validation errors
            if (Object.keys(this.errors).length > 0) {
                console.warn('[Duo] Validation failed:', this.errors);
                alert('Validation failed: ' + Object.values(this.errors).join(', '));
                return;
            }
            console.log('[Duo] Validation passed');

            try {
                // Add record to IndexedDB with sync flag
                const record = {
                    ...formData,
                    id: Date.now(), // Temporary ID
                    _duo_pending_sync: true,
                    _duo_operation: 'create'
                };

                await store.add(record);
                console.log('[Duo] Created record offline:', record);

                // Queue for sync
                const syncQueue = window.duo.getSyncQueue();
                if (syncQueue) {
                    await syncQueue.enqueue({
                        storeName: '{$storeName}',
                        operation: 'create',
                        data: record
                    });
                }

                // Reset form fields
                formFields.forEach(field => {
                    if (typeof this[field] === 'string') {
                        this[field] = '';
                    } else if (typeof this[field] === 'number') {
                        this[field] = 0;
                    } else if (typeof this[field] === 'boolean') {
                        this[field] = false;
                    }
                });

                // Refresh data from IndexedDB
                await this.duoSync();
            } catch (err) {
                console.error('[Duo] Failed to create record:', err);
            }
        }";
    }

    /**
     * Generate an update method for Alpine
     */
    protected function generateUpdateMethod(string $methodName, array $paramNames): string
    {
        $modelName = $this->extractModelFromMethodName($methodName);
        $storeName = $this->getStoreName($modelName);
        $recordParam = $paramNames[0] ?? 'record';

        return "async {$methodName}({$recordParam}) {
            if (!window.duo) {
                console.error('[Duo] Duo client not initialized');
                return;
            }

            const db = window.duo.getDatabase();
            if (!db) {
                console.error('[Duo] Database not initialized');
                return;
            }

            const store = db.getStore('{$storeName}');
            if (!store) {
                console.error('[Duo] Store not found: {$storeName}');
                return;
            }

            try {
                // Update record in IndexedDB
                const updatedRecord = {
                    ...{$recordParam},
                    _duo_pending_sync: true,
                    _duo_operation: 'update'
                };

                await store.put(updatedRecord);
                console.log('[Duo] Updated record offline:', updatedRecord);

                // Queue for sync
                const syncQueue = window.duo.getSyncQueue();
                if (syncQueue) {
                    await syncQueue.enqueue({
                        storeName: '{$storeName}',
                        operation: 'update',
                        data: updatedRecord
                    });
                }

                // Refresh data from IndexedDB
                await this.duoSync();
            } catch (err) {
                console.error('[Duo] Failed to update record:', err);
            }
        }";
    }

    /**
     * Generate a delete method for Alpine
     */
    protected function generateDeleteMethod(string $methodName, array $paramNames): string
    {
        $modelName = $this->extractModelFromMethodName($methodName);
        $storeName = $this->getStoreName($modelName);
        $recordParam = $paramNames[0] ?? 'record';

        return "async {$methodName}({$recordParam}) {
            if (!window.duo) {
                console.error('[Duo] Duo client not initialized');
                return;
            }

            const db = window.duo.getDatabase();
            if (!db) {
                console.error('[Duo] Database not initialized');
                return;
            }

            const store = db.getStore('{$storeName}');
            if (!store) {
                console.error('[Duo] Store not found: {$storeName}');
                return;
            }

            try {
                const recordId = typeof {$recordParam} === 'object' ? {$recordParam}.id : {$recordParam};

                // Delete record from IndexedDB
                await store.delete(recordId);
                console.log('[Duo] Deleted record offline:', recordId);

                // Queue for sync
                const syncQueue = window.duo.getSyncQueue();
                if (syncQueue) {
                    await syncQueue.enqueue({
                        storeName: '{$storeName}',
                        operation: 'delete',
                        data: { id: recordId, _duo_operation: 'delete' }
                    });
                }

                // Refresh data from IndexedDB
                await this.duoSync();
            } catch (err) {
                console.error('[Duo] Failed to delete record:', err);
            }
        }";
    }

    /**
     * Extract model name from method name (e.g., createTodo -> Todo)
     */
    protected function extractModelFromMethodName(string $methodName): string
    {
        // Remove common prefixes
        $name = preg_replace('/^(create|store|update|edit|delete|destroy)/', '', $methodName);

        // If empty, check component data for collection names
        if (empty($name)) {
            foreach (array_keys($this->componentData) as $key) {
                if (! str_starts_with($key, '_')) {
                    return ucfirst(rtrim($key, 's'));
                }
            }
        }

        return ucfirst($name);
    }

    /**
     * Get IndexedDB store name from model name
     */
    protected function getStoreName(string $modelName): string
    {
        // Default to App\Models namespace
        return 'App_Models_'.$modelName;
    }

    /**
     * Check if value is a Laravel Collection
     */
    protected function isCollection($value): bool
    {
        return is_object($value) && method_exists($value, 'toArray') && ! empty($value->toArray());
    }

    /**
     * Generate JavaScript validation code from Laravel validation rules
     */
    protected function generateValidationCode(array $validationRules, array $formFields): string
    {
        if (empty($validationRules)) {
            return '';
        }

        $validationCode = [];

        foreach ($validationRules as $field => $rules) {
            $fieldValidation = [];

            foreach ($rules as $rule) {
                // Parse rule and parameters (e.g., "max:255" => rule="max", param="255")
                $ruleParts = explode(':', $rule);
                $ruleName = $ruleParts[0];
                $ruleParam = $ruleParts[1] ?? null;

                switch ($ruleName) {
                    case 'required':
                        $fieldValidation[] = "
                if (!formData.{$field} || formData.{$field}.toString().trim() === '') {
                    this.errors.{$field} = 'The {$field} field is required.';
                }";
                        break;

                    case 'string':
                        $fieldValidation[] = "
                if (formData.{$field} && typeof formData.{$field} !== 'string') {
                    this.errors.{$field} = 'The {$field} must be a string.';
                }";
                        break;

                    case 'max':
                        if ($ruleParam) {
                            $fieldValidation[] = "
                if (formData.{$field} && formData.{$field}.toString().length > {$ruleParam}) {
                    this.errors.{$field} = 'The {$field} may not be greater than {$ruleParam} characters.';
                }";
                        }
                        break;

                    case 'min':
                        if ($ruleParam) {
                            $fieldValidation[] = "
                if (formData.{$field} && formData.{$field}.toString().length < {$ruleParam}) {
                    this.errors.{$field} = 'The {$field} must be at least {$ruleParam} characters.';
                }";
                        }
                        break;

                    case 'email':
                        $fieldValidation[] = "
                if (formData.{$field} && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.{$field})) {
                    this.errors.{$field} = 'The {$field} must be a valid email address.';
                }";
                        break;

                    case 'numeric':
                        $fieldValidation[] = "
                if (formData.{$field} && isNaN(formData.{$field})) {
                    this.errors.{$field} = 'The {$field} must be a number.';
                }";
                        break;
                }
            }

            if (! empty($fieldValidation)) {
                $validationCode[] = implode('', $fieldValidation);
            }
        }

        return implode("\n", $validationCode);
    }

    /**
     * Generate Duo-specific methods (duoSync, syncServerToIndexedDB, setupLiveQuery, init)
     */
    protected function generateDuoMethods(): array
    {
        $methods = [];

        // Generate duoSync method - loads data from IndexedDB to Alpine state
        $methods['duoSync'] = $this->generateDuoSyncMethod();

        // Generate syncServerToIndexedDB - syncs server data to IndexedDB on init
        $methods['syncServerToIndexedDB'] = $this->generateSyncServerToIndexedDBMethod();

        // Generate setupLiveQuery - sets up reactive subscriptions for multi-tab sync
        $methods['setupLiveQuery'] = $this->generateSetupLiveQueryMethod();

        // Generate init method
        $methods['init'] = $this->generateInitMethod();

        return $methods;
    }

    /**
     * Generate duoSync method that loads data from IndexedDB to Alpine state
     */
    protected function generateDuoSyncMethod(): string
    {
        $syncOperations = [];

        // Find all collections in component data
        foreach ($this->componentData as $key => $value) {
            if (! str_starts_with($key, '_') && ($this->isCollection($value) || is_array($value))) {
                $modelName = ucfirst(rtrim($key, 's'));
                $storeName = $this->getStoreName($modelName);

                $syncOperations[] = "
            // Sync {$key} from IndexedDB
            try {
                const {$key}Store = db.getStore('{$storeName}');
                if ({$key}Store) {
                    const records = await {$key}Store.toArray();
                    console.log('[Duo] Loaded', records.length, '{$key} from IndexedDB');

                    // Force Alpine reactivity by creating new array reference
                    if (this.\$nextTick) {
                        this.{$key} = records.slice();
                        this.\$nextTick(() => {
                            console.log('[Duo] {$key} updated, length:', this.{$key}.length);
                        });
                    } else {
                        this.{$key} = records.slice();
                    }
                }
            } catch (err) {
                console.error('[Duo] Failed to sync {$key} from IndexedDB:', err);
            }";
            }
        }

        $syncCode = implode("\n", $syncOperations);

        return "async duoSync() {
            if (!window.duo) {
                console.warn('[Duo] Duo client not initialized, skipping sync');
                return;
            }

            const db = window.duo.getDatabase();
            if (!db) {
                console.warn('[Duo] Database not initialized');
                return;
            }

            console.log('[Duo] Syncing data from IndexedDB to Alpine...');
{$syncCode}
        }";
    }

    /**
     * Generate syncServerToIndexedDB method
     */
    protected function generateSyncServerToIndexedDBMethod(): string
    {
        $syncOperations = [];

        // Find all collections in component data
        foreach ($this->componentData as $key => $value) {
            if (! str_starts_with($key, '_') && ($this->isCollection($value) || is_array($value))) {
                $modelName = ucfirst(rtrim($key, 's'));
                $storeName = $this->getStoreName($modelName);

                $syncOperations[] = "
            // Sync {$key} to IndexedDB
            try {
                const {$key}Store = db.getStore('{$storeName}');
                if ({$key}Store && this.{$key}) {
                    // Clear existing data
                    await {$key}Store.clear();

                    // Insert server data (serialize to plain objects)
                    if (Array.isArray(this.{$key}) && this.{$key}.length > 0) {
                        const serialized = this.{$key}.map(item => {
                            // Convert to plain object and serialize dates
                            const plain = JSON.parse(JSON.stringify(item));
                            return plain;
                        });
                        await {$key}Store.bulkAdd(serialized);
                        console.log('[Duo] Synced', serialized.length, '{$key} records to IndexedDB');
                    }
                }
            } catch (err) {
                console.error('[Duo] Failed to sync {$key} to IndexedDB:', err);
            }";
            }
        }

        $syncCode = implode("\n", $syncOperations);

        return "async syncServerToIndexedDB() {
            if (!window.duo) {
                console.warn('[Duo] Duo client not initialized');
                return;
            }

            const db = window.duo.getDatabase();
            if (!db) {
                console.warn('[Duo] Database not initialized');
                return;
            }

            console.log('[Duo] Syncing server data to IndexedDB...');
{$syncCode}

            console.log('[Duo] Server data synced to IndexedDB');
        }";
    }

    /**
     * Generate setupLiveQuery method for real-time multi-tab sync
     */
    protected function generateSetupLiveQueryMethod(): string
    {
        $subscriptions = [];

        // Find all collections in component data
        foreach ($this->componentData as $key => $value) {
            if (! str_starts_with($key, '_') && ($this->isCollection($value) || is_array($value))) {
                $modelName = ucfirst(rtrim($key, 's'));
                $storeName = $this->getStoreName($modelName);

                $subscriptions[] = "
            // Set up liveQuery for {$key} (multi-tab sync)
            try {
                const {$key}Store = db.getStore('{$storeName}');
                if ({$key}Store) {
                    const subscription = window.duo.liveQuery(() => {$key}Store.toArray())
                        .subscribe(
                            items => {
                                console.log('[Duo] liveQuery updated {$key}:', items.length, 'items');
                                this.{$key} = items;
                            },
                            error => console.error('[Duo] liveQuery error for {$key}:', error)
                        );

                    // Store subscription for cleanup
                    if (!this._duoSubscriptions) this._duoSubscriptions = [];
                    this._duoSubscriptions.push(subscription);
                }
            } catch (err) {
                console.error('[Duo] Failed to set up liveQuery for {$key}:', err);
            }";
            }
        }

        $subscriptionCode = implode("\n", $subscriptions);

        return "setupLiveQuery() {
            if (!window.duo) {
                console.warn('[Duo] Duo client not initialized');
                return;
            }

            const db = window.duo.getDatabase();
            if (!db) {
                console.warn('[Duo] Database not initialized');
                return;
            }

            console.log('[Duo] Setting up liveQuery for real-time sync...');
{$subscriptionCode}

            console.log('[Duo] liveQuery subscriptions active (multi-tab sync enabled)');
        }";
    }

    /**
     * Generate init method for Alpine component
     */
    protected function generateInitMethod(): string
    {
        return "async init() {
            console.log('[Duo] Initializing component with Duo...');

            // Wait for Duo client to be ready
            if (!window.duo) {
                console.warn('[Duo] Duo client not found, waiting...');
                await new Promise(resolve => {
                    const checkDuo = setInterval(() => {
                        if (window.duo) {
                            clearInterval(checkDuo);
                            resolve();
                        }
                    }, 100);

                    // Timeout after 5 seconds
                    setTimeout(() => {
                        clearInterval(checkDuo);
                        resolve();
                    }, 5000);
                });
            }

            if (!window.duo) {
                console.error('[Duo] Duo client not available after timeout');
                this.duoLoading = false;
                return;
            }

            try {
                // Sync server data to IndexedDB
                await this.syncServerToIndexedDB();

                // Load data from IndexedDB to Alpine state
                await this.duoSync();

                // Set up liveQuery for real-time multi-tab sync
                this.setupLiveQuery();

                // Mark as ready
                this.duoLoading = false;
                this.duoReady = true;

                console.log('[Duo] Component initialized and ready');
            } catch (err) {
                console.error('[Duo] Failed to initialize component:', err);
                this.duoLoading = false;
            }
        }";
    }
}
