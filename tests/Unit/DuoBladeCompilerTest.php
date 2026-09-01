<?php

declare(strict_types=1);

use JoshCirre\Duo\Compiler\DuoBladeCompiler;

beforeEach(function () {
    $this->compiler = new DuoBladeCompiler;
});

it('skips templates without data-duo markers', function () {
    $template = '<div><form wire:submit="save"><input wire:model="name"></form></div>';

    expect($this->compiler->compile($template))->toBe($template);
});

it('transforms wire:submit to x-on:submit.prevent', function () {
    $template = '<form wire:submit="addTodo" data-duo-collection="todos"></form>';
    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain('x-on:submit.prevent="duo.submit(\'addTodo\')"')
        ->not->toContain('wire:submit');
});

it('transforms wire:model to x-model', function () {
    $template = '<input wire:model="title" data-duo-collection="todos">';
    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain('x-model="duo.form.title"')
        ->not->toContain('wire:model');
});

it('transforms wire:model.live to x-model', function () {
    $template = '<input wire:model.live="title" data-duo-collection="todos">';
    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain('x-model="duo.form.title"')
        ->not->toContain('wire:model');
});

it('transforms wire:click without args to duo.call', function () {
    $template = '<button wire:click="save" data-duo-text="label">Save</button>';
    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain('x-on:click="duo.call(\'save\')"')
        ->not->toContain('wire:click');
});

it('transforms wire:click with args to duo.call', function () {
    $template = '<button wire:click="deleteTodo(5)" data-duo-text="label">Delete</button>';
    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain("x-on:click=\"duo.call('deleteTodo', [5])\"")
        ->not->toContain('wire:click');
});

it('transforms data-duo-text to x-text', function () {
    $template = '<h3 data-duo-text="title">Title</h3>';
    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain('x-text="item.title"')
        ->not->toContain('data-duo-text');
});

it('transforms data-duo-html to x-html', function () {
    $template = '<div data-duo-html="content">Content</div>';
    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain('x-html="item.content"')
        ->not->toContain('data-duo-html');
});

it('transforms data-duo-show to x-show', function () {
    $template = '<p data-duo-show="description">Desc</p>';
    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain('x-show="item.description"')
        ->not->toContain('data-duo-show');
});

it('transforms data-duo-checked to :checked', function () {
    $template = '<input type="checkbox" data-duo-checked="completed">';
    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain(':checked="item.completed"')
        ->not->toContain('data-duo-checked');
});

it('transforms data-duo-class to :class', function () {
    $template = '<div data-duo-class="{ active: item.active }">Content</div>';
    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain(':class="{ active: item.active }"')
        ->not->toContain('data-duo-class');
});

it('transforms forelse collection pattern', function () {
    $template = <<<'BLADE'
<div data-duo-collection="todos" data-duo-key="id">
    @forelse($todos as $todo)
        <div data-duo-template wire:key="{{ $todo->id }}">
            <h3 data-duo-text="title">{{ $todo->title }}</h3>
        </div>
    @empty
        <div data-duo-empty>No todos yet</div>
    @endforelse
</div>
BLADE;

    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain('x-for="item in duo.todos"')
        ->toContain(':key="item.id"')
        ->toContain('x-text="item.title"')
        ->toContain('x-show="duo.todos.length === 0"')
        ->toContain('wire:ignore')
        ->not->toContain('@forelse')
        ->not->toContain('@empty')
        ->not->toContain('@endforelse')
        ->not->toContain('data-duo-template')
        ->not->toContain('data-duo-empty')
        // Blade echo expressions should be stripped (loop variable is gone)
        ->not->toContain('{{ $todo->title }}');
});

it('transforms wire:click inside template to use item references', function () {
    $template = <<<'BLADE'
<div data-duo-collection="todos" data-duo-key="id">
    @forelse($todos as $todo)
        <div data-duo-template wire:key="{{ $todo->id }}">
            <button wire:click="deleteTodo({{ $todo->id }})">Delete</button>
        </div>
    @empty
        <div data-duo-empty>Empty</div>
    @endforelse
</div>
BLADE;

    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain("x-on:click=\"duo.call('deleteTodo', [item.id])\"")
        ->not->toContain('wire:click');
});

it('handles multiple wire attributes on the same form', function () {
    $template = <<<'BLADE'
<div data-duo-collection="todos">
    <form wire:submit="addTodo">
        <input wire:model="title">
        <textarea wire:model="description"></textarea>
        <button type="submit">Add</button>
    </form>
</div>
BLADE;

    $result = $this->compiler->compile($template);

    expect($result)
        ->toContain('x-on:submit.prevent="duo.submit(\'addTodo\')"')
        ->toContain('x-model="duo.form.title"')
        ->toContain('x-model="duo.form.description"')
        ->not->toContain('wire:submit')
        ->not->toContain('wire:model');
});
