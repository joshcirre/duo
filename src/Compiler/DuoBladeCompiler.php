<?php

declare(strict_types=1);

namespace JoshCirre\Duo\Compiler;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Facades\Forte;
use Forte\Rewriting\NodePath;

class DuoBladeCompiler
{
    /**
     * Transform a Blade template source, converting Livewire directives
     * to Alpine.js equivalents for Duo-enabled components.
     *
     * Only transforms templates that contain data-duo-* markers.
     */
    public function compile(string $template): string
    {
        if (! $this->shouldCompile($template)) {
            return $template;
        }

        $doc = Forte::parse($template);

        $doc = $doc->rewriteWith(function (NodePath $path) {
            $node = $path->node();

            if (! $node->isElement()) {
                return;
            }

            $element = $node->asElement();

            $this->rewriteWireSubmit($path, $element);
            $this->rewriteWireModel($path, $element);
            $this->rewriteWireClick($path, $element);
            $this->rewriteDuoBindings($path, $element);
        });

        $doc = $this->rewriteForelseCollections($doc);

        return $doc->render();
    }

    protected function shouldCompile(string $template): bool
    {
        return str_contains($template, 'data-duo-');
    }

    /**
     * wire:submit="method" → x-on:submit.prevent="duo.submit('method')"
     */
    protected function rewriteWireSubmit(NodePath $path, ElementNode $element): void
    {
        $wireSubmit = $this->findWireAttribute($element, 'wire:submit');
        if ($wireSubmit === null) {
            return;
        }

        $method = $wireSubmit['value'];
        $path->removeAttribute($wireSubmit['name']);
        $path->setAttribute('x-on:submit.prevent', "duo.submit('{$method}')");
    }

    /**
     * wire:model="field" → x-model="duo.form.field"
     * wire:model.live="field" → x-model="duo.form.field"
     */
    protected function rewriteWireModel(NodePath $path, ElementNode $element): void
    {
        $wireModel = $this->findWireAttribute($element, 'wire:model');
        if ($wireModel === null) {
            return;
        }

        $field = $wireModel['value'];
        $path->removeAttribute($wireModel['name']);
        $path->setAttribute('x-model', "duo.form.{$field}");
    }

    /**
     * wire:click="method" → x-on:click="duo.call('method')"
     * wire:click="method(args)" → x-on:click="duo.call('method', [args])"
     *
     * Inside a data-duo-template, model ID arguments like {{ $item->id }}
     * are rewritten to use Alpine's item.id reference.
     */
    protected function rewriteWireClick(NodePath $path, ElementNode $element): void
    {
        $wireClick = $this->findWireAttribute($element, 'wire:click');
        if ($wireClick === null) {
            return;
        }

        $expr = $wireClick['value'];
        $path->removeAttribute($wireClick['name']);

        $isInsideTemplate = $this->isInsideDuoTemplate($path);
        $alpineExpr = $this->buildAlpineCallExpression($expr, $isInsideTemplate);

        $path->setAttribute('x-on:click', $alpineExpr);
    }

    /**
     * Rewrite data-duo-* binding attributes to Alpine equivalents.
     *
     * data-duo-text="field" → x-text="item.field"
     * data-duo-html="field" → x-html="item.field"
     * data-duo-show="field" → x-show="item.field"
     * data-duo-checked="field" → :checked="item.field"
     * data-duo-bind:attr="field" → :attr="item.field"
     * data-duo-class="expression" → :class="expression"
     */
    protected function rewriteDuoBindings(NodePath $path, ElementNode $element): void
    {
        $mappings = [
            'data-duo-text' => 'x-text',
            'data-duo-html' => 'x-html',
            'data-duo-show' => 'x-show',
            'data-duo-checked' => ':checked',
        ];

        foreach ($mappings as $duoAttr => $alpineAttr) {
            if ($element->hasAttribute($duoAttr)) {
                $value = $element->getAttribute($duoAttr);
                $path->removeAttribute($duoAttr);
                $path->setAttribute($alpineAttr, "item.{$value}");
            }
        }

        if ($element->hasAttribute('data-duo-class')) {
            $value = $element->getAttribute('data-duo-class');
            $path->removeAttribute('data-duo-class');
            $path->setAttribute(':class', $value);
        }

        // data-duo-bind:* attributes
        foreach ($element->getAttributes() as $attr) {
            $name = $attr->nameText();
            if (str_starts_with($name, 'data-duo-bind:')) {
                $boundAttr = str_replace('data-duo-bind:', '', $name);
                $value = $attr->valueText();
                $path->removeAttribute($name);
                $path->setAttribute(":{$boundAttr}", "item.{$value}");
            }
        }
    }

    /*
     * Transform forelse blocks inside data-duo-collection containers.
     * (Plain comment: the Blade directives below would parse as PHPDoc tags.)
     *
     * Converts:
     *   <div data-duo-collection="todos" data-duo-key="id">
     *     @forelse($todos as $todo)
     *       <div data-duo-template wire:key="{{ $todo->id }}">...</div>
     *     @empty
     *       <div data-duo-empty>...</div>
     *     @endforelse
     *   </div>
     *
     * To:
     *   <div data-duo-collection="todos" data-duo-key="id" wire:ignore>
     *     <template x-for="item in duo.todos" :key="item.id">
     *       <div>...</div>
     *     </template>
     *     <div x-show="duo.todos.length === 0">...</div>
     *   </div>
     */
    protected function rewriteForelseCollections($doc)
    {
        return $doc->rewriteWith(function (NodePath $path) {
            if (! $path->isElement()) {
                return;
            }

            $element = $path->asElement();
            if (! $element->hasAttribute('data-duo-collection')) {
                return;
            }

            $collectionName = $element->getAttribute('data-duo-collection');
            $keyField = $element->getAttribute('data-duo-key') ?? 'id';

            // Add wire:ignore to prevent Livewire from updating this DOM
            $path->setAttribute('wire:ignore', '');

            // Find the @forelse block among children and rebuild contents
            $newInnerHtml = $this->buildCollectionTemplate(
                $element,
                $collectionName,
                $keyField
            );

            if ($newInnerHtml !== null) {
                $path->replaceChildren($newInnerHtml);
            }
        });
    }

    /**
     * Build the Alpine x-for template + empty state from a @forelse block.
     */
    protected function buildCollectionTemplate(
        ElementNode $container,
        string $collectionName,
        string $keyField
    ): ?string {
        $forelseBlock = null;

        foreach ($container->children() as $child) {
            if ($child instanceof DirectiveBlockNode && $child->isForelse()) {
                $forelseBlock = $child;
                break;
            }
        }

        if ($forelseBlock === null) {
            return null;
        }

        // In Forte's AST, content between @forelse and @empty is stored
        // as children of the @forelse DirectiveNode, and content between
        // @empty and @endforelse is children of the @empty DirectiveNode.
        $templateContent = '';
        $emptyContent = '';

        foreach ($forelseBlock->children() as $child) {
            if (! ($child instanceof DirectiveNode)) {
                continue;
            }

            $name = $child->nameText();

            if ($name === 'forelse') {
                foreach ($child->getChildren() as $innerChild) {
                    $templateContent .= $innerChild->render();
                }
            } elseif ($name === 'empty') {
                foreach ($child->getChildren() as $innerChild) {
                    $emptyContent .= $innerChild->render();
                }
            }
        }

        // Clean up the template item content
        $templateContent = $this->cleanTemplateItem($templateContent, $keyField);
        $emptyContent = $this->cleanEmptyContent($emptyContent, $collectionName);

        $result = "\n";
        $result .= "<template x-for=\"item in duo.{$collectionName}\" :key=\"item.{$keyField}\">";
        $result .= $templateContent;
        $result .= "</template>\n";

        if (trim($emptyContent) !== '') {
            $result .= $emptyContent;
        }

        return $result;
    }

    /**
     * Clean up a template item: remove data-duo-template, wire:key,
     * and add :key binding.
     */
    protected function cleanTemplateItem(string $html, string $keyField): string
    {
        // Remove data-duo-template attribute
        $html = preg_replace('/\s*data-duo-template/', '', $html);

        // Remove wire:key="{{ ... }}" attribute
        $html = preg_replace('/\s*wire:key="[^"]*"/', '', $html);

        // Add :key="item.keyField" to the first element
        $html = preg_replace(
            '/^(\s*<\w+)/',
            "$1 :key=\"item.{$keyField}\"",
            $html,
            1
        );

        // Strip Blade echo expressions inside elements that have x-text/x-html
        // bindings, since Alpine will handle the content display.
        // e.g., <p x-text="item.title">{{ $todo->title }}</p> → <p x-text="item.title"></p>
        $html = preg_replace(
            '/(<[^>]*x-text="[^"]*"[^>]*>)\s*\{\{[^}]*\}\}\s*(<\/)/s',
            '$1$2',
            $html
        );
        $html = preg_replace(
            '/(<[^>]*x-html="[^"]*"[^>]*>)\s*\{\{[^}]*\}\}\s*(<\/)/s',
            '$1$2',
            $html
        );

        // Strip remaining Blade echo expressions ({{ ... }}) that reference
        // the loop variable — since the @forelse is removed, these would
        // cause "undefined variable" errors at render time.
        // We replace them with empty strings inside the template.
        $html = preg_replace('/\{\{[^}]*\}\}/', '', $html);

        return $html;
    }

    /**
     * Clean up empty state content: remove data-duo-empty,
     * add x-show for empty state.
     */
    protected function cleanEmptyContent(string $html, string $collectionName): string
    {
        // Remove data-duo-empty attribute and add x-show
        $html = preg_replace(
            '/data-duo-empty/',
            "x-show=\"duo.{$collectionName}.length === 0\"",
            $html
        );

        return $html;
    }

    /**
     * Find a wire:* attribute on an element, handling modifiers like wire:model.live.
     *
     * @return array{name: string, value: string}|null
     */
    protected function findWireAttribute(ElementNode $element, string $prefix): ?array
    {
        foreach ($element->getAttributes() as $attr) {
            $name = $attr->nameText();
            if ($name === $prefix || str_starts_with($name, $prefix.'.')) {
                return [
                    'name' => $name,
                    'value' => $attr->valueText(),
                ];
            }
        }

        return null;
    }

    /**
     * Check if a node is inside a data-duo-template element.
     */
    protected function isInsideDuoTemplate(NodePath $path): bool
    {
        $ancestor = $path->findAncestor(function (Node $node) {
            if ($node instanceof ElementNode) {
                return $node->hasAttribute('data-duo-template');
            }

            return false;
        });

        return $ancestor !== null;
    }

    /**
     * Parse a wire:click expression and build an Alpine duo.call() expression.
     *
     * "deleteTodo({{ $todo->id }})" → "duo.call('deleteTodo', [item.id])"
     * "toggleTodo" → "duo.call('toggleTodo')"
     */
    protected function buildAlpineCallExpression(string $expr, bool $insideTemplate): string
    {
        $expr = trim($expr);

        // Simple method name (no arguments)
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)$/', $expr, $m)) {
            return "duo.call('{$m[1]}')";
        }

        // Method with arguments: method(args)
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\((.+)\)$/s', $expr, $m)) {
            $method = $m[1];
            $argsStr = trim($m[2]);

            if ($insideTemplate) {
                // Inside a template, convert Blade echo expressions to Alpine refs
                // {{ $todo->id }} → item.id
                // {{ $item->id }} → item.id
                $args = $this->parseTemplateArgs($argsStr);
            } else {
                $args = $this->parseStandaloneArgs($argsStr);
            }

            return "duo.call('{$method}', [{$args}])";
        }

        // Fallback: treat as simple method name
        return "duo.call('{$expr}')";
    }

    /**
     * Parse arguments inside a data-duo-template context.
     * Converts Blade echo expressions ({{ $var->prop }}) to Alpine refs (item.prop).
     */
    protected function parseTemplateArgs(string $argsStr): string
    {
        // Replace {{ $anything->property }} with item.property
        $args = preg_replace(
            '/\{\{\s*\$\w+->(\w+)\s*\}\}/',
            'item.$1',
            $argsStr
        );

        // Replace {{ $anything }} with item.id (common pattern)
        $args = preg_replace(
            '/\{\{\s*\$\w+\s*\}\}/',
            'item.id',
            $args
        );

        return $args;
    }

    /**
     * Parse arguments outside template context.
     * Keeps Blade expressions as-is since they'll be rendered server-side.
     */
    protected function parseStandaloneArgs(string $argsStr): string
    {
        return $argsStr;
    }
}
