/**
 * Duo Service Worker
 *
 * Handles offline caching for pages with the WithDuo trait.
 * Caches HTML pages and their assets (CSS, JS, images) for true offline functionality.
 */

const CACHE_VERSION = 'duo-v1';
const CACHE_NAME = `duo-offline-${CACHE_VERSION}`;

// Assets to cache immediately on install
const PRECACHE_ASSETS = [
    // Add any core assets here that should always be available
];

/**
 * Install event - precache essential assets
 */
self.addEventListener('install', (event) => {
    console.log('[Duo SW] Installing service worker...');

    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            console.log('[Duo SW] Precaching assets');
            return cache.addAll(PRECACHE_ASSETS);
        }).then(() => {
            // Force the waiting service worker to become the active service worker
            return self.skipWaiting();
        })
    );
});

/**
 * Activate event - clean up old caches
 */
self.addEventListener('activate', (event) => {
    console.log('[Duo SW] Activating service worker...');

    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName.startsWith('duo-offline-') && cacheName !== CACHE_NAME) {
                        console.log('[Duo SW] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => {
            // Claim all clients immediately
            return self.clients.claim();
        })
    );
});

/**
 * Fetch event - handle caching strategy
 */
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Only handle same-origin requests
    if (url.origin !== location.origin) {
        return;
    }

    // Skip non-GET requests
    if (request.method !== 'GET') {
        return;
    }

    // Skip Livewire update requests (these should always go to network)
    if (url.pathname.includes('/livewire/update')) {
        return;
    }

    event.respondWith(handleFetch(request));
});

/**
 * Handle fetch with caching strategy
 */
async function handleFetch(request) {
    const url = new URL(request.url);

    // Determine strategy based on request type
    if (isAsset(url)) {
        // Assets: Cache-first strategy
        return cacheFirst(request);
    } else if (isPage(url)) {
        // Pages: Network-first, then cache
        return networkFirstWithCache(request);
    } else {
        // Everything else: Network only
        return fetch(request);
    }
}

/**
 * Check if URL is an asset (CSS, JS, image, font)
 */
function isAsset(url) {
    const assetExtensions = ['.css', '.js', '.jpg', '.jpeg', '.png', '.gif', '.svg', '.webp', '.woff', '.woff2', '.ttf', '.eot'];
    return assetExtensions.some(ext => url.pathname.endsWith(ext));
}

/**
 * Check if URL is a page (HTML)
 */
function isPage(url) {
    // Check if it's a navigation request or has no extension (likely HTML)
    return !isAsset(url) && (url.pathname === '/' || !url.pathname.includes('.'));
}

/**
 * Cache-first strategy: Check cache first, fall back to network
 */
async function cacheFirst(request) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);

    if (cached) {
        console.log('[Duo SW] Serving from cache:', request.url);
        return cached;
    }

    console.log('[Duo SW] Cache miss, fetching:', request.url);
    try {
        const response = await fetch(request);
        if (response.ok) {
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        console.error('[Duo SW] Fetch failed:', error);
        throw error;
    }
}

/**
 * Network-first with cache fallback strategy for pages
 */
async function networkFirstWithCache(request) {
    const cache = await caches.open(CACHE_NAME);

    try {
        console.log('[Duo SW] Fetching page:', request.url);
        const response = await fetch(request);

        if (response.ok) {
            // Check if this is a Duo-enabled page
            const clone = response.clone();
            const html = await clone.text();

            if (isDuoPage(html)) {
                console.log('[Duo SW] Caching Duo-enabled page:', request.url);

                // Cache the page
                await cache.put(request, new Response(html, {
                    headers: response.headers,
                    status: response.status,
                    statusText: response.statusText
                }));

                // Extract and cache assets
                await cachePageAssets(html, request.url);
            }
        }

        return response;
    } catch (error) {
        console.log('[Duo SW] Network failed, trying cache:', request.url);
        const cached = await cache.match(request);

        if (cached) {
            console.log('[Duo SW] Serving cached page:', request.url);
            return cached;
        }

        console.error('[Duo SW] No cached version available');
        throw error;
    }
}

/**
 * Check if HTML contains Duo cache meta tag
 */
function isDuoPage(html) {
    return html.includes('name="duo-cache"') && html.includes('content="true"');
}

/**
 * Extract and cache assets referenced in the page
 */
async function cachePageAssets(html, baseUrl) {
    const cache = await caches.open(CACHE_NAME);
    const assets = extractAssets(html, baseUrl);

    console.log(`[Duo SW] Found ${assets.length} assets to cache`);

    // Cache assets in parallel
    const cachePromises = assets.map(async (assetUrl) => {
        try {
            const cached = await cache.match(assetUrl);
            if (!cached) {
                console.log('[Duo SW] Caching asset:', assetUrl);
                const response = await fetch(assetUrl);
                if (response.ok) {
                    await cache.put(assetUrl, response);
                }
            }
        } catch (error) {
            console.warn('[Duo SW] Failed to cache asset:', assetUrl, error);
        }
    });

    await Promise.allSettled(cachePromises);
}

/**
 * Extract asset URLs from HTML
 */
function extractAssets(html, baseUrl) {
    const assets = new Set();
    const base = new URL(baseUrl);

    // Regular expressions to find asset references
    const patterns = [
        // <link href="...">
        /<link[^>]+href=["']([^"']+)["']/gi,
        // <script src="...">
        /<script[^>]+src=["']([^"']+)["']/gi,
        // <img src="...">
        /<img[^>]+src=["']([^"']+)["']/gi,
        // srcset="..."
        /srcset=["']([^"']+)["']/gi,
        // CSS url()
        /url\(["']?([^"')]+)["']?\)/gi,
    ];

    patterns.forEach(pattern => {
        let match;
        while ((match = pattern.exec(html)) !== null) {
            const url = match[1];

            // Skip data URLs and external URLs
            if (url.startsWith('data:') || url.startsWith('http://') || url.startsWith('https://')) {
                if (!url.startsWith(base.origin)) {
                    continue;
                }
            }

            // Convert relative URLs to absolute
            try {
                const absoluteUrl = new URL(url, base).href;
                if (isAsset(new URL(absoluteUrl))) {
                    assets.add(absoluteUrl);
                }
            } catch (e) {
                console.warn('[Duo SW] Invalid asset URL:', url);
            }
        }
    });

    return Array.from(assets);
}

/**
 * Message event - handle commands from the app
 */
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }

    if (event.data && event.data.type === 'CLEAR_CACHE') {
        event.waitUntil(
            caches.delete(CACHE_NAME).then(() => {
                console.log('[Duo SW] Cache cleared');
                return { success: true };
            })
        );
    }
});
