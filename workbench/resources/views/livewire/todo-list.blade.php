<?php

use Livewire\Volt\Component;
use Workbench\App\Models\Todo;
use JoshCirre\Duo\WithDuo;

new class extends Component {
    use WithDuo;
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

    public function with()
    {
        return [
            'todos' => Todo::latest()->orderBy('id', 'desc')->get(),
        ];
    }
}; ?>

<div>
    <x-duo::sync-status />
    <div class="max-w-3xl mx-auto p-6">
    <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
        <h2 class="text-2xl font-semibold text-zinc-900 dark:text-white mb-6">Duo Todo List Demo</h2>

        <form wire:submit="addTodo" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1.5">
                    What needs to be done?
                </label>
                <input
                    type="text"
                    wire:model="newTodoTitle"
                    placeholder="Enter a task..."
                    class="w-full px-3.5 py-2.5 bg-white dark:bg-zinc-900 border border-zinc-300 dark:border-zinc-600 rounded-lg text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                >
                @error('newTodoTitle')
                    <span class="text-sm text-red-600 dark:text-red-400 mt-1 block">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1.5">
                    Description (optional)
                </label>
                <textarea
                    wire:model="newTodoDescription"
                    placeholder="Add more details..."
                    rows="2"
                    class="w-full px-3.5 py-2.5 bg-white dark:bg-zinc-900 border border-zinc-300 dark:border-zinc-600 rounded-lg text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition resize-none"
                ></textarea>
            </div>

            <button
                type="submit"
                class="w-full bg-zinc-900 dark:bg-white hover:bg-zinc-800 dark:hover:bg-zinc-100 text-white dark:text-zinc-900 font-medium py-2.5 px-4 rounded-lg transition shadow-sm"
            >
                Add Todo
            </button>
        </form>
    </div>

    <div class="space-y-2">
        @forelse($todos as $todo)
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-700 p-4 flex items-start gap-4 hover:border-zinc-300 dark:hover:border-zinc-600 transition">
                <input
                    type="checkbox"
                    wire:click="toggleTodo({{ $todo->id }})"
                    {{ $todo->completed ? 'checked' : '' }}
                    class="mt-0.5 h-4 w-4 rounded border-zinc-300 dark:border-zinc-600 text-blue-600 focus:ring-blue-500 focus:ring-offset-0 cursor-pointer"
                >

                <div class="flex-1 min-w-0">
                    <h3 class="text-sm font-medium text-zinc-900 dark:text-white {{ $todo->completed ? 'line-through text-zinc-500 dark:text-zinc-500' : '' }}">
                        {{ $todo->title }}
                    </h3>
                    @if($todo->description)
                        <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-0.5 {{ $todo->completed ? 'line-through' : '' }}">
                            {{ $todo->description }}
                        </p>
                    @endif
                    <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-1.5">
                        {{ $todo->created_at->diffForHumans() }}
                    </p>
                </div>

                <button
                    wire:click="deleteTodo({{ $todo->id }})"
                    type="button"
                    class="inline-flex items-center justify-center h-8 w-8 rounded-lg text-zinc-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20 transition-colors"
                    title="Delete task"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </button>
            </div>
        @empty
            <div class="bg-zinc-50 dark:bg-zinc-800/50 rounded-xl border-2 border-dashed border-zinc-200 dark:border-zinc-700 p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">No todos yet</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-500 mt-1">Add your first task above to get started</p>
            </div>
        @endforelse
    </div>
    </div>
</div>
