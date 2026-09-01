# Duo Workbench Demo

This directory contains a working demo of Duo: a todo list built as a **Livewire 4
single-file component** with the `WithDuo` trait and `data-duo-*` template markers.

## Setup

1. Install dependencies:
```bash
composer install
npm install
```

2. Build the package (client JS + service worker):
```bash
npm run build
```

3. Build the workbench app (sqlite database, migrations):
```bash
composer run build
```

4. Build the workbench frontend assets:
```bash
npm run workbench:build
```

## Running the Demo

Start the development server:
```bash
php vendor/bin/testbench serve
```

Then open your browser to `http://localhost:8000`

## What's Included

- **Todo Model** (`workbench/app/Models/Todo.php`) — uses the `Syncable` trait
- **TodoList component** (`workbench/resources/views/components/todo-list.blade.php`) —
  a Livewire 4 single-file component using `WithDuo`, registered via
  `Livewire::addLocation()` in the `WorkbenchServiceProvider`
- **Database Migration** (`workbench/database/migrations/2024_01_01_000001_create_todos_table.php`)
- **Duo Manifest** (`resources/js/duo/manifest.json`) — IndexedDB schema for the Todo model

## How It Works

On render, Duo's Blade precompiler transforms the `data-duo-*` markers and `wire:*`
attributes into Alpine bindings (`x-for` over IndexedDB data, `duo.call(...)` handlers),
and `DuoSynth` attaches a `duo` effect to every Livewire response containing the
component's model state plus the exact Eloquent operations that ran. The client merges
that into IndexedDB, so reads are instant and the UI keeps working offline.
