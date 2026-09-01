# Changelog

All notable changes to Duo will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-09-01

This release drops Livewire 3, removes the custom REST sync layer, and makes the whole loop work end to end — verified in a real browser, including offline page loads. Upgrade both packages together: `joshcirre/duo` and `@joshcirre/vite-plugin-duo` 0.2.0.

### Changed
- **Livewire 4 only.** Requires `livewire/livewire ^4.0` and Laravel 12. Works with class-based components and Livewire 4 single-file components. Livewire 3 support is removed.
- **Writes go through your Livewire methods.** The custom REST sync layer (`/api/duo/{table}` routes, `DuoSyncController`, client `SyncQueue`) is gone. Duo applies changes optimistically to IndexedDB, then calls the real `$wire` method; the server's `DuoOperationCollector` reports the Eloquent operations that ran and the client reconciles. Validation, authorization, and events behave exactly as in plain Livewire.
- **Offline actions are queued and replayed.** Actions that cannot reach the server are persisted (localStorage) and replayed through `$wire` when connectivity returns, including after a reload.
- **Optimistic changes roll back when the server does not perform them** — e.g. a failed validation removes the temp record while Livewire renders the error.
- The Alpine `duo` scope is now added server-side (`x-data="duo"` on the component root, wrapping any author-supplied `x-data`) and registered at module load, eliminating the client-side DOM compiler and the "duo is not defined" race.
- Method analysis now also detects boolean toggles (`'done' => ! $todo->done`), property → column mappings (`'title' => $this->newTitle`), and literal defaults, so optimistic creates and toggles render correctly before the response arrives.
- `@duoMeta` always emits the `duo-cache` meta tag (the layout head renders before any component).
- Duo's Blade precompiler and `DuoSynth` hook register during `booting()` so they run ahead of Livewire's own precompilers and hook registry.
- `config/duo.php` trimmed to the keys that are read: `debug`, `auto_discover`, `model_paths`, `sync_status.*`.
- README, ROADMAP, and the Boost AI guidelines rewritten to match the shipped architecture.

### Added
- Livewire 4 single-file component support (the workbench demo is now one).
- `duo-synced` and `duo:mutation` window events; `window.duo.getActionQueue()`.
- Startup sweep of orphaned pending records left by an interrupted session.
- Vitest unit tests for the action queue and operation expectations; Pest tests for single-file components and root decoration.

### Fixed
- `/duo-sw.js` is now served from the tracked source file. It previously pointed at `dist/`, which is not part of the composer package, so offline page loads never worked for Packagist installs without a production Vite build.
- Duo's precompiler ran after Livewire's, letting compiled `wire:key` PHP leak into `x-for` templates.
- `DuoSynth` registered too late for `ComponentHookRegistry`, so real requests carried an empty `duo` effect while tests passed.

### Removed
- `DuoSyncController`, `/api/duo/*` routes, manifest `endpoints`/`sync` keys, client `SyncQueue`, `DuoDomCompiler`, `Syncable::getDuoSyncStrategy()`/`shouldSyncToDuo()`, and the `syncInterval`/`maxRetries` client options (still accepted, ignored).

## [0.1.x] - 2025

### Added
- Automatic Blade-to-Alpine.js transformation for Livewire components with `WithDuo` trait
- `<x-duo::sync-status />` component for visual sync status indication
- Offline/online detection with automatic sync queue pause/resume
- Background sync with retry logic and network error handling
- `x-cloak` and loading state management for smooth initialization
- Automatic manifest generation with Vite plugin file watching
- Support for both Livewire class-based and Volt components
- Comprehensive transformation of Blade syntax to Alpine.js:
  - `wire:click` → `@click` with IndexedDB operations
  - `@forelse` loops → `x-for` templates
  - `{{ $model->property }}` → `x-text` bindings
  - Conditional classes → `:class` bindings
  - `@if` statements → `x-show` directives

### Changed
- Renamed trait from `Concerns\Syncable` to `Syncable` (removed namespace nesting)
- Component trait is now `WithDuo` instead of `Duo` or `UsesDuo`
- Vite plugin now includes Dexie.js as a dependency automatically
- Improved error handling for network failures
- Enhanced debugging with comprehensive logging

### Fixed
- Trait detection after namespace refactoring
- Offline sync queue not pausing when network unavailable
- Network errors counting against retry limit
- HTML transformation regex patterns for flexible class matching

## [0.1.0] - Initial Development

### Added
- Initial package structure
- Basic IndexedDB integration with Dexie.js
- Livewire integration for automatic caching
- Vite plugin for manifest generation
- `Syncable` trait for models
- `duo:discover` and `duo:generate` Artisan commands
- Basic sync queue with background synchronization

[Unreleased]: https://github.com/joshcirre/duo/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/joshcirre/duo/compare/v0.1.7...v0.2.0
[0.1.x]: https://github.com/joshcirre/duo/compare/v0.1.0...v0.1.7
[0.1.0]: https://github.com/joshcirre/duo/releases/tag/v0.1.0
