<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use JoshCirre\Duo\WithDuo;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Tests\Fixtures\Models\Todo;

class TodoList extends Component
{
    use WithDuo;

    public string $newTodo = '';

    #[Computed]
    public function todos()
    {
        return Todo::latest()->get();
    }

    public function addTodo(): void
    {
        $this->validate([
            'newTodo' => 'required|min:3',
        ]);

        Todo::create([
            'title' => $this->newTodo,
            'user_id' => 1, // For testing
            'completed' => false,
        ]);

        $this->newTodo = '';
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

    public function render()
    {
        return view('livewire.todo-list');
    }
}
