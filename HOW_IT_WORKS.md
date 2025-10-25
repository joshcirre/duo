# How Duo Works - Zero Network Requests After Initial Load

## The Magic

Add `use Duo;` to your Livewire component. That's it. No network requests after initial load.

```php
class TodoList extends Component
{
    use Duo;  // 🎯 This one line enables offline-first

    public function addTodo() {
        Todo::create([...]);  // Works offline!
    }
}
```

## What Happens

### Initial Page Load (First Time Only)
1. **Server Renders**: Livewire sends todos from database
2. **Duo Caches**: All Todo models → IndexedDB automatically
3. **Component Displays**: Shows data (just like normal Livewire)

### After Initial Load (All Subsequent Actions)

#### Adding a Todo:
```
User clicks "Add Todo"
    ↓
Duo intercepts wire:click
    ↓
Creates todo in IndexedDB (instant!)
    ↓
Updates UI from IndexedDB (instant!)
    ↓
NO NETWORK REQUEST ❌
    ↓
Background sync queues it for later ⏰
```

#### Toggling/Deleting:
```
User clicks toggle/delete
    ↓
Duo intercepts the action
    ↓
Updates/deletes in IndexedDB (instant!)
    ↓
UI updates from IndexedDB (instant!)
    ↓
NO NETWORK REQUEST ❌
```

## The Implementation

### PHP Side (Super Simple)

```php
// 1. Model has Syncable trait
class Todo extends Model
{
    use Syncable;
}

// 2. Component has Duo trait
class TodoList extends Component
{
    use Duo;

    // Your normal Livewire methods - NO CHANGES NEEDED
    public function addTodo() { Todo::create([...]); }
    public function toggleTodo($id) { /* ... */ }
    public function deleteTodo($id) { /* ... */ }
}
```

### JavaScript Side (Automatic)

Duo intercepts the Livewire `commit` hook:

```typescript
window.Livewire.hook('commit', async ({ component, commit, respond }) => {
    if (component has Duo trait) {
        // Handle the operation in IndexedDB
        await handleLocally(commit.calls);

        // Update UI from IndexedDB
        await refreshFromIndexedDB();

        // PREVENT network request
        respond({});
        return;
    }

    // Normal Livewire flow for non-Duo components
});
```

## What Duo Detects Automatically

### Method Name Patterns:
- `addTodo`, `createX`, `add*` → **Create** in IndexedDB
- `toggleTodo`, `updateX` → **Update** in IndexedDB
- `deleteTodo`, `removeX` → **Delete** from IndexedDB

### Data Extraction:
- Looks for `newTodoTitle`, `newX` properties → Extracts for creation
- Clears input fields after creation
- Uses method params for ID in update/delete

### UI Refresh:
- After operation: Hydrates component from IndexedDB
- Looks for `todos`, `items`, `data`, `records` properties
- Updates them with IndexedDB data

## Network Traffic Comparison

### Before Duo:
```
[Initial Load]    Server → Client (todos data)
[Add Todo]        Client → Server → Client (network delay)
[Toggle]          Client → Server → Client (network delay)
[Delete]          Client → Server → Client (network delay)
```

### With Duo:
```
[Initial Load]    Server → Client → IndexedDB (cache)
[Add Todo]        IndexedDB only (instant!) ⚡
[Toggle]          IndexedDB only (instant!) ⚡
[Delete]          IndexedDB only (instant!) ⚡
[Background Sync] IndexedDB → Server (when ready) 📡
```

## Offline Mode

```
User goes offline
    ↓
User adds/edits/deletes todos
    ↓
Everything works normally! ✅
    ↓
Changes queued in IndexedDB
    ↓
User comes back online
    ↓
Background sync pushes changes to server 🔄
```

## Debug Mode

Enable to see what's happening:

```javascript
initializeDuo({
    manifest,
    debug: true,  // 👈 See all Duo operations
});
```

Console output:
```
[Duo] Livewire initialized, setting up Duo interceptor
[Duo] Duo-enabled component initialized: todo-list
[Duo] Hydrated todos with 0 records from IndexedDB
[Duo] Intercepting commit for Duo component
[Duo] Handling call locally: addTodo
[Duo] Added to IndexedDB: { title: "Test", description: "" }
[Duo] Hydrated todos with 1 records from IndexedDB
[Duo] Preventing network request, handled locally ✅
```

## Performance Benefits

1. **Instant UI Updates**: No waiting for server round-trip
2. **Zero Network Latency**: After initial load, everything is local
3. **Reduced Server Load**: Operations happen client-side
4. **Works Offline**: Full functionality without internet
5. **Better UX**: App feels native, not web

## What Gets Synced

- ✅ Model data in Livewire component state
- ✅ Create/Update/Delete operations
- ✅ All fields on the model
- ✅ Timestamps (created_at, updated_at)

## What Doesn't Require Network

After initial load:
- ✅ Adding items
- ✅ Editing items
- ✅ Deleting items
- ✅ Toggling properties
- ✅ Filtering (if done client-side)
- ✅ Sorting (if done client-side)

## The Best Part

**You don't change any of your existing Livewire code.** Just add two traits and it works!

```diff
  class Todo extends Model
  {
+     use Syncable;
  }

  class TodoList extends Component
  {
+     use Duo;

      // All your existing methods work as-is!
  }
```

That's it. Local-first, offline-capable, instant UI updates. 🎉
