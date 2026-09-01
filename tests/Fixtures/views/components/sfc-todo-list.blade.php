<?php

use JoshCirre\Duo\WithDuo;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Tests\Fixtures\Models\Todo;

new class extends Component {
    use WithDuo;

    public string $newTodoTitle = '';

    public function addTodo(): void
    {
        $this->validate([
            'newTodoTitle' => 'required|min:3',
        ]);

        Todo::create([
            'title' => $this->newTodoTitle,
            'user_id' => 1, // For testing
            'completed' => false,
        ]);

        $this->reset('newTodoTitle');
    }

    public function toggleTodo($id): void
    {
        $todo = Todo::findOrFail($id);
        $todo->update(['completed' => ! $todo->completed]);
    }

    public function deleteTodo($id): void
    {
        Todo::findOrFail($id)->delete();
    }

    #[Computed]
    public function todos()
    {
        return Todo::latest()->get();
    }
}; ?>

<div>
    <form wire:submit="addTodo">
        <input type="text" wire:model="newTodoTitle" placeholder="New todo...">
        <button type="submit">Add</button>
    </form>

    <div data-duo-collection="todos" data-duo-key="id">
        @forelse($this->todos as $todo)
            <div data-duo-template wire:key="{{ $todo->id }}">
                <input type="checkbox" wire:click="toggleTodo({{ $todo->id }})" data-duo-checked="completed">
                <h3 data-duo-text="title">{{ $todo->title }}</h3>
                <button wire:click="deleteTodo({{ $todo->id }})">Delete</button>
            </div>
        @empty
            <div data-duo-empty>No todos yet</div>
        @endforelse
    </div>
</div>
