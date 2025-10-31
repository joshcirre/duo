# Duo (VERY MUCH A WIP)

**Local-first IndexedDB syncing for Laravel and Livewire applications.**

Duo enables automatic client-side caching and synchronization of your Eloquent models using IndexedDB, providing a seamless offline-first experience for your Laravel/Livewire applications. Just add a trait to your Livewire component and Duo handles the rest—automatically transforming your server-side components to work with IndexedDB.

## Features

- 🚀 **Zero Configuration**: Add one trait and Duo automatically transforms your Livewire components to Alpine.js
- 💾 **Automatic IndexedDB Caching**: Transparently cache Eloquent models in the browser
- 🗄️ **Schema Extraction**: Automatically extracts database column types, nullability, and defaults for IndexedDB
- ⚡ **Optimistic Updates**: Instant UI updates with background server synchronization
- 🔄 **Offline Support**: Automatic offline detection with sync queue that resumes when back online
- 📊 **Visual Sync Status**: Built-in component showing online/offline/syncing states
- 🎯 **Livewire Integration**: Seamless integration with Livewire 3+ and Volt components
- 📦 **Type-Safe**: Full TypeScript support with auto-generated types from database schema
- 🔌 **Vite Plugin**: Automatic manifest generation with file watching

## Local Development Setup

Want to contribute or test Duo locally? Follow these steps to set up local development with symlinked packages.

### 1. Clone and Install Duo

```bash
# Clone the Duo package repository
git clone https://github.com/joshcirre/duo.git
cd duo

# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Build the package
npm run build
```

### 2. Symlink Composer Package

Link the Duo package to your local Laravel application:

```bash
# In your Laravel app directory (e.g., ~/Code/my-laravel-app)
cd ~/Code/my-laravel-app

# Add the local repository to composer.json
composer config repositories.duo path ../duo

# Require the package from the local path
composer require joshcirre/duo:@dev
```

This creates a symlink in `vendor/joshcirre/duo` pointing to your local Duo directory. Changes to the PHP code are immediately reflected.

### 3. Symlink NPM Package

Link the Vite plugin to your Laravel application:

```bash
# In the Duo package directory
cd ~/Code/duo
npm link

# In your Laravel app directory
cd ~/Code/my-laravel-app
npm link @joshcirre/vite-plugin-duo
```

Now your Laravel app uses the local version of the Vite plugin.

### 4. Watch for Changes

In the Duo package directory, run the build watcher:

```bash
cd ~/Code/duo
npm run dev
```

This watches for TypeScript changes and rebuilds automatically. Changes are immediately available in your linked Laravel app.

### 5. Test Your Changes

In your Laravel app:

```bash
# Run both Vite and Laravel (recommended)
composer run dev
```

This runs both `npm run dev` and `php artisan serve` concurrently. Any changes you make to Duo's PHP or TypeScript code will be reflected immediately!

**Alternative (manual):**
```bash
# Terminal 1: Vite
npm run dev

# Terminal 2: Laravel
php artisan serve
```

### 6. Unlinking (When Done)

To remove the symlinks:

```bash
# Unlink npm package (in your Laravel app)
cd ~/Code/my-laravel-app
npm unlink @joshcirre/vite-plugin-duo

# Unlink composer package
composer config repositories.duo --unset
composer require joshcirre/duo  # Reinstall from Packagist

# Unlink from Duo directory
cd ~/Code/duo
npm unlink
```

### Development Tips

- **PHP Changes**: Automatically picked up via symlink
- **TypeScript Changes**: Require `npm run build` or `npm run dev` (watch mode)
- **View Changes**: Blade components update automatically
- **Config Changes**: May require `php artisan optimize:clear`
- **Manifest Changes**: Run `php artisan duo:generate` manually if needed

---

## Installation

### Composer Package

```bash
composer require joshcirre/duo
```

### NPM Package (Vite Plugin)

```bash
npm install -D @joshcirre/vite-plugin-duo
```

> **Note:** Dexie is automatically installed as a dependency.

## Quick Start

### 1. Add the Syncable Trait to Your Models

Add the `Syncable` trait to any Eloquent model you want to cache in IndexedDB:

```php
use JoshCirre\Duo\Syncable;

class Todo extends Model
{
    use Syncable;

    protected $fillable = ['title', 'description', 'completed'];
}
```

**Both `$fillable` and `$guarded` are supported:**

```php
// Option 1: Using $fillable (explicit allow list)
protected $fillable = ['title', 'description', 'completed'];

// Option 2: Using $guarded (explicit deny list)
protected $guarded = ['id']; // Everything except 'id' is fillable
```

Duo automatically extracts your model's fillable attributes and database schema (column types, nullable, defaults) to generate the IndexedDB manifest—no manual configuration needed!

**User-Scoped Models:**

For models that belong to users, add a `user()` relationship but **do NOT add `user_id` to `$fillable`**:

```php
class Todo extends Model
{
    use Syncable;

    // ✅ CORRECT: user_id is NOT in $fillable (security)
    protected $fillable = ['title', 'description', 'completed'];

    // ✅ Add user relationship - Duo auto-assigns user_id during sync
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

**Why?** Including `user_id` in `$fillable` is a security risk—users could assign items to other users. Duo automatically detects the `user()` relationship and assigns the authenticated user's ID securely during sync.

### 2. Add @duoMeta Directive to Your Layout

**CRITICAL:** Add the `@duoMeta` directive to the `<head>` section of your main layout. This provides the CSRF token and enables offline page caching:

```blade
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    @duoMeta

    <title>{{ $title ?? config('app.name') }}</title>
    <!-- rest of your head content -->
</head>
```

The `@duoMeta` directive outputs:
- `<meta name="csrf-token">` - Required for API sync requests
- `<meta name="duo-cache">` - Tells the service worker to cache this page for offline access

### 3. Configure Vite

Add the Duo plugin to your `vite.config.js`:

```javascript
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { duo } from '@joshcirre/vite-plugin-duo';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        duo(), // That's it! Uses sensible defaults
    ],
});
```

**That's all you need!** The plugin will automatically:
- ✅ Generate the manifest at `resources/js/duo/manifest.json`
- ✅ Watch `app/Models/**/*.php` for changes
- ✅ Auto-regenerate manifest when models change
- ✅ Inject Duo initialization code into `resources/js/app.js`
- ✅ Copy the service worker to `public/duo-sw.js` on build

**Want to customize?** All options have sensible defaults and are optional:

```javascript
duo({
    // Manifest path (default: 'resources/js/duo/manifest.json')
    manifestPath: 'resources/js/duo/manifest.json',

    // Watch for file changes (default: true)
    watch: true,

    // Auto-generate manifest (default: true)
    autoGenerate: true,

    // Files to watch for changes (default: ['app/Models/**/*.php'])
    patterns: [
        'app/Models/**/*.php',
        'resources/views/livewire/**/*.php',  // Include Volt components
        'app/Livewire/**/*.php',              // Include class-based components
    ],

    // Entry file for auto-injection (default: 'resources/js/app.js')
    entry: 'resources/js/app.js',

    // Auto-inject initialization code (default: true)
    autoInject: true,

    // Custom artisan command (default: 'php artisan duo:generate')
    command: 'php artisan duo:generate',
})
```

### 4. Add the WithDuo Trait to Your Livewire Components

This is where the magic happens! Add the `WithDuo` trait to any Livewire component and Duo will automatically transform it to use IndexedDB:

**Volt Component Example:**

```php
<?php
use Livewire\Volt\Component;
use App\Models\Todo;
use JoshCirre\Duo\WithDuo;

new class extends Component {
    use WithDuo;  // ✨ This is all you need!

    public string $newTodoTitle = '';

    public function addTodo()
    {
        Todo::create(['title' => $this->newTodoTitle]);
        $this->reset('newTodoTitle');
    }

    public function toggleTodo($id)
    {
        $todo = Todo::findOrFail($id);
        $todo->update(['completed' => !$todo->completed]);
    }

    public function deleteTodo($id)
    {
        Todo::findOrFail($id)->delete();
    }

    public function with()
    {
        return ['todos' => Todo::latest()->get()];
    }
}; ?>

<div>
    <form wire:submit="addTodo">
        <input type="text" wire:model="newTodoTitle" placeholder="New todo...">
        <button type="submit">Add</button>
    </form>

    <div class="space-y-2">
        @forelse($todos as $todo)
            <div>
                <input
                    type="checkbox"
                    wire:click="toggleTodo({{ $todo->id }})"
                    {{ $todo->completed ? 'checked' : '' }}
                >
                <span>{{ $todo->title }}</span>
                <button wire:click="deleteTodo({{ $todo->id }})">Delete</button>
            </div>
        @empty
            <p>No todos yet</p>
        @endforelse
    </div>
</div>
```

**Class-Based Component Example:**

```php
<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Todo;
use JoshCirre\Duo\WithDuo;

class TodoList extends Component
{
    use WithDuo;  // ✨ Add this trait

    public string $newTodoTitle = '';

    public function addTodo()
    {
        Todo::create(['title' => $this->newTodoTitle]);
        $this->reset('newTodoTitle');
    }

    public function render()
    {
        return view('livewire.todo-list', [
            'todos' => Todo::latest()->get(),
        ]);
    }
}
```

**What Happens Automatically:**

When you add the `WithDuo` trait, Duo will:
1. ✅ Transform `wire:click` to Alpine.js `@click` handlers
2. ✅ Convert `@forelse` loops to Alpine `x-for` templates
3. ✅ Transform `{{ $todo->property }}` to `x-text` bindings
4. ✅ Convert conditional classes to `:class` bindings
5. ✅ Add `x-cloak` and loading state management
6. ✅ Route all data operations through IndexedDB
7. ✅ Queue changes for background sync to the server

### 5. Add Optional Components

**Sync Status Indicator:**

Display a visual indicator of the sync status:

```blade
<x-duo::sync-status position="top-right" />
```

This component shows:
- 🟠 **Offline**: "You're offline - Changes saved locally"
- 🔵 **Syncing**: "Syncing X changes..."
- 🟢 **Synced**: "All changes synced"

**Debug Panel (Local Development Only):**

Add a debug panel to view IndexedDB info and manage the database during development:

```blade
<x-duo::debug position="bottom-right" />
```

This component (only visible in `local` environment) provides:
- 📊 **Database Info**: Name, schema version, stores, and record counts
- 🔄 **Refresh**: Update database information on demand
- 🗑️ **Clear Database**: Delete IndexedDB and reload (useful for testing schema upgrades)

The debug panel automatically shows the current schema version (timestamp-based) and makes it easy to test schema migrations by clearing the database.

### 6. Run Your Application

**Development:**
```bash
composer run dev
```

This runs both `npm run dev` and `php artisan serve` concurrently. The Vite plugin will automatically:
- Run `php artisan duo:generate` to create the manifest
- Watch for model and component changes
- Regenerate the manifest when files change

**Production Build:**
```bash
npm run build
```

The production build will:
- Generate the IndexedDB manifest
- Copy the service worker to `public/duo-sw.js` automatically
- Bundle all Duo client code with your assets

**Important for Offline Support:**
1. Visit your application **while online** (at least once after deploying)
2. The service worker will detect pages with `@duoMeta` and cache them
3. After the initial visit, the page will work offline

The service worker route (`/duo-sw.js`) is automatically registered by the Duo service provider—no additional configuration needed!

## How It Works

Duo uses a sophisticated local-first architecture that transforms your Livewire components into offline-capable Alpine.js applications:

### Initial Load

1. **Component Transformation**: When a component with the `WithDuo` trait renders, Duo intercepts the HTML and transforms it:
   - Blade `@forelse` loops → Alpine `x-for` templates
   - `wire:click` handlers → Alpine `@click` with IndexedDB operations
   - `{{ $model->property }}` → `<span x-text="model.property"></span>`
   - Conditional classes → `:class` bindings
   - Adds loading states and `x-cloak` for smooth initialization

2. **Sync Server Data**: On page load, the Alpine component syncs server data to IndexedDB
3. **Ready State**: Component shows with `duoReady` flag set to true

### Data Operations

**Reads:**
- All data is read from IndexedDB (instant, no network delay)
- Alpine templates reactively update from the local cache

**Writes:**
- Changes write to IndexedDB immediately (optimistic update)
- UI updates instantly
- Operation queues for background server sync
- Sync happens automatically in the background

### Offline Support

**Going Offline:**
1. Browser's `navigator.onLine` API detects offline state
2. Sync queue automatically pauses
3. All operations continue to work locally
4. Sync status component shows orange "Offline" badge

**Coming Back Online:**
1. Browser detects connection restored
2. Sync queue automatically resumes
3. All queued operations sync to server
4. Network errors don't count against retry limit
5. Sync status shows progress, then green "Synced" when complete

### Background Sync

- Runs on configurable interval (default: 5 seconds)
- Processes queued operations in order
- Retries failed operations (default: 3 attempts)
- Updates local cache with server responses
- Handles concurrent operations safely

## Configuration

### Global Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=duo-config
```

Edit `config/duo.php`:

```php
return [
    'database_name' => env('DUO_DATABASE_NAME', 'duo_cache'),
    'sync_strategy' => env('DUO_SYNC_STRATEGY', 'write-behind'),
    'sync_interval' => env('DUO_SYNC_INTERVAL', 5000), // milliseconds
    'max_retry_attempts' => env('DUO_MAX_RETRY_ATTEMPTS', 3),
    'cache_ttl' => env('DUO_CACHE_TTL', null), // seconds, null = no expiration
    'debug' => env('DUO_DEBUG', false),
    'auto_discover' => env('DUO_AUTO_DISCOVER', true),
];
```

### Component-Level Configuration

You can customize Duo's behavior on a per-component basis using the type-safe `DuoConfig` class. This provides **full IDE autocomplete and type checking**!

**Volt Component:**
```php
<?php
use Livewire\Volt\Component;
use JoshCirre\Duo\{WithDuo, DuoConfig};

new class extends Component {
    use WithDuo;

    // Type-safe configuration with IDE autocomplete!
    protected function duoConfig(): DuoConfig
    {
        return DuoConfig::make(
            syncInterval: 3000,
            timestampRefreshInterval: 5000,
            debug: true
        );
    }

    // ... rest of your component
}
```

**Class-Based Component:**
```php
<?php

namespace App\Livewire;

use Livewire\Component;
use JoshCirre\Duo\{WithDuo, DuoConfig};

class TodoList extends Component
{
    use WithDuo;

    protected function duoConfig(): DuoConfig
    {
        return DuoConfig::make(
            timestampRefreshInterval: 30000,
            maxRetryAttempts: 5
        );
    }

    // ... rest of your component
}
```

**Available Configuration Options:**

All options can be set globally in `config/duo.php` and overridden per-component in `duoConfig()`:

| Option | Type | Default | Global Config | Component Override | Description |
|--------|------|---------|---------------|-------------------|-------------|
| `syncInterval` | int | 5000 | `duo.sync_interval` | `syncInterval` | Milliseconds between sync attempts to server. Controls how often pending changes are sent. |
| `timestampRefreshInterval` | int | 10000 | `duo.timestamp_refresh_interval` | `timestampRefreshInterval` | Milliseconds between timestamp updates. Controls how often relative times like "5 minutes ago" refresh. |
| `maxRetryAttempts` | int | 3 | `duo.max_retry_attempts` | `maxRetryAttempts` | Maximum number of retry attempts for failed sync operations before giving up. |
| `debug` | bool | false | `duo.debug` | `debug` | Enable verbose console logging. Useful for debugging transformation and sync issues. |

**Configuration Priority:**

```
Component duoConfig() > Global config/duo.php > Hardcoded defaults
```

**Example with all options:**
```php
protected function duoConfig(): DuoConfig
{
    return DuoConfig::make(
        syncInterval: 3000,              // Sync every 3 seconds
        timestampRefreshInterval: 5000,  // Refresh timestamps every 5 seconds
        maxRetryAttempts: 5,             // Retry failed syncs 5 times
        debug: true                      // Enable debug logging
    );
}
```

**Benefits of Type-Safe Config:**
- ✅ **IDE Autocomplete**: Your IDE shows all available options as you type
- ✅ **Type Checking**: PHP will catch typos and wrong types at runtime
- ✅ **Validation**: Invalid values (like negative numbers) throw clear exceptions
- ✅ **Documentation**: Hover over parameters in your IDE to see descriptions
- ✅ **Refactoring**: Rename config options safely across your entire codebase

**Why Component-Level Config?**

Component-level configuration allows you to:
- 🎯 **Fine-tune performance**: High-traffic pages can sync less frequently or refresh timestamps slower
- 🐛 **Debug specific components**: Enable debug mode only where you need it
- 📱 **Mobile optimization**: Adjust sync and refresh rates based on device capabilities
- 🎨 **User experience**: Customize behavior for different types of content
- ⚡ **Critical components**: Increase sync frequency for time-sensitive data

**Global vs Component Config:**
- **Global** (`config/duo.php`): Sets application-wide defaults, configured via environment variables
- **Component** (`duoConfig()`): Overrides for specific components, set in code

This architecture provides flexibility while maintaining sensible defaults across your entire application.

### Vite Plugin Options

The Duo Vite plugin has sensible defaults and requires no configuration. Simply add `duo()` to your Vite plugins.

**All options are optional:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `manifestPath` | string | `'resources/js/duo/manifest.json'` | Path where the manifest file is generated |
| `watch` | boolean | `true` | Watch files for changes during development |
| `autoGenerate` | boolean | `true` | Automatically run `duo:generate` on build and file changes |
| `patterns` | string[] | `['app/Models/**/*.php']` | Glob patterns to watch for changes. Add Volt/Livewire paths if you want manifest regeneration on component changes |
| `entry` | string | `'resources/js/app.js'` | Entry file where Duo initialization code is injected |
| `autoInject` | boolean | `true` | Automatically inject Duo initialization code into entry file |
| `command` | string | `'php artisan duo:generate'` | Custom artisan command to run for manifest generation |
| `basePath` | string | `process.cwd()` | Base path for resolving file paths |

**Example with custom options:**

```javascript
duo({
    patterns: [
        'app/Models/**/*.php',           // Watch models
        'resources/views/livewire/**/*.php', // Watch Volt components
        'app/Livewire/**/*.php',         // Watch class-based components
    ],
    watch: true,    // Regenerate on file changes (dev only)
    autoGenerate: true,  // Auto-run duo:generate
})
```

**Disabling auto-injection (manual initialization):**

If you want full control over Duo initialization:

```javascript
// vite.config.js
duo({
    autoInject: false,
})
```

Then manually initialize in your JavaScript:

```javascript
// resources/js/app.js
import { initializeDuo } from '@joshcirre/vite-plugin-duo/client';
import manifest from 'virtual:duo-manifest';

await initializeDuo({
    manifest,
    debug: import.meta.env.DEV,
    syncInterval: 3000,
    maxRetries: 5,
});
```

## Advanced Usage

### Manual Database Operations

```javascript
import { getDuo } from '@joshcirre/duo/client';

const duo = getDuo();
const db = duo.getDatabase();

// Get a store
const postsStore = db.getStore('App_Models_Post');

// Query data
const allPosts = await postsStore.toArray();
const post = await postsStore.get(1);

// Add/update
await postsStore.put({
    id: 1,
    title: 'Hello World',
    content: 'This is a post',
});

// Delete
await postsStore.delete(1);
```

### Manual Sync Operations

```javascript
const duo = getDuo();
const syncQueue = duo.getSyncQueue();

// Check sync status
const status = syncQueue.getSyncStatus();
console.log('Online:', status.isOnline);
console.log('Pending:', status.pendingCount);
console.log('Processing:', status.isProcessing);

// Check if online
const isOnline = syncQueue.isNetworkOnline();

// Get pending operations
const pending = syncQueue.getPendingOperations();

// Force sync now
await syncQueue.processQueue();
```

### Listen for Sync Events

Duo dispatches a `duo-synced` event whenever a sync operation completes successfully. You can listen for this event to trigger custom behavior:

**Livewire Components:**

```php
use Livewire\Attributes\On;

#[On('duo-synced')]
public function handleSyncComplete()
{
    // Refresh data, show notification, etc.
    $this->dispatch('notify', message: 'Changes synced!');
}
```

**Alpine Components:**

```javascript
// Using Alpine's @event directive
<div @duo-synced.window="handleSync($event.detail)">
    <!-- Your component -->
</div>

// Or in Alpine x-data
x-data="{
    init() {
        window.addEventListener('duo-synced', (event) => {
            console.log('Sync completed:', event.detail.operation);
            // event.detail.operation contains: id, storeName, operation, data, timestamp
        });
    }
}"
```

**Vanilla JavaScript:**

```javascript
window.addEventListener('duo-synced', (event) => {
    const { operation } = event.detail;
    console.log('Synced:', operation.storeName, operation.operation);
});
```

This is particularly useful for:
- Refreshing server-side data displays after sync
- Showing toast notifications when changes are saved
- Tracking sync analytics
- Updating UI elements that show server state

### Access Sync Status in Custom Components

```javascript
// In Alpine component
x-data="{
    duoStatus: { isOnline: true, pendingCount: 0, isProcessing: false },
    init() {
        setInterval(() => {
            if (window.duo && window.duo.getSyncQueue()) {
                this.duoStatus = window.duo.getSyncQueue().getSyncStatus();
            }
        }, 1000);
    }
}"
```

### Clear Cache

```javascript
const duo = getDuo();
await duo.clearCache();
```

### Custom Sync Component

You can build your own sync indicator using the sync status API:

```blade
<div x-data="{
    status: { isOnline: true, pendingCount: 0 },
    init() {
        setInterval(() => {
            if (window.duo?.getSyncQueue()) {
                this.status = window.duo.getSyncQueue().getSyncStatus();
            }
        }, 1000);
    }
}">
    <span x-show="!status.isOnline" class="text-orange-600">
        Offline
    </span>
    <span x-show="status.isOnline && status.pendingCount > 0" class="text-blue-600">
        Syncing <span x-text="status.pendingCount"></span> changes
    </span>
    <span x-show="status.isOnline && status.pendingCount === 0" class="text-green-600">
        Synced
    </span>
</div>
```

## Artisan Commands

### Discover Models

```bash
php artisan duo:discover
```

Lists all Eloquent models using the `Syncable` trait. Useful for verifying which models will be included in the manifest.

### Generate Manifest

```bash
php artisan duo:generate
```

Generates the `manifest.json` file with IndexedDB schema from your models. The Vite plugin runs this automatically, but you can run it manually:

```bash
# Generate with custom path
php artisan duo:generate --path=resources/js/duo

# Force regeneration
php artisan duo:generate --force
```

**Note:** The Vite plugin with `watch: true` automatically regenerates the manifest when model files change, so you rarely need to run this manually.

## Troubleshooting

### Component Not Transforming

If your Livewire component isn't being transformed to Alpine:

1. **Check the trait is present:**
   ```php
   use JoshCirre\Duo\WithDuo;

   class MyComponent extends Component {
       use WithDuo;  // Make sure this is here
   }
   ```

2. **Clear caches:**
   ```bash
   php artisan optimize:clear
   composer dump-autoload
   ```

3. **Check Laravel logs:**
   ```bash
   tail -f storage/logs/laravel.log | grep Duo
   ```

### "window.duo not available"

If you see this error in the console:

1. **Check Duo is initialized:**
   - Duo initializes automatically via the Vite plugin
   - Check that the Duo plugin is added to your `vite.config.js`

2. **Regenerate the manifest:**
   ```bash
   php artisan duo:generate
   npm run build
   ```

3. **Check Vite is running:**
   ```bash
   npm run dev
   ```

### Data Not Syncing

If changes aren't syncing to the server:

1. **Check the browser console** for sync errors
2. **Check sync queue status:**
   ```javascript
   console.log(window.duo.getSyncQueue().getSyncStatus());
   ```
3. **Verify routes are registered** - Duo registers routes at `/duo/sync`
4. **Check network tab** in DevTools for failed requests

### Changes Not Persisting

If changes disappear after refresh:

1. **Check IndexedDB** in Browser DevTools → Application → IndexedDB
2. **Verify the model has `Syncable` trait**
3. **Check server logs** for save errors
4. **Clear IndexedDB and resync:**
   ```javascript
   await window.duo.clearCache();
   location.reload();
   ```

### Clearing IndexedDB

Need to clear IndexedDB during development? You have several options:

**Option 1: Debug Panel (Easiest)**
```blade
<x-duo::debug position="bottom-right" />
```
Click "Delete Database & Reload" to clear IndexedDB and reload the page.

**Option 2: Browser Console**
```javascript
// Delete Duo database
await window.duo?.getDatabase()?.delete();
location.reload();

// Or delete ALL databases (nuclear option)
const dbs = await indexedDB.databases();
for (const db of dbs) {
    indexedDB.deleteDatabase(db.name);
}
location.reload();
```

**Option 3: Browser DevTools**
- Chrome/Edge: DevTools → Application → IndexedDB → Right-click database → Delete
- Firefox: DevTools → Storage → IndexedDB → Right-click database → Delete
- Safari: Web Inspector → Storage → IndexedDB → Select database → Click trash icon

**When to clear IndexedDB:**
- Testing schema version upgrades
- Debugging data sync issues
- After changing model fillable attributes
- When data appears corrupted

## FAQ

### Do I need to change my Livewire components?

**No!** Just add the `WithDuo` trait. Your existing Blade templates and Livewire methods work as-is. Duo automatically transforms them to use IndexedDB and Alpine.js.

### Will this work with Volt components?

**Yes!** Duo works seamlessly with both class-based Livewire components and Volt single-file components.

### What happens if JavaScript is disabled?

Components without the `WithDuo` trait will continue to work as normal server-side Livewire components. Components with the trait require JavaScript for the IndexedDB functionality.

### Can I use this with existing Alpine.js code?

**Yes!** Duo generates Alpine.js-compatible code, so you can mix Duo-transformed components with regular Alpine components.

### Does this replace Livewire?

**No.** Duo enhances Livewire by adding local-first capabilities. The server is still the source of truth. Duo just caches data locally and provides offline support.

### Can I use Flux components?

**Partially.** Flux components work great for forms, buttons, and static UI elements. However, Flux components inside `@forelse` loops won't transform correctly since they're server-side components. Use plain HTML with Alpine bindings for loop items.

### How do I handle conflicts?

Duo uses a "server wins" strategy. When sync operations complete, the server response updates the local cache. This ensures the server remains the source of truth.

### Can I customize the transformation?

Currently, the transformation is automatic. Custom transformation logic is planned for a future release.

## Requirements

- PHP ^8.2
- Laravel ^11.0 or ^12.0
- Livewire ^3.0
- Alpine.js 3.x (included with Livewire)
- Modern browser with IndexedDB support

## Browser Support

Duo works in all modern browsers that support IndexedDB:

- Chrome/Edge 24+
- Firefox 16+
- Safari 10+
- iOS Safari 10+

## License

MIT License. See [LICENSE.md](LICENSE.md) for details.

## Credits

Created by [Josh Cirre](https://github.com/joshcirre)

Built with:
- [Dexie.js](https://dexie.org/) - Minimalistic IndexedDB wrapper
- [Laravel](https://laravel.com/) - PHP framework
- [Livewire](https://livewire.laravel.com/) - Full-stack framework
- [Alpine.js](https://alpinejs.dev/) - Lightweight JavaScript framework
- [Livewire Flux](https://flux.laravel.com/) - UI components (optional)

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Roadmap

See [ROADMAP.md](ROADMAP.md) for planned features including:
- Full page caching for complete offline mode
- Seamless conflict resolution with visual components
- Multiplayer mode ("Duet") with real-time sync
- Permission-based conflict resolution
- Architecture optimizations and Livewire v4 compatibility

## Support

- [GitHub Issues](https://github.com/joshcirre/duo/issues)
- [Documentation](https://github.com/joshcirre/duo/wiki)
- [Discussions](https://github.com/joshcirre/duo/discussions)
