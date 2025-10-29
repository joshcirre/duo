import { DuoDatabase, type DuoConfig, type DuoRecord } from './core/database';
import { SyncQueue, type SyncQueueConfig } from './sync/queue';
import { ServiceWorkerManager, type ServiceWorkerConfig } from './offline/service-worker-manager';

export interface DuoClientConfig {
  manifest?: Record<string, any>;
  manifestPath?: string;
  syncInterval?: number;
  maxRetries?: number;
  debug?: boolean;
  offline?: ServiceWorkerConfig;
}

/**
 * Main Duo Client
 */
export class DuoClient {
  private db?: DuoDatabase;
  private syncQueue?: SyncQueue;
  private serviceWorkerManager?: ServiceWorkerManager;
  private config: DuoClientConfig;

  constructor(config: DuoClientConfig = {}) {
    this.config = {
      manifestPath: '/js/duo/manifest.json',
      syncInterval: 5000,
      maxRetries: 3,
      debug: false,
      offline: {
        enabled: true,
        ...config.offline,
      },
      ...config,
    };
  }

  /**
   * Initialize the Duo client
   */
  async initialize(): Promise<void> {
    // Load manifest (either passed directly or fetched)
    const manifest = this.config.manifest || await this.loadManifest();

    // Create database
    const dbConfig: DuoConfig = {
      databaseName: 'duo_cache',
      databaseVersion: 1,
      stores: manifest,
      debug: this.config.debug,
    };

    this.db = new DuoDatabase(dbConfig);

    // Hydrate stores from server on first load
    await this.hydrateStores();

    // Create sync queue
    const syncConfig: SyncQueueConfig = {
      maxRetries: this.config.maxRetries!,
      syncInterval: this.config.syncInterval!,
      debug: this.config.debug,
      onSyncSuccess: (operation) => {
        if (this.config.debug) {
          console.log('[Duo] Sync successful:', operation);
        }
      },
      onSyncError: (operation, error) => {
        console.error('[Duo] Sync failed:', operation, error);
      },
    };

    this.syncQueue = new SyncQueue(this.db, syncConfig);
    this.syncQueue.start();

    // Register service worker for offline page caching
    if (this.config.offline?.enabled !== false) {
      this.serviceWorkerManager = new ServiceWorkerManager(this.config.offline);
      await this.serviceWorkerManager.register();
    }

    if (this.config.debug) {
      console.log('[Duo] Client initialized');
      const stats = await this.db.getStats();
      console.log('[Duo] Database stats:', stats);
    }
  }

  /**
   * Load the manifest file
   */
  private async loadManifest(): Promise<Record<string, any>> {
    try {
      const response = await fetch(this.config.manifestPath!);
      if (!response.ok) {
        throw new Error(`Failed to load manifest: ${response.statusText}`);
      }
      return await response.json();
    } catch (error) {
      console.error('[Duo] Failed to load manifest:', error);
      return {};
    }
  }

  /**
   * Hydrate stores from server on first load
   * Note: This is now handled by Alpine integration on component mount
   * which syncs server data from x-data to IndexedDB on every page load
   */
  private async hydrateStores(): Promise<void> {
    // This method is kept for backward compatibility but is now a no-op
    // Server data syncing happens in alpine-integration.ts via x-data
    if (this.config.debug) {
      console.log('[Duo] Hydration will be handled by Alpine integration on component mount');
    }
  }

  /**
   * Get the database instance
   */
  getDatabase(): DuoDatabase | undefined {
    return this.db;
  }

  /**
   * Get the sync queue instance
   */
  getSyncQueue(): SyncQueue | undefined {
    return this.syncQueue;
  }

  /**
   * Get the service worker manager instance
   */
  getServiceWorkerManager(): ServiceWorkerManager | undefined {
    return this.serviceWorkerManager;
  }

  /**
   * Manually trigger a sync
   */
  async sync(): Promise<void> {
    // This will be called by the sync queue automatically
    if (this.config.debug) {
      console.log('[Duo] Manual sync triggered');
    }
  }

  /**
   * Clear all cached data (IndexedDB and Service Worker caches)
   */
  async clearCache(): Promise<void> {
    await this.db?.clearAll();
    await this.serviceWorkerManager?.clearCache();
    if (this.config.debug) {
      console.log('[Duo] All caches cleared');
    }
  }

  /**
   * Destroy the client and cleanup
   */
  async destroy(): Promise<void> {
    this.syncQueue?.stop();
    await this.db?.close();
    await this.serviceWorkerManager?.unregister();

    if (this.config.debug) {
      console.log('[Duo] Client destroyed');
    }
  }
}

// Global instance
let duoInstance: DuoClient | null = null;

/**
 * Initialize Duo with configuration
 */
export async function initializeDuo(config?: DuoClientConfig): Promise<DuoClient> {
  if (duoInstance) {
    return duoInstance;
  }

  duoInstance = new DuoClient(config);
  await duoInstance.initialize();

  // Make it globally available for easy access
  if (typeof window !== 'undefined') {
    (window as any).duo = duoInstance;
  }

  return duoInstance;
}

/**
 * Get the global Duo instance
 */
export function getDuo(): DuoClient | null {
  return duoInstance;
}

// Re-export types and classes
export { DuoDatabase, SyncQueue };
export type { DuoConfig, DuoRecord, SyncQueueConfig };

/**
 * Helper functions for easy data access
 */

/**
 * Get all records from a table
 */
export async function getAll(table: string): Promise<any[]> {
  const duo = getDuo();
  if (!duo || !duo.getDatabase()) return [];

  const storeName = `App_Models_${table.charAt(0).toUpperCase() + table.slice(1)}`;
  const store = duo.getDatabase()!.getStore(storeName);

  if (!store) return [];
  return await store.toArray();
}

/**
 * Get a single record by ID
 */
export async function getById(table: string, id: number | string): Promise<any | null> {
  const duo = getDuo();
  if (!duo || !duo.getDatabase()) return null;

  const storeName = `App_Models_${table.charAt(0).toUpperCase() + table.slice(1)}`;
  const store = duo.getDatabase()!.getStore(storeName);

  if (!store) return null;
  return await store.get(id);
}

/**
 * Create a new record
 */
export async function create(table: string, data: Record<string, any>): Promise<any> {
  const duo = getDuo();
  if (!duo || !duo.getDatabase() || !duo.getSyncQueue()) {
    throw new Error('Duo not initialized');
  }

  const storeName = `App_Models_${table.charAt(0).toUpperCase() + table.slice(1)}`;
  const store = duo.getDatabase()!.getStore(storeName);

  if (!store) throw new Error(`Store not found: ${storeName}`);

  // Generate temporary ID for optimistic update
  const tempId = Date.now();
  const record = {
    ...data,
    id: tempId,
    _duo_pending_sync: true,
    _duo_operation: 'create' as const,
  };

  await store.put(record);

  // Queue for sync
  await duo.getSyncQueue()!.enqueue({
    storeName,
    operation: 'create',
    data: record,
  });

  return record;
}

/**
 * Update an existing record
 */
export async function update(table: string, id: number | string, data: Record<string, any>): Promise<any> {
  const duo = getDuo();
  if (!duo || !duo.getDatabase() || !duo.getSyncQueue()) {
    throw new Error('Duo not initialized');
  }

  const storeName = `App_Models_${table.charAt(0).toUpperCase() + table.slice(1)}`;
  const store = duo.getDatabase()!.getStore(storeName);

  if (!store) throw new Error(`Store not found: ${storeName}`);

  const existing = await store.get(id);
  if (!existing) throw new Error(`Record not found: ${id}`);

  const updated = {
    ...existing,
    ...data,
    _duo_pending_sync: true,
    _duo_operation: 'update' as const,
  };

  await store.put(updated);

  // Queue for sync
  await duo.getSyncQueue()!.enqueue({
    storeName,
    operation: 'update',
    data: updated,
  });

  return updated;
}

/**
 * Delete a record
 */
export async function remove(table: string, id: number | string): Promise<void> {
  const duo = getDuo();
  if (!duo || !duo.getDatabase() || !duo.getSyncQueue()) {
    throw new Error('Duo not initialized');
  }

  const storeName = `App_Models_${table.charAt(0).toUpperCase() + table.slice(1)}`;
  const store = duo.getDatabase()!.getStore(storeName);

  if (!store) throw new Error(`Store not found: ${storeName}`);

  const existing = await store.get(id);
  if (!existing) return;

  await store.delete(id);

  // Queue for sync
  await duo.getSyncQueue()!.enqueue({
    storeName,
    operation: 'delete',
    data: existing,
  });
}
