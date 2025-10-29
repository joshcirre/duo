/**
 * Service Worker Manager
 *
 * Handles registration and lifecycle of the Duo service worker for offline caching.
 */

export interface ServiceWorkerConfig {
    enabled?: boolean;
    path?: string;
    scope?: string;
    updateCheckInterval?: number;
}

export class ServiceWorkerManager {
    private registration: ServiceWorkerRegistration | null = null;
    private config: Required<ServiceWorkerConfig>;

    constructor(config: ServiceWorkerConfig = {}) {
        this.config = {
            enabled: config.enabled ?? true,
            path: config.path ?? '/duo-sw.js',
            scope: config.scope ?? '/',
            updateCheckInterval: config.updateCheckInterval ?? 3600000, // 1 hour
        };
    }

    /**
     * Register the service worker
     */
    async register(): Promise<void> {
        if (!this.config.enabled) {
            console.log('[Duo SW Manager] Service worker disabled');
            return;
        }

        if (!('serviceWorker' in navigator)) {
            console.warn('[Duo SW Manager] Service workers not supported');
            return;
        }

        try {
            console.log('[Duo SW Manager] Registering service worker...');

            this.registration = await navigator.serviceWorker.register(
                this.config.path,
                { scope: this.config.scope }
            );

            console.log('[Duo SW Manager] Service worker registered successfully');

            // Handle updates
            this.setupUpdateHandling();

            // Check for updates periodically
            this.startUpdateCheck();

        } catch (error) {
            console.error('[Duo SW Manager] Registration failed:', error);
        }
    }

    /**
     * Unregister the service worker
     */
    async unregister(): Promise<void> {
        if (!this.registration) {
            return;
        }

        try {
            await this.registration.unregister();
            console.log('[Duo SW Manager] Service worker unregistered');
            this.registration = null;
        } catch (error) {
            console.error('[Duo SW Manager] Unregister failed:', error);
        }
    }

    /**
     * Clear all caches
     */
    async clearCache(): Promise<void> {
        if (!this.registration || !this.registration.active) {
            console.warn('[Duo SW Manager] No active service worker');
            return;
        }

        // Send message to service worker to clear cache
        this.registration.active.postMessage({
            type: 'CLEAR_CACHE'
        });

        console.log('[Duo SW Manager] Cache clear requested');
    }

    /**
     * Check if service worker is active
     */
    isActive(): boolean {
        return this.registration?.active !== null && this.registration?.active !== undefined;
    }

    /**
     * Get service worker registration
     */
    getRegistration(): ServiceWorkerRegistration | null {
        return this.registration;
    }

    /**
     * Setup handling for service worker updates
     */
    private setupUpdateHandling(): void {
        if (!this.registration) {
            return;
        }

        // Handle waiting service worker
        this.registration.addEventListener('updatefound', () => {
            const newWorker = this.registration?.installing;

            if (!newWorker) {
                return;
            }

            console.log('[Duo SW Manager] New service worker found');

            newWorker.addEventListener('statechange', () => {
                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                    // New service worker installed, waiting to activate
                    console.log('[Duo SW Manager] New service worker ready to activate');

                    // Optionally notify the user or auto-update
                    this.handleUpdateAvailable(newWorker);
                }
            });
        });

        // Listen for controller change (new SW activated)
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            console.log('[Duo SW Manager] New service worker activated');
            // Optionally reload the page
            // window.location.reload();
        });
    }

    /**
     * Handle update available
     */
    private handleUpdateAvailable(newWorker: ServiceWorker): void {
        // Auto-activate the new service worker
        // In production, you might want to prompt the user first
        newWorker.postMessage({ type: 'SKIP_WAITING' });

        console.log('[Duo SW Manager] Activating new service worker...');
    }

    /**
     * Periodically check for service worker updates
     */
    private startUpdateCheck(): void {
        setInterval(() => {
            if (this.registration) {
                console.log('[Duo SW Manager] Checking for updates...');
                this.registration.update().catch((error) => {
                    console.error('[Duo SW Manager] Update check failed:', error);
                });
            }
        }, this.config.updateCheckInterval);
    }
}

/**
 * Register service worker with default configuration
 */
export async function registerServiceWorker(config?: ServiceWorkerConfig): Promise<ServiceWorkerManager> {
    const manager = new ServiceWorkerManager(config);
    await manager.register();
    return manager;
}
