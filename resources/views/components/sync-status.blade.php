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
            // Initial update
            this.updateSyncStatus();
            // Poll every second
            setInterval(() => this.updateSyncStatus(), 1000);
        }
    }"
    x-init="init()"
    {{ $attributes->merge(['class' => $classes . ' z-50']) }}
>
    <!-- Offline Banner -->
    <div
        x-show="!duoSyncStatus.isOnline"
        x-transition
        class="bg-orange-500 text-white px-4 py-3 rounded-lg shadow-lg flex items-center gap-3"
    >
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
        </svg>
        <div>
            <div class="font-semibold">You're offline</div>
            <div class="text-sm opacity-90">Changes will be saved locally</div>
        </div>
    </div>

    <!-- Syncing Indicator -->
    <div
        x-show="duoSyncStatus.isOnline && duoSyncStatus.pendingCount > 0"
        x-transition
        class="bg-blue-500 text-white px-4 py-3 rounded-lg shadow-lg flex items-center gap-3"
    >
        <svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        <div>
            <div class="font-semibold">Syncing changes...</div>
            <div class="text-sm opacity-90" x-text="duoSyncStatus.pendingCount + ' operation' + (duoSyncStatus.pendingCount === 1 ? '' : 's') + ' pending'"></div>
        </div>
    </div>

    <!-- Synced Indicator (briefly shows then fades) -->
    <div
        x-show="duoSyncStatus.isOnline && duoSyncStatus.pendingCount === 0 && !duoSyncStatus.isProcessing"
        x-transition
        @duo-synced.window="
            $el.classList.remove('opacity-0');
            setTimeout(() => $el.classList.add('opacity-0'), 2000);
        "
        class="bg-green-500 text-white px-4 py-3 rounded-lg shadow-lg flex items-center gap-3 opacity-0 transition-opacity duration-500"
        style="display: none;"
    >
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
        </svg>
        <div>
            <div class="font-semibold">All changes synced</div>
        </div>
    </div>
</div>
