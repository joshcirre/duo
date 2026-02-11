<?php

declare(strict_types=1);

use Livewire\Livewire;
use Tests\Fixtures\Livewire\TodoList;
use Tests\Fixtures\Models\Todo;
use Tests\Fixtures\Models\User;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);
});

test('component with WithDuo trait can be rendered', function () {
    Livewire::test(TodoList::class)
        ->assertOk();
});

test('component adds data-duo-enabled attribute', function () {
    $component = Livewire::test(TodoList::class);

    $component->assertOk();

    $html = $component->html();
    expect($html)->toContain('data-duo-enabled="true"');
});

test('component adds data-duo-meta attribute with component name', function () {
    $component = Livewire::test(TodoList::class);
    $html = $component->html();

    expect($html)->toContain('data-duo-meta="');
    expect($html)->toContain('TodoList');
});

test('duo metadata includes model info when collection has data', function () {
    Todo::create([
        'title' => 'Test Todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $component = Livewire::test(TodoList::class);
    $html = $component->html();
    
    expect($html)->toContain('data-duo-meta="');
    expect($html)->toContain('todos');
});

test('component can create todos', function () {
    Livewire::test(TodoList::class)
        ->set('newTodo', 'Test Todo')
        ->call('addTodo')
        ->assertHasNoErrors();

    expect(Todo::where('title', 'Test Todo')->exists())->toBeTrue();
});

test('component validates todo input', function () {
    Livewire::test(TodoList::class)
        ->set('newTodo', 'ab')
        ->call('addTodo')
        ->assertHasErrors('newTodo');
});

test('component can toggle todo completion', function () {
    $todo = Todo::create([
        'title' => 'Test Todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    Livewire::test(TodoList::class)
        ->call('toggleTodo', $todo->id)
        ->assertHasNoErrors();

    expect($todo->fresh()->completed)->toBeTrue();
});

test('component can delete todos', function () {
    $todo = Todo::create([
        'title' => 'Test Todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    Livewire::test(TodoList::class)
        ->call('deleteTodo', $todo->id)
        ->assertHasNoErrors();

    expect(Todo::find($todo->id))->toBeNull();
});

test('computed property returns todos', function () {
    Todo::create([
        'title' => 'Todo 1',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    Todo::create([
        'title' => 'Todo 2',
        'user_id' => $this->user->id,
        'completed' => true,
    ]);

    $component = Livewire::test(TodoList::class);

    expect($component->get('todos'))->toHaveCount(2);
});

test('DuoSynth adds duo effect to component response', function () {
    $component = Livewire::test(TodoList::class)
        ->set('newTodo', 'Test Todo')
        ->call('addTodo');

    expect($component->effects)->toHaveKey('duo');
    expect($component->effects['duo']['enabled'])->toBeTrue();
    expect($component->effects['duo'])->toHaveKeys(['meta', 'state']);
});

test('duo effect state contains model data', function () {
    Todo::create([
        'title' => 'Existing Todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $component = Livewire::test(TodoList::class)
        ->set('newTodo', 'Another Todo')
        ->call('addTodo');

    $duoEffect = $component->effects['duo'];
    expect($duoEffect['state'])->toHaveKey('todos');
    expect($duoEffect['state']['todos'])->toBeArray();
    expect(count($duoEffect['state']['todos']))->toBeGreaterThanOrEqual(1);
});

test('metadata includes type field for collections', function () {
    Todo::create([
        'title' => 'Test Todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $component = Livewire::test(TodoList::class);
    $html = $component->html();

    preg_match('/data-duo-meta="([^"]*)"/', $html, $matches);
    expect($matches)->not->toBeEmpty();

    $meta = json_decode(htmlspecialchars_decode($matches[1]), true);
    expect($meta['models']['todos']['type'])->toBe('collection');
});

test('collectSyncableModels detects single model instances', function () {
    $todo = Todo::create([
        'title' => 'Single Todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $provider = app(JoshCirre\Duo\DuoServiceProvider::class);

    $models = [];
    $reflection = new ReflectionMethod($provider, 'collectSyncableModels');
    $reflection->setAccessible(true);
    $args = [$todo, 'singleTodo', &$models];
    $reflection->invokeArgs($provider, $args);

    expect($models)->toHaveKey('singleTodo');
    expect($models['singleTodo']['type'])->toBe('model');
});
