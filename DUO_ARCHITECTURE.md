# Duo Architecture - Local-First with Livewire

## Overview

Duo enables **offline-first, local-first functionality** for Laravel Livewire applications using IndexedDB. Components marked with the `UsesDuo` trait automatically get offline capabilities with background syncing.

## How It Works

### 1. Mark Your Livewire Component

```php
use JoshCirre\Duo\Concerns\UsesDuo;

class TodoList extends Component
{
    use UsesDuo;  // That's it!

    public function addTodo() { /* ... */ }
    public function toggleTodo($id) { /* ... */ }
    public function deleteTodo($id) { /* ... */ }
}
```

### 2. Normal Livewire Component

Your component works exactly as before - no changes to your existing wire:* actions needed!

```blade
<button wire:click="addTodo">Add Todo</button>
<button wire:click="toggleTodo({{ $todo->id }})">Toggle</button>
<button wire:click="deleteTodo({{ $todo->id }})">Delete</button>
```

### 3. What Happens

#### On Page Load:
1. Component loads normally from server (initial hydration)
2. Duo detects the `UsesDuo` trait
3. Data is cached in IndexedDB
4. Component is populated from IndexedDB

#### On User Action (e.g., wire:click="addTodo"):
1. **Local-First**: Data is written to IndexedDB immediately
2. **UI Updates**: Component is refreshed from IndexedDB (instant!)
3. **Background Sync**: Normal Livewire request happens in parallel
4. **Server Response**: Updates IndexedDB when it comes back
5. **Sync to Other Devices**: Background sync queue handles propagation

#### Offline Mode:
1. User clicks "Add Todo"
2. Data is written to IndexedDB
3. UI updates instantly from IndexedDB
4. Livewire request fails (offline)
5. Change is queued for background sync
6. When online, changes sync to server automatically

## Architecture Components

### PHP Side

#### 1. `UsesDuo` Trait
- Marks Livewire components for Duo interception
- Auto-detects the model class being used
- Adds metadata to component responses

#### 2. `Syncable` Trait (Models)
- Marks Eloquent models for IndexedDB caching
- Generates manifest schema

### JavaScript Side

#### 1. `DuoLivewireInterceptor`
- Detects components with `UsesDuo` trait
- Intercepts wire:* method calls
- Handles local-first operations:
  - `addTodo()` → Creates record in IndexedDB
  - `toggleTodo($id)` → Updates record in IndexedDB
  - `deleteTodo($id)` → Deletes from IndexedDB
- Refreshes component from IndexedDB after operations
- **Does NOT block normal Livewire flow**

#### 2. `DuoDatabase` (Dexie wrapper)
- Manages IndexedDB stores
- One store per model (e.g., `App_Models_Todo`)
- Handles CRUD operations

#### 3. `SyncQueue`
- Queues changes for background sync
- Retries failed syncs
- Handles conflict resolution

## Flow Diagram

```
User Action (wire:click="addTodo")
    ↓
DuoLivewireInterceptor detects UsesDuo component
    ↓
Write to IndexedDB (instant)
    ↓
Refresh component from IndexedDB (instant UI update)
    ↓
Normal Livewire request continues in parallel
    ↓
Server response → Update IndexedDB
    ↓
Background sync queue → Sync to other devices
```

## Benefits

1. **Zero Changes to Existing Code**: Just add the `UsesDuo` trait
2. **Instant UI Updates**: No waiting for server responses
3. **Offline Support**: Works completely offline
4. **Multi-Device Sync**: Changes propagate across devices
5. **Non-Breaking**: If Duo fails, normal Livewire still works
6. **Progressive Enhancement**: Start with server-first, add offline later

## Example: Todo List

```php
// Livewire Component
class TodoList extends Component
{
    use UsesDuo;

    public function addTodo() {
        Todo::create([...]);  // Works offline!
    }
}
```

```blade
<!-- View - no changes needed! -->
<button wire:click="addTodo">Add Todo</button>
```

**What happens:**
- Click button → Todo added to IndexedDB instantly
- UI updates from IndexedDB (no server round-trip needed)
- Background: Livewire syncs to server
- When server responds: IndexedDB is updated with real ID
- Other devices get the change via background sync

## Configuration

```js
initializeDuo({
    manifest,
    debug: true,  // See what Duo is doing
    syncInterval: 5000,  // Background sync every 5s
    maxRetries: 3,  // Retry failed syncs
});
```

## Debugging

Enable debug mode to see Duo's operations:

```js
initializeDuo({ manifest, debug: true });
```

Console output:
```
[Duo] Client initialized
[Duo] Livewire initialized, setting up Duo interceptor
[Duo] Duo-enabled component initialized: todo-list
[Duo] Hydrated todos with 5 records from IndexedDB
[Duo] Intercepting commit for Duo component
[Duo] Handling call locally: addTodo
[Duo] Added to IndexedDB: { title: "New todo" }
[Duo] Hydrated todos with 6 records from IndexedDB
```

## Future Enhancements

- [ ] Automatic conflict resolution strategies
- [ ] Real-time sync via WebSockets/Pusher
- [ ] Optimistic UI with rollback on server error
- [ ] Partial sync (only changed fields)
- [ ] Multi-tab synchronization
- [ ] Sync status indicators in UI
