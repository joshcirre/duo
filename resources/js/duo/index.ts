import { DuoDatabase, type DuoConfig, type DuoRecord } from './core/database';
import { SyncQueue, type SyncQueueConfig } from './sync/queue';
import { ServiceWorkerManager, type ServiceWorkerConfig } from './offline/service-worker-manager';
import { DuoLivewireInterceptor } from './livewire/duo-interceptor';
import { liveQuery } from 'dexie';

export interface DuoClientConfig {
  manifest?: Record<string, any>;
  manifestPath?: string;
  syncInterval?: number;
  maxRetries?: number;
  debug?: boolean;
  offline?: ServiceWorkerConfig;
}

export class DuoClient {
  private db?: DuoDatabase;
  private syncQueue?: SyncQueue;
  private serviceWorkerManager?: ServiceWorkerManager;
  private interceptor?: DuoLivewireInterceptor;
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

  async initialize(): Promise<void> {
    const manifestData = this.config.manifest || (await this.loadManifest());

    const schemaVersion = manifestData._version || 1;
    const stores = manifestData.stores || manifestData;
    const databaseName = this.generateDatabaseName();

    const dbConfig: DuoConfig = {
      databaseName,
      databaseVersion: schemaVersion,
      stores,
      debug: this.config.debug,
    };

    this.db = new DuoDatabase(dbConfig);

    if (this.config.debug) {
      console.log(`[Duo] Database initialized: ${databaseName}`);
    }

    const syncConfig: SyncQueueConfig = {
      maxRetries: this.config.maxRetries!,
      syncInterval: this.config.syncInterval!,
      debug: this.config.debug,
      onSyncSuccess: (operation) => {
        if (this.config.debug) {
          console.log('[Duo] Sync successful:', operation);
        }
        window.dispatchEvent(
          new CustomEvent('duo-synced', { detail: { operation } })
        );
        if (window.Livewire) {
          window.Livewire.dispatch('duo-synced', { operation });
        }
      },
      onSyncError: (operation, error) => {
        console.error('[Duo] Sync failed:', operation, error);
      },
    };

    this.syncQueue = new SyncQueue(this.db, syncConfig);
    this.syncQueue.start();

    // Initialize the Livewire interceptor for offline support
    this.interceptor = new DuoLivewireInterceptor(this.db, this.syncQueue, {
      debug: this.config.debug,
    });
    this.interceptor.initialize();

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

  private generateDatabaseName(): string {
    const origin = window.location.origin;
    const hash = this.simpleHash(origin);
    return `duo_${hash}`;
  }

  private simpleHash(str: string): string {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
      const char = str.charCodeAt(i);
      hash = (hash << 5) - hash + char;
      hash = hash & hash;
    }
    return Math.abs(hash).toString(36);
  }

  getDatabase(): DuoDatabase | undefined {
    return this.db;
  }

  getSyncQueue(): SyncQueue | undefined {
    return this.syncQueue;
  }

  getInterceptor(): DuoLivewireInterceptor | undefined {
    return this.interceptor;
  }

  getServiceWorkerManager(): ServiceWorkerManager | undefined {
    return this.serviceWorkerManager;
  }

  liveQuery<T>(querier: () => T | Promise<T>) {
    return liveQuery(querier);
  }

  async sync(): Promise<void> {
    if (this.config.debug) {
      console.log('[Duo] Manual sync triggered');
    }
  }

  async clearCache(): Promise<void> {
    await this.db?.clearAll();
    await this.serviceWorkerManager?.clearCache();
    if (this.config.debug) {
      console.log('[Duo] All caches cleared');
    }
  }

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

export async function initializeDuo(
  config?: DuoClientConfig
): Promise<DuoClient> {
  if (duoInstance) {
    return duoInstance;
  }

  duoInstance = new DuoClient(config);
  await duoInstance.initialize();

  if (typeof window !== 'undefined') {
    (window as any).duo = duoInstance;
  }

  return duoInstance;
}

export function getDuo(): DuoClient | null {
  return duoInstance;
}

export { DuoDatabase, SyncQueue, DuoLivewireInterceptor };
export type { DuoConfig, DuoRecord, SyncQueueConfig };

// Helper functions for easy data access
export async function getAll(table: string): Promise<any[]> {
  const duo = getDuo();
  if (!duo || !duo.getDatabase()) return [];

  const storeName = `App_Models_${table.charAt(0).toUpperCase() + table.slice(1)}`;
  const store = duo.getDatabase()!.getStore(storeName);

  if (!store) return [];
  return await store.toArray();
}

export async function getById(
  table: string,
  id: number | string
): Promise<any | null> {
  const duo = getDuo();
  if (!duo || !duo.getDatabase()) return null;

  const storeName = `App_Models_${table.charAt(0).toUpperCase() + table.slice(1)}`;
  const store = duo.getDatabase()!.getStore(storeName);

  if (!store) return null;
  return await store.get(id);
}

export async function create(
  table: string,
  data: Record<string, any>
): Promise<any> {
  const duo = getDuo();
  if (!duo || !duo.getDatabase() || !duo.getSyncQueue()) {
    throw new Error('Duo not initialized');
  }

  const storeName = `App_Models_${table.charAt(0).toUpperCase() + table.slice(1)}`;
  const store = duo.getDatabase()!.getStore(storeName);

  if (!store) throw new Error(`Store not found: ${storeName}`);

  const tempId = Date.now();
  const record = {
    ...data,
    id: tempId,
    _duo_pending_sync: true,
    _duo_operation: 'create' as const,
  };

  await store.put(record);

  await duo.getSyncQueue()!.enqueue({
    storeName,
    operation: 'create',
    data: record,
  });

  return record;
}

export async function update(
  table: string,
  id: number | string,
  data: Record<string, any>
): Promise<any> {
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

  await duo.getSyncQueue()!.enqueue({
    storeName,
    operation: 'update',
    data: updated,
  });

  return updated;
}

export async function remove(
  table: string,
  id: number | string
): Promise<void> {
  const duo = getDuo();
  if (!duo || !duo.getDatabase() || !duo.getSyncQueue()) {
    throw new Error('Duo not initialized');
  }

  const storeName = `App_Models_${table.charAt(0).toUpperCase() + table.slice(1)}`;
  const store = duo.getDatabase()!.getStore(storeName);

  if (!store) throw new Error(`Store not found: ${storeName}`);

  const existing = await store.get(id);
  if (!existing) return;

  await store.put({
    ...existing,
    _duo_pending_sync: true,
    _duo_operation: 'delete' as const,
  });

  await duo.getSyncQueue()!.enqueue({
    storeName,
    operation: 'delete',
    data: existing,
  });
}
