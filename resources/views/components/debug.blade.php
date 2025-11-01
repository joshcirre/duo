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
$flexDirection = str_contains($position, 'bottom') ? 'column-reverse' : 'column';
$alignItems = str_contains($position, 'right') ? 'flex-end' : 'flex-start';
$marginPanel = str_contains($position, 'bottom') ? 'margin-bottom: 0.5rem;' : 'margin-top: 0.5rem;';

// Get actual state from app container (set by middleware)
$transformationsEnabled = app()->bound('duo.transformations.enabled')
    ? app('duo.transformations.enabled')
    : true;
@endphp

{{-- Development-only Duo debugging helper --}}
@if(app()->environment('local'))
<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
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
    style="{{ $style }} display: flex; flex-direction: {{ $flexDirection }}; align-items: {{ $alignItems }};"
>
    {{-- Toggle Button --}}
    <div style="display: flex; align-items: center; gap: 0.5rem;">
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
        style="width: 24rem; background-color: white; border: 1px solid #e4e4e7; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); overflow: hidden; {{ $marginPanel }}"
    >
        {{-- Header --}}
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; border-bottom: 1px solid #e4e4e7;">
            <flux:heading size="lg">Duo Debug Panel</flux:heading>
            <flux:button @click="open = false" variant="ghost" size="xs" icon="x-mark" icon-variant="micro" square />
        </div>

        {{-- Content --}}
        <div style="padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem; max-height: 500px; overflow-y: auto;">
            <template x-if="!dbInfo">
                <div style="display: flex; align-items: center; justify-content: center; padding: 2rem 0;">
                    <flux:icon.arrow-path style="animation: spin 1s linear infinite; width: 1.25rem; height: 1.25rem; color: #a1a1aa;" />
                </div>
            </template>

            <template x-if="dbInfo && dbInfo.error">
                <flux:badge color="red" size="sm" style="width: 100%; justify-content: flex-start;">
                    <flux:icon.exclamation-triangle variant="micro" />
                    <span x-text="dbInfo.error"></span>
                </flux:badge>
            </template>

            <template x-if="dbInfo && !dbInfo.error">
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    {{-- Database Name --}}
                    <div>
                        <flux:subheading>Database Name</flux:subheading>
                        <flux:text style="font-family: monospace; font-size: 0.75rem; word-break: break-all;" x-text="dbInfo.name"></flux:text>
                    </div>

                    {{-- Schema Version --}}
                    <div>
                        <flux:subheading>Schema Version</flux:subheading>
                        <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                            <flux:text style="font-family: monospace; font-weight: 600;" x-text="dbInfo.version"></flux:text>
                            <flux:text style="font-size: 0.75rem; color: #71717a;">Timestamp-based (like Laravel migrations)</flux:text>
                        </div>
                    </div>

                    {{-- Stores --}}
                    <div>
                        <flux:subheading>Stores</flux:subheading>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.375rem; margin-top: 0.25rem;">
                            <template x-for="store in dbInfo.stores" :key="store">
                                <flux:badge size="sm" color="zinc" style="font-family: monospace; font-size: 0.75rem;" x-text="store"></flux:badge>
                            </template>
                        </div>
                    </div>

                    {{-- Record Counts --}}
                    <div>
                        <flux:subheading>Record Counts</flux:subheading>
                        <div style="display: flex; flex-direction: column; gap: 0.375rem; margin-top: 0.25rem;">
                            <template x-for="[store, count] in Object.entries(dbInfo.stats)" :key="store">
                                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.875rem;">
                                    <flux:text style="font-size: 0.75rem;">
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
        <div style="padding: 0.75rem 1rem; border-top: 1px solid #e4e4e7; display: flex; flex-direction: column; gap: 0.5rem;">
            {{-- Duo Transformations Toggle --}}
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem; background-color: #fafafa; border-radius: 0.5rem; border: 1px solid #e4e4e7;">
                <div style="flex: 1;">
                    <flux:subheading>Duo Transformations</flux:subheading>
                    <flux:text style="font-size: 0.75rem; color: #71717a;">
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
                style="width: 100%;"
                icon="arrow-path"
            >
                Refresh Info
            </flux:button>

            <flux:button
                @click="clearDatabase()"
                variant="danger"
                size="sm"
                style="width: 100%;"
                icon="trash"
            >
                Delete Database & Reload
            </flux:button>
        </div>

        {{-- Footer Tip --}}
        <div style="padding: 0.5rem 1rem; background-color: #fafafa; border-top: 1px solid #e4e4e7;">
            <flux:text style="font-size: 0.75rem; color: #71717a;">
                <strong>Tip:</strong> Use the toggle to compare Duo vs. standard Livewire rendering.
            </flux:text>
        </div>
    </div>
</div>
@endif
