# Changelog

All notable changes to Duo will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/joshcirre/duo/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/joshcirre/duo/releases/tag/v0.1.0
