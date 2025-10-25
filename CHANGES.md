# Recent Improvements to Duo

## Automatic Manifest Generation

The Vite plugin now automatically runs `php artisan duo:generate` - users no longer need to run it manually!

### What Changed:

1. **Auto-generation on Build**
   - When you run `npm run build` or `npm run dev`, the manifest is automatically generated
   - If the manifest doesn't exist, it's created automatically
   - No manual `php artisan duo:generate` command needed!

2. **Hot Module Replacement (HMR)**
   - When you modify model files during development, the manifest is regenerated automatically
   - Default watch pattern: `app/Models/**/*.php`
   - Customizable via the `patterns` option

3. **Zero Manual Steps**
   - Just add the `Syncable` trait to your models
   - Add the Duo plugin to Vite
   - Run `npm run dev` - everything else is automatic!

### New Plugin Options:

```js
duo({
    manifestPath: 'resources/js/duo/manifest.json',  // Where to output manifest
    watch: true,                                      // Watch for model changes (dev mode)
    patterns: ['app/Models/**/*.php'],               // Which files to watch
    autoGenerate: true,                              // Auto-run duo:generate (default: true)
    command: 'php artisan duo:generate',             // Custom command if needed
})
```

### Example Output:

```
vite v7.0.6 building for production...
[plugin vite-plugin-duo] [Duo] Manifest not found, regenerating manifest...
[plugin vite-plugin-duo] [Duo] Manifest generated with 1 model(s)
transforming...
✓ 9 modules transformed.
```

## Simplified Dependencies

### Dexie is Included Automatically

Users no longer need to manually install Dexie:

**Before:**
```bash
npm install -D @joshcirre/vite-plugin-duo
npm install dexie  # ❌ No longer needed!
```

**After:**
```bash
npm install -D @joshcirre/vite-plugin-duo  # ✅ Dexie included automatically
```

Dexie is now a dependency of the vite plugin, so it's installed automatically.

## Updated Installation Steps

The complete installation is now even simpler:

### 1. Install Packages

```bash
composer require joshcirre/duo
npm install -D @joshcirre/vite-plugin-duo
```

### 2. Add Trait to Models

```php
use JoshCirre\Duo\Concerns\Syncable;

class Todo extends Model
{
    use Syncable;
}
```

### 3. Configure Vite

```js
import { duo } from '@joshcirre/vite-plugin-duo';

export default defineConfig({
    plugins: [
        laravel({ /* ... */ }),
        duo(),  // That's it!
    ],
});
```

### 4. Initialize in JavaScript

```js
import { initializeDuo } from '@joshcirre/vite-plugin-duo/client';
import manifest from 'virtual:duo-manifest';

initializeDuo({
    manifest,
    debug: import.meta.env.DEV,
});
```

### 5. Run Build

```bash
npm run dev
```

Done! The manifest is automatically generated and models are synced to IndexedDB.

## Benefits

1. **Fewer Manual Steps**: No need to run `php artisan duo:generate` manually
2. **Better DX**: Changes to models automatically regenerate the manifest during development
3. **Simpler Installation**: One less package to install (Dexie)
4. **More Reliable**: Can't forget to regenerate the manifest after model changes
5. **Configurable**: Power users can still customize the behavior if needed

## Inspired By

This approach is inspired by the [Laravel Wayfinder](https://github.com/spatie/laravel-wayfinder) plugin, which also auto-generates TypeScript types during the build process.
