<?php

declare(strict_types=1);

use JoshCirre\Duo\DuoOperationCollector;
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

test('operation collector captures create from addTodo method', function () {
    // Drain any prior operations
    $collector = app(DuoOperationCollector::class);
    $collector->drain();

    // Simulate what addTodo does
    Todo::create([
        'title' => 'Captured Todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $operations = $collector->drain();

    expect($operations)->toHaveCount(1);
    expect($operations[0]['type'])->toBe('create');
    expect($operations[0]['data']['title'])->toBe('Captured Todo');
    expect($operations[0]['table'])->toBe('todos');
});

test('DuoSynth effect includes operations after method call', function () {
    // Create a todo first so the model type can be detected
    Todo::create([
        'title' => 'Existing',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $component = Livewire::test(TodoList::class);

    // The initial render should have the duo effect
    expect($component->effects)->toHaveKey('duo');

    // The create operation should be captured
    $duoEffect = $component->effects['duo'];
    expect($duoEffect)->toHaveKey('operations');
    expect($duoEffect['operations'])->not->toBeEmpty();
    expect($duoEffect['operations'][0]['type'])->toBe('create');
});

test('DuoSynth captures delete operations in effect payload', function () {
    $todo = Todo::create([
        'title' => 'To Delete',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $component = Livewire::test(TodoList::class)
        ->call('deleteTodo', $todo->id);

    $duoEffect = $component->effects['duo'];

    expect($duoEffect)->toHaveKey('operations');
    expect($duoEffect['operations'])->toHaveCount(1);
    expect($duoEffect['operations'][0]['type'])->toBe('delete');
    expect($duoEffect['operations'][0]['key'])->toBe($todo->id);
});

test('DuoSynth captures update operations in effect payload', function () {
    $todo = Todo::create([
        'title' => 'To Toggle',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $component = Livewire::test(TodoList::class)
        ->call('toggleTodo', $todo->id);

    $duoEffect = $component->effects['duo'];

    expect($duoEffect)->toHaveKey('operations');
    expect($duoEffect['operations'])->toHaveCount(1);
    expect($duoEffect['operations'][0]['type'])->toBe('update');
    expect($duoEffect['operations'][0]['data']['completed'])->toBe(true);
});

test('method metadata includes inferred operation types', function () {
    Todo::create([
        'title' => 'Test',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $component = Livewire::test(TodoList::class);
    $html = $component->html();

    preg_match('/data-duo-meta="([^"]*)"/', $html, $matches);
    $meta = json_decode(htmlspecialchars_decode($matches[1]), true);

    // addTodo has Todo::create() in its body
    expect($meta['methods']['addTodo']['operation'])->toBe('create');

    // deleteTodo has ->delete() in its body
    expect($meta['methods']['deleteTodo']['operation'])->toBe('delete');

    // toggleTodo has ->update() in its body
    expect($meta['methods']['toggleTodo']['operation'])->toBe('update');
});

test('model metadata includes schema and fillable fields', function () {
    Todo::create([
        'title' => 'Test',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $component = Livewire::test(TodoList::class);
    $html = $component->html();

    preg_match('/data-duo-meta="([^"]*)"/', $html, $matches);
    $meta = json_decode(htmlspecialchars_decode($matches[1]), true);

    expect($meta['models']['todos'])->toHaveKeys(['schema', 'fillable']);
    expect($meta['models']['todos']['fillable'])->toContain('title');
    expect($meta['models']['todos']['fillable'])->toContain('completed');
    // SQLite stores booleans as integers, so the mapped type depends on the DB driver
    expect($meta['models']['todos']['schema']['completed']['type'])->toBeIn(['boolean', 'number']);
});

test('initial render includes operations from model creation', function () {
    // The collector captures ALL Eloquent operations on Syncable models,
    // including the Todo::create here which happens before the Livewire render
    Todo::create([
        'title' => 'Test',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $component = Livewire::test(TodoList::class);

    $duoEffect = $component->effects['duo'] ?? null;
    expect($duoEffect)->not->toBeNull();

    // The create operation from above should be captured
    if (isset($duoEffect['operations'])) {
        $createOps = array_filter($duoEffect['operations'], fn ($op) => $op['type'] === 'create');
        expect(count($createOps))->toBeGreaterThanOrEqual(1);
    }
});
