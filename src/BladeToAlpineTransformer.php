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

    protected array $duoConfig;

    protected ?array $orderBy;

    public function __construct(
        string $bladeSource,
        ?string $renderedHtml,
        array $componentData,
        array $componentMethods,
        object $component,
        ?array $orderBy = null
    ) {
        $this->bladeDocument = Document::fromText($bladeSource);
        $this->bladeDocument->resolveStructures();
        $this->renderedHtml = $renderedHtml ?? '';
        $this->componentData = $componentData;
        $this->componentMethods = $componentMethods;
        $this->component = $component;
        $this->orderBy = $orderBy;

        // Extract Duo configuration from component if available
        $this->duoConfig = $this->extractDuoConfig();
    }

    /**
     * Extract Duo configuration from the component
     *
     * Priority: Component duoConfig() > Global config/duo.php > Hardcoded defaults
     */
    protected function extractDuoConfig(): array
    {
        // Hardcoded defaults (lowest priority)
        $defaults = [
            'syncInterval' => 5000,
            'timestampRefreshInterval' => 10000,
            'maxRetryAttempts' => 3,
            'debug' => false,
        ];

        // Global config (medium priority)
        $globalConfig = [
            'syncInterval' => config('duo.sync_interval', 5000),
            'timestampRefreshInterval' => config('duo.timestamp_refresh_interval', 10000),
            'maxRetryAttempts' => config('duo.max_retry_attempts', 3),
            'debug' => config('duo.debug', false),
        ];

        // Start with defaults, merge global
        $config = array_merge($defaults, $globalConfig);

        // Component config (highest priority)
        if (method_exists($this->component, 'duoConfig')) {
            try {
                $componentConfig = $this->component->duoConfig();

                // Handle DuoConfig object
                if ($componentConfig instanceof DuoConfig) {
                    $config = array_merge($config, $componentConfig->toArray());
                }
            } catch (\Exception $e) {
                \Log::warning('[Duo] Failed to get duoConfig from component', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $config;
    }

    /**
     * Transform the Blade source BEFORE rendering
     * This does ALL transformations: wire:model, @if/@else, loops, x-data
     */
    public function transformBladeSource(): string
    {
        \Log::info('[Duo] Starting Blade source transformation');

        $blade = $this->bladeDocument->toString();

        // Step 1: Replace wire: directives with Alpine equivalents
        $blade = preg_replace('/wire:model(?:\.live)?="([^"]+)"/i', 'x-model="$1"', $blade);
        $blade = preg_replace('/wire:submit="([^"]+)"/i', '@submit.prevent="$1"', $blade);
        $blade = preg_replace('/wire:click="([^"]+)"/i', '@click="$1"', $blade);

        // Step 3: Transform @foreach loops FIRST (before @if/@else)
        // This ensures that when we extract @if/@else branch content, the @foreach is already converted to Alpine
        $blade = $this->transformForeachInBladeSource($blade);

        // Step 4: Find all @if directives that check for empty/not-empty collections
        // We need to re-parse after changes
        $this->bladeDocument = Document::fromText($blade);
        $this->bladeDocument->resolveStructures();

        $ifDirectives = $this->bladeDocument->findDirectivesByName('if');

        foreach ($ifDirectives->all() as $directive) {
            if (! $directive->structure) {
                continue;
            }

            // Get the condition from the directive
            $condition = $directive->arguments->content ?? '';
            $collectionCheck = $this->detectCollectionCondition($condition);
            if (! $collectionCheck) {
                continue;
            }

            \Log::info('[Duo] Found collection conditional', [
                'collection' => $collectionCheck['collection'],
                'checksEmpty' => $collectionCheck['checksEmpty'],
            ]);

            // Transform this @if/@else in the Blade source
            $blade = $this->transformConditionalInSource($blade, $directive, $collectionCheck);

            // Re-parse after modifying
            $this->bladeDocument = Document::fromText($blade);
            $this->bladeDocument->resolveStructures();
        }

        \Log::info('[Duo] Blade source transformation complete');

        return $blade;
    }

    /**
     * Transform the rendered HTML
     * ONLY adds x-data to the root element - all other transformations happen at Blade source level
     */
    public function transformHtml(): string
    {
        \Log::info('[Duo] Starting HTML transformation');

        $html = $this->renderedHtml;

        // Add Alpine x-data to root element (the ONLY thing we do at HTML level)
        $html = $this->addAlpineXData($html);

        \Log::info('[Duo] HTML transformation complete');

        return $html;
    }

    /**
     * Transform an @if/@else conditional in the Blade source
     */
    protected function transformConditionalInSource(string $blade, DirectiveNode $directive, array $collectionCheck): string
    {
        $collectionName = $collectionCheck['collection'];
        $checksEmpty = $collectionCheck['checksEmpty'];

        $structure = $directive->structure;
        $primaryBranch = $structure->getPrimaryBranch(); // @if branch
        $elseBranches = $structure->getElseBranches();
        $elseBranch = $elseBranches[0] ?? null;

        if (! $elseBranch) {
            \Log::warning('[Duo] No @else branch found, skipping');

            return $blade;
        }

        // Get the full source text of the @if/@else/@endif block
        // We need to find the @endif directive
        $ifStart = $directive->position->startOffset;

        // Find the @endif directive that corresponds to this @if
        $endifNode = $this->findMatchingEndif($directive);
        if (! $endifNode) {
            \Log::warning('[Duo] Could not find matching @endif');
            return $blade;
        }

        $endifEnd = $endifNode->position->endOffset;

        $fullConditional = substr($blade, $ifStart, $endifEnd - $ifStart);

        \Log::info('[Duo] Extracting conditional block', [
            'ifStart' => $ifStart,
            'endifEnd' => $endifEnd,
            'length' => strlen($fullConditional),
        ]);

        // Extract the content between @if and @else
        $elseDirective = $elseBranch->target;

        // Log the exact positions and surrounding characters
        \Log::info('[Duo] Directive positions', [
            'if_startOffset' => $directive->position->startOffset,
            'if_endOffset' => $directive->position->endOffset,
            'if_directive' => substr($blade, $directive->position->startOffset, 30),
            'else_startOffset' => $elseDirective->position->startOffset,
            'else_endOffset' => $elseDirective->position->endOffset,
            'else_directive' => substr($blade, $elseDirective->position->startOffset, 10),
            'endif_startOffset' => $endifNode->position->startOffset,
            'endif_endOffset' => $endifNode->position->endOffset,
            'endif_directive' => substr($blade, $endifNode->position->startOffset, 10),
        ]);

        $rawPrimaryContent = substr($blade, $directive->position->endOffset, $elseDirective->position->startOffset - $directive->position->endOffset);

        // Skip past the newline after the @if directive, and trim whitespace before @else
        $primaryContent = preg_replace('/^[^\n]*\n/', '', $rawPrimaryContent, 1);
        $primaryContent = rtrim($primaryContent);

        // Extract the content between @else and @endif
        $rawElseContent = substr($blade, $elseDirective->position->endOffset, $endifNode->position->startOffset - $elseDirective->position->endOffset);

        // Skip past the newline after the @else directive, and trim whitespace before @endif
        $elseContent = preg_replace('/^[^\n]*\n/', '', $rawElseContent, 1);
        $elseContent = rtrim($elseContent);

        \Log::info('[Duo] Extracted branch contents', [
            'primaryLength' => strlen($primaryContent),
            'elseLength' => strlen($elseContent),
            'primaryStart' => substr($primaryContent, 0, 50),
            'primaryEnd' => substr($primaryContent, -50),
            'elseStart' => substr($elseContent, 0, 50),
            'elseEnd' => substr($elseContent, -50),
        ]);

        // Determine which branch is the empty state
        if ($checksEmpty) {
            $emptyContent = trim($primaryContent);
            $hasItemsContent = trim($elseContent);
        } else {
            $emptyContent = trim($elseContent);
            $hasItemsContent = trim($primaryContent);
        }

        // Add x-show attributes to the first element in each branch
        // This preserves the original structure and doesn't break parent spacing classes
        $emptyWithXShow = $this->addXShowToFirstElement($emptyContent, "{$collectionName}.length === 0");
        $itemsWithXShow = $this->addXShowToFirstElement($hasItemsContent, "{$collectionName}.length > 0");

        $replacement = trim($emptyWithXShow) . "\n" . trim($itemsWithXShow);

        \Log::info('[Duo] Built replacement', [
            'replacementLength' => strlen($replacement),
        ]);

        // Replace in the source (add +1 to include the last character of @endif)
        $transformed = substr_replace($blade, $replacement, $ifStart, ($endifEnd + 1) - $ifStart);

        return $transformed;
    }

    /**
     * Add x-show attribute to the first element in the content
     */
    protected function addXShowToFirstElement(string $content, string $condition): string
    {
        // Find the first opening tag (either <div, <template, <p, etc.)
        if (preg_match('/<(\w+)(\s[^>]*)?>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $tagName = $matches[1][0];
            $existingAttrs = $matches[2][0] ?? '';
            $tagStart = $matches[0][1];
            $tagLength = strlen($matches[0][0]);

            // Build the new opening tag with x-show
            $newTag = "<{$tagName}{$existingAttrs} x-show=\"{$condition}\">";

            // Replace the original tag
            return substr_replace($content, $newTag, $tagStart, $tagLength);
        }

        // If no element found, wrap with div (fallback)
        return "<div x-show=\"{$condition}\">\n{$content}\n</div>";
    }

    /**
     * Transform @foreach loops in the Blade source to Alpine x-for
     */
    protected function transformForeachInBladeSource(string $blade): string
    {
        \Log::info('[Duo] Transforming @foreach loops in Blade source');

        // Re-parse to get fresh structures
        $this->bladeDocument = Document::fromText($blade);
        $this->bladeDocument->resolveStructures();

        $foreachDirectives = $this->bladeDocument->findDirectivesByName('foreach');

        foreach ($foreachDirectives->all() as $directive) {
            $loopInfo = $this->parseLoopArguments($directive->arguments->content ?? '');
            if (! $loopInfo) {
                continue;
            }

            $collectionName = $loopInfo['collection'];
            $itemVarName = $loopInfo['itemVar'];

            // Check if this collection is in our component data
            if (! isset($this->componentData[$collectionName])) {
                continue;
            }

            \Log::info('[Duo] Transforming @foreach in source', [
                'collection' => $collectionName,
                'itemVar' => $itemVarName,
            ]);

            // Find the matching @endforeach
            $endforeachNode = $this->findMatchingEndforeach($directive);
            if (! $endforeachNode) {
                \Log::warning('[Duo] Could not find matching @endforeach');
                continue;
            }

            // Extract the content between @foreach and @endforeach
            $foreachStart = $directive->position->startOffset;
            $foreachEnd = $directive->position->endOffset;
            $endforeachStart = $endforeachNode->position->startOffset;
            $endforeachEnd = $endforeachNode->position->endOffset;

            \Log::info('[Duo] Foreach positions', [
                'foreach_startOffset' => $foreachStart,
                'foreach_endOffset' => $foreachEnd,
                'foreach_directive' => substr($blade, $foreachStart, 40),
                'endforeach_startOffset' => $endforeachStart,
                'endforeach_endOffset' => $endforeachEnd,
                'endforeach_directive' => substr($blade, $endforeachStart, 15),
                'char_before_endforeach' => substr($blade, $endforeachStart - 1, 1),
                'char_at_endforeach' => substr($blade, $endforeachStart, 1),
            ]);

            $rawLoopContent = substr($blade, $foreachEnd, $endforeachStart - $foreachEnd);

            \Log::info('[Duo] Raw loop extraction', [
                'length' => strlen($rawLoopContent),
                'first_10_chars' => substr($rawLoopContent, 0, 10),
                'last_10_chars' => substr($rawLoopContent, -10),
            ]);

            // Skip past the newline after the @foreach directive, and trim whitespace before @endforeach
            $loopContent = preg_replace('/^[^\n]*\n/', '', $rawLoopContent, 1);
            $loopContent = rtrim($loopContent);

            // Transform the loop content to Alpine
            $transformedContent = $this->transformLoopContentToAlpine($loopContent, $itemVarName);

            // Build the collection expression with sorting if available
            $collectionExpr = $this->buildCollectionExpression($collectionName);

            // Wrap with x-for template
            $replacement = <<<BLADE
<template x-for="{$itemVarName} in {$collectionExpr}" :key="{$itemVarName}.id">
{$transformedContent}
</template>
BLADE;

            // Replace the @foreach...@endforeach block (add +1 to include the last character of @endforeach)
            $blade = substr_replace(
                $blade,
                $replacement,
                $foreachStart,
                ($endforeachNode->position->endOffset + 1) - $foreachStart
            );

            // Re-parse after modifying
            $this->bladeDocument = Document::fromText($blade);
            $this->bladeDocument->resolveStructures();
        }

        return $blade;
    }

    /**
     * Build collection expression with sorting if available
     */
    protected function buildCollectionExpression(string $collectionName): string
    {
        // Check if we have orderBy information for this collection
        if (!$this->orderBy || !isset($this->orderBy[$collectionName])) {
            return $collectionName;
        }

        $orderInfo = $this->orderBy[$collectionName];
        $column = $orderInfo['column'];
        $direction = $orderInfo['direction'] ?? 'asc';

        // Build JavaScript sort expression
        // For descending: array.sort((a, b) => b.column > a.column ? 1 : -1)
        // For ascending: array.sort((a, b) => a.column > b.column ? 1 : -1)
        if ($direction === 'desc') {
            return "[...{$collectionName}].sort((a, b) => {
                const aVal = a.{$column};
                const bVal = b.{$column};
                if (aVal === bVal) return 0;
                return bVal > aVal ? 1 : -1;
            })";
        }

        return "[...{$collectionName}].sort((a, b) => {
            const aVal = a.{$column};
            const bVal = b.{$column};
            if (aVal === bVal) return 0;
            return aVal > bVal ? 1 : -1;
        })";
    }

    /**
     * Transform loop content from Blade to Alpine
     */
    protected function transformLoopContentToAlpine(string $content, string $itemVar): string
    {
        // Remove wire:key attributes
        $content = preg_replace('/\s*wire:key="[^"]*"/', '', $content);

        // Transform @click="method({{ $item }})" to @click="method(item)"
        // Note: wire:click has already been converted to @click by the global replacement
        $content = preg_replace_callback(
            '/@click="(\w+)\(\{\{\s*\$' . $itemVar . '\s*\}\}\)"/',
            function ($matches) use ($itemVar) {
                $method = $matches[1];
                return '@click="' . $method . '(' . $itemVar . ')"';
            },
            $content
        );

        // Transform {{ $item->property }} expressions
        // Replace with <span x-text="..."></span>
        $content = preg_replace_callback(
            '/\{\{\s*\$' . $itemVar . '->(\w+)(?:->(\w+)\(\))?\s*\}\}/',
            function ($matches) use ($itemVar) {
                $property = $matches[1];
                $method = $matches[2] ?? null;

                // Handle method calls like ->created_at->diffForHumans()
                if ($method) {
                    return '<span x-text="diffForHumans(' . $itemVar . '.' . $property . ', _now)"></span>';
                }
                return '<span x-text="' . $itemVar . '.' . $property . '"></span>';
            },
            $content
        );

        return $content;
    }

    /**
     * Find the matching @endforeach for a @foreach directive
     */
    protected function findMatchingEndforeach(DirectiveNode $foreachDirective): ?DirectiveNode
    {
        $depth = 0;
        $foreachPosition = $foreachDirective->position->startOffset;

        foreach ($this->bladeDocument->getNodes() as $node) {
            if (! $node instanceof DirectiveNode) {
                continue;
            }

            if ($node->position->startOffset < $foreachPosition) {
                continue;
            }

            // Track nesting depth
            if ($node->content === 'foreach' || $node->content === 'forelse') {
                $depth++;
            } elseif ($node->content === 'endforeach' || $node->content === 'endforelse') {
                $depth--;
                if ($depth === 0) {
                    return $node;
                }
            }
        }

        return null;
    }

    /**
     * Find the matching @endif for an @if directive
     */
    protected function findMatchingEndif(DirectiveNode $ifDirective): ?DirectiveNode
    {
        $allDirectives = $this->bladeDocument->findDirectivesByName('endif')->all();

        // Find the first @endif that comes after this @if
        // We need to match nesting depth
        $depth = 0;
        $ifPosition = $ifDirective->position->startOffset;

        foreach ($this->bladeDocument->getNodes() as $node) {
            if (! $node instanceof DirectiveNode) {
                continue;
            }

            if ($node->position->startOffset < $ifPosition) {
                continue;
            }

            // Track nesting depth
            if ($node->content === 'if' || $node->content === 'unless') {
                $depth++;
            } elseif ($node->content === 'endif') {
                $depth--;
                if ($depth === 0) {
                    return $node;
                }
            }
        }

        return null;
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
     * Ensure both empty and populated states exist in HTML for @if/@else conditionals
     */
    protected function ensureBothConditionalStates(string $html): string
    {
        $ifDirectives = $this->bladeDocument->findDirectivesByName('if');

        // Find all nested Livewire component boundaries to skip them
        $nestedComponentRanges = $this->findNestedLivewireComponentRanges();

        foreach ($ifDirectives->all() as $directive) {
            // Skip if this directive is inside a nested Livewire component
            if ($this->isInsideNestedComponent($directive, $nestedComponentRanges)) {
                \Log::info('[Duo] Skipping @if inside nested Livewire component', [
                    'position' => $directive->position->startOffset ?? 'unknown',
                ]);
                continue;
            }

            $condition = $directive->arguments->content ?? '';

            // Try to detect if this condition checks a collection's emptiness
            $collectionInfo = $this->detectCollectionCondition($condition);

            if (! $collectionInfo) {
                continue;
            }

            $collectionName = $collectionInfo['collection'];
            $checksEmpty = $collectionInfo['checksEmpty'];

            if (! isset($this->componentData[$collectionName])) {
                continue;
            }

            if (! $directive->structure || ! $directive->structure->hasElseBranch()) {
                continue;
            }

            \Log::info('[Duo] Found @if/@else with collection check', [
                'collection' => $collectionName,
                'checksEmpty' => $checksEmpty,
                'condition' => $condition,
            ]);

            $html = $this->injectMissingConditionalBranch($html, $directive, $collectionName, $checksEmpty);
        }

        return $html;
    }

    /**
     * Find position ranges of nested Livewire components in the Blade source
     */
    protected function findNestedLivewireComponentRanges(): array
    {
        $ranges = [];
        $bladeContent = $this->bladeDocument->toString();

        \Log::info('[Duo] Searching for nested Livewire components', [
            'bladeLength' => strlen($bladeContent),
        ]);

        // Pattern to match <livewire:component-name /> or @livewire('component-name')
        // We look for opening and closing tags/directives

        // Match @livewire('component')
        if (preg_match_all('/@livewire\s*\([\'"]([^\'"]+)[\'"]\s*(?:,\s*\[.*?\])?\)/s', $bladeContent, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $ranges[] = [
                    'start' => $match[1],
                    'end' => $match[1] + strlen($match[0]),
                ];
            }
        }

        // Match <livewire:component-name ... /> (self-closing)
        if (preg_match_all('/<livewire:[^>]+\/>/s', $bladeContent, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $ranges[] = [
                    'start' => $match[1],
                    'end' => $match[1] + strlen($match[0]),
                ];
            }
        }

        // Match <livewire:component-name ...>...</livewire:component-name> (with closing tag)
        if (preg_match_all('/<livewire:([a-z\-]+)([^>]*)>(.*?)<\/livewire:\1>/s', $bladeContent, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $ranges[] = [
                    'start' => $match[1],
                    'end' => $match[1] + strlen($match[0]),
                ];
            }
        }

        \Log::info('[Duo] Found nested Livewire component ranges', [
            'count' => count($ranges),
            'ranges' => $ranges,
        ]);

        return $ranges;
    }

    /**
     * Check if a directive is inside a nested Livewire component
     */
    protected function isInsideNestedComponent($directive, array $ranges): bool
    {
        $directiveStart = $directive->position->startOffset ?? null;

        if ($directiveStart === null) {
            return false;
        }

        foreach ($ranges as $range) {
            if ($directiveStart >= $range['start'] && $directiveStart <= $range['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find nested Livewire component boundaries in rendered HTML
     * by looking for divs with wire:snapshot and wire:id attributes
     */
    protected function findNestedLivewireComponentRangesInHtml(string $html): array
    {
        $ranges = [];

        // Find all Livewire component divs (they have wire:snapshot, wire:effects, and wire:id)
        // Pattern: <div wire:snapshot="..." ... wire:id="...">
        preg_match_all('/<div[^>]+wire:snapshot[^>]+wire:id="([^"]+)"[^>]*>/s', $html, $matches, PREG_OFFSET_CAPTURE);

        \Log::info('[Duo] Searching for wire:snapshot divs in HTML', [
            'totalMatches' => count($matches[0]),
            'htmlLength' => strlen($html),
            'htmlPreview' => substr($html, 0, 500),
        ]);

        if (count($matches[0]) <= 1) {
            // Only one component (the root), no nested components
            \Log::info('[Duo] Only one or zero wire:snapshot divs found, no nested components');

            return [];
        }

        // Skip the first match (that's the root component), process the rest as nested
        for ($i = 1; $i < count($matches[0]); $i++) {
            $openingTag = $matches[0][$i][0];
            $startPos = $matches[0][$i][1];
            $wireId = $matches[1][$i][0];

            // Find the matching closing </div> by counting div depth
            $endPos = $this->findMatchingClosingDiv($html, $startPos);

            if ($endPos !== null) {
                $ranges[] = [
                    'start' => $startPos,
                    'end' => $endPos,
                    'wireId' => $wireId,
                ];
            }
        }

        return $ranges;
    }

    /**
     * Find the position of the matching closing </div> tag
     * by counting div depth from a starting position
     */
    protected function findMatchingClosingDiv(string $html, int $startPos): ?int
    {
        $depth = 1;
        $pos = $startPos;

        // Move past the opening tag
        $pos = strpos($html, '>', $pos);
        if ($pos === false) {
            return null;
        }
        $pos++;

        // Find matching closing tag by counting depth
        while ($depth > 0 && $pos < strlen($html)) {
            // Find next opening or closing div tag
            $nextOpen = strpos($html, '<div', $pos);
            $nextClose = strpos($html, '</div>', $pos);

            if ($nextClose === false) {
                // No more closing tags
                return null;
            }

            if ($nextOpen !== false && $nextOpen < $nextClose) {
                // Found opening tag before closing tag
                $depth++;
                $pos = $nextOpen + 4;
            } else {
                // Found closing tag
                $depth--;
                if ($depth === 0) {
                    // This is our matching closing tag
                    return $nextClose + 6; // +6 for length of "</div>"
                }
                $pos = $nextClose + 6;
            }
        }

        return null;
    }

    /**
     * Detect if a condition checks a collection's emptiness/fullness
     * Returns ['collection' => 'todos', 'checksEmpty' => true/false] or null
     */
    protected function detectCollectionCondition(string $condition): ?array
    {
        $originalCondition = $condition;

        // Remove outer parentheses more carefully - only remove matching outer parens, not all parens
        $condition = trim($condition);
        if (str_starts_with($condition, '(') && str_ends_with($condition, ')')) {
            // Remove one layer of outer parentheses
            $condition = substr($condition, 1, -1);
            $condition = trim($condition);
        }

        \Log::info('[Duo] Pattern detection debug', [
            'original' => $originalCondition,
            'after_trim' => $condition,
            'pattern1_test' => preg_match('/\$this->(\w+)->isEmpty\(\)/', $condition),
        ]);

        // Pattern 1: $this->collection->isEmpty()
        if (preg_match('/\$this->(\w+)->isEmpty\(\)/', $condition, $matches)) {
            \Log::info('[Duo] Matched Pattern 1 (isEmpty)', ['collection' => $matches[1]]);

            return ['collection' => $matches[1], 'checksEmpty' => true];
        }

        // Pattern 2: !$this->collection->isEmpty()
        if (preg_match('/!\s*\$this->(\w+)->isEmpty\(\)/', $condition, $matches)) {
            return ['collection' => $matches[1], 'checksEmpty' => false];
        }

        // Pattern 3: empty($this->collection) or empty($collection)
        if (preg_match('/empty\(\s*\$(?:this->)?(\w+)\s*\)/', $condition, $matches)) {
            return ['collection' => $matches[1], 'checksEmpty' => true];
        }

        // Pattern 4: !empty($this->collection) or !empty($collection)
        if (preg_match('/!\s*empty\(\s*\$(?:this->)?(\w+)\s*\)/', $condition, $matches)) {
            return ['collection' => $matches[1], 'checksEmpty' => false];
        }

        // Pattern 5: $this->collection->count() === 0 (or == 0)
        if (preg_match('/\$(?:this->)?(\w+)->count\(\)\s*(?:===|==)\s*0/', $condition, $matches)) {
            return ['collection' => $matches[1], 'checksEmpty' => true];
        }

        // Pattern 6: $this->collection->count() > 0
        if (preg_match('/\$(?:this->)?(\w+)->count\(\)\s*>\s*0/', $condition, $matches)) {
            return ['collection' => $matches[1], 'checksEmpty' => false];
        }

        // Pattern 7: !$this->collection->count()
        if (preg_match('/!\s*\$(?:this->)?(\w+)->count\(\)/', $condition, $matches)) {
            return ['collection' => $matches[1], 'checksEmpty' => true];
        }

        // Pattern 8: $this->collection->count() (truthy check - has items)
        if (preg_match('/^\$(?:this->)?(\w+)->count\(\)$/', $condition, $matches)) {
            return ['collection' => $matches[1], 'checksEmpty' => false];
        }

        // Pattern 9: count($this->collection) === 0 (or == 0)
        if (preg_match('/count\(\s*\$(?:this->)?(\w+)\s*\)\s*(?:===|==)\s*0/', $condition, $matches)) {
            return ['collection' => $matches[1], 'checksEmpty' => true];
        }

        // Pattern 10: count($this->collection) > 0
        if (preg_match('/count\(\s*\$(?:this->)?(\w+)\s*\)\s*>\s*0/', $condition, $matches)) {
            return ['collection' => $matches[1], 'checksEmpty' => false];
        }

        // Pattern 11: $this->collection->isNotEmpty()
        if (preg_match('/\$(?:this->)?(\w+)->isNotEmpty\(\)/', $condition, $matches)) {
            return ['collection' => $matches[1], 'checksEmpty' => false];
        }

        // Check if any of our known collections are mentioned
        // This is a fallback - try to detect collection mentions
        foreach (array_keys($this->componentData) as $key) {
            if (str_contains($condition, '$this->'.$key) || str_contains($condition, '$'.$key)) {
                // Collection is mentioned, try to infer if checking empty or not
                // If condition contains !, empty, === 0, == 0, it's likely checking empty
                if (preg_match('/(!|empty|===\s*0|==\s*0)/', $condition)) {
                    \Log::info('[Duo] Using fallback pattern - detected checksEmpty=true', [
                        'collection' => $key,
                        'condition' => $condition,
                    ]);

                    return ['collection' => $key, 'checksEmpty' => true];
                }
                // Otherwise assume checking for has items
                \Log::info('[Duo] Using fallback pattern - detected checksEmpty=false', [
                    'collection' => $key,
                    'condition' => $condition,
                ]);

                return ['collection' => $key, 'checksEmpty' => false];
            }
        }

        return null;
    }

    /**
     * Inject the missing conditional branch into HTML
     */
    protected function injectMissingConditionalBranch(string $html, $directive, string $collectionName, bool $conditionChecksEmpty): string
    {
        $structure = $directive->structure;
        $primaryBranch = $structure->getPrimaryBranch(); // @if branch
        $elseBranches = $structure->getElseBranches();
        $elseBranch = $elseBranches[0] ?? null;

        if (! $elseBranch) {
            return $html;
        }

        // Determine current state
        $collection = $this->componentData[$collectionName];
        $collectionIsEmpty = is_countable($collection) ? count($collection) === 0 : empty($collection);

        \Log::info('[Duo] Processing conditional branch injection', [
            'collection' => $collectionName,
            'collectionIsEmpty' => $collectionIsEmpty,
            'conditionChecksEmpty' => $conditionChecksEmpty,
        ]);

        // Determine which branch contains which state based on the condition
        if ($conditionChecksEmpty) {
            // @if(isEmpty) means: primary = empty state, else = has items
            $emptyStateBranch = $primaryBranch;
            $hasItemsBranch = $elseBranch;
        } else {
            // @if(isNotEmpty) means: primary = has items, else = empty state
            $emptyStateBranch = $elseBranch;
            $hasItemsBranch = $primaryBranch;
        }

        // DEBUG: Let's see what's in these branch objects
        \Log::info('[Duo] Branch object inspection', [
            'emptyStateBranch_class' => get_class($emptyStateBranch),
            'emptyStateBranch_properties' => get_object_vars($emptyStateBranch),
            'hasItemsBranch_class' => get_class($hasItemsBranch),
            'hasItemsBranch_properties' => get_object_vars($hasItemsBranch),
        ]);

        // Find ALL BLOCK/ENDBLOCK pairs in the rendered HTML
        preg_match_all('/<!--\[if BLOCK\]><!\[endif\]-->(.*?)<!--\[if ENDBLOCK\]><!\[endif\]-->/s', $html, $allMatches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        \Log::info('[Duo] BLOCK marker analysis', [
            'totalBlocks' => count($allMatches),
            'htmlLength' => strlen($html),
        ]);

        // Find the LAST BLOCK (nested components render first, so their BLOCKs appear earlier)
        // The main component's BLOCK will be the last one in the HTML
        $match = null;
        if (count($allMatches) > 0) {
            // Take the last BLOCK marker
            $match = end($allMatches);

            \Log::info('[Duo] Selected LAST BLOCK marker (main component)', [
                'totalBlocks' => count($allMatches),
                'selectedPosition' => $match[0][1],
            ]);
        }

        if (! $match) {
            \Log::warning('[Duo] No BLOCK/ENDBLOCK pair found outside nested components');

            return $html;
        }

        $renderedBlockHtml = $match[1][0];
        $blockStart = $match[0][1];
        $blockLength = strlen($match[0][0]);

        \Log::info('[Duo] Found BLOCK/ENDBLOCK pair outside nested components', [
            'blockStart' => $blockStart,
            'blockLength' => $blockLength,
            'renderedHtmlPreview' => substr($renderedBlockHtml, 0, 200),
        ]);

        // Determine which branch was rendered and which needs to be injected
        if ($collectionIsEmpty) {
            // Collection is empty, so the empty state branch was rendered
            $emptyStateHtml = $renderedBlockHtml;

            // For the has-items branch, we need to render it with sample data
            $sampleData = $this->createSampleDataForCollection($collectionName);
            $hasItemsHtml = $this->renderBranchContent($hasItemsBranch->content, $collectionName, array_merge($this->componentData, [$collectionName => $sampleData]));
        } else {
            // Collection has items, so the has-items branch was rendered
            $hasItemsHtml = $renderedBlockHtml;

            // For the empty branch, render with empty collection
            $emptyStateHtml = $this->renderBranchContent($emptyStateBranch->content, $collectionName, array_merge($this->componentData, [$collectionName => []]));
        }

        // Build replacement with both states
        $replacement = <<<HTML
        <div x-show="{$collectionName}.length === 0">
            {$emptyStateHtml}
        </div>
        <div x-show="{$collectionName}.length > 0">
            {$hasItemsHtml}
        </div>
        HTML;

        \Log::info('[Duo] Built replacement HTML', [
            'emptyStateLength' => strlen($emptyStateHtml),
            'hasItemsLength' => strlen($hasItemsHtml),
            'replacementLength' => strlen($replacement),
            'replacementPreview' => substr($replacement, 0, 300),
        ]);

        $result = substr_replace($html, $replacement, $blockStart, $blockLength);

        \Log::info('[Duo] Replacement complete', [
            'originalLength' => strlen($html),
            'resultLength' => strlen($result),
            'changed' => strlen($html) !== strlen($result),
        ]);

        return $result;
    }

    /**
     * Create sample data for a collection to enable rendering of @foreach loops
     */
    protected function createSampleDataForCollection(string $collectionName): array
    {
        // Check if we have any existing items in the collection to use as a template
        $collection = $this->componentData[$collectionName] ?? null;

        if ($collection && is_countable($collection) && count($collection) > 0) {
            // Use existing items
            return is_object($collection) && method_exists($collection, 'toArray') ? $collection->toArray() : (array) $collection;
        }

        // Try to find the model from our component data
        $modelName = ucfirst(rtrim($collectionName, 's'));

        // Create a minimal sample record with common fields
        $sampleRecord = [
            'id' => 1,
            'title' => 'Sample',
            'name' => 'Sample',
            'description' => 'Sample',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Wrap in a collection-like structure
        return [$sampleRecord];
    }

    /**
     * Render Blade branch content to HTML
     */
    protected function renderBranchContent(string $bladeContent, string $collectionName, array $data): string
    {
        // Remove outer whitespace
        $bladeContent = trim($bladeContent);

        \Log::info('[Duo] Rendering branch content', [
            'collectionName' => $collectionName,
            'bladeContentLength' => strlen($bladeContent),
            'bladePreview' => substr($bladeContent, 0, 200),
            'dataKeys' => array_keys($data),
        ]);

        // Replace ALL $this->propertyName with $propertyName so it works in eval scope
        // This allows @foreach($this->todos as $todo) to work with our $data array
        // It also handles any other $this-> references like $this->user, etc.
        $bladeContent = preg_replace_callback('/\$this->(\w+)\b/', function ($matches) use ($data) {
            $propertyName = $matches[1];
            // Only replace if we have this property in our data
            if (array_key_exists($propertyName, $data)) {
                return '$'.$propertyName;
            }

            // Keep the original if we don't have it (will likely error, but that's expected)
            return $matches[0];
        }, $bladeContent);

        \Log::info('[Duo] Transformed Blade content', [
            'transformedPreview' => substr($bladeContent, 0, 200),
        ]);

        // Use Blade to compile and render this content
        try {
            $compiled = \Blade::compileString($bladeContent);

            // Create isolated scope with data
            $__data = array_merge(['component' => $this->component], $data);

            // Extract variables for the view scope
            extract($__data, EXTR_SKIP);

            // Evaluate the compiled PHP
            ob_start();
            eval('?>'.$compiled);

            $result = ob_get_clean();

            \Log::info('[Duo] Branch rendered successfully', [
                'resultLength' => strlen($result),
                'resultPreview' => substr($result, 0, 200),
            ]);

            return $result;
        } catch (\Exception $e) {
            \Log::error('[Duo] Failed to render branch content', [
                'error' => $e->getMessage(),
                'content' => substr($bladeContent, 0, 200),
            ]);

            return '';
        }
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

        // Step 1: Find and transform Carbon date method calls in the Blade source
        $dateMethodMappings = $this->findDateMethodCalls($itemVarName, $sampleItem);

        // Step 2: Replace date method outputs with Alpine helper calls
        foreach ($dateMethodMappings as $mapping) {
            $html = $this->replaceDateMethodInHtml($html, $mapping);
        }

        // Step 3: Replace actual values with Alpine x-text bindings (for non-date fields)
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

        // Step 4: Replace wire:click with Alpine @click
        // Match wire:click="method(...)" and replace entire parameter with item variable
        $html = preg_replace(
            '/wire:click="([a-zA-Z_]\w*)\(.*?\)"/',
            '@click="$1('.$itemVarName.')"',
            $html
        );

        return $html;
    }

    /**
     * Find Carbon date method calls in the Blade source
     * Returns array of mappings: ['rendered_value' => ..., 'alpine_expression' => ...]
     */
    protected function findDateMethodCalls(string $itemVarName, mixed $sampleItem): array
    {
        $mappings = [];
        $bladeSource = $this->bladeDocument->toString();

        // Common Carbon methods to detect
        $carbonMethods = [
            'diffForHumans' => [],
            'format' => ['arg'],
            'toDateString' => [],
            'toTimeString' => [],
            'toDateTimeString' => [],
            'toFormattedDateString' => [],
        ];

        // Find all echo expressions in the Blade source {{ ... }}
        preg_match_all('/\{\{\s*\$'.$itemVarName.'->(\w+)->(\w+)\((.*?)\)\s*\}\}/', $bladeSource, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $fieldName = $match[1]; // e.g., "created_at"
            $methodName = $match[2]; // e.g., "diffForHumans"
            $methodArgs = trim($match[3]); // e.g., "'Y-m-d'" or empty

            // Check if this is a known Carbon method
            if (! isset($carbonMethods[$methodName])) {
                continue;
            }

            // Get the rendered value from the sample item
            if (! isset($sampleItem[$fieldName])) {
                continue;
            }

            $fieldValue = $sampleItem[$fieldName];

            // Try to render the method call to get the output value
            try {
                if (is_string($fieldValue)) {
                    // Parse as Carbon date
                    $date = \Carbon\Carbon::parse($fieldValue);
                } elseif ($fieldValue instanceof \Carbon\Carbon) {
                    $date = $fieldValue;
                } else {
                    continue;
                }

                // Call the method to get the rendered value
                if ($methodArgs) {
                    // Remove quotes from argument
                    $cleanArgs = trim($methodArgs, '\'"');
                    $renderedValue = $date->$methodName($cleanArgs);
                    // Add _now dependency for reactivity
                    $alpineExpression = "{$methodName}({$itemVarName}.{$fieldName}, '{$cleanArgs}', _now)";
                } else {
                    $renderedValue = $date->$methodName();
                    // Add _now dependency for reactivity
                    $alpineExpression = "{$methodName}({$itemVarName}.{$fieldName}, _now)";
                }

                $mappings[] = [
                    'rendered_value' => $renderedValue,
                    'alpine_expression' => $alpineExpression,
                    'field_name' => $fieldName,
                    'method_name' => $methodName,
                ];

                \Log::info('[Duo] Detected Carbon date method', [
                    'field' => $fieldName,
                    'method' => $methodName,
                    'rendered' => $renderedValue,
                    'alpine' => $alpineExpression,
                ]);
            } catch (\Exception $e) {
                \Log::warning('[Duo] Failed to parse date field', [
                    'field' => $fieldName,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return $mappings;
    }

    /**
     * Replace a date method's rendered output with Alpine x-text binding
     */
    protected function replaceDateMethodInHtml(string $html, array $mapping): string
    {
        $renderedValue = htmlspecialchars($mapping['rendered_value']);
        $alpineExpression = $mapping['alpine_expression'];
        $escapedValue = preg_quote($renderedValue, '/');

        // Find the rendered value in the HTML and wrap it with x-text
        // Look for the value inside tags
        $html = preg_replace(
            '/>(\s*)'.$escapedValue.'(\s*)</s',
            ' x-text="'.$alpineExpression.'">$1$2<',
            $html,
            1
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

        // Add reactive timestamp property for auto-updating relative times
        $parts[] = '_now: Date.now()';

        // Add Duo configuration
        $parts[] = '_duoConfig: '.json_encode($this->duoConfig);

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

        // Detect form fields - include only scalar values that can be saved to IndexedDB
        // This prevents computed properties, collections, and complex objects from being included
        $formFields = [];
        foreach ($this->componentData as $key => $value) {
            // Skip internal fields
            if (str_starts_with($key, '_')) {
                continue;
            }

            // Skip collections (even if empty)
            if (is_object($value) && method_exists($value, 'toArray')) {
                continue;
            }

            // Skip arrays and traversable objects
            if (is_array($value) || $value instanceof \Traversable) {
                continue;
            }

            // Skip Eloquent models
            if (is_object($value) && method_exists($value, 'getKey')) {
                continue;
            }

            // Skip other complex objects (keep only objects with __toString for things like Carbon dates)
            if (is_object($value) && ! method_exists($value, '__toString')) {
                continue;
            }

            $formFields[] = $key;
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

                // UI will auto-update via liveQuery (no manual sync needed)
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

                // UI will auto-update via liveQuery (no manual sync needed)
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

                // UI will auto-update via liveQuery (no manual sync needed)
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
        // Check if it's a Collection (even if empty)
        return is_object($value) && method_exists($value, 'toArray');
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

        // Generate timestamp refresh method for reactive timestamps
        $methods['setupTimestampRefresh'] = $this->generateSetupTimestampRefreshMethod();

        // Generate date formatting helpers (for Carbon-style methods)
        $methods['diffForHumans'] = $this->generateDiffForHumansMethod();
        $methods['format'] = $this->generateFormatDateMethod();
        $methods['toDateString'] = $this->generateToDateStringMethod();
        $methods['toTimeString'] = $this->generateToTimeStringMethod();
        $methods['toDateTimeString'] = $this->generateToDateTimeStringMethod();
        $methods['toFormattedDateString'] = $this->generateToFormattedDateStringMethod();

        // Generate init method
        $methods['init'] = $this->generateInitMethod();

        return $methods;
    }

    /**
     * Generate diffForHumans method for Carbon-style date formatting
     */
    protected function generateDiffForHumansMethod(): string
    {
        return "diffForHumans(date, _nowParam = null) {
            if (!date) return '';

            // _nowParam is used to make Alpine reactive to _now changes
            // We use the actual current time for calculation
            const now = new Date();
            const then = new Date(date);
            const seconds = Math.floor((now - then) / 1000);

            if (seconds < 5) return 'just now';
            if (seconds < 60) return seconds + ' seconds ago';

            const minutes = Math.floor(seconds / 60);
            if (minutes < 60) return minutes === 1 ? '1 minute ago' : minutes + ' minutes ago';

            const hours = Math.floor(minutes / 60);
            if (hours < 24) return hours === 1 ? '1 hour ago' : hours + ' hours ago';

            const days = Math.floor(hours / 24);
            if (days < 7) return days === 1 ? '1 day ago' : days + ' days ago';

            const weeks = Math.floor(days / 7);
            if (weeks < 4) return weeks === 1 ? '1 week ago' : weeks + ' weeks ago';

            const months = Math.floor(days / 30);
            if (months < 12) return months === 1 ? '1 month ago' : months + ' months ago';

            const years = Math.floor(days / 365);
            return years === 1 ? '1 year ago' : years + ' years ago';
        }";
    }

    /**
     * Generate format method for generic date formatting
     */
    protected function generateFormatDateMethod(): string
    {
        return "format(date, formatString = 'Y-m-d', _nowParam = null) {
            if (!date) return '';

            const d = new Date(date);

            // Convert PHP date format to JavaScript
            const year = d.getFullYear();
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            const hours = String(d.getHours()).padStart(2, '0');
            const minutes = String(d.getMinutes()).padStart(2, '0');
            const seconds = String(d.getSeconds()).padStart(2, '0');

            // Map common PHP date formats to JavaScript output
            const formats = {
                'Y-m-d': `\${year}-\${month}-\${day}`,
                'Y-m-d H:i:s': `\${year}-\${month}-\${day} \${hours}:\${minutes}:\${seconds}`,
                'd/m/Y': `\${day}/\${month}/\${year}`,
                'm/d/Y': `\${month}/\${day}/\${year}`,
            };

            if (formats[formatString]) {
                return eval('`' + formats[formatString] + '`');
            }

            // Handle named formats
            if (formatString === 'short') return d.toLocaleDateString();
            if (formatString === 'long') return d.toLocaleString();

            // Default: return ISO string
            return d.toISOString();
        }";
    }

    /**
     * Generate JavaScript method for toDateString
     */
    protected function generateToDateStringMethod(): string
    {
        return "toDateString(date, _nowParam = null) {
            if (!date) return '';
            const d = new Date(date);
            return d.toDateString();
        }";
    }

    /**
     * Generate JavaScript method for toTimeString
     */
    protected function generateToTimeStringMethod(): string
    {
        return "toTimeString(date, _nowParam = null) {
            if (!date) return '';
            const d = new Date(date);
            return d.toTimeString().split(' ')[0]; // Return just the time part
        }";
    }

    /**
     * Generate JavaScript method for toDateTimeString
     */
    protected function generateToDateTimeStringMethod(): string
    {
        return "toDateTimeString(date, _nowParam = null) {
            if (!date) return '';
            const d = new Date(date);
            const year = d.getFullYear();
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            const hours = String(d.getHours()).padStart(2, '0');
            const minutes = String(d.getMinutes()).padStart(2, '0');
            const seconds = String(d.getSeconds()).padStart(2, '0');
            return `\${year}-\${month}-\${day} \${hours}:\${minutes}:\${seconds}`;
        }";
    }

    /**
     * Generate JavaScript method for toFormattedDateString
     */
    protected function generateToFormattedDateStringMethod(): string
    {
        return "toFormattedDateString(date, _nowParam = null) {
            if (!date) return '';
            const d = new Date(date);
            return d.toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }";
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

                // Generate sorting logic if ORDER BY exists
                $sortCode = '';
                if ($this->orderBy) {
                    $column = $this->orderBy['column'];
                    $direction = $this->orderBy['direction'];
                    $ascReturn = $direction === 'asc' ? '-1' : '1';
                    $descReturn = $direction === 'asc' ? '1' : '-1';
                    $sortCode = <<<JS

                    // Apply ORDER BY: {$column} {$direction}
                    records.sort((a, b) => {{
                        const aVal = a['{$column}'];
                        const bVal = b['{$column}'];
                        if (aVal === null && bVal === null) return 0;
                        if (aVal === null) return 1;
                        if (bVal === null) return -1;
                        if (aVal < bVal) return {$ascReturn};
                        if (aVal > bVal) return {$descReturn};
                        return 0;
                    }});
JS;
                }

                $syncOperations[] = "
            // Sync {$key} from IndexedDB
            try {
                const {$key}Store = db.getStore('{$storeName}');
                if ({$key}Store) {
                    const records = await {$key}Store.toArray();{$sortCode}
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

                // Generate sorting logic if ORDER BY exists
                $sortCode = '';
                if ($this->orderBy) {
                    $column = $this->orderBy['column'];
                    $direction = $this->orderBy['direction'];
                    $ascReturn = $direction === 'asc' ? '-1' : '1';
                    $descReturn = $direction === 'asc' ? '1' : '-1';
                    $sortCode = <<<JS

                                // Apply ORDER BY: {$column} {$direction}
                                items.sort((a, b) => {{
                                    const aVal = a['{$column}'];
                                    const bVal = b['{$column}'];
                                    if (aVal === null && bVal === null) return 0;
                                    if (aVal === null) return 1;
                                    if (bVal === null) return -1;
                                    if (aVal < bVal) return {$ascReturn};
                                    if (aVal > bVal) return {$descReturn};
                                    return 0;
                                }});
JS;
                }

                $subscriptions[] = "
            // Set up liveQuery for {$key} (multi-tab sync)
            try {
                const {$key}Store = db.getStore('{$storeName}');
                if ({$key}Store) {
                    const subscription = window.duo.liveQuery(() =>
                        {$key}Store.toArray().then(items =>
                            items.filter(item => item._duo_operation !== 'delete')
                        )
                    )
                        .subscribe(
                            items => {
                                console.log('[Duo] liveQuery updated {$key}:', items.length, 'items');{$sortCode}
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
            const debug = this._duoConfig?.debug || false;

            if (debug) console.log('[Duo] Initializing component with Duo...', this._duoConfig);

            // Wait for Duo client to be ready
            if (!window.duo) {
                if (debug) console.warn('[Duo] Duo client not found, waiting...');
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

                // Set up timestamp refresh timer
                this.setupTimestampRefresh();

                // Mark as ready
                this.duoLoading = false;
                this.duoReady = true;

                if (debug) console.log('[Duo] Component initialized and ready');
            } catch (err) {
                console.error('[Duo] Failed to initialize component:', err);
                this.duoLoading = false;
            }
        }";
    }

    /**
     * Generate method to set up timestamp refresh timer
     */
    protected function generateSetupTimestampRefreshMethod(): string
    {
        return "setupTimestampRefresh() {
            // Update _now at configured interval to refresh relative timestamps
            // This makes 'just now' turn into '1 minute ago' automatically
            const interval = this._duoConfig?.timestampRefreshInterval || 10000;

            if (this._duoConfig?.debug) {
                console.log('[Duo] Setting up timestamp refresh every', interval, 'ms');
            }

            const refreshInterval = setInterval(() => {
                this._now = Date.now();
                if (this._duoConfig?.debug) {
                    console.log('[Duo] Timestamps refreshed at', new Date(this._now).toISOString());
                }
            }, interval);

            // Clean up on destroy
            if (!this._intervals) this._intervals = [];
            this._intervals.push(refreshInterval);
        }";
    }
}
