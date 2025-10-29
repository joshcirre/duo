# Duo Roadmap

This document outlines planned features and improvements for Duo. Items are organized by priority and category.

> **Note:** This roadmap is subject to change based on community feedback and project priorities.

## 🚀 High Priority

### Full Page Caching
Enable complete offline-first mode where entire pages work without network connection.

**Status:** 📋 Planned
**Complexity:** High

- Cache entire page HTML in IndexedDB
- Intercept navigation and serve from cache
- Background refresh when online
- Service worker integration for offline routing

**Benefits:**
- True offline-first experience
- Instant page loads from cache
- Progressive web app capabilities

---

### Seamless Conflict Resolution
Automatic conflict resolution with visual indicators for user review.

**Status:** 📋 Planned
**Complexity:** Medium

- Generic `<x-duo::conflicts />` component (similar to sync-status)
- Automatic conflict detection when syncing
- Visual diff showing local vs server changes
- User-friendly resolution UI
- Config-based resolution strategies

**Strategies:**
- Server wins (default)
- Client wins
- Newest wins (by timestamp)
- Manual review (show conflict component)
- Permission-based (see below)

---

### Permission-Based Conflict Resolution
Role-based automatic conflict resolution.

**Status:** 📋 Planned
**Complexity:** Medium
**Depends on:** Seamless Conflict Resolution

```php
// Config example
'conflict_resolution' => [
    'strategy' => 'permission-based',
    'hierarchy' => [
        'admin' => 'always-wins',      // Admin changes override everyone
        'moderator' => 'wins-over-user', // Moderators override users
        'user' => 'manual-review',     // Users get conflict UI
    ],
],
```

**Use Cases:**
- Admin edits should always win
- Collaborative editing with roles
- Content moderation workflows

---

## 🎯 Medium Priority

### Multiplayer Mode ("Duet")
Real-time sync across multiple users/devices using Laravel Echo/Reverb.

**Status:** 💭 Concept
**Complexity:** High
**Package Name:** `Duet` (extension package)

```php
// Example usage
use JoshCirre\Duet\Multiplayer;

class TodoList extends Component
{
    use WithDuo;
    use Multiplayer;  // Enables real-time sync
}
```

**Features:**
- Real-time updates via WebSockets (Echo/Reverb)
- See other users' cursors/presence
- Live collaboration indicators
- Conflict-free replicated data types (CRDTs)
- Optimistic updates with rollback

**Config:**
```php
'multiplayer' => [
    'driver' => 'reverb',  // or 'pusher', 'ably'
    'presence_channel' => true,
    'show_cursors' => true,
    'conflict_strategy' => 'operational-transform',
],
```

---

### Architecture Refactor: Queue Livewire Calls Instead of Custom Sync Layer
Simplify sync mechanism by queuing and replaying actual Livewire method calls instead of maintaining a custom IndexedDB-to-database sync layer.

**Status:** 🔬 Research
**Complexity:** Medium
**Breaking Change:** Yes (internal only, same API surface)

**Current Approach:**
```
User Action
    ↓
Alpine (transformed from Blade) updates UI instantly
    ↓
Write to IndexedDB
    ↓
Custom Sync Queue: IndexedDB → Custom API endpoints
    ↓
Manual database updates
```

**Proposed Approach:**
```
User Action
    ↓
Alpine (transformed from Blade) updates UI instantly
    ↓
Write to IndexedDB
    ↓
Queue the Livewire method call itself
    ↓
When online: POST /livewire/update (replay wire:click="addTodo")
    ↓
Livewire method runs naturally → Eloquent handles database
```

**Key Insight:**
Keep the Blade → Alpine transformation (instant UI), keep IndexedDB (offline persistence), but instead of syncing IndexedDB data to the server database through a custom layer, just replay the original Livewire method calls! The server-side methods already know how to update the database through normal Eloquent operations.

**Benefits:**
- ✅ **Keep instant UI** - Alpine transformation still provides immediate updates
- ✅ **Keep offline data** - IndexedDB still caches everything locally
- ✅ **Simpler sync** - No custom API endpoints for syncing data
- ✅ **Use Livewire naturally** - Server methods work as written (Eloquent, validation, etc.)
- ✅ **Less maintenance** - Livewire handles all server communication
- ✅ **Smaller bundle size** - Remove custom sync layer
- ✅ **Better error handling** - Leverage Livewire's validation and error responses
- ✅ **Easier debugging** - Clear separation: Alpine (UI), IndexedDB (offline data), Livewire (server sync)

**Implementation Concept:**

```javascript
// When user clicks something offline
@click="addTodo()"
    ↓
// Alpine method runs
addTodo() {
    // 1. Update IndexedDB immediately (instant UI update)
    await db.todos.add({ title: this.newTodoTitle });
    this.todos = await db.todos.toArray();

    // 2. Queue the Livewire call for later
    if (!navigator.onLine) {
        await duoQueue.add({
            component: 'todo-list',
            method: 'addTodo',
            params: {},
            snapshot: Livewire.find('todo-list').snapshot  // Current component state
        });
    } else {
        // Online: Just call Livewire normally
        Livewire.find('todo-list').call('addTodo');
    }
}

// When back online, replay all queued calls
async function processQueue() {
    const pending = await duoQueue.getAll();

    for (const call of pending) {
        // Replay the Livewire method call
        await fetch('/livewire/update', {
            method: 'POST',
            body: JSON.stringify({
                snapshot: call.snapshot,
                updates: [{
                    type: 'callMethod',
                    payload: {
                        method: call.method,
                        params: call.params
                    }
                }]
            })
        });

        await duoQueue.remove(call.id);
    }
}
```

**What Gets Queued:**
- Livewire method name (e.g., `addTodo`)
- Method parameters (e.g., `[{ title: "..." }]`)
- Component snapshot (for context)
- Timestamp and retry count

**When Online Reconnects:**
Simply replay all queued Livewire method calls in order! Each method runs on the server as if the user just clicked it.

**Considerations:**
- How to handle stale component snapshots?
- What if the model was updated by someone else?
- Need to update IndexedDB with server response
- Conflict resolution when replaying old calls
- Handle validation errors from replayed calls

**This would eliminate:**
- ❌ Custom API endpoints for syncing data
- ❌ Manual database insert/update logic in sync layer
- ❌ Complex data transformation between IndexedDB and server
- ❌ Separate sync routes and controllers

**This would keep:**
- ✅ Blade → Alpine transformation (instant UI)
- ✅ IndexedDB for local data storage
- ✅ All current transformation logic

**Research Needed:**
- Study Livewire's `callMethod` update structure
- Test method replay with stale snapshots
- Understand snapshot fingerprint validation
- Handle validation errors gracefully
- Performance impact of queued method calls

---

## 🔧 Optimization & Compatibility

### Alpine.js Optimization
Leverage Alpine.js plugins and optimizations.

**Status:** 📋 Planned
**Complexity:** Low

**Potential Plugins:**
- `@alpinejs/persist` - Persist state across page loads
- `@alpinejs/focus` - Focus management for forms
- `@alpinejs/collapse` - Smooth animations
- `@alpinejs/morph` - Efficient DOM updates (similar to Livewire morphing)

**Custom Optimizations:**
- Lazy-load Alpine data from IndexedDB
- Debounce sync operations
- Virtual scrolling for large lists
- Memoization of computed properties

---

### Laravel Optimization
Leverage Laravel 11+ performance features.

**Status:** 📋 Planned
**Complexity:** Low

**Features to Integrate:**
- `defer()` - Defer non-critical sync operations
- Queued jobs for background sync
- Batch database operations
- Lazy collections for large datasets

```php
// Example: Defer sync to after response
public function addTodo()
{
    $todo = Todo::create([...]);

    defer(fn() => $this->syncToOtherDevices($todo));
}
```

---

### Livewire v4 Compatibility
Ensure compatibility with Livewire v4 when released.

**Status:** ⏳ Waiting
**Complexity:** Unknown

- Monitor Livewire v4 development
- Test against beta releases
- Update HTML transformation logic if needed
- Leverage new Livewire features

---

## 💡 Future Ideas

### Background Sync Service Worker
Use service workers for more robust background sync.

**Status:** 💭 Concept
**Complexity:** High

- Background Sync API for reliable syncing
- Push notifications for sync conflicts
- Periodic background sync
- Better offline detection

---

### Flux Component Compatibility
Better integration with Livewire Flux components inside loops.

**Status:** 💭 Concept
**Complexity:** High

**Challenge:** Flux components render server-side, Alpine needs client-side templates.

**Possible Solutions:**
- Component slot rendering
- Hybrid approach (Flux wrapper, Alpine content)
- Custom Flux-to-Alpine compiler

---

### TypeScript Type Generation
Auto-generate TypeScript types from Eloquent models.

**Status:** 💭 Concept
**Complexity:** Medium

```typescript
// Auto-generated from Todo model
interface Todo {
    id: number;
    title: string;
    description: string | null;
    completed: boolean;
    created_at: string;
    updated_at: string;
}
```

---

### Debug Dashboard
Visual dashboard for monitoring Duo operations.

**Status:** 💭 Concept
**Complexity:** Medium

- View all cached models
- Monitor sync queue
- Inspect conflicts
- Clear caches
- Performance metrics

Accessible via: `/duo/dashboard` (in dev mode)

---

## 📊 Status Legend

- 📋 **Planned** - Scheduled for development
- 🔬 **Research** - Investigating feasibility
- 💭 **Concept** - Early idea stage
- ⏳ **Waiting** - Blocked by external factors
- 🚧 **In Progress** - Currently being developed
- ✅ **Complete** - Implemented and released

---

## Contributing

Interested in helping with any of these features? Check out our [Contributing Guide](CONTRIBUTING.md) and open an issue to discuss!

## Feedback

Have ideas for features not listed here? [Open an issue](https://github.com/joshcirre/duo/issues/new) with the `enhancement` label.
