<div class="max-w-2xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <h2 class="text-2xl font-bold mb-4">Duo Todo List Demo</h2>

        <form wire:submit="addTodo" class="mb-6">
            <div class="mb-4">
                <input
                    type="text"
                    wire:model="newTodoTitle"
                    placeholder="What needs to be done?"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                @error('newTodoTitle')
                    <span class="text-red-500 text-sm">{{ $message }}</span>
                @enderror
            </div>

            <div class="mb-4">
                <textarea
                    wire:model="newTodoDescription"
                    placeholder="Description (optional)"
                    rows="2"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                ></textarea>
            </div>

            <button
                type="submit"
                class="w-full bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg transition"
            >
                Add Todo
            </button>
        </form>
    </div>

    <div class="space-y-3">
        @forelse($todos as $todo)
            <div class="bg-white rounded-lg shadow-md p-4 flex items-start gap-4">
                <input
                    type="checkbox"
                    wire:click="toggleTodo({{ $todo->id }})"
                    {{ $todo->completed ? 'checked' : '' }}
                    class="mt-1 w-5 h-5 text-blue-600 rounded focus:ring-2 focus:ring-blue-500"
                >

                <div class="flex-1">
                    <h3 class="font-semibold {{ $todo->completed ? 'line-through text-gray-500' : '' }}">
                        {{ $todo->title }}
                    </h3>
                    @if($todo->description)
                        <p class="text-sm text-gray-600 mt-1 {{ $todo->completed ? 'line-through' : '' }}">
                            {{ $todo->description }}
                        </p>
                    @endif
                    <p class="text-xs text-gray-400 mt-2">
                        {{ $todo->created_at->diffForHumans() }}
                    </p>
                </div>

                <button
                    wire:click="deleteTodo({{ $todo->id }})"
                    class="text-red-500 hover:text-red-700 font-semibold"
                >
                    Delete
                </button>
            </div>
        @empty
            <div class="bg-gray-50 rounded-lg p-8 text-center text-gray-500">
                No todos yet. Add one above!
            </div>
        @endforelse
    </div>

    <div class="mt-6 p-4 bg-blue-50 rounded-lg">
        <p class="text-sm text-gray-700">
            <strong>Duo Demo:</strong> This todo list uses IndexedDB for local-first syncing.
            Your changes are cached locally and synced to the server in the background.
        </p>
    </div>
</div>
