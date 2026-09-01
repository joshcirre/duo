import { liveQuery } from 'dexie';
import { registerDuoAlpineComponent } from './alpine/duo-component';
import { DuoDatabase, type DuoConfig, type DuoRecord, type StoreConfig } from './core/database';
import { getClient, markReady, whenReady } from './core/runtime';
import { DuoLivewireInterceptor } from './livewire/duo-interceptor';
import { ServiceWorkerManager, type ServiceWorkerConfig } from './offline/service-worker-manager';
import { ActionQueue, type QueuedAction } from './sync/action-queue';

export interface DuoClientConfig {
  manifest?: Record<string, any>;
  manifestPath?: string;
  debug?: boolean;
  offline?: ServiceWorkerConfig;
  /** @deprecated No longer used — sync goes through Livewire actions. */
  syncInterval?: number;
  /** @deprecated No longer used — sync goes through Livewire actions. */
  maxRetries?: number;
}

// Register the Alpine `duo` scope the moment this module loads. Livewire
// starts Alpine on DOMContentLoaded and immediately walks the DOM, where the
// server has already placed x-data="duo" on compiled components — the scope
// has to be registered before that walk, not after IndexedDB opens.
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  if (window.Alpine) {
    registerDuoAlpineComponent(window.Alpine);
  } else {
    document.addEventListener(
      'alpine:init',
      () => {
        if (window.Alpine) registerDuoAlpineComponent(window.Alpine);
      },
      { once: true }
    );
  }
}

export class DuoClient {
  private db?: DuoDatabase;
  private queue?: ActionQueue;
  private serviceWorkerManager?: ServiceWorkerManager;
  private interceptor?: DuoLivewireInterceptor;
  private config: DuoClientConfig;

  constructor(config: DuoClientConfig = {}) {
    this.config = {
      manifestPath: '/js/duo/manifest.json',
      debug: false,
      ...config,
      offline: {
        enabled: true,
        ...config.offline,
      },
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

    this.queue = new ActionQueue(this.db, {
      storageKey: `duo:actions:${databaseName}`,
      debug: this.config.debug,
    });
    this.queue.listen();
    await this.queue.reconcileOrphans();

    this.interceptor = new DuoLivewireInterceptor(this.db, this.queue, {
      debug: this.config.debug,
    });
    this.interceptor.initialize();

    if (this.config.offline?.enabled !== false) {
      this.serviceWorkerManager = new ServiceWorkerManager(this.config.offline);
      await this.serviceWorkerManager.register();
    }

    markReady(this);
    this.scheduleReplay();

    if (this.config.debug) {
      console.log('[Duo] Client initialized');
      const stats = await this.db.getStats();
      console.log('[Duo] Database stats:', stats);
    }
  }

  /**
   * Replay anything left in the queue once Livewire has initialized the
   * components on this page.
   */
  private scheduleReplay(): void {
    const kick = () => {
      if (navigator.onLine) void this.queue?.replay();
    };

    const components = window.Livewire?.all?.() ?? [];
    if (components.length > 0) {
      kick();
    } else {
      document.addEventListener('livewire:initialized', kick, { once: true });
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
    return `duo_${simpleHash(window.location.origin)}`;
  }

  getDatabase(): DuoDatabase | undefined {
    return this.db;
  }

  getActionQueue(): ActionQueue | undefined {
    return this.queue;
  }

  /** @deprecated Use getActionQueue(). */
  getSyncQueue(): ActionQueue | undefined {
    return this.queue;
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
    await this.queue?.replay();
  }

  async clearCache(): Promise<void> {
    await this.db?.clearAll();
    await this.serviceWorkerManager?.clearCache();
    if (this.config.debug) {
      console.log('[Duo] All caches cleared');
    }
  }

  async destroy(): Promise<void> {
    this.interceptor?.destroy();
    this.queue?.destroy();
    await this.db?.close();
    await this.serviceWorkerManager?.unregister();

    if (this.config.debug) {
      console.log('[Duo] Client destroyed');
    }
  }
}

function simpleHash(str: string): string {
  let hash = 0;
  for (let i = 0; i < str.length; i++) {
    hash = (hash << 5) - hash + str.charCodeAt(i);
    hash = hash & hash;
  }
  return Math.abs(hash).toString(36);
}

let duoInstance: DuoClient | null = null;
let initializing: Promise<DuoClient> | null = null;

export async function initializeDuo(config?: DuoClientConfig): Promise<DuoClient> {
  if (duoInstance) return duoInstance;
  if (initializing) return initializing;

  initializing = (async () => {
    const client = new DuoClient(config);
    await client.initialize();
    duoInstance = client;

    if (typeof window !== 'undefined') {
      (window as any).duo = client;
    }

    return client;
  })();

  return initializing;
}

export function getDuo(): DuoClient | null {
  return duoInstance ?? getClient();
}

export { whenReady as duoReady };
export { DuoDatabase, ActionQueue, DuoLivewireInterceptor, registerDuoAlpineComponent };
export type { DuoConfig, DuoRecord, StoreConfig, QueuedAction };
export type { DuoComponentMeta, DuoEffect, DuoOperation } from './livewire/types';
