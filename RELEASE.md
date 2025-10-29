# Release Notes - v0.1.0

**Release Tag:** `v0.1.0`

**Release Title:**
```
🎉 Duo v0.1.0 - First Public Release
```

---

## GitHub Release Notes (copy below)

```markdown
# Duo v0.1.0 - Local-First Syncing for Laravel & Livewire

First public release of Duo - a local-first IndexedDB syncing package for Laravel and Livewire applications.

## 📦 Package Information

This release includes:
- **Composer Package:** `joshcirre/duo` - Laravel service provider and traits
- **NPM Package:** `@joshcirre/vite-plugin-duo` - Vite plugin and client library

## ✨ Features

### Core Functionality
- 🔄 **Automatic IndexedDB Syncing** - Transparent client-side caching of Eloquent models
- ⚡ **Optimistic Updates** - Instant UI updates with background server synchronization
- 📡 **Offline Support** - Automatic offline detection with sync queue that resumes when back online
- 🎯 **Livewire Integration** - Seamless integration with Livewire 3+ and Volt components
- 🚀 **Zero Configuration** - Add one trait and Duo handles the rest

### Full Page Caching
- 💾 **Offline Page Caching** - Complete page caching with service workers
- 🔧 **Dynamic Service Worker** - No publishing needed, served automatically from package
- 🔒 **CSRF Protection** - `@duoMeta` directive includes CSRF token automatically
- 🖼️ **Asset Caching** - Caches HTML, CSS, JS, images, and fonts for true offline mode

### Developer Experience
- 📊 **Visual Sync Status** - Built-in component showing online/offline/syncing states
- 📦 **Type-Safe** - Full TypeScript support with auto-generated types
- 🔌 **Vite Plugin** - Automatic manifest generation with file watching
- ⚡ **Auto-Injection** - No manual initialization needed in your app.js
- 🧪 **Workbench Demo** - Fully functional todo list demo included

## 📚 Installation

### Composer
```bash
composer require joshcirre/duo
```

### NPM
```bash
npm install -D @joshcirre/vite-plugin-duo
```

## 🚀 Quick Start

1. Add the `Syncable` trait to your models:
```php
use JoshCirre\Duo\Syncable;

class Todo extends Model
{
    use Syncable;
}
```

2. Add the `WithDuo` trait to your Livewire/Volt components:
```php
use JoshCirre\Duo\WithDuo;

new class extends Component {
    use WithDuo;
    // ...
}
```

3. Add `@duoMeta` to your layout:
```blade
<head>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @duoMeta
</head>
```

4. Configure Vite:
```js
import duo from '@joshcirre/vite-plugin-duo';

export default defineConfig({
    plugins: [duo()],
});
```

That's it! Your app now has local-first syncing with offline support. The Vite plugin automatically injects the initialization code - no manual setup needed in your `app.js`!

## 📖 Documentation

- [README](https://github.com/joshcirre/duo/blob/main/README.md)
- [Roadmap](https://github.com/joshcirre/duo/blob/main/ROADMAP.md)

## 🙏 Acknowledgments

Built with:
- Laravel
- Livewire
- Dexie.js
- Vite

## 📄 License

MIT License - see [LICENSE](https://github.com/joshcirre/duo/blob/main/LICENSE) for details.

---

**Note:** This is a 0.x release, meaning the API may change before 1.0. Please report any issues on [GitHub](https://github.com/joshcirre/duo/issues).
```
