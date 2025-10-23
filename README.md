# Duo

**Local-first IndexedDB syncing for Laravel and Livewire applications.**

Duo enables automatic client-side caching and synchronization of your Eloquent models using IndexedDB, providing a seamless offline-first experience for your Laravel/Livewire applications.

## Features

- **Automatic IndexedDB Caching**: Transparently cache Eloquent models in the browser
- **Write-Behind Sync**: Optimistic updates with background server synchronization
- **Livewire Integration**: Seamless integration with Livewire 3+ components
- **Type-Safe**: Full TypeScript support with generated types
- **Zero Configuration**: Works out of the box with sensible defaults
- **Offline Support**: Continue working offline, sync when reconnected

## Installation

### Composer Package

```bash
composer require joshcirre/duo
```

### NPM Package (Vite Plugin)

```bash
npm install -D @joshcirre/vite-plugin-duo
npm install dexie
```

## Quick Start

### 1. Add the Trait to Your Models

```php
use JoshCirre\Duo\Concerns\Syncable;

class Post extends Model
{
    use Syncable;

    protected $fillable = ['title', 'content'];
}
```

Or use the attribute:

```php
use JoshCirre\Duo\Attributes\UseDuo;

#[UseDuo]
class Post extends Model
{
    protected $fillable = ['title', 'content'];
}
```

### 2. Generate the Manifest

```bash
php artisan duo:generate
```

This creates a manifest file at `resources/js/duo/manifest.json` with your model schema.

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
        duo({
            manifestPath: 'resources/js/duo/manifest.json',
            watch: true,
        }),
    ],
});
```

### 4. Initialize in Your JavaScript

```javascript
import { initializeDuo } from '@joshcirre/duo/client';

// Initialize Duo when your app loads
initializeDuo({
    debug: import.meta.env.DEV,
    syncInterval: 5000,
    maxRetries: 3,
});
```

## How It Works

1. **Read-First Strategy**: When your Livewire components request data, Duo checks IndexedDB first
2. **Fallback to Server**: If data isn't cached, Duo fetches from the server and caches it
3. **Write-Behind Sync**: Changes are written to IndexedDB immediately, then queued for server sync
4. **Background Sync**: Pending changes sync to the server in the background
5. **Conflict Resolution**: Server responses update the local cache

## Configuration

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

### Manual Sync

```javascript
const duo = getDuo();
const syncQueue = duo.getSyncQueue();

// Check queue size
console.log('Pending syncs:', syncQueue.getQueueSize());

// Get pending operations
const pending = syncQueue.getPendingOperations();
```

### Clear Cache

```javascript
const duo = getDuo();
await duo.clearCache();
```

## Artisan Commands

### Discover Models

```bash
php artisan duo:discover
```

Lists all models using the Duo trait.

### Generate Manifest

```bash
php artisan duo:generate --path=resources/js/duo
```

Generates the IndexedDB schema manifest from your models.

## Requirements

- PHP ^8.2
- Laravel ^11.0 or ^12.0
- Livewire ^3.0
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
- [Laravel](https://laravel.com/)
- [Livewire](https://livewire.laravel.com/)

## Support

- [GitHub Issues](https://github.com/joshcirre/duo/issues)
- [Documentation](https://github.com/joshcirre/duo/wiki)
