<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Debug
    |--------------------------------------------------------------------------
    |
    | Reserved for server-side debug logging. Client-side logging is controlled
    | by the `debug` option passed to initializeDuo() (the Vite plugin passes
    | import.meta.env.DEV).
    |
    */
    'debug' => env('DUO_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Auto-discover Models
    |--------------------------------------------------------------------------
    |
    | Scan the paths below for models using the Syncable trait so their
    | IndexedDB stores are known without registering them manually.
    |
    */
    'auto_discover' => env('DUO_AUTO_DISCOVER', true),

    /*
    |--------------------------------------------------------------------------
    | Model Paths
    |--------------------------------------------------------------------------
    |
    | Paths to scan for models when auto-discovery is enabled.
    |
    */
    'model_paths' => [
        app_path('Models'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Status Component
    |--------------------------------------------------------------------------
    |
    | Defaults for the <x-duo::sync-status /> component. Component props
    | override these values.
    |
    */
    'sync_status' => [
        // Only show the "Syncing" badge if syncing takes longer than this (ms)
        'show_delay' => env('DUO_SYNC_STATUS_DELAY', 1000),

        // Show an "All changes synced" badge after a successful sync
        'show_success' => env('DUO_SYNC_STATUS_SHOW_SUCCESS', false),

        // How long the success badge stays visible (ms)
        'success_duration' => env('DUO_SYNC_STATUS_SUCCESS_DURATION', 2000),
    ],
];
