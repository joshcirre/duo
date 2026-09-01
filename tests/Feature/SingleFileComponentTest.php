<?php

declare(strict_types=1);

use Livewire\Livewire;
use Tests\Fixtures\Models\Todo;
use Tests\Fixtures\Models\User;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'sfc@example.com',
    ]);
});

test('single-file component with WithDuo renders', function () {
    Livewire::test('sfc-todo-list')
        ->assertOk();
});

test('single-file component gets data-duo-enabled and meta attributes', function () {
    $html = Livewire::test('sfc-todo-list')->html();

    expect($html)
        ->toContain('data-duo-enabled="true"')
        ->toContain('data-duo-meta="');
});

test('blade precompiler transforms the single-file component template', function () {
    Todo::create([
        'title' => 'Existing todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $html = Livewire::test('sfc-todo-list')->html();

    expect($html)
        ->toContain('x-for="item in duo.todos"')
        ->toContain(':key="item.id"')
        ->toContain('x-text="item.title"')
        ->toContain(':checked="item.completed"')
        ->toContain('x-on:submit.prevent="duo.submit(\'addTodo\')"')
        ->toContain('x-model="duo.form.newTodoTitle"')
        ->toContain("duo.call('deleteTodo', [item.id])")
        ->toContain('wire:ignore')
        ->not->toContain('wire:submit')
        ->not->toContain('wire:model')
        ->not->toContain('data-duo-template');
});

test('single-file component includes duo effect with meta and state', function () {
    Todo::create([
        'title' => 'Effect todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $component = Livewire::test('sfc-todo-list')
        ->set('newTodoTitle', 'Another todo')
        ->call('addTodo');

    $duo = $component->effects['duo'];

    expect($duo['enabled'])->toBeTrue();
    expect($duo['meta']['models'])->toHaveKey('todos');
    expect($duo['meta']['models']['todos']['type'])->toBe('collection');
    expect($duo['state']['todos'])->toBeArray();
    expect(count($duo['state']['todos']))->toBeGreaterThanOrEqual(2);
});

test('single-file component method metadata includes inferred operations', function () {
    $component = Livewire::test('sfc-todo-list');

    $meta = $component->effects['duo']['meta'];

    expect($meta['methods']['addTodo']['operation'])->toBe('create');
    expect($meta['methods']['toggleTodo']['operation'])->toBe('update');
    expect($meta['methods']['deleteTodo']['operation'])->toBe('delete');
});

test('method analysis detects toggles, property mapping, and literal defaults', function () {
    $meta = Livewire::test('sfc-todo-list')->effects['duo']['meta'];

    expect($meta['methods']['toggleTodo']['toggles'])->toBe(['completed']);
    expect($meta['methods']['addTodo']['mapping'])->toBe(['newTodoTitle' => 'title']);
    expect($meta['methods']['addTodo']['defaults'])->toBe(['user_id' => 1, 'completed' => false]);
    expect($meta['methods']['deleteTodo']['toggles'])->toBe([]);
});

test('compiled single-file component root receives the duo Alpine scope', function () {
    $html = Livewire::test('sfc-todo-list')->html();

    expect($html)->toMatch('/^<div[^>]*\sx-data="duo"/');
});

test('eloquent operations are captured through single-file component actions', function () {
    $component = Livewire::test('sfc-todo-list')
        ->set('newTodoTitle', 'Captured todo')
        ->call('addTodo');

    $operations = $component->effects['duo']['operations'] ?? [];

    expect($operations)->toHaveCount(1);
    expect($operations[0]['type'])->toBe('create');
    expect($operations[0]['store'])->toBe('Tests_Fixtures_Models_Todo');
    expect($operations[0]['data']['title'])->toBe('Captured todo');
});

test('single-file component CRUD methods work', function () {
    Livewire::test('sfc-todo-list')
        ->set('newTodoTitle', 'CRUD todo')
        ->call('addTodo');

    $todo = Todo::where('title', 'CRUD todo')->first();
    expect($todo)->not->toBeNull();

    Livewire::test('sfc-todo-list')->call('toggleTodo', $todo->id);
    expect($todo->fresh()->completed)->toBeTrue();

    Livewire::test('sfc-todo-list')->call('deleteTodo', $todo->id);
    expect(Todo::find($todo->id))->toBeNull();
});
