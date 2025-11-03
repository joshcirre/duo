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

### User-Aware Conflict Resolution Component
Simple, user-friendly component for resolving conflicts across users and devices.

**Status:** 💭 Concept
**Complexity:** Medium
**Depends on:** Seamless Conflict Resolution
**Related to:** Multiplayer Mode (Duet)

**Problem:** When conflicts occur (same user on different devices, or different users editing the same data), users need a simple, intuitive way to understand and resolve them.

**Proposed Solution:**
A drop-in Blade component that shows conflicts with user attribution and simple accept/deny actions.

```blade
{{-- Automatically detects and displays conflicts --}}
<x-duo::conflict-resolver model="Todo" />

{{-- Or with custom configuration --}}
<x-duo::conflict-resolver
    model="Todo"
    :show-user-info="true"
    :auto-resolve-strategy="'newest-wins'"
    :require-permission="'resolve-conflicts'"
/>
```

**User Experience:**

**Scenario 1: Multi-User Conflicts**
```
┌─────────────────────────────────────────────┐
│ ⚠️  Conflict Detected                       │
│                                             │
│ Ben has 3 changes that overwrite yours:     │
│                                             │
│ • Todo: "Buy milk" → "Buy oat milk"         │
│ • Todo: "Meeting at 3pm" (deleted)          │
│ • Todo: "Call Sarah" → "Call Sarah ASAP"    │
│                                             │
│ [Accept Ben's Changes] [Keep Mine]          │
└─────────────────────────────────────────────┘
```

**Scenario 2: Multi-Device Conflicts (Same User)**
```
┌─────────────────────────────────────────────┐
│ ⚠️  Sync Conflict                           │
│                                             │
│ Your laptop has 2 changes made offline      │
│ while your phone was editing:               │
│                                             │
│ Laptop (offline):                           │
│ • Todo: "Call dentist" (completed)          │
│ • Todo: "Buy groceries" → "Buy healthy food"│
│                                             │
│ Phone (current):                            │
│ • Todo: "Call dentist" (still pending)      │
│ • Todo: "Buy groceries" (unchanged)         │
│                                             │
│ [Use Laptop Version] [Use Phone Version]    │
│ [Merge Changes]                             │
└─────────────────────────────────────────────┘
```

**Features:**

1. **User Attribution:**
   - Shows who made conflicting changes (name, device, timestamp)
   - Differentiates between different users vs. same user on different devices
   - Shows number of conflicting changes

2. **Simple Actions:**
   - Accept/Deny buttons for conflicts
   - Optional "Merge" for compatible changes
   - Batch resolve (accept all, deny all)

3. **Permission-Based Control:**
   ```php
   // In component config
   'conflict_permissions' => [
       'can_resolve' => 'resolve-conflicts',      // Who can manually resolve
       'auto_resolve' => [
           'admin' => 'always-accept',            // Admins auto-win
           'moderator' => 'accept-over-user',     // Mods win over users
           'user' => 'manual-review',             // Users see the UI
       ],
   ],
   ```

4. **Device-Aware Resolution:**
   - Detects when same user has conflicting changes on different devices
   - Shows device names (e.g., "Your MacBook Pro", "Your iPhone")
   - Offers "Use newest" or "Use from [device name]"

5. **Visual Diff:**
   ```blade
   {{-- Show what changed --}}
   <div class="conflict-diff">
       <div class="before">
           <span class="label">Your version:</span>
           "Buy milk"
       </div>
       <div class="after">
           <span class="label">Ben's version:</span>
           "Buy oat milk"
       </div>
   </div>
   ```

**Component API:**

```php
// Livewire component
use JoshCirre\Duo\Components\ConflictResolver;

class ConflictResolver extends Component
{
    public string $model;              // Model class (e.g., Todo::class)
    public bool $showUserInfo = true;  // Show user attribution
    public ?string $requirePermission = null; // Gate check

    // Automatically populated by Duo
    public Collection $conflicts;      // List of detected conflicts

    public function acceptChanges(array $conflictIds)
    {
        // Accept specific conflicts
    }

    public function denyChanges(array $conflictIds)
    {
        // Keep local version
    }

    public function mergeChanges(int $conflictId, array $mergeStrategy)
    {
        // Custom merge logic
    }
}
```

**Conflict Detection:**

Duo automatically tracks:
- Who made the change (`user_id`, `user_name`)
- When it was made (`updated_at`, `synced_at`)
- Which device (from user agent or device identifier)
- What changed (before/after values)

```json
{
  "conflict_id": "abc123",
  "model": "App\\Models\\Todo",
  "record_id": 42,
  "field": "title",
  "local_value": "Buy milk",
  "remote_value": "Buy oat milk",
  "local_user": { "id": 1, "name": "You" },
  "remote_user": { "id": 2, "name": "Ben" },
  "local_device": "MacBook Pro",
  "remote_device": "iPhone 14",
  "local_timestamp": "2025-01-30 14:30:00",
  "remote_timestamp": "2025-01-30 14:31:00"
}
```

**Auto-Resolution Strategies:**

```php
// config/duo.php
'conflict_resolution' => [
    'strategy' => 'smart',  // smart, manual, newest, permission-based

    'smart_rules' => [
        // Same user, different devices → newest wins
        'same_user_multi_device' => 'newest-wins',

        // Different users → show UI
        'multi_user' => 'manual-review',

        // Admin vs anyone → admin wins
        'permission_hierarchy' => true,
    ],
],
```

**Benefits:**
- 🎯 Simple, user-friendly conflict resolution
- 👥 Shows WHO made changes, not just WHAT changed
- 📱 Device-aware (knows if it's your phone vs laptop)
- 🔐 Permission-based control over resolution
- ⚡ Auto-resolve simple cases, prompt for complex ones
- 🎨 Beautiful, accessible UI out of the box

**Use Cases:**
- Collaborative todo apps (multiple users editing same list)
- Multi-device editing (same user on phone + laptop)
- Team dashboards with concurrent edits
- Offline mobile app syncing back to desktop
- Content management with multiple editors

**Implementation Challenges:**
- Tracking device identifiers reliably
- Storing conflict metadata efficiently
- Designing intuitive merge UI
- Handling cascading conflicts (A conflicts with B which conflicts with C)
- Performance with many simultaneous conflicts

**Example Usage:**

```blade
{{-- In your Livewire component view --}}
<div>
    <h1>My Todos</h1>

    {{-- Conflict resolver automatically shows when conflicts exist --}}
    <x-duo::conflict-resolver
        model="App\Models\Todo"
        :show-user-info="true"
    />

    {{-- Your normal todo list --}}
    @foreach($this->todos as $todo)
        <div>{{ $todo->title }}</div>
    @endforeach
</div>
```

**Related Features:**
- Builds on "Seamless Conflict Resolution" (High Priority)
- Integrates with "Permission-Based Conflict Resolution" (High Priority)
- Would be enhanced by "Multiplayer Mode (Duet)" for real-time conflict prevention
- Could use "Debug Dashboard" to inspect conflict history

---

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

### Static AST Analysis for Method Detection
Replace runtime SQL query capture with static PHP Abstract Syntax Tree (AST) analysis.

**Status:** 📋 Planned
**Complexity:** High
**Priority:** High (Security & Performance)

**Current Approach (Runtime SQL Capture):**
- Executes component methods with dummy data in rolled-back transactions
- Captures SQL queries to determine operation types (INSERT/UPDATE/DELETE)
- Extracts which columns are affected
- **Security concerns**: Side effects (emails, API calls), dummy data creation, public manifest exposure
- **Performance concerns**: SQL analysis runs on every Volt component render

**Proposed Approach (Static AST Analysis):**
Parse PHP code without executing it, using PHP-Parser or similar libraries to build an Abstract Syntax Tree and extract method semantics.

**What We CAN Detect Statically (90%+ of cases):**

```php
// Example 1: Create with field mapping
public function addTodo() {
    auth()->user()->todos()->create([
        'title' => $this->newTodoTitle,
        'description' => $this->newTodoDescription,
    ]);
}
```
**AST Extraction**:
- ✅ Method called: `create()` → Operation: INSERT
- ✅ Columns: `['title', 'description']`
- ✅ Field mapping: `{newTodoTitle: 'title', newTodoDescription: 'description'}`
- ✅ Model: `Todo` (from `todos()` relationship)

```php
// Example 2: Toggle detection
public function toggleCompleted($id) {
    $todo = Todo::findOrFail($id);
    $todo->update(['completed' => !$todo->completed]);
}
```
**AST Extraction**:
- ✅ Method: `update()` → Operation: UPDATE
- ✅ Column: `'completed'`
- ✅ Pattern: Boolean toggle (unary `!` operator)
- ✅ Model: `Todo` (from static call)

```php
// Example 3: Relationship-based create
public function store() {
    $this->user->posts()->create([
        'title' => $this->title,
        'body' => $this->body,
    ]);
}
```
**AST Extraction**:
- ✅ Method: `create()` → INSERT
- ✅ Model: `Post` (from `posts()` relationship)
- ✅ Columns: `['title', 'body']`
- ✅ Direct field mapping (property names match columns)

**What We CAN'T Detect (Rare edge cases):**

```php
// Dynamic columns from runtime data
public function update(Request $request) {
    $columns = $request->only(['title', 'description']); // Runtime value
    $todo->update($columns); // Can't statically determine columns
}

// Conditional logic with complex branching
public function save() {
    if ($this->someComplexCondition()) {
        Model::create(['field1' => ...]);
    } else {
        Model::update(['field2' => ...]);
    }
}
```

**Implementation Plan:**

1. **Add PHP-Parser dependency**: `composer require nikic/php-parser`

2. **Create AST Analyzer Service**:
   ```php
   class EloquentMethodAnalyzer {
       public function analyzeMethod(\ReflectionMethod $method): MethodAnalysis {
           $ast = $this->parseMethodSource($method);
           return $this->extractEloquentCalls($ast);
       }

       private function extractEloquentCalls(Node $ast): MethodAnalysis {
           // Traverse AST looking for:
           // - create() calls → INSERT + extract array keys
           // - update() calls → UPDATE + extract array keys
           // - delete() calls → DELETE
           // - Relationship chains (todos(), user()->posts())
       }
   }
   ```

3. **Extract Field Mappings**:
   ```php
   // When we see: 'title' => $this->newTodoTitle
   // AST shows:
   // - Array key: 'title' (database column)
   // - Value: Property access to 'newTodoTitle' (component property)
   // → Mapping: newTodoTitle → title
   ```

4. **Toggle Detection**:
   ```php
   // When we see: 'completed' => !$model->completed
   // AST shows:
   // - Key: 'completed'
   // - Value: UnaryOp_BooleanNot of property access
   // → Boolean toggle on 'completed' column
   ```

5. **Fallback Strategy**:
   - For 90% of methods: Use static analysis
   - For complex/dynamic cases: Fall back to current SQL capture
   - Log when fallback is used to track coverage

**Benefits:**

✅ **Security**:
- No code execution during analysis
- No side effects (emails, API calls, webhooks)
- No dummy data in database
- Can sanitize manifest before exposing publicly

✅ **Performance**:
- Zero runtime overhead for Volt components
- Analysis only during `php artisan duo:generate`
- Scales to thousands of components

✅ **Accuracy**:
- Detects field mappings (`newTodoTitle` → `title`)
- Identifies boolean toggles automatically
- Extracts relationships and models precisely

✅ **Developer Experience**:
- Works with any coding style
- Handles `addTodo`, `createTodo`, `bananaTodo` equally
- Clear error messages when pattern isn't recognized

**Testing Strategy:**

```php
// Test suite covering common patterns
test('detects create operations', function () {
    $analysis = $analyzer->analyze(CreateTodoMethod::class);

    expect($analysis->operationType)->toBe('insert');
    expect($analysis->columns)->toBe(['title', 'description']);
    expect($analysis->fieldMapping)->toBe([
        'newTodoTitle' => 'title',
        'newTodoDescription' => 'description',
    ]);
});
```

**Manifest Output:**

```json
{
  "components": {
    "TodoDemo": {
      "methods": {
        "addTodo": {
          "operationType": "insert",
          "model": "App\\Models\\Todo",
          "columns": ["title", "description"],
          "fieldMapping": {
            "newTodoTitle": "title",
            "newTodoDescription": "description"
          },
          "analysisMethod": "static" // or "runtime" if fallback used
        },
        "toggleTodo": {
          "operationType": "update",
          "model": "App\\Models\\Todo",
          "columns": ["completed"],
          "pattern": "toggle",
          "analysisMethod": "static"
        }
      }
    }
  }
}
```

**Migration Path:**

1. Implement static analysis alongside current SQL capture
2. Compare results in development (log discrepancies)
3. Gradually increase static analysis coverage
4. Eventually deprecate SQL capture for static-analyzable methods
5. Keep SQL capture as fallback for dynamic cases

**Related Issues:**
- Solves field mapping problems (newTodoTitle vs title)
- Eliminates security concerns with code execution
- Makes Volt components as efficient as class-based components
- Enables manifest sanitization before public exposure

---

### Cache Method Analysis in Manifest
Cache method operation types and parameters in manifest to eliminate runtime reflection.

**Status:** 📋 Planned
**Complexity:** Medium

**Problem:** Currently, page renders still perform method reflection (reading signatures, parameters) even though SQL capture is skipped. This adds unnecessary overhead on every page load.

**Proposed Solution:** Store method analysis results in the manifest during generation, then read from manifest on page renders.

**Current Flow:**
```
Page Render:
  ↓
Method Reflection (get signatures, parameters)
  ↓
Generate Alpine Methods
  ↓
Render to HTML
```

**Optimized Flow:**
```
Manifest Generation (one-time):
  ↓
Method Reflection + SQL Analysis
  ↓
Store in manifest.json

Page Render (every request):
  ↓
Read from manifest (no reflection!)
  ↓
Generate Alpine Methods
  ↓
Render to HTML
```

**Manifest Structure:**
```json
{
  "stores": { ... },
  "components": {
    "TodoDemo": {
      "methods": {
        "addTodo": {
          "operationType": "insert",
          "parameters": ["newTodoTitle", "newTodoDescription"],
          "updatedColumns": [],
          "validation": { "newTodoTitle": "required|min:3" }
        },
        "toggleTodo": {
          "operationType": "update",
          "parameters": ["id"],
          "updatedColumns": ["completed"]
        }
      }
    }
  }
}
```

**Benefits:**
- ✅ Zero reflection overhead on page renders
- ✅ Faster page loads
- ✅ Consistent method info across renders
- ✅ Easier debugging (see what Duo detected)
- ✅ Could enable external tooling to read manifest

**Implementation:**
1. During `duo:generate`, store method analysis in manifest
2. On page renders, read method info from manifest instead of reflection
3. Fall back to reflection if manifest is stale or missing

---

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

### Move x-data Addition to Blade Source Level
Eliminate the HTML transformation phase by adding x-data at the Blade source level.

**Status:** 🚧 In Progress
**Complexity:** Medium

**Current Approach:**
- Transform Blade source (wire: directives, @foreach, @if/@else)
- Render transformed Blade to HTML
- Find root element in HTML and add x-data

**Proposed Approach:**
Transform everything at Blade source level, including x-data injection.

**Potential Solutions:**

**Option 1: Custom Blade Directive (Explicit)**
```blade
<div @duoInit>
    <!-- component content -->
</div>
```
Transform `@duoInit` → `x-data="{...}"` at Blade source level.

**Option 2: Auto-detect Root Element**
Find first `<div>` after PHP block and inject x-data attribute.

**Option 3: Convention-Based**
Require root element to have a specific class or attribute.

**Benefits:**
- ✅ Single transformation phase (Blade source only)
- ✅ Simpler pipeline
- ✅ No HTML parsing needed
- ✅ Cleaner architecture

**Decision Needed:** Choose between explicit directive (Option 1) vs. auto-detection (Option 2)

---

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

### Global Offline Mode
Enable site-wide offline-first mode with a single configuration flag.

**Status:** 💭 Concept
**Complexity:** High

**Problem:** Currently, Duo requires component-level setup (WithDuo trait) and individual page optimization. For demo apps or apps that want full offline capability, this can be tedious.

**Proposed Solution:**
A global configuration option that makes the entire application offline-first by default, automatically caching all pages in the service worker and enabling offline mode for all components with the `WithDuo` trait.

```php
// config/duo.php
'global_offline_mode' => env('DUO_GLOBAL_OFFLINE', false),
```

**How It Works:**

1. **Service Worker Auto-Registration:**
   - When `global_offline_mode => true`, Duo automatically registers a service worker
   - Service worker caches all visited pages (HTML, CSS, JS, assets)
   - Site becomes a full Progressive Web App (PWA)

2. **Automatic Static Fallback:**
   - All pages without `WithDuo` components work offline as static cached pages
   - No interactivity, but users can still browse cached content

3. **Enhanced Offline Components:**
   - Any component with `WithDuo` trait automatically gets full offline functionality
   - No additional configuration needed
   - Works seamlessly within the cached static pages

4. **Intelligent Cache Strategy:**
   ```php
   'cache_strategy' => [
       'pages' => 'network-first',      // Try network, fallback to cache
       'assets' => 'cache-first',       // Serve from cache, update in background
       'api' => 'network-only',         // Always fresh data when online
   ],
   ```

**Example Configuration:**

```php
// Full offline mode for demo apps
'global_offline_mode' => true,
'offline_config' => [
    'cache_all_pages' => true,           // Cache every visited page
    'cache_all_assets' => true,          // Cache CSS/JS/images
    'show_offline_indicator' => true,    // UI indicator when offline
    'preload_routes' => [                // Pre-cache these routes on install
        '/',
        '/dashboard',
        '/todos',
    ],
    'max_cache_age' => 86400,            // Cache TTL in seconds (24h)
    'excluded_routes' => [               // Don't cache these
        '/admin/*',
        '/api/external/*',
    ],
],
```

**User Experience:**

```blade
{{-- Developer writes normal Blade --}}
<x-app-layout>
    <h1>My Page</h1>

    {{-- This component automatically works offline when global mode enabled --}}
    <livewire:todo-list />  {{-- Uses WithDuo trait --}}

    {{-- Static content also cached and works offline --}}
    <div>This static content is cached too!</div>
</x-app-layout>
```

**Benefits:**
- 🚀 Zero-config offline mode for entire application
- 📱 Instant PWA capabilities
- 🎯 Perfect for demos, prototypes, and MVPs
- ✨ Static pages work offline (read-only)
- ⚡ WithDuo components get full offline CRUD
- 🔄 Seamless online/offline transitions

**Implementation Challenges:**
- Service worker lifecycle management
- Cache invalidation strategies
- Handling authenticated routes offline
- Asset versioning and cache busting
- Memory/storage quota management
- Conflict resolution when pages update

**Potential Issues:**
- May cache too aggressively for production apps
- Stale content if not managed carefully
- Storage quota limits on mobile devices
- Complexity of cache invalidation

**Use Cases:**
- Demo applications showcasing Duo capabilities
- Offline-first prototypes
- Internal tools with spotty connectivity
- Progressive Web Apps (PWAs)
- Mobile apps built with Laravel

**Related Features:**
- Builds on "Full Page Caching" (High Priority)
- Could integrate with "Background Sync Service Worker" (Future Ideas)
- May require "Debug Dashboard" for cache management (Future Ideas)

---

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
