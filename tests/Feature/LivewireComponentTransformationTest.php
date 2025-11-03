<?php

declare(strict_types=1);

use Livewire\Livewire;
use Tests\Fixtures\Livewire\TodoList;
use Tests\Fixtures\Models\Todo;
use Tests\Fixtures\Models\User;

beforeEach(function () {
    // Create a test user
    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);
});

test('component with WithDuo trait can be rendered', function () {
    Livewire::test(TodoList::class)
        ->assertOk();
});

test('component transformation is applied when duo is enabled', function () {
    // Enable transformations
    app()->instance('duo.transformations.enabled', true);

    $component = Livewire::test(TodoList::class);

    // Component should render without errors
    $component->assertOk();
});

test('component transformation is skipped when duo is disabled', function () {
    // Disable transformations
    app()->instance('duo.transformations.enabled', false);

    $component = Livewire::test(TodoList::class);

    // Component should still render without errors (as standard Livewire)
    $component->assertOk();
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
        ->set('newTodo', 'ab') // Too short (min:3)
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
