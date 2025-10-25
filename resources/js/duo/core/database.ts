import Dexie, { type Table } from 'dexie';

export interface DuoConfig {
  databaseName: string;
  databaseVersion: number;
  stores: Record<string, StoreConfig>;
  debug?: boolean;
}

export interface StoreConfig {
  model: string;
  table: string;
  primaryKey: string;
  indexes: string[];
  timestamps: boolean;
}

export interface DuoRecord {
  [key: string]: any;
  _duo_synced_at?: number;
  _duo_version?: number;
  _duo_pending_sync?: boolean;
  _duo_operation?: 'create' | 'update' | 'delete';
}

/**
 * Duo Database - IndexedDB wrapper using Dexie
 */
export class DuoDatabase extends Dexie {
  private config: DuoConfig;
  private stores: Map<string, Table<DuoRecord, any>> = new Map();

  constructor(config: DuoConfig) {
    super(config.databaseName);
    this.config = config;
    this.initializeStores();
  }

  /**
   * Initialize IndexedDB stores from the manifest
   */
  private initializeStores(): void {
    const schema: Record<string, string> = {};

    for (const [storeName, storeConfig] of Object.entries(this.config.stores)) {
      // Create Dexie schema string with primary key and indexes
      const indexes = [
        `++${storeConfig.primaryKey}`,
        ...storeConfig.indexes.filter((idx) => idx !== storeConfig.primaryKey),
        '_duo_synced_at',
        '_duo_pending_sync',
      ].join(',');

      schema[storeName] = indexes;
    }

    this.version(this.config.databaseVersion).stores(schema);

    // Now populate the stores map AFTER defining the schema
    for (const storeName of Object.keys(this.config.stores)) {
      this.stores.set(storeName, this.table(storeName));
    }

    if (this.config.debug) {
      console.log('[Duo] Database initialized with stores:', Object.keys(schema));
    }
  }

  /**
   * Get a store by name
   */
  getStore(storeName: string): Table<DuoRecord, any> | undefined {
    return this.stores.get(storeName);
  }

  /**
   * Get all stores
   */
  getAllStores(): Map<string, Table<DuoRecord, any>> {
    return this.stores;
  }

  /**
   * Get store config
   */
  getStoreConfig(storeName: string): StoreConfig | undefined {
    return this.config.stores[storeName];
  }

  /**
   * Clear all data from a store
   */
  async clearStore(storeName: string): Promise<void> {
    const store = this.getStore(storeName);
    if (store) {
      await store.clear();
      if (this.config.debug) {
        console.log(`[Duo] Cleared store: ${storeName}`);
      }
    }
  }

  /**
   * Clear all stores
   */
  async clearAll(): Promise<void> {
    for (const storeName of this.stores.keys()) {
      await this.clearStore(storeName);
    }
  }

  /**
   * Get statistics about the database
   */
  async getStats(): Promise<Record<string, number>> {
    const stats: Record<string, number> = {};

    for (const [storeName, store] of this.stores) {
      stats[storeName] = await store.count();
    }

    return stats;
  }
}
