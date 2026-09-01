# Release Notes - v0.2.0

**Release Tag:** `v0.2.0`

**Release Title:**
```
Duo v0.2.0 - Livewire 4, real offline, no REST layer
```

---

## GitHub Release Notes

```markdown
# Duo v0.2.0 — Livewire 4, real offline, no REST layer

Duo is local-first Livewire: your component reads from IndexedDB, applies changes optimistically, and keeps working offline — while your own Livewire methods remain the only thing that writes to your database.

This release is a rewrite of the sync architecture and drops Livewire 3. **Upgrade both packages together:**

- Composer: `joshcirre/duo` `^0.2`
- NPM: `@joshcirre/vite-plugin-duo` `^0.2`

## What changed

- **Livewire 4 only.** Class-based and single-file components. Laravel 12.
- **Writes go through your Livewire methods.** No more `/api/duo/{table}` endpoints. Duo writes an optimistic record, then calls your real `$wire` method; the server reports the Eloquent operations that ran and the client reconciles. Validation, policies, and events work exactly as in plain Livewire.
- **Optimistic changes roll back** when the server did not perform them — a failed validation removes the temp row while Livewire renders the error.
- **Offline actions are queued** (localStorage) and replayed through `$wire` when you're back online, including after a reload.
- **Offline page loads work:** with `@duoMeta` in your layout, the service worker caches visited pages and the component boots from IndexedDB with no network.
- The Alpine `duo` scope is added server-side, so there is no client-side DOM rewriting and no "duo is not defined" race.
- Method analysis detects toggles, `$this->prop → column` mappings, and literal defaults for accurate optimistic rendering.

## Verified in a real browser

Against the workbench demo in headless Chromium: optimistic create/toggle/delete, validation rollback with Livewire-rendered errors, offline queue + replay, recovery from a killed server, replay of a previous session's queue on load, and a full page load with the server down.

## Upgrading from 0.1.x

1. `composer require joshcirre/duo:^0.2` and `npm install -D @joshcirre/vite-plugin-duo@^0.2`
2. Requires `livewire/livewire ^4.0`.
3. Add `data-duo-*` markers to the lists you want rendered from IndexedDB (see the README). Components with `WithDuo` alone still get page caching and offline action replay.
4. Remove any code that called `/api/duo/*` or `window.duo.getSyncQueue()` (still present as an alias for the action queue).
5. Clear browsers' IndexedDB once (`<x-duo::debug />` → Delete Database) if you were running 0.1.x locally.

See the [CHANGELOG](https://github.com/joshcirre/duo/blob/main/CHANGELOG.md) for the full list, and the [README](https://github.com/joshcirre/duo/blob/main/README.md) for the marker reference and known limitations.

**Note:** 0.x — the marker vocabulary and JavaScript API may still change.
```
