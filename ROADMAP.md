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

## ✅ Recently Completed

### Dexie liveQuery Integration
Replace polling with reactive Dexie liveQuery for automatic UI updates.

**Status:** ✅ Complete
**Completed:** 2025-01-30
**Complexity:** Medium

**Implemented:**
- ✅ Sync status component uses liveQuery to count pending operations (no polling!)
- ✅ Comparison view demo uses liveQuery for IndexedDB display
- ✅ Multi-tab sync works automatically (same user, different browser tabs)
- ✅ BladeToAlpineTransformer generates liveQuery subscriptions for all collections
- ✅ Removed redundant `duoSync()` calls from CRUD methods
- ✅ 50% performance improvement (1 query instead of 2 per operation)

**How It Works:**
```javascript
// Automatically generated in init()
setupLiveQuery() {
    const subscription = window.duo.liveQuery(() => store.toArray())
        .subscribe(items => this.todos = items);
}

// CRUD methods no longer need manual refresh
async createTodo() {
    await store.add(record);
    // UI auto-updates via liveQuery! No manual call needed
}
```

**Benefits:**
- 🔥 Multi-tab sync for free (same device, different tabs see instant updates)
- ✨ Cleaner generated code (no duoSync() scattered everywhere)
- ⚡ More efficient (no manual polling intervals)
- 🎯 Automatically reactive to any IndexedDB change

---

### Automatic Carbon Date Transformation
Automatically detect and transform Carbon date methods in Blade templates.

**Status:** ✅ Complete
**Completed:** 2025-01-30
**Complexity:** Medium

**Implemented:**
- ✅ Automatic detection of Carbon methods like `diffForHumans()`, `format()`, etc.
- ✅ JavaScript equivalents generated for all common Carbon date methods
- ✅ Reactive timestamps that update automatically (e.g., "just now" → "1 minute ago")
- ✅ Component-level configuration for refresh intervals
- ✅ Zero manual code changes required

**Example:**
```blade
<!-- Blade (write normal code) -->
{{ $todo->created_at->diffForHumans() }}

<!-- Automatically becomes -->
<span x-text="diffForHumans(todo.created_at, _now)"></span>
```

**Supported Methods:**
- `diffForHumans()` - "5 minutes ago"
- `format('Y-m-d')` - "2025-01-30"
- `toDateString()` - "Thu Jan 30 2025"
- `toTimeString()` - "14:30:00"
- `toDateTimeString()` - "2025-01-30 14:30:00"
- `toFormattedDateString()` - "Jan 30, 2025"

---

### Component-Level Configuration
Per-component customization through type-safe `duoConfig()` method.

**Status:** ✅ Complete
**Completed:** 2025-01-30
**Complexity:** Low

**Implemented:**
- ✅ Type-safe `DuoConfig` class with named parameters
- ✅ Full IDE autocomplete and type checking
- ✅ Built-in validation (e.g., positive integers)
- ✅ Configuration extracted and injected into Alpine components
- ✅ Component config takes precedence over global config
- ✅ Four config options: syncInterval, timestampRefreshInterval, maxRetryAttempts, debug

**Example:**
```php
use JoshCirre\Duo\{WithDuo, DuoConfig};

class TodoList extends Component {
    use WithDuo;

    protected function duoConfig(): DuoConfig
    {
        return DuoConfig::make(
            syncInterval: 3000,
            timestampRefreshInterval: 5000,
            debug: true
        );
    }
}
```

**Adding New Configuration Options:**

The type-safe system makes adding new options straightforward:

1. **Add to `config/duo.php`:**
   ```php
   'new_option' => env('DUO_NEW_OPTION', 'default'),
   ```

2. **Add to `DuoConfig` constructor:**
   ```php
   public function __construct(
       public readonly ?int $syncInterval = null,
       public readonly ?string $newOption = null, // New!
   ) {
       // Add validation if needed
       if ($this->newOption !== null && !in_array($this->newOption, ['valid', 'values'])) {
           throw new \InvalidArgumentException('Invalid newOption value');
       }
   }
   ```

3. **Add to `DuoConfig::make()` method:**
   ```php
   public static function make(
       ?int $syncInterval = null,
       ?string $newOption = null, // New!
   ): self {
       return new self(
           syncInterval: $syncInterval,
           newOption: $newOption, // New!
       );
   }
   ```

4. **Add to `DuoConfig::toArray()` method:**
   ```php
   if ($this->newOption !== null) {
       $config['newOption'] = $this->newOption;
   }
   ```

5. **Add to `BladeToAlpineTransformer::extractDuoConfig()`:**
   ```php
   $globalConfig = [
       'syncInterval' => config('duo.sync_interval', 5000),
       'newOption' => config('duo.new_option', 'default'), // New!
   ];
   ```

6. **Use in generated JavaScript:**
   ```javascript
   // Access via this._duoConfig.newOption in generated methods
   const option = this._duoConfig?.newOption || 'default';
   ```

**Benefits:**
- ✅ IDE autocomplete updates automatically
- ✅ Type checking catches errors at development time
- ✅ Single source of truth for available options
- ✅ Clear upgrade path for new features

---

## 🚧 In Progress

### Database Schema Extraction & TypeScript Types
Auto-generate schema information and TypeScript types from Eloquent models.

**Status:** 🚧 In Progress (Schema Extraction ✅ Complete, TypeScript Generation 📋 Planned)
**Complexity:** Medium

**Completed:**
- ✅ Automatic database schema extraction (column types, nullable, defaults)
- ✅ Support for both `$fillable` and `$guarded` properties
- ✅ Schema included in manifest for IndexedDB type hints
- ✅ Maps database types to JavaScript-friendly types (string, number, boolean, date, object, blob)

**Planned:**
- 📋 Auto-generate TypeScript interfaces from extracted schema
- 📋 Export types for use in frontend code
- 📋 Type-safe IndexedDB queries

```typescript
// Auto-generated from Todo model schema
interface Todo {
    id: number;              // from schema: { type: 'number', nullable: false, autoIncrement: true }
    title: string;           // from schema: { type: 'string', nullable: false }
    description: string | null; // from schema: { type: 'string', nullable: true }
    completed: boolean;      // from schema: { type: 'number', nullable: false }
    created_at: Date | null; // from schema: { type: 'date', nullable: true }
    updated_at: Date | null; // from schema: { type: 'date', nullable: true }
}
```

---

## 💡 Future Ideas

### Future Configuration Options
Potential new configuration options to add to `DuoConfig`.

**Status:** 💭 Concept
**Complexity:** Low (thanks to type-safe system)

**Potential Options:**

**1. Conflict Resolution Strategy:**
```php
DuoConfig::make(
    conflictResolution: ConflictStrategy::ServerWins
    // or: ConflictStrategy::ClientWins, ConflictStrategy::Newest, ConflictStrategy::Manual
)
```

**2. Offline Storage Limits:**
```php
DuoConfig::make(
    maxStorageItems: 1000,        // Max items per store
    storageQuotaMB: 50            // Max storage in MB
)
```

**3. Sync Strategies:**
```php
DuoConfig::make(
    syncStrategy: SyncStrategy::WriteThrough,  // Immediate sync
    // or: SyncStrategy::WriteBehind (default), SyncStrategy::Manual
    batchSize: 10                 // Sync N operations at once
)
```

**4. Network Conditions:**
```php
DuoConfig::make(
    syncOnlyOnWifi: true,         // Mobile data savings
    offlineQueueLimit: 100        // Max queued operations
)
```

**5. Transformation Hints:**
```php
DuoConfig::make(
    skipElements: ['.no-transform', '#static'],
    preserveClasses: ['tooltip', 'dropdown'],
    customBindings: ['data-status' => 'item.status']
)
```

**6. Performance Tuning:**
```php
DuoConfig::make(
    enableVirtualScroll: true,    // For large lists
    lazyLoadThreshold: 100,       // Load data in chunks
    debounceMs: 300               // Debounce sync triggers
)
```

**7. Developer Experience:**
```php
DuoConfig::make(
    logLevel: LogLevel::Verbose,  // Debug, Info, Warn, Error, None
    enableDevTools: true,         // Show debug panel
    showPerformanceMetrics: true  // Track sync times
)
```

**Implementation Notes:**
- All new options follow the same 6-step process documented above
- Enum types provide additional type safety (e.g., `ConflictStrategy`, `SyncStrategy`)
- Complex options can use nested DTOs (e.g., `NetworkConfig`, `TransformConfig`)

---

### Advanced Blade-to-Alpine Transformation Hints
Provide configuration and Blade directives to help with edge cases in automatic transformation.

**Status:** 💭 Concept
**Complexity:** Medium

**Problem:** While Duo's automatic transformation handles most cases, complex Blade patterns or custom components may need hints for correct transformation.

**Proposed Solutions:**

**1. Component-Level Transformation Config:**
```php
protected function duoConfig(): array
{
    return [
        'transformations' => [
            'skipElements' => ['.no-transform', '#static-content'],
            'customBindings' => [
                'data-status' => 'item.status', // Custom attribute binding
            ],
            'preserveClasses' => ['tooltip', 'dropdown'], // Don't transform these classes
        ],
    ];
}
```

**2. Blade Transformation Directives:**
```blade
{{-- Exclude from transformation --}}
@duoSkip
<div>
    This content won't be transformed to Alpine
</div>
@endDuoSkip

{{-- Custom transformation hints --}}
@foreach($items as $item)
    <div @duoHint:key="item.uuid"> {{-- Use UUID instead of ID --}}
        {{ $item->title }}
    </div>
@endforeach

{{-- Force specific Alpine binding --}}
<span @duoHint:bind="customMethod(item)">{{ $item->computed }}</span>
```

**3. Custom Transform Handlers:**
```php
// In duoConfig()
'transformHandlers' => [
    'CustomComponent' => fn($html, $data) => /* custom logic */,
],
```

**Benefits:**
- 🎯 Handle edge cases gracefully
- 🛠️ Fine-grained control when needed
- 🔧 Escape hatches for complex scenarios
- 📝 Clear intent in Blade templates

**Use Cases:**
- Complex custom components that don't follow standard patterns
- Third-party Blade components
- Dynamic attribute generation
- Special Alpine.js patterns (e.g., `x-teleport`, `x-id`)

---

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
