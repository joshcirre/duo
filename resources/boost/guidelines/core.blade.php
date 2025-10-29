## Duo - Local-First Offline Syncing

Duo provides local-first IndexedDB syncing for Laravel and Livewire applications. It enables offline functionality with automatic background sync to the server.

### Core Concepts

- **Models**: Add the `Syncable` trait to enable offline sync
- **Components**: Use `WithDuo` trait on Livewire components for reactive offline data
- **Sync**: Optimistic updates to IndexedDB with background server sync
- **Auth**: Uses session-based authentication (web middleware) for offline persistence

### Required Setup

1. **Add @duoMeta directive** - CRITICAL: Must be in the `<head>` section of your layout. This provides the CSRF token and cache metadata.

@verbatim
<code-snippet name="Add @duoMeta to layout head" lang="blade">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
@duoMeta

    <title>{{ $title ?? config('app.name') }}</title>
    <!-- other head content -->
</head>
</code-snippet>
@endverbatim

2. **Configure Vite** - Add Duo plugin to `vite.config.js`:

@verbatim
<code-snippet name="Vite configuration" lang="js">
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import duo from '@joshcirre/vite-plugin-duo';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        duo(),
    ],
});
</code-snippet>
@endverbatim

3. **Add user_id column** - For user-scoped models, add migration:

@verbatim
<code-snippet name="Migration for user-scoped model" lang="php">
Schema::create('todos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('title');
    $table->boolean('completed')->default(false);
    $table->timestamps();
});
</code-snippet>
@endverbatim

### Model Configuration

Add the `Syncable` trait to models you want to sync offline. Duo automatically detects `$fillable` or `$guarded` properties and extracts the database schema.

@verbatim
<code-snippet name="Basic syncable model" lang="php">
use JoshCirre\Duo\Syncable;

class Todo extends Model
{
    use Syncable;

    protected $fillable = ['title', 'completed', 'description'];

    protected $casts = [
        'completed' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
</code-snippet>
@endverbatim

**IMPORTANT**: For models with a `user()` relationship:
- Do NOT add `user_id` to `$fillable` (security risk - users could assign items to others)
- Duo automatically assigns `user_id` from the authenticated user during sync
- The model must have a `user()` relationship method for auto-assignment to work

@verbatim
<code-snippet name="User-scoped model (note: user_id NOT in fillable)" lang="php">
class Todo extends Model
{
    use Syncable;

    // CORRECT: user_id is NOT in $fillable for security
    protected $fillable = ['title', 'completed', 'description'];

    // This relationship tells Duo to auto-assign user_id
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
</code-snippet>
@endverbatim

### Livewire Components

Use the `WithDuo` trait on Livewire components to enable reactive offline data. Duo transforms Livewire wire: directives to Alpine.js for offline functionality.

@verbatim
<code-snippet name="Livewire component with Duo" lang="php">
use JoshCirre\Duo\WithDuo;

class Todos extends Component
{
    use WithDuo;

    #[Computed]
    public function todos()
    {
        return auth()->user()
            ->todos()
            ->latest()
            ->get();
    }

    public function toggleCompleted($id)
    {
        $todo = auth()->user()->todos()->findOrFail($id);
        $todo->update(['completed' => !$todo->completed]);
    }

    public function deleteTodo($id)
    {
        auth()->user()->todos()->findOrFail($id)->delete();
    }

    public function render()
    {
        return view('livewire.todos');
    }
}
</code-snippet>
@endverbatim

### View Patterns

Duo automatically transforms Blade loops to Alpine.js for reactive rendering:

@verbatim
<code-snippet name="Reactive todo list view" lang="blade">
<div>
    <div class="space-y-4">
        @forelse($this->todos as $todo)
            <div class="flex items-center gap-4">
                <input
                    type="checkbox"
                    wire:click="toggleCompleted({{ $todo->id }})"
                    {{ $todo->completed ? 'checked' : '' }}
                />
                <span>{{ $todo->title }}</span>
                <button wire:click="deleteTodo({{ $todo->id }})">Delete</button>
            </div>
        @empty
            <p>No todos yet</p>
        @endforelse
    </div>
</div>
</code-snippet>
@endverbatim

### Important Notes

- **Authentication**: Duo uses session-based auth (web middleware) so authentication persists across page refreshes, even offline
- **CSRF Token**: The `@duoMeta` directive must be present for sync requests to work
- **Sync Routes**: Automatically registered at `/api/duo/{table}` with web middleware
- **Schema Detection**: Duo automatically reads your database schema and maps types to JavaScript
- **Optimistic Updates**: Changes appear instantly in the UI, then sync in the background
- **Offline Support**: Service worker enables offline page access and queues operations until online

### Commands

@verbatim
<code-snippet name="Generate manifest after model changes" lang="bash">
php artisan duo:manifest
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Discover all models with Syncable trait" lang="bash">
php artisan duo:discover
</code-snippet>
@endverbatim
