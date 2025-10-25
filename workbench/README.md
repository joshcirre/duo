# Duo Workbench Demo

This directory contains a working demo of Duo with a todo list application.

## Setup

1. Install dependencies:
```bash
composer install
npm install
```

2. Build the package:
```bash
npm run build
```

3. Build the workbench:
```bash
php vendor/bin/testbench workbench:build
```

## Running the Demo

Start the development server:
```bash
composer serve
```

Then open your browser to `http://localhost:8000`

## What's Included

- **Todo Model** (`workbench/app/Models/Todo.php`) - A simple todo model using the `Syncable` trait
- **TodoList Livewire Component** (`workbench/app/Livewire/TodoList.php`) - Interactive todo list
- **Database Migration** (`workbench/database/migrations/2024_01_01_000001_create_todos_table.php`)
- **Duo Manifest** (`resources/js/duo/manifest.json`) - IndexedDB schema for the Todo model

## How It Works

1. The Todo model uses the `Syncable` trait to enable Duo syncing
2. When the page loads, Duo initializes and creates an IndexedDB database
3. All todo operations (create, update, delete) are cached locally in IndexedDB
4. Changes are automatically synced to the server in the background
5. The app works offline and syncs when connection is restored

## Testing Offline Behavior

1. Open your browser's DevTools
2. Go to the Network tab
3. Set throttling to "Offline"
4. Try creating, updating, or deleting todos
5. Changes are saved locally in IndexedDB
6. Re-enable network to see changes sync to the server
