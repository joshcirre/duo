<?php

declare(strict_types=1);

use JoshCirre\Duo\DuoOperationCollector;
use Tests\Fixtures\Models\Todo;
use Tests\Fixtures\Models\User;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    // Drain any operations from the User::create above
    app(DuoOperationCollector::class)->drain();
});

test('collector captures create operations on syncable models', function () {
    $todo = Todo::create([
        'title' => 'Test Todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $operations = app(DuoOperationCollector::class)->drain();

    expect($operations)->toHaveCount(1);
    expect($operations[0]['type'])->toBe('create');
    expect($operations[0]['table'])->toBe('todos');
    expect($operations[0]['key'])->toBe($todo->id);
    expect($operations[0]['data']['title'])->toBe('Test Todo');
});

test('collector captures update operations', function () {
    $todo = Todo::create([
        'title' => 'Test Todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    app(DuoOperationCollector::class)->drain(); // clear create

    $todo->update(['completed' => true]);

    $operations = app(DuoOperationCollector::class)->drain();

    expect($operations)->toHaveCount(1);
    expect($operations[0]['type'])->toBe('update');
    expect($operations[0]['data']['completed'])->toBe(true);
});

test('collector captures delete operations', function () {
    $todo = Todo::create([
        'title' => 'Test Todo',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    app(DuoOperationCollector::class)->drain(); // clear create

    $todo->delete();

    $operations = app(DuoOperationCollector::class)->drain();

    expect($operations)->toHaveCount(1);
    expect($operations[0]['type'])->toBe('delete');
    expect($operations[0]['key'])->toBe($todo->id);
});

test('collector captures multiple operations in sequence', function () {
    $todo1 = Todo::create([
        'title' => 'First',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $todo1->update(['completed' => true]);

    $todo2 = Todo::create([
        'title' => 'Second',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $todo2->delete();

    $operations = app(DuoOperationCollector::class)->drain();

    expect($operations)->toHaveCount(4);
    expect($operations[0]['type'])->toBe('create');
    expect($operations[1]['type'])->toBe('update');
    expect($operations[2]['type'])->toBe('create');
    expect($operations[3]['type'])->toBe('delete');
});

test('collector includes store name derived from model class', function () {
    Todo::create([
        'title' => 'Test',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    $operations = app(DuoOperationCollector::class)->drain();

    expect($operations[0]['store'])->toBe('Tests_Fixtures_Models_Todo');
});

test('drain clears operations for next collection', function () {
    Todo::create([
        'title' => 'First',
        'user_id' => $this->user->id,
        'completed' => false,
    ]);

    app(DuoOperationCollector::class)->drain();

    // Second drain should be empty
    $operations = app(DuoOperationCollector::class)->drain();

    expect($operations)->toHaveCount(0);
});

test('collector does not capture non-syncable models', function () {
    // User doesn't use Syncable trait
    User::create([
        'name' => 'Another User',
        'email' => 'another@example.com',
    ]);

    $operations = app(DuoOperationCollector::class)->drain();

    expect($operations)->toHaveCount(0);
});
