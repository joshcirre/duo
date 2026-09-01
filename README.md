# Duo

**Local-first Livewire.** Add a trait to your model and your Livewire 4 component, mark up the list you want rendered instantly, and Duo mirrors your Eloquent data into IndexedDB, renders from it, applies changes optimistically, and keeps the page working offline — while **your own Livewire methods remain the only thing that ever writes to your database**.

> **Status: early.** The core loop works end to end and is verified in a real browser (see [What's verified](#whats-verified)), but the marker vocabulary and JavaScript API may still change.

## The idea in one paragraph

Duo adds no API endpoints and never talks to your database. When you click "Add", Duo writes an optimistic record to IndexedDB and then calls your real `addTodo()` through Livewire's `$wire`, exactly as Livewire would have. On the server, Duo records which Eloquent rows your method created, updated, or deleted and sends that back inside the normal Livewire response. The client swaps the temporary record for the real one. If your method rejected the input — validation, a policy, an exception — Duo removes the optimistic change and Livewire renders the error the way it always does. If the request can't reach the server, the action is queued and replayed when you're back online, even after a reload.

## Requirements

- PHP 8.2+, Laravel 12
- **Livewire 4** — class-based or single-file components (Livewire 3 is not supported)
- A modern browser with IndexedDB

## Installation

```bash
composer require joshcirre/duo
npm install -D @joshcirre/vite-plugin-duo
```

Duo works without publishing anything. Optional:

```bash
php artisan vendor:publish --tag=duo-config   # config/duo.php
php artisan vendor:publish --tag=duo-views    # resources/views/vendor/duo/components/
```

## Quick start

### 1. Mark the models you want mirrored

```php
use JoshCirre\Duo\Syncable;

class Todo extends Model
{
    use Syncable;

    protected $fillable = ['title', 'description', 'completed'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

Duo reads `$fillable`/`$guarded` and the table schema to build the IndexedDB store. Keep `user_id` **out** of `$fillable` and assign it in your Livewire method (or through a relationship) — Duo doesn't change how your methods run, so your existing authorization applies unchanged.

### 2. Add `@duoMeta` to your layout's `<head>`

```blade
<head>
    <meta charset="utf-8" />
    @duoMeta
    ...
</head>
```

It emits the CSRF meta tag and a `duo-cache` meta tag that tells the service worker to cache the page for offline loads.

### 3. Add the Vite plugin

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { duo } from '@joshcirre/vite-plugin-duo';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.js'], refresh: true }),
        duo(),
    ],
});
```

The plugin generates `resources/js/duo/manifest.json` from your models (and regenerates it when they change), injects Duo's initialization into `resources/js/app.js`, and copies the service worker to `public/duo-sw.js` on build. Duo's client must load before `Livewire.start()` — the standard `@vite` in `<head>` does this.

### 4. Add `WithDuo` and mark up your list

A Livewire 4 single-file component:

```blade
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

        Todo::create([
            'title' => $this->newTodoTitle,
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
        <input type="text" wire:model="newTodoTitle">
        @error('newTodoTitle') <span>{{ $message }}</span> @enderror
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
```

Class-based components work the same way — put the same markup in the component's view.

Duo compiles this at Blade compile time. Inside a `data-duo-collection`, the `@forelse` becomes an Alpine `x-for` over IndexedDB, and `wire:*` directives become calls on Duo's `duo` scope:

| You write | Duo compiles to |
|---|---|
| `wire:submit="addTodo"` | `x-on:submit.prevent="duo.submit('addTodo')"` |
| `wire:model="newTodoTitle"` (any modifier) | `x-model="duo.form.newTodoTitle"` |
| `wire:click="deleteTodo({{ $todo->id }})"` (in the template) | `x-on:click="duo.call('deleteTodo', [item.id])"` |
| `wire:click="clearCompleted"` (elsewhere) | `x-on:click="duo.call('clearCompleted')"` |
| `@forelse … @empty … @endforelse` | `<template x-for="item in duo.todos" :key="item.id">` + `x-show="duo.todos.length === 0"` |

Because the loop no longer runs on the server, every value shown inside the template needs a marker:

| Marker | Purpose |
|---|---|
| `data-duo-collection="todos"` | Wrapper around the `@forelse`; the name is the component property or computed method |
| `data-duo-key="id"` | Primary key field (default `id`) |
| `data-duo-template` | The single root element of each loop iteration |
| `data-duo-empty` | The `@empty` element |
| `data-duo-text="title"` | `x-text="item.title"` (the Blade echo inside is removed) |
| `data-duo-html="body"` | `x-html="item.body"` |
| `data-duo-show="description"` | `x-show="item.description"` |
| `data-duo-checked="completed"` | `:checked="item.completed"` |
| `data-duo-bind:href="url"` | `:href="item.url"` |
| `data-duo-class="{ 'line-through': item.completed }"` | `:class="…"` (raw Alpine expression; `item` is the row) |

Plain Blade echoes inside the template that have no marker render empty. Everything **outside** the collection — the form, `@error` blocks, anything else — is ordinary Livewire and keeps morphing normally.

### 5. Optional components

```blade
<x-duo::sync-status position="top-right" />   {{-- offline / syncing badges --}}
<x-duo::debug position="bottom-right" />       {{-- local only: inspect and clear IndexedDB --}}
```

`sync-status` props: `position`, `inline`, `show-delay` (ms before "Syncing" appears, default 1000), `show-success` (default false). Defaults live under `sync_status` in `config/duo.php`.

### 6. Run it

```bash
npm run dev
php artisan serve
```

Visit the page once while online so the service worker can cache it; after that it loads with no network.

## How Duo understands your methods

Duo reads the source of each public method on a `WithDuo` component and infers what it does to the model, so the optimistic update can be right *before* the server answers. This only drives the optimistic UI — the server always reports what actually happened and wins.

| Pattern in your method | What Duo infers |
|---|---|
| `Todo::create([...])`, `$user->todos()->create([...])` | operation `create` |
| `$todo->update([...])`, `$todo->save()` | operation `update` |
| `$todo->delete()`, `Todo::destroy(...)` | operation `delete` |
| `'completed' => ! $todo->completed` | toggles `completed` |
| `'title' => $this->newTodoTitle` | maps the `newTodoTitle` property to the `title` column |
| `'completed' => false` | default `completed = false` on new records |

Keep the attribute arrays passed to `create()`/`update()`/`fill()` literal and you get accurate optimistic rendering for free. Methods Duo can't classify still work — they just wait for the round trip.

## Offline behaviour

- **Reads** always come from IndexedDB (via Dexie `liveQuery`), so lists render instantly and work with no network.
- **Writes** apply to IndexedDB immediately, then run your Livewire method. If the browser is offline or the request fails at the network level, the action (`component`, `method`, `params`, and the form values as deferred property updates) is stored in `localStorage` and replayed through `$wire` when the `online` event fires or the page is next loaded. Replay stops at the first network failure and resumes later.
- **Rollback**: after each action, Duo checks that the server reported the operation it expected (a create for the temp record, an update/delete for that key). If it didn't — validation failed, a policy denied it, the method took another branch — the optimistic change is undone. Livewire has already rendered any validation errors.
- **Page loads** work offline once the page has been visited online: the service worker serves the cached HTML and assets, and the component boots from IndexedDB.

## Two levels of adoption

- **`WithDuo` alone** (no markers): the page is cached for offline loading, the component's model state is mirrored to IndexedDB on every response, and actions that can't reach the server are queued and replayed. Rendering stays server-side.
- **`WithDuo` + markers**: everything above, plus instant rendering from IndexedDB and optimistic create/update/delete.

## Configuration

`config/duo.php`:

| Key | Default | Purpose |
|---|---|---|
| `debug` | `false` | Reserved for server-side debug logging |
| `auto_discover` | `true` | Scan `model_paths` for `Syncable` models at boot |
| `model_paths` | `[app_path('Models')]` | Where to look for models |
| `sync_status.show_delay` | `1000` | ms before the "Syncing" badge appears |
| `sync_status.show_success` | `false` | Show an "All changes synced" badge |
| `sync_status.success_duration` | `2000` | ms the success badge stays visible |

Client debug logging is controlled by the `debug` option passed to `initializeDuo()` (the Vite plugin passes `import.meta.env.DEV`).

### Vite plugin options

All optional:

| Option | Default | Description |
|---|---|---|
| `manifestPath` | `'resources/js/duo/manifest.json'` | Where the manifest is written |
| `watch` | `true` | Regenerate on file changes in dev |
| `autoGenerate` | `true` | Run the artisan command on build and on change |
| `patterns` | `['app/Models/**/*.php']` | Files whose changes trigger regeneration |
| `entry` | `'resources/js/app.js'` | Entry file that receives the injected initialization |
| `autoInject` | `true` | Inject `initializeDuo(...)` into the entry file |
| `command` | `'php artisan duo:generate'` | Manifest command |

With `autoInject: false`, initialize yourself — at module top level, not in a `DOMContentLoaded` handler, so Duo's hooks attach before Livewire starts:

```js
import { initializeDuo } from '@joshcirre/vite-plugin-duo/client';
import manifest from 'virtual:duo-manifest';

initializeDuo({ manifest, debug: import.meta.env.DEV });
```

## JavaScript API

`initializeDuo()` resolves to a `DuoClient`, also exposed as `window.duo`:

```js
const duo = window.duo;
duo.getDatabase();          // Dexie database; getStore('App_Models_Todo') → Dexie Table
duo.getActionQueue();       // pending Livewire actions: all(), size, pendingCount, replay()
duo.liveQuery(() => store.toArray());
await duo.clearCache();     // IndexedDB + service worker caches
```

Events on `window`:

- `duo-synced` — an action was delivered to the server (`event.detail.action`)
- `duo:mutation` — a server-reported operation was applied to IndexedDB (`store`, `table`, `type`, `data`)

The Alpine scope on a compiled component exposes `duo.<collection>` (rows), `duo.form.<field>`, `duo.online`, `duo.ready`, `duo.submit(method)`, and `duo.call(method, args)`, so you can add your own bindings next to the compiled ones.

## Artisan commands

```bash
php artisan duo:discover   # list Syncable models
php artisan duo:generate   # write resources/js/duo/manifest.json (the Vite plugin runs this for you)
```

## Troubleshooting

**"duo is not defined" in the console** — Duo's client loaded after `Livewire.start()`. Make sure `@vite` is in `<head>` and `initializeDuo()` runs at module top level.

**Store "…" is not in the manifest** — run `php artisan duo:generate` (or let the Vite plugin do it) and rebuild.

**Nothing renders in the list** — check that the wrapper has `data-duo-collection` and each iteration has a single root element with `data-duo-template`; check the browser console with debug enabled.

**Validation messages don't show** — they are rendered by Livewire's morph, so the `@error` block must be *outside* the `data-duo-collection` element (which is `wire:ignore`).

**Reset the browser state** — use `<x-duo::debug />` → "Delete Database & Reload", or in the console: `await window.duo.getDatabase().delete(); localStorage.clear(); location.reload()`.

## Known limitations

- The component's collection is treated as the full mirror of the store. A paginated or filtered `todos` computed will cause rows outside the current page/filter to be removed locally. Use Duo on unfiltered collections for now.
- Rows are ordered newest-first (`created_at` desc, then key desc) regardless of the server query's order.
- Editing a record that was created offline and hasn't synced yet applies locally only; the edit is not folded into the queued create.
- If the page is closed while an action is in flight, the action is replayed on the next load, which can duplicate a create.
- The action queue is shared across tabs of the same origin; two open tabs can both replay it.
- Blade components (including Flux) inside a `data-duo-template` render once at compile time and aren't reactive; use plain elements with markers there.

## What's verified

Run against the workbench demo (Livewire 4.4, Laravel 12) in headless Chromium:

- initial render with `x-data="duo"` on the root and no Alpine errors
- optimistic create → server create → temp record replaced
- optimistic toggle (inferred `toggles`) and delete
- invalid submit → temp removed, form restored, Livewire renders the validation error
- offline → create + toggle queued and persisted → online → replayed
- server process killed → real network failure → queued → server back → delivered
- queued action from a previous page session replayed on load
- full page load with the server down, rendering from IndexedDB

Test suites: `composer test` (Pest, Pint, Peck), `vendor/bin/phpstan`, `npx vitest run`.

## Local development

```bash
composer install && npm install
npm run build                 # client + vite plugin
composer run build            # testbench skeleton: sqlite db + migrations
npm run workbench:build       # workbench assets
php vendor/bin/testbench serve
```

The demo is `workbench/resources/views/components/todo-list.blade.php`. To use Duo from a local Laravel app, add a `path` repository for the composer package and `npm link` the Vite plugin.

## License

MIT. See [LICENSE.md](LICENSE.md).

## Credits

Created by [Josh Cirre](https://github.com/joshcirre). Built on [Livewire](https://livewire.laravel.com/), [Alpine.js](https://alpinejs.dev/), [Dexie.js](https://dexie.org/), and [Forte](https://github.com/fortephp/forte).
