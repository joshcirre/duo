import { DuoDatabase, type DuoConfig, type DuoRecord } from './core/database';
import { SyncQueue, type SyncQueueConfig } from './sync/queue';
import { LivewireIntegration, type LivewireIntegrationConfig } from './livewire/integration';

export interface DuoClientConfig {
  manifest?: Record<string, any>;
  manifestPath?: string;
  syncInterval?: number;
  maxRetries?: number;
  debug?: boolean;
  livewire?: LivewireIntegrationConfig;
}

/**
 * Main Duo Client
 */
export class DuoClient {
  private db?: DuoDatabase;
  private syncQueue?: SyncQueue;
  private livewireIntegration?: LivewireIntegration;
  private config: DuoClientConfig;

  constructor(config: DuoClientConfig = {}) {
    this.config = {
      manifestPath: '/js/duo/manifest.json',
      syncInterval: 5000,
      maxRetries: 3,
      debug: false,
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

    // Initialize Livewire integration
    this.livewireIntegration = new LivewireIntegration(
      this.db,
      this.syncQueue,
      this.config.livewire
    );
    this.livewireIntegration.initialize();

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
   * Manually trigger a sync
   */
  async sync(): Promise<void> {
    // This will be called by the sync queue automatically
    if (this.config.debug) {
      console.log('[Duo] Manual sync triggered');
    }
  }

  /**
   * Clear all cached data
   */
  async clearCache(): Promise<void> {
    await this.db?.clearAll();
    if (this.config.debug) {
      console.log('[Duo] Cache cleared');
    }
  }

  /**
   * Destroy the client and cleanup
   */
  async destroy(): Promise<void> {
    this.syncQueue?.stop();
    this.livewireIntegration?.destroy();
    await this.db?.close();

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

  return duoInstance;
}

/**
 * Get the global Duo instance
 */
export function getDuo(): DuoClient | null {
  return duoInstance;
}

// Re-export types and classes
export { DuoDatabase, SyncQueue, LivewireIntegration };
export type { DuoConfig, DuoRecord, SyncQueueConfig, LivewireIntegrationConfig };
