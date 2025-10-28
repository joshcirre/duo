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
        duoSyncStatus: { isOnline: true, pendingCount: 0, isProcessing: false },
        updateSyncStatus() {
            if (window.duo && window.duo.getSyncQueue()) {
                this.duoSyncStatus = window.duo.getSyncQueue().getSyncStatus();
            }
        },
        init() {
            this.updateSyncStatus();
            setInterval(() => this.updateSyncStatus(), 1000);
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
