@props([
    'position' => 'bottom-right', // bottom-right, bottom-left, top-right, top-left
])

@php
// Map position to inline styles (more reliable than Tailwind classes for packages)
$positionStyles = [
    'top-right' => 'position: fixed; top: 1rem; right: 1rem; z-index: 9999;',
    'top-left' => 'position: fixed; top: 1rem; left: 1rem; z-index: 9999;',
    'bottom-right' => 'position: fixed; bottom: 1rem; right: 1rem; z-index: 9999;',
    'bottom-left' => 'position: fixed; bottom: 1rem; left: 1rem; z-index: 9999;',
];

$style = $positionStyles[$position] ?? $positionStyles['bottom-right'];
$flexDirection = str_contains($position, 'bottom') ? 'flex-col-reverse' : 'flex-col';

// Get actual state from app container (set by middleware)
$transformationsEnabled = app()->bound('duo.transformations.enabled')
    ? app('duo.transformations.enabled')
    : true;
@endphp

{{-- Development-only Duo debugging helper --}}
@if(app()->environment('local'))
<div
    x-data="{
        open: false,
        dbInfo: null,
        duoEnabled: {{ $transformationsEnabled ? 'true' : 'false' }},
        async getDbInfo() {
            if (!window.duo) {
                this.dbInfo = { error: 'Duo not initialized' };
                return;
            }

            const db = window.duo.getDatabase();
            if (!db) {
                this.dbInfo = { error: 'Database not available' };
                return;
            }

            const stats = await db.getStats();
            const stores = Array.from(db.getAllStores().keys());

            this.dbInfo = {
                name: db.name,
                version: db.verno,
                stores,
                stats
            };
        },
        async clearDatabase() {
            if (!confirm('Delete IndexedDB? This will clear all local data and reload the page.')) return;

            if (window.duo && window.duo.getDatabase()) {
                await window.duo.getDatabase().delete();
                location.reload();
            }
        },
        toggleDuoTransformations() {
            // The x-model has already toggled duoEnabled, so use that value
            const url = new URL(window.location.href);

            // Set query parameter based on new state
            if (this.duoEnabled) {
                // Remove the parameter to enable (default is on)
                url.searchParams.delete('duo');
            } else {
                // Add ?duo=off to disable
                url.searchParams.set('duo', 'off');
            }

            window.location.href = url.toString();
        },
        async init() {
            setTimeout(() => this.getDbInfo(), 1000);
        }
    }"
    x-init="init()"
    style="{{ $style }}"
    class="flex {{ $flexDirection }}"
>
    {{-- Toggle Button --}}
    <div class="flex items-center gap-2 {{ str_contains($position, 'right') ? 'self-end' : 'self-start' }}">
        @if($transformationsEnabled)
            <flux:badge color="green" size="sm">Duo ON</flux:badge>
        @else
            <flux:badge color="zinc" size="sm">Livewire</flux:badge>
        @endif
        <flux:button
            @click="open = !open"
            variant="primary"
            size="sm"
            icon="wrench-screwdriver"
        >
            Debug
        </flux:button>
    </div>

    {{-- Debug Panel --}}
    <div
        x-show="open"
        x-cloak
        @click.outside="open = false"
        class="w-96 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg shadow-xl overflow-hidden {{ str_contains($position, 'right') ? 'self-end' : 'self-start' }} {{ str_contains($position, 'bottom') ? 'mb-2' : 'mt-2' }}"
    >
        {{-- Header --}}
        <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-800">
            <flux:heading size="lg">Duo Debug Panel</flux:heading>
            <flux:button @click="open = false" variant="ghost" size="xs" icon="x-mark" icon-variant="micro" square />
        </div>

        {{-- Content --}}
        <div class="p-4 space-y-3 max-h-[500px] overflow-y-auto">
            <template x-if="!dbInfo">
                <div class="flex items-center justify-center py-8">
                    <flux:icon.arrow-path class="animate-spin size-5 text-zinc-400" />
                </div>
            </template>

            <template x-if="dbInfo && dbInfo.error">
                <flux:badge color="red" size="sm" class="w-full justify-start">
                    <flux:icon.exclamation-triangle variant="micro" />
                    <span x-text="dbInfo.error"></span>
                </flux:badge>
            </template>

            <template x-if="dbInfo && !dbInfo.error">
                <div class="space-y-3">
                    {{-- Database Name --}}
                    <div>
                        <flux:subheading>Database Name</flux:subheading>
                        <flux:text class="font-mono text-xs break-all" x-text="dbInfo.name"></flux:text>
                    </div>

                    {{-- Schema Version --}}
                    <div>
                        <flux:subheading>Schema Version</flux:subheading>
                        <div class="space-y-1">
                            <flux:text class="font-mono font-semibold" x-text="dbInfo.version"></flux:text>
                            <flux:text class="text-xs text-zinc-500">Timestamp-based (like Laravel migrations)</flux:text>
                        </div>
                    </div>

                    {{-- Stores --}}
                    <div>
                        <flux:subheading>Stores</flux:subheading>
                        <div class="flex flex-wrap gap-1.5 mt-1">
                            <template x-for="store in dbInfo.stores" :key="store">
                                <flux:badge size="sm" color="zinc" class="font-mono text-xs" x-text="store"></flux:badge>
                            </template>
                        </div>
                    </div>

                    {{-- Record Counts --}}
                    <div>
                        <flux:subheading>Record Counts</flux:subheading>
                        <div class="space-y-1.5 mt-1">
                            <template x-for="[store, count] in Object.entries(dbInfo.stats)" :key="store">
                                <div class="flex items-center justify-between text-sm">
                                    <flux:text class="text-xs">
                                        <span x-text="store.split('_').pop()"></span>
                                    </flux:text>
                                    <flux:badge size="sm" x-text="count + ' records'"></flux:badge>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- Actions --}}
        <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-800 space-y-2">
            {{-- Duo Transformations Toggle --}}
            <div class="flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-950 rounded-lg border border-zinc-200 dark:border-zinc-800">
                <div class="flex-1">
                    <flux:subheading>Duo Transformations</flux:subheading>
                    <flux:text class="text-xs text-zinc-500">
                        <span x-show="duoEnabled">Local-first mode enabled</span>
                        <span x-show="!duoEnabled">Standard Livewire mode</span>
                    </flux:text>
                </div>
                <flux:switch
                    x-model="duoEnabled"
                    @change="toggleDuoTransformations()"
                />
            </div>

            <flux:button
                @click="getDbInfo()"
                variant="ghost"
                size="sm"
                class="w-full"
                icon="arrow-path"
            >
                Refresh Info
            </flux:button>

            <flux:button
                @click="clearDatabase()"
                variant="danger"
                size="sm"
                class="w-full"
                icon="trash"
            >
                Delete Database & Reload
            </flux:button>
        </div>

        {{-- Footer Tip --}}
        <div class="px-4 py-2 bg-zinc-50 dark:bg-zinc-950 border-t border-zinc-200 dark:border-zinc-800">
            <flux:text class="text-xs text-zinc-500">
                <strong>Tip:</strong> Use the toggle to compare Duo vs. standard Livewire rendering.
            </flux:text>
        </div>
    </div>
</div>
@endif
