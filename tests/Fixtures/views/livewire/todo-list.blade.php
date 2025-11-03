<div>
    <h2>Todo List</h2>

    <input wire:model="newTodo" type="text" />
    <button wire:click="addTodo">Add</button>

    @forelse($this->todos as $todo)
        <div>
            <input
                type="checkbox"
                wire:click="toggleTodo({{ $todo->id }})"
                {{ $todo->completed ? 'checked' : '' }}
            />
            <span>{{ $todo->title }}</span>
            <button wire:click="deleteTodo({{ $todo->id }})">Delete</button>
        </div>
    @empty
        <p>No todos yet</p>
    @endforelse
</div>
