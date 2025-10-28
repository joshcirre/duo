import type { DuoDatabase, DuoRecord } from '../core/database';

export interface SyncOperation {
  id: string;
  storeName: string;
  operation: 'create' | 'update' | 'delete';
  data: DuoRecord;
  timestamp: number;
  retryCount: number;
  endpoint?: string;
}

export interface SyncQueueConfig {
  maxRetries: number;
  syncInterval: number;
  onSyncSuccess?: (operation: SyncOperation) => void;
  onSyncError?: (operation: SyncOperation, error: Error) => void;
  debug?: boolean;
}

/**
 * Sync Queue - Manages write-behind synchronization to the server
 */
export class SyncQueue {
  private db: DuoDatabase;
  private config: SyncQueueConfig;
  private queue: SyncOperation[] = [];
  private isProcessing = false;
  private intervalId?: number;

  constructor(db: DuoDatabase, config: SyncQueueConfig) {
    this.db = db;
    this.config = config;
  }

  /**
   * Start the sync queue processor
   */
  start(): void {
    if (this.intervalId) {
      return;
    }

    this.loadPendingOperations();

    if (this.config.syncInterval > 0) {
      this.intervalId = window.setInterval(() => {
        this.processQueue();
      }, this.config.syncInterval);
    }

    if (this.config.debug) {
      console.log('[Duo] Sync queue started');
    }
  }

  /**
   * Stop the sync queue processor
   */
  stop(): void {
    if (this.intervalId) {
      clearInterval(this.intervalId);
      this.intervalId = undefined;

      if (this.config.debug) {
        console.log('[Duo] Sync queue stopped');
      }
    }
  }

  /**
   * Add an operation to the sync queue
   */
  async enqueue(operation: Omit<SyncOperation, 'id' | 'timestamp' | 'retryCount'>): Promise<void> {
    const syncOp: SyncOperation = {
      ...operation,
      id: this.generateId(),
      timestamp: Date.now(),
      retryCount: 0,
    };

    this.queue.push(syncOp);

    // Mark the record as pending sync in IndexedDB
    const store = this.db.getStore(operation.storeName);
    if (store) {
      await store.update(operation.data, {
        _duo_pending_sync: true,
        _duo_operation: operation.operation,
      });
    }

    if (this.config.debug) {
      console.log('[Duo] Operation enqueued:', syncOp);
    }

    // Trigger immediate processing if not already processing
    if (!this.isProcessing) {
      this.processQueue();
    }
  }

  /**
   * Process the sync queue
   */
  private async processQueue(): Promise<void> {
    if (this.isProcessing || this.queue.length === 0) {
      return;
    }

    this.isProcessing = true;

    try {
      const operations = [...this.queue];

      for (const operation of operations) {
        try {
          await this.syncOperation(operation);
          this.removeFromQueue(operation.id);
          this.config.onSyncSuccess?.(operation);

          // Remove pending flag from IndexedDB
          const store = this.db.getStore(operation.storeName);
          if (store && operation.operation !== 'delete') {
            await store.update(operation.data, {
              _duo_pending_sync: false,
              _duo_synced_at: Date.now(),
            });
          }
        } catch (error) {
          this.handleSyncError(operation, error as Error);
        }
      }
    } finally {
      this.isProcessing = false;
    }
  }

  /**
   * Sync a single operation to the server
   */
  private async syncOperation(operation: SyncOperation): Promise<void> {
    const storeConfig = this.db.getStoreConfig(operation.storeName);
    if (!storeConfig) {
      throw new Error(`Store config not found for: ${operation.storeName}`);
    }

    // Use endpoints from manifest if available, otherwise fallback to default
    const endpoint = operation.endpoint || this.getEndpointFromConfig(storeConfig, operation);
    const method = this.getHttpMethod(operation.operation);

    const response = await fetch(endpoint, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': this.getCsrfToken(),
      },
      body: operation.operation !== 'delete' ? JSON.stringify(operation.data) : undefined,
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    // Handle server response for CREATE operations
    // Server returns the record with the real ID
    if (operation.operation === 'create') {
      const serverData = await response.json();
      const store = this.db.getStore(operation.storeName);

      if (store && serverData) {
        const primaryKey = storeConfig.primaryKey || 'id';
        const tempId = operation.data[primaryKey];
        const realId = serverData[primaryKey];

        if (this.config.debug) {
          console.log(`[Duo] Replacing temp ID ${tempId} with server ID ${realId}`);
        }

        // Delete the temporary record
        await store.delete(tempId);

        // Add the server record (without pending flag)
        await store.put({
          ...serverData,
          _duo_pending_sync: false,
          _duo_synced_at: Date.now(),
        });
      }
    }

    if (this.config.debug) {
      console.log('[Duo] Operation synced:', operation);
    }
  }

  /**
   * Handle sync errors with retry logic
   */
  private handleSyncError(operation: SyncOperation, error: Error): void {
    operation.retryCount++;

    if (operation.retryCount >= this.config.maxRetries) {
      console.error('[Duo] Max retries reached for operation:', operation, error);
      this.removeFromQueue(operation.id);
      this.config.onSyncError?.(operation, error);
    } else {
      if (this.config.debug) {
        console.warn(
          `[Duo] Sync failed (retry ${operation.retryCount}/${this.config.maxRetries}):`,
          error
        );
      }
    }
  }

  /**
   * Load pending operations from IndexedDB on startup
   */
  private async loadPendingOperations(): Promise<void> {
    for (const [storeName, store] of this.db.getAllStores()) {
      const pending = await store.where('_duo_pending_sync').equals(1).toArray();

      for (const record of pending) {
        this.queue.push({
          id: this.generateId(),
          storeName,
          operation: record._duo_operation || 'update',
          data: record,
          timestamp: record._duo_synced_at || Date.now(),
          retryCount: 0,
        });
      }
    }

    if (this.config.debug && this.queue.length > 0) {
      console.log(`[Duo] Loaded ${this.queue.length} pending operation(s)`);
    }
  }

  /**
   * Remove an operation from the queue
   */
  private removeFromQueue(id: string): void {
    this.queue = this.queue.filter((op) => op.id !== id);
  }

  /**
   * Get endpoint from store config or fallback to default
   */
  private getEndpointFromConfig(storeConfig: any, operation: SyncOperation): string {
    // Use endpoints from manifest if available
    if (storeConfig.endpoints) {
      const id = operation.data[storeConfig.primaryKey || 'id'];

      switch (operation.operation) {
        case 'create':
          return storeConfig.endpoints.store;
        case 'update':
          return storeConfig.endpoints.update.replace('{id}', id);
        case 'delete':
          return storeConfig.endpoints.destroy.replace('{id}', id);
        default:
          return storeConfig.endpoints.index;
      }
    }

    // Fallback to default endpoint building
    return this.getDefaultEndpoint(storeConfig.table, operation);
  }

  /**
   * Get the default API endpoint for a table
   */
  private getDefaultEndpoint(table: string, operation: SyncOperation): string {
    const base = `/api/duo/${table}`;

    switch (operation.operation) {
      case 'create':
        return base;
      case 'update':
      case 'delete':
        const id = operation.data[this.db.getStoreConfig(operation.storeName)?.primaryKey || 'id'];
        return `${base}/${id}`;
      default:
        return base;
    }
  }

  /**
   * Get HTTP method for operation type
   */
  private getHttpMethod(operation: SyncOperation['operation']): string {
    switch (operation) {
      case 'create':
        return 'POST';
      case 'update':
        return 'PUT';
      case 'delete':
        return 'DELETE';
      default:
        return 'POST';
    }
  }

  /**
   * Get CSRF token from meta tag
   */
  private getCsrfToken(): string {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    return token || '';
  }

  /**
   * Generate a unique ID for operations
   */
  private generateId(): string {
    return `${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;
  }

  /**
   * Get current queue size
   */
  getQueueSize(): number {
    return this.queue.length;
  }

  /**
   * Get all pending operations
   */
  getPendingOperations(): SyncOperation[] {
    return [...this.queue];
  }
}
