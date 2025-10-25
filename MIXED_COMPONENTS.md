# Using Duo with Mixed Components

## The Beautiful Thing About Duo

You can have **both** Duo-enabled (local-first) and normal (server-first) Livewire components on the same page!

## How It Works

### Component WITH `use Duo;`
```php
class TodoList extends Component
{
    use Duo;  // ← Duo-enabled = Local-first

    public function addTodo() { /* ... */ }
}
```

**What happens:**
- ✅ `wire:click` → Transformed to `data-duo-action`
- ✅ Actions handled in IndexedDB
- ✅ Zero network requests
- ✅ Works offline
- ✅ Component gets `data-duo-enabled="true"` attribute

### Component WITHOUT `use Duo;`
```php
class UserSettings extends Component
{
    // No Duo trait = Normal Livewire

    public function updateSettings() { /* ... */ }
}
```

**What happens:**
- ✅ `wire:click` → Stays as `wire:click`
- ✅ Actions go to server normally
- ✅ Network requests happen
- ✅ Normal Livewire behavior
- ✅ No `data-duo-enabled` attribute

## Example: Mixed Page

```blade
<div>
    {{-- Local-first todo list (offline-capable) --}}
    <livewire:todo-list />  {{-- Has: use Duo; --}}

    {{-- Server-first user settings (needs server validation) --}}
    <livewire:user-settings />  {{-- No Duo trait --}}

    {{-- Local-first shopping cart (offline-capable) --}}
    <livewire:shopping-cart />  {{-- Has: use Duo; --}}
</div>
```

### Rendered HTML:

**TodoList (with Duo):**
```html
<div data-duo-enabled="true" wire:id="...">
    <button data-duo-action="addTodo">Add Todo</button>
    <!-- No wire:click! Handled locally! -->
</div>
```

**UserSettings (without Duo):**
```html
<div wire:id="...">
    <button wire:click="updateSettings">Save Settings</button>
    <!-- Still wire:click! Goes to server normally! -->
</div>
```

## When to Use Duo

### ✅ Perfect for Duo (Local-first):
- Todo lists
- Shopping carts
- Draft editors
- Note taking
- Offline forms
- Data tables with local filtering/sorting
- Anything that benefits from instant UI updates
- Anything that should work offline

### ❌ Keep Server-first (No Duo):
- Payment processing
- Authentication/login
- Admin actions (delete user, etc.)
- Anything requiring server-side validation
- Anything with complex business logic
- Third-party API integrations

## How Livewire Hooks Work

Livewire calls lifecycle hooks based on trait method names:

```php
trait Duo
{
    // Only called if component has Duo trait ✅
    public function renderedDuo($html) {
        // Transform wire:click → data-duo-action
        return $html;
    }

    // Only called if component has Duo trait ✅
    public function dehydrateDuo($context) {
        // Add Duo metadata
    }
}
```

**If a component doesn't have the `Duo` trait:**
- ❌ `renderedDuo()` never called
- ❌ No HTML transformation
- ❌ `wire:click` stays as-is
- ✅ Normal Livewire behavior

## JavaScript Detection

The JavaScript also respects this:

```typescript
document.addEventListener('click', (e) => {
    const duoElement = e.target.closest('[data-duo-action]');

    if (!duoElement) {
        // Not a Duo component - let Livewire handle it normally ✅
        return;
    }

    // Duo component - handle locally ✅
    handleInIndexedDB(action);
});
```

## Performance Benefits

### Duo Components (Local-first):
- ⚡ Instant UI updates
- 📴 Works offline
- 🚫 Zero network latency
- 💾 Cached in IndexedDB
- 📱 Native app feel

### Normal Components (Server-first):
- 🔒 Server-side validation
- 💳 Payment processing
- 🔐 Authentication
- 📡 Real-time server data
- 🔄 Traditional Livewire

## Best Practices

### 1. **Separate Concerns**
```php
// Data display/editing = Duo ✅
class TodoList extends Component {
    use Duo;
}

// Critical actions = Normal Livewire ✅
class DeleteAccount extends Component {
    // No Duo trait
}
```

### 2. **Progressive Enhancement**
Start with normal Livewire, add Duo where it makes sense:

```php
// Start here (server-first)
class Posts extends Component { }

// Add Duo when ready (local-first)
class Posts extends Component {
    use Duo;  // ← One line change!
}
```

### 3. **Test Both**
- ✅ Test Duo components offline
- ✅ Test normal components online
- ✅ Mix both on same page

## The Magic

**One trait. One line of code. Completely opt-in.**

```diff
  class TodoList extends Component
  {
+     use Duo;  // ← This is all you need!
  }
```

Everything else just works! 🎉

## Summary

- ✅ Duo trait = Local-first (offline-capable)
- ✅ No trait = Server-first (normal Livewire)
- ✅ Both can coexist on same page
- ✅ HTML transformation only happens with Duo trait
- ✅ JavaScript only intercepts Duo components
- ✅ Zero impact on non-Duo components
- ✅ Completely opt-in per component

This is **true progressive enhancement**! 🚀
