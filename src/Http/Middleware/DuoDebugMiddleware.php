<?php

declare(strict_types=1);

namespace JoshCirre\Duo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Middleware to handle Duo debug mode toggling (local environment only).
 *
 * Usage:
 * - Add ?duo=off to any URL to disable Duo transformations (shows standard Livewire)
 * - Remove parameter or use ?duo=on to enable transformations (default)
 */
class DuoDebugMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Only run in local environment
        if (! app()->environment('local')) {
            return $next($request);
        }

        // Check for ?duo=off query parameter to disable transformations
        // Default is enabled (true) unless explicitly set to 'off'
        $enabled = $request->query('duo') !== 'off';

        \Log::info('[Duo Debug] Transformations state', [
            'duo_param' => $request->query('duo'),
            'enabled' => $enabled,
        ]);

        // Store in app container for use during render
        app()->instance('duo.transformations.enabled', $enabled);

        return $next($request);
    }
}
