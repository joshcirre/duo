<?php

namespace Workbench\App\Livewire;

use Livewire\Component;
use Workbench\App\Models\Todo;

class TodoList extends Component
{
    public string $newTodoTitle = '';
    public string $newTodoDescription = '';

    public function addTodo()
    {
        $this->validate([
            'newTodoTitle' => 'required|min:3',
        ]);

        Todo::create([
            'title' => $this->newTodoTitle,
            'description' => $this->newTodoDescription,
            'completed' => false,
        ]);

        $this->reset(['newTodoTitle', 'newTodoDescription']);
    }

    public function toggleTodo($id)
    {
        $todo = Todo::findOrFail($id);
        $todo->update([
            'completed' => !$todo->completed,
        ]);
    }

    public function deleteTodo($id)
    {
        Todo::findOrFail($id)->delete();
    }

    public function render()
    {
        return view('livewire.todo-list', [
            'todos' => Todo::latest()->get(),
        ]);
    }
}
