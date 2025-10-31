<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Database Name
    |--------------------------------------------------------------------------
    |
    | The name of the IndexedDB database that will be created in the browser.
    |
    */
    'database_name' => env('DUO_DATABASE_NAME', 'duo_cache'),

    /*
    |--------------------------------------------------------------------------
    | Database Version
    |--------------------------------------------------------------------------
    |
    | The version of the IndexedDB database schema.
    |
    */
    'database_version' => env('DUO_DATABASE_VERSION', 1),

    /*
    |--------------------------------------------------------------------------
    | Sync Strategy
    |--------------------------------------------------------------------------
    |
    | Default sync strategy: 'write-behind' or 'write-through'
    |
    */
    'sync_strategy' => env('DUO_SYNC_STRATEGY', 'write-behind'),

    /*
    |--------------------------------------------------------------------------
    | Sync Interval
    |--------------------------------------------------------------------------
    |
    | How often (in milliseconds) to attempt syncing pending changes to server.
    | Set to 0 to disable automatic syncing.
    |
    */
    'sync_interval' => env('DUO_SYNC_INTERVAL', 5000),

    /*
    |--------------------------------------------------------------------------
    | Max Retry Attempts
    |--------------------------------------------------------------------------
    |
    | Maximum number of retry attempts for failed sync operations.
    |
    */
    'max_retry_attempts' => env('DUO_MAX_RETRY_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Timestamp Refresh Interval
    |--------------------------------------------------------------------------
    |
    | How often (in milliseconds) to refresh relative timestamps like
    | "5 minutes ago" in the UI. Set to 0 to disable auto-refresh.
    | Can be overridden per-component via duoConfig().
    |
    */
    'timestamp_refresh_interval' => env('DUO_TIMESTAMP_REFRESH_INTERVAL', 10000),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | Default time-to-live for cached items (in seconds).
    | Set to null for no expiration.
    |
    */
    'cache_ttl' => env('DUO_CACHE_TTL', null),

    /*
    |--------------------------------------------------------------------------
    | Enable Debug Mode
    |--------------------------------------------------------------------------
    |
    | Enable verbose logging for debugging purposes.
    | Can be overridden per-component via duoConfig().
    |
    */
    'debug' => env('DUO_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Auto-discover Models
    |--------------------------------------------------------------------------
    |
    | Automatically scan for models using the Duo trait and generate
    | appropriate IndexedDB stores.
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
    | Configuration for the <x-duo::sync-status /> component.
    |
    */
    'sync_status' => [
        // Delay (in milliseconds) before showing sync indicator
        // Only show if syncing takes longer than this value
        'show_delay' => env('DUO_SYNC_STATUS_DELAY', 100),

        // Show "All changes synced" message after successful sync
        'show_success' => env('DUO_SYNC_STATUS_SHOW_SUCCESS', false),

        // Duration (in milliseconds) to show success message before fading
        'success_duration' => env('DUO_SYNC_STATUS_SUCCESS_DURATION', 2000),
    ],
];
