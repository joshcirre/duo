# Duo Examples

This document provides practical examples of using Duo in your Laravel/Livewire applications.

## Basic Setup

### 1. Model Configuration

**Simple Model with Duo**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use JoshCirre\Duo\Concerns\Syncable;

class Post extends Model
{
    use Syncable;

    protected $fillable = ['title', 'content', 'published_at'];

    protected $casts = [
        'published_at' => 'datetime',
    ];
}
```

**Using the Attribute**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use JoshCirre\Duo\Attributes\UseDuo;

#[UseDuo(syncStrategy: 'write-behind', cacheTtl: 3600)]
class Comment extends Model
{
    protected $fillable = ['post_id', 'content', 'author_name'];
}
```

### 2. Livewire Component

**BlogPost Component**

```php
<?php

namespace App\Livewire;

use App\Models\Post;
use Livewire\Component;

class BlogPost extends Component
{
    public Post $post;

    public function mount($postId)
    {
        // Duo will automatically cache this in IndexedDB
        $this->post = Post::findOrFail($postId);
    }

    public function updatePost($title, $content)
    {
        // Changes are written to IndexedDB immediately
        // Then synced to server in the background
        $this->post->update([
            'title' => $title,
            'content' => $content,
        ]);

        $this->dispatch('post-updated');
    }

    public function render()
    {
        return view('livewire.blog-post');
    }
}
```

### 3. JavaScript Initialization

**resources/js/app.js**

```javascript
import './bootstrap';
import { initializeDuo } from '@joshcirre/duo/client';

// Initialize Duo when the app loads
document.addEventListener('DOMContentLoaded', async () => {
    await initializeDuo({
        debug: import.meta.env.DEV,
        syncInterval: 5000,
        maxRetries: 3,
        livewire: {
            interceptWireModel: true,
            autoSync: true,
        },
    });

    console.log('Duo initialized and ready!');
});
```

**vite.config.js**

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

## Advanced Examples

### Custom Sync Logic

**Model with Custom Sync Strategy**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use JoshCirre\Duo\Concerns\Syncable;

class Task extends Model
{
    use Syncable;

    protected $fillable = ['title', 'completed', 'priority'];

    /**
     * Determine if this model should be cached in IndexedDB
     */
    public function shouldSyncToDuo(): bool
    {
        // Only cache high-priority tasks
        return $this->priority === 'high';
    }

    /**
     * Customize the data sent to IndexedDB
     */
    public function toDuoArray(): array
    {
        $array = parent::toDuoArray();

        // Add custom metadata
        $array['_cached_at_browser'] = now()->toIso8601String();
        $array['_is_critical'] = $this->priority === 'high';

        return $array;
    }
}
```

### Manual Database Access

**Direct IndexedDB Operations**

```javascript
import { getDuo } from '@joshcirre/duo/client';

// Get the Duo instance
const duo = getDuo();
const db = duo.getDatabase();

// Access a specific store
const postsStore = db.getStore('App_Models_Post');

// Query posts
async function getPosts() {
    const allPosts = await postsStore.toArray();
    console.log('Cached posts:', allPosts);

    return allPosts;
}

// Get a specific post
async function getPost(id) {
    const post = await postsStore.get(id);
    return post;
}

// Search posts
async function searchPosts(query) {
    const posts = await postsStore
        .filter(post => post.title.includes(query))
        .toArray();

    return posts;
}

// Add/update a post
async function savePost(post) {
    await postsStore.put({
        ...post,
        _duo_pending_sync: true,
        _duo_operation: post.id ? 'update' : 'create',
    });

    console.log('Post saved to IndexedDB');
}

// Delete a post
async function deletePost(id) {
    await postsStore.delete(id);
    console.log('Post deleted from IndexedDB');
}
```

### Sync Queue Management

**Monitoring and Managing Sync**

```javascript
import { getDuo } from '@joshcirre/duo/client';

const duo = getDuo();
const syncQueue = duo.getSyncQueue();

// Check pending sync operations
function checkSyncStatus() {
    const queueSize = syncQueue.getQueueSize();
    console.log(`Pending syncs: ${queueSize}`);

    const pending = syncQueue.getPendingOperations();
    pending.forEach(op => {
        console.log(`- ${op.operation} on ${op.storeName} (retries: ${op.retryCount})`);
    });
}

// Custom sync success handler
const duoConfig = {
    onSyncSuccess: (operation) => {
        console.log('✅ Synced:', operation.storeName, operation.operation);
        showNotification('Changes saved to server');
    },
    onSyncError: (operation, error) => {
        console.error('❌ Sync failed:', error);
        showNotification('Failed to sync. Will retry...', 'error');
    },
};
```

### Offline Detection

**Handle Online/Offline State**

```javascript
import { getDuo } from '@joshcirre/duo/client';

let isOnline = navigator.onLine;

window.addEventListener('online', () => {
    isOnline = true;
    console.log('Back online! Syncing pending changes...');

    const duo = getDuo();
    duo.sync();

    showNotification('Back online. Syncing changes...');
});

window.addEventListener('offline', () => {
    isOnline = false;
    console.log('Offline mode enabled');

    showNotification('You are offline. Changes will sync when reconnected.');
});

// Show sync status in UI
function updateSyncStatusUI() {
    const duo = getDuo();
    const syncQueue = duo.getSyncQueue();
    const pendingCount = syncQueue.getQueueSize();

    const statusElement = document.getElementById('sync-status');

    if (!isOnline) {
        statusElement.textContent = `Offline (${pendingCount} pending)`;
        statusElement.className = 'status-offline';
    } else if (pendingCount > 0) {
        statusElement.textContent = `Syncing... (${pendingCount} remaining)`;
        statusElement.className = 'status-syncing';
    } else {
        statusElement.textContent = 'All changes saved';
        statusElement.className = 'status-synced';
    }
}

// Update status every second
setInterval(updateSyncStatusUI, 1000);
```

### Cache Management

**Clearing and Managing Cache**

```javascript
import { getDuo } from '@joshcirre/duo/client';

const duo = getDuo();
const db = duo.getDatabase();

// Clear specific store
async function clearPostsCache() {
    await db.clearStore('App_Models_Post');
    console.log('Posts cache cleared');
}

// Clear all caches
async function clearAllCache() {
    await duo.clearCache();
    console.log('All caches cleared');
}

// Get cache statistics
async function getCacheStats() {
    const stats = await db.getStats();

    console.log('Cache Statistics:');
    Object.entries(stats).forEach(([store, count]) => {
        console.log(`  ${store}: ${count} record(s)`);
    });

    return stats;
}

// Display cache stats in UI
async function displayCacheStats() {
    const stats = await getCacheStats();
    const total = Object.values(stats).reduce((sum, count) => sum + count, 0);

    document.getElementById('cache-info').innerHTML = `
        <p>Total cached records: ${total}</p>
        <ul>
            ${Object.entries(stats)
                .map(([store, count]) => `<li>${store}: ${count}</li>`)
                .join('')}
        </ul>
    `;
}
```

### API Integration

**Custom Endpoints**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index()
    {
        return Post::all();
    }

    public function show(Post $post)
    {
        return $post;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $post = Post::create($validated);

        return response()->json($post, 201);
    }

    public function update(Request $request, Post $post)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
        ]);

        $post->update($validated);

        return response()->json($post);
    }

    public function destroy(Post $post)
    {
        $post->delete();

        return response()->json(null, 204);
    }
}
```

**routes/api.php**

```php
<?php

use App\Http\Controllers\Api\PostController;
use Illuminate\Support\Facades\Route;

Route::apiResource('posts', PostController::class);
```

## Testing

**Testing with Duo**

```php
<?php

use App\Models\Post;
use JoshCirre\Duo\ModelRegistry;

test('post is registered with duo', function () {
    $registry = app(ModelRegistry::class);

    expect($registry->has(Post::class))->toBeTrue();
});

test('post generates correct duo manifest', function () {
    $registry = app(ModelRegistry::class);
    $manifest = $registry->toManifest();

    expect($manifest)->toHaveKey('App_Models_Post')
        ->and($manifest['App_Models_Post']['table'])->toBe('posts');
});
```

## Troubleshooting

### Debug Mode

Enable debug mode to see detailed logs:

```javascript
initializeDuo({
    debug: true,
});
```

### Clearing Cache During Development

```javascript
// Add a button in your dev environment
document.getElementById('clear-cache-btn')?.addEventListener('click', async () => {
    const duo = getDuo();
    await duo.clearCache();
    window.location.reload();
});
```

### Inspecting IndexedDB

Use browser DevTools:
1. Open DevTools (F12)
2. Navigate to "Application" tab
3. Find "IndexedDB" in the sidebar
4. Explore the `duo_cache` database

## Best Practices

1. **Only cache what you need**: Use `shouldSyncToDuo()` to filter models
2. **Monitor queue size**: Alert users if sync queue gets too large
3. **Handle offline gracefully**: Show clear indicators when offline
4. **Test sync failures**: Simulate network errors during development
5. **Clear cache on logout**: Prevent data leakage between users
6. **Version your manifest**: Regenerate after model changes

## Next Steps

- Read the [full documentation](README.md)
- Check out [contributing guidelines](CONTRIBUTING.md)
- Report issues on [GitHub](https://github.com/joshcirre/duo/issues)
