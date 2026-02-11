<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

/**
 * Add this trait to any Livewire component to enable offline-first behavior.
 *
 * When this trait is present, Duo will:
 * 1. Cache the rendered page via service worker for offline access
 * 2. Intercept Livewire requests at the JS level for instant local response
 * 3. Sync changes to the server in the background
 *
 * No code changes required — your existing Blade templates and Livewire
 * methods work as-is. Duo handles the offline layer transparently.
 */
trait WithDuo
{
    protected function duoConfig(): DuoConfig
    {
        return DuoConfig::make();
    }
}
