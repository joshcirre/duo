@props(['position' => 'top-right', 'inline' => false])

@php
$positionClasses = [
    'top-right' => 'fixed top-4 right-4',
    'top-left' => 'fixed top-4 left-4',
    'bottom-right' => 'fixed bottom-4 right-4',
    'bottom-left' => 'fixed bottom-4 left-4',
    'inline' => '',
];

$classes = $inline ? '' : ($positionClasses[$position] ?? $positionClasses['top-right']);
@endphp

<div
    x-data="{
        duoSyncStatus: { isOnline: navigator.onLine, pendingCount: 0, isProcessing: false },
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
                    const pending = await store.where('_duo_pending_sync').equals(1).count();
                    totalPending += pending;
                }
                return totalPending;
            }).subscribe(
                count => {
                    this.duoSyncStatus.pendingCount = count;
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
            this.$cleanup = () => subscription.unsubscribe();
        }
    }"
    x-init="init()"
    {{ $attributes->merge(['class' => $classes . ' z-50']) }}
>
    <!-- Offline Badge -->
    <div
        x-show="!duoSyncStatus.isOnline"
        x-transition
    >
        <flux:badge color="amber" icon="exclamation-triangle" size="lg">
            <div class="flex flex-col items-start">
                <span class="font-semibold">You're offline</span>
                <span class="text-xs opacity-90">Changes saved locally</span>
            </div>
        </flux:badge>
    </div>

    <!-- Syncing Badge -->
    <div
        x-show="duoSyncStatus.isOnline && duoSyncStatus.pendingCount > 0"
        x-transition
    >
        <flux:badge color="blue" icon="arrow-path" icon-variant="micro" size="lg">
            <div class="flex flex-col items-start">
                <span class="font-semibold">Syncing changes...</span>
                <span class="text-xs opacity-90" x-text="duoSyncStatus.pendingCount + ' operation' + (duoSyncStatus.pendingCount === 1 ? '' : 's') + ' pending'"></span>
            </div>
        </flux:badge>
    </div>

    <!-- Synced Badge (briefly shows then fades) -->
    <div
        x-show="duoSyncStatus.isOnline && duoSyncStatus.pendingCount === 0 && !duoSyncStatus.isProcessing"
        x-transition
        @duo-synced.window="
            $el.classList.remove('opacity-0');
            setTimeout(() => $el.classList.add('opacity-0'), 2000);
        "
        class="opacity-0 transition-opacity duration-500"
        style="display: none;"
    >
        <flux:badge color="lime" icon="check-circle" size="lg">
            <span class="font-semibold">All changes synced</span>
        </flux:badge>
    </div>
</div>
