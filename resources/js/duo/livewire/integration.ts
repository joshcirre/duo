import type { DuoDatabase, DuoRecord } from '../core/database';
import type { SyncQueue } from '../sync/queue';

export interface LivewireIntegrationConfig {
  interceptWireModel?: boolean;
  autoSync?: boolean;
  debug?: boolean;
}

/**
 * Livewire Integration - Hooks into Livewire to intercept model operations
 */
export class LivewireIntegration {
  private db: DuoDatabase;
  private syncQueue: SyncQueue;
  private config: LivewireIntegrationConfig;
  private originalFetch?: typeof window.fetch;

  constructor(db: DuoDatabase, syncQueue: SyncQueue, config: LivewireIntegrationConfig = {}) {
    this.db = db;
    this.syncQueue = syncQueue;
    this.config = {
      interceptWireModel: true,
      autoSync: true,
      debug: false,
      ...config,
    };
  }

  /**
   * Initialize Livewire integration
   */
  initialize(): void {
    this.interceptFetchRequests();
    this.setupLivewireHooks();

    if (this.config.debug) {
      console.log('[Duo] Livewire integration initialized');
    }
  }

  /**
   * Intercept fetch requests to cache responses in IndexedDB
   */
  private interceptFetchRequests(): void {
    this.originalFetch = window.fetch;

    window.fetch = async (...args) => {
      const [resource, init] = args;
      const url =
        typeof resource === 'string'
          ? resource
          : resource instanceof Request
            ? resource.url
            : resource.toString();

      // Only intercept API requests
      if (!url.includes('/api/') && !url.includes('/livewire/')) {
        return this.originalFetch!(...args);
      }

      const method = init?.method?.toUpperCase() || 'GET';

      // For GET requests, try to read from cache first
      if (method === 'GET') {
        const cachedData = await this.getCachedResponse(url);
        if (cachedData) {
          if (this.config.debug) {
            console.log('[Duo] Serving from cache:', url);
          }
          return new Response(JSON.stringify(cachedData), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          });
        }
      }

      // Make the actual request
      const response = await this.originalFetch!(...args);

      // Cache successful GET responses
      if (method === 'GET' && response.ok) {
        const clonedResponse = response.clone();
        const data = await clonedResponse.json();
        await this.cacheResponse(url, data);
      }

      // For write operations, queue for sync
      if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && this.config.autoSync) {
        await this.handleWriteOperation(url, method, init?.body);
      }

      return response;
    };
  }

  /**
   * Setup Livewire-specific hooks
   */
  private setupLivewireHooks(): void {
    if (this.config.debug) {
      console.log(
        '[Duo] Setting up Livewire hooks, Livewire available:',
        typeof window.Livewire !== 'undefined'
      );
    }

    // Wait for Livewire to be available
    if (typeof window.Livewire === 'undefined') {
      if (this.config.debug) {
        console.log('[Duo] Waiting for livewire:init event...');
      }
      document.addEventListener('livewire:init', () => {
        if (this.config.debug) {
          console.log('[Duo] livewire:init event fired');
        }
        this.attachLivewireHooks();
      });
    } else {
      if (this.config.debug) {
        console.log('[Duo] Livewire already available, attaching hooks immediately');
      }
      this.attachLivewireHooks();
    }
  }

  /**
   * Attach hooks to Livewire events
   */
  private attachLivewireHooks(): void {
    // Log all available hooks for debugging
    if (this.config.debug) {
      console.log('[Duo] Attaching Livewire hooks...');
    }

    // Hook into component initialization to replace server data with IndexedDB data
    window.Livewire.hook('component.init', async ({ component }: any) => {
      await this.hydrateComponentFromCache(component);
    });

    window.Livewire.hook('commit', ({ component, commit, respond }: any) => {
      if (this.config.debug) {
        console.log('[Duo] Livewire commit:', { component: component.name, commit });
      }
    });

    window.Livewire.hook('message.sent', ({ message }: any) => {
      if (this.config.debug) {
        console.log('[Duo] Livewire message sent:', message);
      }
    });

    window.Livewire.hook('message.received', async ({ message, component }: any) => {
      if (this.config.debug) {
        console.log('[Duo] Livewire message received:', { message, component: component.name });
      }

      // Cache data from Livewire responses
      if (message.response?.serverMemo?.data) {
        await this.cacheComponentData(component.name, message.response.serverMemo.data);
      }

      // After receiving response, refresh component from cache
      await this.hydrateComponentFromCache(component);
    });
  }

  /**
   * Hydrate component data from IndexedDB cache
   */
  private async hydrateComponentFromCache(component: any): Promise<void> {
    try {
      const data = component.$wire?.$get?.('$all');

      if (!data || typeof data !== 'object') {
        return;
      }

      // Look for properties that contain arrays (likely model collections)
      for (const [key, value] of Object.entries(data)) {
        if (Array.isArray(value) && value.length > 0 && this.isModelData(value[0])) {
          // Try to find matching store
          const storeName = this.detectStoreFromData(value[0]);
          if (storeName) {
            const store = this.db.getStore(storeName);
            if (store) {
              const cachedData = await store.toArray();
              // Update the component with cached data
              component.$wire?.$set?.(key, cachedData);

              if (this.config.debug) {
                console.log(
                  `[Duo] Hydrated ${key} with ${cachedData.length} records from IndexedDB`
                );
              }
            }
          }
        }
      }
    } catch (error) {
      if (this.config.debug) {
        console.warn('[Duo] Error hydrating component from cache:', error);
      }
    }
  }

  /**
   * Handle method calls that might modify data
   */
  private async handleMethodCall(component: any, payload: any): Promise<void> {
    // This is where we'd intercept methods like addTodo, deleteTodo, etc.
    // For now, we'll let the normal flow happen and rely on message.received to sync
  }

  /**
   * Detect which store to use based on model data
   */
  private detectStoreFromData(modelData: any): string | null {
    // Check if data has common Laravel model properties
    if (!this.isModelData(modelData)) {
      return null;
    }

    // Try to detect from the data structure
    // This is a simple heuristic - you might need to make this smarter
    const stores = this.db.getAllStores();
    for (const [storeName] of stores) {
      // For now, just return the first store that exists
      // In a real implementation, you'd want to be smarter about this
      return storeName;
    }

    return null;
  }

  /**
   * Get cached response for a URL
   */
  private async getCachedResponse(url: string): Promise<any> {
    const storeName = this.getStoreNameFromUrl(url);
    if (!storeName) {
      return null;
    }

    const store = this.db.getStore(storeName);
    if (!store) {
      return null;
    }

    const id = this.getIdFromUrl(url);
    if (id) {
      return await store.get(id);
    }

    // Return all records for collection requests
    return await store.toArray();
  }

  /**
   * Cache a response in IndexedDB
   */
  private async cacheResponse(url: string, data: any): Promise<void> {
    const storeName = this.getStoreNameFromUrl(url);
    if (!storeName) {
      return;
    }

    const store = this.db.getStore(storeName);
    if (!store) {
      return;
    }

    // Handle both single records and collections
    if (Array.isArray(data)) {
      await store.bulkPut(
        data.map((item) => ({
          ...item,
          _duo_synced_at: Date.now(),
        }))
      );
    } else {
      await store.put({
        ...data,
        _duo_synced_at: Date.now(),
      });
    }

    if (this.config.debug) {
      console.log('[Duo] Cached response:', url, data);
    }
  }

  /**
   * Handle write operations (create, update, delete)
   */
  private async handleWriteOperation(url: string, method: string, body?: any): Promise<void> {
    const storeName = this.getStoreNameFromUrl(url);
    if (!storeName) {
      return;
    }

    const store = this.db.getStore(storeName);
    if (!store) {
      return;
    }

    const data = body ? JSON.parse(body as string) : {};

    let operation: 'create' | 'update' | 'delete';
    switch (method) {
      case 'POST':
        operation = 'create';
        break;
      case 'DELETE':
        operation = 'delete';
        break;
      default:
        operation = 'update';
    }

    // Update local cache immediately (write-behind)
    if (operation === 'delete') {
      const id = this.getIdFromUrl(url);
      if (id) {
        await store.delete(id);
      }
    } else {
      await store.put({
        ...data,
        _duo_pending_sync: true,
        _duo_operation: operation,
      });
    }

    // Queue for server sync
    await this.syncQueue.enqueue({
      storeName,
      operation,
      data,
      endpoint: url,
    });
  }

  /**
   * Cache Livewire component data
   */
  private async cacheComponentData(
    componentName: string,
    data: Record<string, any>
  ): Promise<void> {
    // Identify which models are in the component data
    for (const [key, value] of Object.entries(data)) {
      if (this.isModelData(value)) {
        const storeName = this.getStoreNameForModel(value);
        if (storeName) {
          const store = this.db.getStore(storeName);
          if (store) {
            await store.put({
              ...value,
              _duo_synced_at: Date.now(),
            });
          }
        }
      }
    }
  }

  /**
   * Check if data looks like a model
   */
  private isModelData(data: any): boolean {
    return typeof data === 'object' && data !== null && 'id' in data && !Array.isArray(data);
  }

  /**
   * Get store name from a URL
   */
  private getStoreNameFromUrl(url: string): string | null {
    const match = url.match(/\/api\/([^\/\?]+)/);
    if (match) {
      // Convert table name to store name (this is simplified, you might need more logic)
      return match[1];
    }
    return null;
  }

  /**
   * Get ID from a URL
   */
  private getIdFromUrl(url: string): string | number | null {
    const match = url.match(/\/(\d+)(?:\?|$)/);
    return match ? parseInt(match[1], 10) : null;
  }

  /**
   * Get store name for a model instance
   */
  private getStoreNameForModel(model: any): string | null {
    // This would need to be enhanced based on how you map models to stores
    // For now, just return null
    return null;
  }

  /**
   * Cleanup and restore original fetch
   */
  destroy(): void {
    if (this.originalFetch) {
      window.fetch = this.originalFetch;
    }
  }
}

// Extend Window interface for Livewire
declare global {
  interface Window {
    Livewire: any;
  }
}
