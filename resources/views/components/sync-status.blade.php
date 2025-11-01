@props(['position' => 'top-right', 'inline' => false, 'showDelay' => null, 'showSuccess' => null])

@php
// Map position to inline styles (more reliable than Tailwind classes)
$positionStyles = [
    'top-right' => 'position: fixed; top: 1rem; right: 1rem; z-index: 9999;',
    'top-left' => 'position: fixed; top: 1rem; left: 1rem; z-index: 9999;',
    'bottom-right' => 'position: fixed; bottom: 1rem; right: 1rem; z-index: 9999;',
    'bottom-left' => 'position: fixed; bottom: 1rem; left: 1rem; z-index: 9999;',
    'inline' => '',
];

$style = $inline ? '' : ($positionStyles[$position] ?? $positionStyles['top-right']);

// Get config values with prop overrides
$showDelay = $showDelay ?? config('duo.sync_status.show_delay', 1000);
$showSuccess = $showSuccess ?? config('duo.sync_status.show_success', false);
$successDuration = config('duo.sync_status.success_duration', 2000);
@endphp

<div
    x-data="{
        duoSyncStatus: {
            isOnline: navigator.onLine,
            pendingCount: 0,
            showIndicator: false,
            showSuccess: {{ $showSuccess ? 'true' : 'false' }},
            syncStartTime: null,
            delayTimer: null,
        },
        config: {
            showDelay: {{ $showDelay }},
            successDuration: {{ $successDuration }},
        },
        async init() {
            // Wait for Duo client to be ready
            if (!window.duo) {
                await new Promise(resolve => {
                    const checkDuo = setInterval(() => {
                        if (window.duo) {
                            clearInterval(checkDuo);
                            resolve();
                        }
                    }, 100);

                    // Timeout after 5 seconds
                    setTimeout(() => {
                        clearInterval(checkDuo);
                        resolve();
                    }, 5000);
                });
            }

            if (!window.duo) return;

            const db = window.duo.getDatabase();
            if (!db) return;

            // Use liveQuery to reactively count pending operations across all stores
            const subscription = window.duo.liveQuery(async () => {
                let totalPending = 0;
                for (const [storeName, store] of db.getAllStores()) {
                    // Get all records and filter for pending sync
                    // Dexie doesn't handle boolean queries well with where(), so we filter manually
                    const all = await store.toArray();
                    const pending = all.filter(item => item._duo_pending_sync === true || item._duo_pending_sync === 1).length;
                    totalPending += pending;
                }
                return totalPending;
            }).subscribe(
                count => {
                    const previousCount = this.duoSyncStatus.pendingCount;
                    this.duoSyncStatus.pendingCount = count;

                    // If count went from 0 to >0, sync started
                    if (previousCount === 0 && count > 0) {
                        this.duoSyncStatus.syncStartTime = Date.now();

                        // Set timer to show indicator after delay
                        if (this.duoSyncStatus.delayTimer) {
                            clearTimeout(this.duoSyncStatus.delayTimer);
                        }
                        this.duoSyncStatus.delayTimer = setTimeout(() => {
                            // Only show if still syncing
                            if (this.duoSyncStatus.pendingCount > 0) {
                                this.duoSyncStatus.showIndicator = true;
                            }
                        }, this.config.showDelay);
                    }

                    // If count went to 0, sync completed
                    if (count === 0 && previousCount > 0) {
                        // Clear the delay timer
                        if (this.duoSyncStatus.delayTimer) {
                            clearTimeout(this.duoSyncStatus.delayTimer);
                            this.duoSyncStatus.delayTimer = null;
                        }

                        // Hide indicator immediately
                        this.duoSyncStatus.showIndicator = false;
                        this.duoSyncStatus.syncStartTime = null;
                    }
                },
                error => console.error('[Duo] Error in sync status liveQuery:', error)
            );

            // Listen for online/offline events
            window.addEventListener('online', () => {
                this.duoSyncStatus.isOnline = true;
            });
            window.addEventListener('offline', () => {
                this.duoSyncStatus.isOnline = false;
            });

            // Cleanup subscription when component is destroyed
            this.$cleanup = () => {
                subscription.unsubscribe();
                if (this.duoSyncStatus.delayTimer) {
                    clearTimeout(this.duoSyncStatus.delayTimer);
                }
            };
        }
    }"
    x-init="init()"
    style="{{ $style }} display: flex; flex-direction: column; gap: 0.5rem;"
>
    <!-- Offline Badge -->
    <div
        x-show="!duoSyncStatus.isOnline"
        x-transition
    >
        <flux:badge color="amber" icon="exclamation-triangle" size="lg">
            <div style="display: flex; flex-direction: column; align-items: flex-start;">
                <span style="font-weight: 600;">You're offline</span>
                <span style="font-size: 0.75rem; opacity: 0.9;">Changes saved locally</span>
            </div>
        </flux:badge>
    </div>

    <!-- Syncing Badge (only shows after delay if sync takes too long) -->
    <div
        x-show="duoSyncStatus.isOnline && duoSyncStatus.showIndicator && duoSyncStatus.pendingCount > 0"
        x-transition
    >
        <flux:badge color="blue" icon="arrow-path" icon-variant="micro" size="lg">
            <div style="display: flex; flex-direction: column; align-items: flex-start;">
                <span style="font-weight: 600;">Syncing changes...</span>
                <span style="font-size: 0.75rem; opacity: 0.9;" x-text="duoSyncStatus.pendingCount + ' operation' + (duoSyncStatus.pendingCount === 1 ? '' : 's') + ' pending'"></span>
            </div>
        </flux:badge>
    </div>

    <!-- Synced Badge (optional, controlled by config) -->
    <template x-if="duoSyncStatus.showSuccess">
        <div
            x-show="duoSyncStatus.isOnline && duoSyncStatus.pendingCount === 0"
            x-transition
            @duo-synced.window="
                $el.style.opacity = '1';
                setTimeout(() => $el.style.opacity = '0', config.successDuration);
            "
            style="opacity: 0; transition: opacity 500ms;"
        >
            <flux:badge color="lime" icon="check-circle" size="lg">
                <span style="font-weight: 600;">All changes synced</span>
            </flux:badge>
        </div>
    </template>
</div>
