## Duo — Local-First Livewire

Duo makes a Livewire 4 component local-first: reads come from IndexedDB, writes apply optimistically and then run the component's own Livewire methods, and actions that cannot reach the server are queued and replayed. Duo adds no API endpoints and never writes to the database itself — the author's Livewire methods stay the single write path, so validation, authorization, and events keep working unchanged.

Requires Livewire 4 (class-based or single-file components) and Laravel 12.

### Setup checklist

1. Model: add the `JoshCirre\Duo\Syncable` trait to each Eloquent model that should be mirrored.
2. Layout: put `@duoMeta` in the `<head>` of the main layout.
3. Vite: add `duo()` from `@joshcirre/vite-plugin-duo` to `vite.config.js` plugins. It generates `resources/js/duo/manifest.json` and injects `initializeDuo()` into `resources/js/app.js`.
4. Component: add the `JoshCirre\Duo\WithDuo` trait and the `data-duo-*` markers described below.
5. After adding or changing a `Syncable` model, run `php artisan duo:generate` (the Vite dev server does this automatically).

@verbatim
<code-snippet name="Layout head" lang="blade">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    @duoMeta
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="vite.config.js" lang="js">
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { duo } from '@joshcirre/vite-plugin-duo';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.js'], refresh: true }),
        duo(),
    ],
});
</code-snippet>
@endverbatim

### Models

@verbatim
<code-snippet name="Syncable model" lang="php">
use JoshCirre\Duo\Syncable;

class Todo extends Model
{
    use Syncable;

    protected $fillable = ['title', 'description', 'completed'];

    protected $casts = ['completed' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
</code-snippet>
@endverbatim

- Do NOT add `user_id` to `$fillable`. Assign ownership in the Livewire method (`auth()->user()->todos()->create([...])`).
- Duo reads `$fillable`/`$guarded` and the table schema to build the IndexedDB store; nothing else is needed on the model.

### Components

Write ordinary Livewire. Expose the list as a `#[Computed]` method and reference it as `$this->todos` in the view. Wrap the `@forelse` in a `data-duo-collection` element and add markers for every value shown inside the loop.

@verbatim
<code-snippet name="Livewire 4 single-file component with Duo" lang="blade">
<?php

use JoshCirre\Duo\WithDuo;
use Livewire\Attributes\Computed;
use Livewire\Component;
use App\Models\Todo;

new class extends Component {
    use WithDuo;

    public string $newTodoTitle = '';

    public function addTodo(): void
    {
        $this->validate(['newTodoTitle' => 'required|min:3']);

        auth()->user()->todos()->create([
            'title' => $this->newTodoTitle,
            'completed' => false,
        ]);

        $this->reset('newTodoTitle');
    }

    public function toggleTodo($id): void
    {
        $todo = auth()->user()->todos()->findOrFail($id);
        $todo->update(['completed' => ! $todo->completed]);
    }

    public function deleteTodo($id): void
    {
        auth()->user()->todos()->findOrFail($id)->delete();
    }

    #[Computed]
    public function todos()
    {
        return auth()->user()->todos()->latest()->get();
    }
}; ?>

<div>
    <form wire:submit="addTodo">
        <input type="text" wire:model="newTodoTitle">
        @error('newTodoTitle') <span class="text-red-600">{{ $message }}</span> @enderror
        <button type="submit">Add</button>
    </form>

    <div data-duo-collection="todos" data-duo-key="id">
        @forelse($this->todos as $todo)
            <div data-duo-template wire:key="{{ $todo->id }}">
                <input type="checkbox" wire:click="toggleTodo({{ $todo->id }})" data-duo-checked="completed">
                <span data-duo-text="title">{{ $todo->title }}</span>
                <button wire:click="deleteTodo({{ $todo->id }})">Delete</button>
            </div>
        @empty
            <p data-duo-empty>No todos yet</p>
        @endforelse
    </div>
</div>
</code-snippet>
@endverbatim

For class-based components, `use WithDuo` on the class and put the same markup in its view.

### Marker reference

Inside `data-duo-collection`, the loop runs in the browser, so Blade echoes without a marker render empty. Use:

- `data-duo-collection="todos"` on the wrapper around `@forelse`; the value is the component property or computed method name.
- `data-duo-key="id"` primary key field (default `id`).
- `data-duo-template` on the single root element of each iteration (keep `wire:key` too).
- `data-duo-empty` on the `@empty` element.
- `data-duo-text="title"` → `x-text="item.title"`; `data-duo-html="body"` → `x-html`; `data-duo-show="description"` → `x-show`; `data-duo-checked="completed"` → `:checked`; `data-duo-bind:href="url"` → `:href="item.url"`; `data-duo-class="{ 'line-through': item.completed }"` → `:class` (Alpine expression; `item` is the row).

Duo compiles `wire:submit`, `wire:model` (any modifier), and `wire:click` to calls on its Alpine `duo` scope, and `wire:click="method({{ $todo->id }})"` inside the template to `duo.call('method', [item.id])`. Everything outside the collection is normal Livewire and keeps morphing (this is where `@error` blocks must live).

### Writing methods Duo can predict

Duo reads each public method's source to infer the optimistic change. The server's report of what actually happened always wins, so a miss only costs the instant update.

- `Model::create([...])` / `$relation->create([...])` → create; `->update([...])` / `->save()` → update; `->delete()` / `::destroy()` → delete.
- `'completed' => ! $todo->completed` → optimistic toggle of `completed`.
- `'title' => $this->newTodoTitle` → the `newTodoTitle` property maps to the `title` column on the optimistic record.
- `'completed' => false` → default value on the optimistic record.

Keep the attribute arrays passed to `create()`/`update()`/`fill()` literal. Building them dynamically (`$request->only(...)`, a variable) still works but disables optimistic rendering for that method.

### Behaviour to explain to users

- Validation failures, policy denials, and exceptions roll the optimistic change back; Livewire renders the errors as usual.
- Offline (or a failed request) queues the action in `localStorage`; it replays through `$wire` when back online or on the next page load.
- Pages with `@duoMeta` are cached by the service worker after one online visit and then load with no network.
- The collection is treated as the complete mirror of the store: do not use Duo markers on paginated or filtered collections.
- Blade components (including Flux) inside `data-duo-template` are not reactive; use plain elements with markers there.
- `<x-duo::sync-status />` shows offline/syncing badges; `<x-duo::debug />` (local only) inspects and clears IndexedDB.

### Commands

@verbatim
<code-snippet name="Artisan commands" lang="bash">
php artisan duo:discover   # list Syncable models
php artisan duo:generate   # regenerate resources/js/duo/manifest.json
</code-snippet>
@endverbatim
