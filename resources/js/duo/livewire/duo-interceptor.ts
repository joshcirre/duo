import type { DuoDatabase, DuoRecord } from '../core/database';
import type { SyncQueue } from '../sync/queue';

export interface DuoInterceptorConfig {
  debug?: boolean;
}

interface DuoModelInfo {
  store: string;
  table: string;
  primaryKey: string;
  type?: 'model' | 'collection';
}

interface DuoComponentMeta {
  enabled: boolean;
  component: string;
  models: Record<string, DuoModelInfo>;
  methods: Record<
    string,
    {
      params: Array<{
        name: string;
        type: string | null;
        isModel: boolean;
      }>;
    }
  >;
}

interface DuoEffect {
  enabled: boolean;
  meta: DuoComponentMeta;
  state: Record<string, any[]>;
}

export class DuoLivewireInterceptor {
  private db: DuoDatabase;
  private syncQueue: SyncQueue;
  private debug: boolean;
  private isOnline = navigator.onLine;
  private componentMeta = new Map<string, DuoComponentMeta>();

  constructor(db: DuoDatabase, syncQueue: SyncQueue, config: DuoInterceptorConfig = {}) {
    this.db = db;
    this.syncQueue = syncQueue;
    this.debug = config.debug ?? false;
    this.setupOnlineDetection();
  }

  initialize(): void {
    if (typeof window === 'undefined') return;

    const setup = () => {
      if (typeof window.Livewire === 'undefined') {
        document.addEventListener('livewire:init', () => this.attachHooks());
        return;
      }
      this.attachHooks();
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', setup);
    } else {
      setup();
    }
  }

  private setupOnlineDetection(): void {
    window.addEventListener('online', () => {
      this.isOnline = true;
      if (this.debug) console.log('[Duo] Back online — syncing pending actions');
      window.dispatchEvent(new CustomEvent('duo:online'));
    });

    window.addEventListener('offline', () => {
      this.isOnline = false;
      if (this.debug) console.log('[Duo] Offline — actions will be handled locally');
      window.dispatchEvent(new CustomEvent('duo:offline'));
    });
  }

  private attachHooks(): void {
    if (this.debug) console.log('[Duo] Attaching Livewire interceptors');

    this.discoverDuoComponents();
    this.observeNewComponents();
    this.setupEffectsCaching();
    this.setupActionInterception();
    this.setupOfflineInterception();
    this.hydrateFromCache();
  }

  private discoverDuoComponents(): void {
    const elements = document.querySelectorAll('[data-duo-enabled="true"]');

    elements.forEach((el) => {
      const wireId = el.getAttribute('wire:id');
      const metaStr = el.getAttribute('data-duo-meta');

      if (!wireId || !metaStr) return;

      try {
        const meta: DuoComponentMeta = JSON.parse(metaStr);
        this.componentMeta.set(wireId, meta);

        if (this.debug) {
          console.log(`[Duo] Discovered component: ${meta.component}`, meta);
        }
      } catch (e) {
        console.warn('[Duo] Failed to parse component meta:', e);
      }
    });
  }

  private observeNewComponents(): void {
    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (node instanceof HTMLElement) {
            if (node.hasAttribute('data-duo-enabled')) {
              this.discoverDuoComponents();
            }
            const nested = node.querySelectorAll('[data-duo-enabled="true"]');
            if (nested.length > 0) {
              this.discoverDuoComponents();
            }
          }
        }
      }
    });

    observer.observe(document.body, { childList: true, subtree: true });
  }

  /**
   * Use Livewire 4's interceptMessage to read the `duo` effect from server
   * responses. This is the canonical state channel — the server sends back
   * model data serialized by DuoSynth, and we merge it into IndexedDB.
   */
  private setupEffectsCaching(): void {
    if (!window.Livewire) return;

    if (typeof window.Livewire.interceptMessage === 'function') {
      window.Livewire.interceptMessage(
        ({ message, onSuccess }: any) => {
          onSuccess(({ payload }: any) => {
            const duo: DuoEffect | undefined = payload?.effects?.duo;
            if (duo?.enabled) {
              const component = message?.component;
              this.cacheFromEffect(component, duo);
            }
          });
        }
      );
    } else if (typeof window.Livewire.hook === 'function') {
      window.Livewire.hook('message.received', ({ component }: any) => {
        this.cacheComponentStateLegacy(component);
      });
    }

    if (typeof window.Livewire.hook === 'function') {
      window.Livewire.hook('component.init', ({ component }: any) => {
        const wireId = component.id || component.el?.getAttribute('wire:id');
        if (wireId && this.componentMeta.has(wireId)) {
          this.cacheComponentStateLegacy(component);
        }
      });
    }
  }

  /**
   * Intercept individual Livewire actions to apply them locally when offline.
   *
   * Uses Livewire 4's interceptAction API to catch each action BEFORE it's
   * sent to the server. When offline, the action is cancelled, applied to
   * IndexedDB, and queued for sync. The DOM is updated via $wire.$commit()
   * when back online.
   */
  private setupActionInterception(): void {
    if (!window.Livewire) return;

    if (typeof window.Livewire.interceptAction !== 'function') return;

    window.Livewire.interceptAction(({ action, onSuccess, onFailure }: any) => {
      const component = action.component;
      if (!component) return;

      const wireId = component.id;
      const meta = wireId ? this.componentMeta.get(wireId) : null;
      if (!meta) return;

      if (!this.isOnline) {
        if (this.debug) {
          console.log(`[Duo] Offline — handling action locally: ${action.name}`, action.params);
        }

        const modelEntry = Object.entries(meta.models)[0];
        if (!modelEntry) return;

        const [, modelInfo] = modelEntry;

        this.applyActionLocally(
          modelInfo.store,
          modelInfo.table,
          action.name,
          action.params || [],
          this.extractFormData(component, meta)
        ).then((applied) => {
          if (applied && this.debug) {
            console.log(`[Duo] Action applied locally: ${action.name}`);
          }
        });

        action.cancel();
        return;
      }

      onFailure(() => {
        if (this.debug) {
          console.log(`[Duo] Action failed, applying locally: ${action.name}`);
        }

        const modelEntry = Object.entries(meta.models)[0];
        if (!modelEntry) return;

        const [, modelInfo] = modelEntry;

        this.applyActionLocally(
          modelInfo.store,
          modelInfo.table,
          action.name,
          action.params || [],
          this.extractFormData(component, meta)
        );
      });
    });
  }

  /**
   * Extract form data from a component's current state (for create operations).
   */
  private extractFormData(component: any, meta: DuoComponentMeta): Record<string, any> {
    const data: Record<string, any> = {};

    try {
      const snapshot = component.snapshot;
      if (snapshot?.data) {
        for (const [key, value] of Object.entries(snapshot.data)) {
          if (key.startsWith('_') || key === 'paginators') continue;
          if (meta.models[key]) continue;
          if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
            data[key] = value;
          }
        }
      }
    } catch {
      // Ignore extraction failures
    }

    return data;
  }

  /**
   * Intercept Livewire requests to handle network-level failures.
   */
  private setupOfflineInterception(): void {
    if (!window.Livewire) return;

    if (typeof window.Livewire.interceptRequest === 'function') {
      window.Livewire.interceptRequest(
        ({ request, onFailure }: any) => {
          onFailure(({ error }: any) => {
            if (this.debug) {
              console.log('[Duo] Livewire request failed:', error);
            }

            window.dispatchEvent(
              new CustomEvent('duo:offline-action', {
                detail: {
                  error: error?.message || 'Network request failed',
                  pendingCount: this.syncQueue.getQueueSize(),
                },
              })
            );
          });
        }
      );
    }
  }

  /**
   * Cache state from the DuoSynth effect payload.
   * Uses merge semantics: preserves locally-pending records.
   */
  private async cacheFromEffect(component: any, duo: DuoEffect): Promise<void> {
    const meta = duo.meta;
    const state = duo.state;

    if (component && meta) {
      const wireId = component.id || component.el?.getAttribute('wire:id');
      if (wireId) {
        this.componentMeta.set(wireId, meta);
      }
    }

    for (const [propName, modelInfo] of Object.entries(meta.models)) {
      const data = state[propName];
      if (!data || !Array.isArray(data)) continue;

      await this.mergeServerState(modelInfo, data);
    }
  }

  /**
   * Merge server state into IndexedDB, preserving locally-pending records.
   * Server is source of truth for synced records, but we never overwrite
   * records that are pending local sync.
   */
  private async mergeServerState(
    modelInfo: DuoModelInfo,
    serverData: any[]
  ): Promise<void> {
    const store = this.db.getStore(modelInfo.store);
    if (!store) return;

    try {
      const pending = await store
        .filter((item: DuoRecord) => item._duo_pending_sync === 1 || item._duo_pending_sync === true)
        .toArray();

      const pendingKeys = new Set(
        pending.map((item: DuoRecord) => item[modelInfo.primaryKey])
      );

      const toUpsert = serverData
        .filter((item: any) => !pendingKeys.has(item[modelInfo.primaryKey]))
        .map((item: any) => ({
          ...item,
          _duo_synced_at: Date.now(),
          _duo_pending_sync: 0,
        }));

      const allExisting = await store.toArray();
      const serverKeys = new Set(
        serverData.map((item: any) => item[modelInfo.primaryKey])
      );
      const toDelete = allExisting.filter(
        (item: DuoRecord) =>
          !serverKeys.has(item[modelInfo.primaryKey]) && !pendingKeys.has(item[modelInfo.primaryKey])
      );

      await this.db.transaction('rw', store, async () => {
        if (toDelete.length > 0) {
          await store.bulkDelete(
            toDelete.map((item: DuoRecord) => item[modelInfo.primaryKey])
          );
        }
        if (toUpsert.length > 0) {
          await store.bulkPut(toUpsert);
        }
      });

      if (this.debug) {
        console.log(
          `[Duo] Merged ${toUpsert.length} server records, preserved ${pending.length} pending, removed ${toDelete.length} stale (${modelInfo.store})`
        );
      }
    } catch (e) {
      console.warn('[Duo] Failed to merge server state:', e);
    }
  }

  /**
   * Legacy Livewire 3 caching: tries to read data from component internals.
   */
  private async cacheComponentStateLegacy(component: any): Promise<void> {
    const wireId = component.id || component.el?.getAttribute('wire:id');
    if (!wireId) return;

    const meta = this.componentMeta.get(wireId);
    if (!meta) return;

    for (const [propName, modelInfo] of Object.entries(meta.models)) {
      let data: any;

      if (component.$wire?.$get) {
        data = component.$wire.$get(propName);
      } else if (component.snapshot?.data) {
        data = component.snapshot.data[propName];
      }

      if (!data || !Array.isArray(data)) continue;

      await this.mergeServerState(modelInfo, data);
    }
  }

  /**
   * On page load, hydrate Duo components with data from IndexedDB.
   */
  private async hydrateFromCache(): Promise<void> {
    for (const [wireId, meta] of this.componentMeta) {
      for (const [propName, modelInfo] of Object.entries(meta.models)) {
        const store = this.db.getStore(modelInfo.store);
        if (!store) continue;

        try {
          const cached = await store.toArray();
          if (cached.length === 0) continue;

          const active = cached.filter(
            (item: DuoRecord) =>
              item._duo_operation !== 'delete' || !item._duo_pending_sync
          );

          if (this.debug) {
            console.log(
              `[Duo] Hydrating ${propName} with ${active.length} cached items`
            );
          }

          const component = window.Livewire?.find(wireId);
          if (component?.$wire) {
            component.$wire.$set(propName, active, false);
          }
        } catch (e) {
          if (this.debug) {
            console.warn('[Duo] Failed to hydrate from cache:', e);
          }
        }
      }
    }
  }

  /**
   * Apply a CRUD action locally to IndexedDB.
   * Called when offline or when optimistic updates are desired.
   * Dispatches duo:mutation events for DOM update coordination.
   */
  async applyActionLocally(
    storeName: string,
    table: string,
    method: string,
    params: any[],
    formData: Record<string, any> = {}
  ): Promise<boolean> {
    const store = this.db.getStore(storeName);
    if (!store) return false;

    let result = false;
    let mutationType: string = 'unknown';
    let mutationData: any = null;

    if (
      method.startsWith('add') ||
      method.startsWith('create') ||
      method.startsWith('store')
    ) {
      result = await this.handleCreate(store, storeName, table, formData);
      mutationType = 'create';
      mutationData = formData;
    } else if (method.startsWith('toggle') || method.startsWith('update')) {
      const id = params[0];
      result = await this.handleUpdate(store, storeName, table, id, formData);
      mutationType = 'update';
      mutationData = { id, ...formData };
    } else if (method.startsWith('delete') || method.startsWith('remove')) {
      const id = params[0];
      result = await this.handleDelete(store, storeName, table, id);
      mutationType = 'delete';
      mutationData = { id };
    }

    if (result) {
      window.dispatchEvent(
        new CustomEvent('duo:mutation', {
          detail: {
            store: storeName,
            table,
            type: mutationType,
            method,
            data: mutationData,
            offline: !this.isOnline,
          },
        })
      );
    }

    return result;
  }

  private async handleCreate(
    store: any,
    storeName: string,
    table: string,
    formData: Record<string, any>
  ): Promise<boolean> {
    const tempId = Date.now();
    const record: DuoRecord = {
      id: tempId,
      ...formData,
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      _duo_pending_sync: 1,
      _duo_operation: 'create',
    };

    await store.put(record);

    await this.syncQueue.enqueue({
      storeName,
      operation: 'create',
      data: record,
    });

    if (this.debug) console.log('[Duo] Created locally:', record);
    return true;
  }

  private async handleUpdate(
    store: any,
    storeName: string,
    table: string,
    id: any,
    formData: Record<string, any>
  ): Promise<boolean> {
    const existing = await store.get(id);
    if (!existing) return false;

    const isBooleanToggle =
      Object.keys(formData).length === 0 ||
      Object.values(formData).some((v) => typeof v === 'boolean');

    const updated: DuoRecord = {
      ...existing,
      ...(Object.keys(formData).length > 0
        ? formData
        : { completed: !existing.completed }),
      updated_at: new Date().toISOString(),
      _duo_pending_sync: 1,
      _duo_operation: 'update',
    };

    await store.put(updated);

    await this.syncQueue.enqueue({
      storeName,
      operation: 'update',
      data: updated,
    });

    if (this.debug) console.log('[Duo] Updated locally:', id);
    return true;
  }

  private async handleDelete(
    store: any,
    storeName: string,
    table: string,
    id: any
  ): Promise<boolean> {
    const existing = await store.get(id);
    if (!existing) return false;

    await store.delete(id);

    await this.syncQueue.enqueue({
      storeName,
      operation: 'delete',
      data: existing,
    });

    if (this.debug) console.log('[Duo] Deleted locally:', id);
    return true;
  }

  getComponentMeta(): Map<string, DuoComponentMeta> {
    return this.componentMeta;
  }

  isNetworkOnline(): boolean {
    return this.isOnline;
  }
}

declare global {
  interface Window {
    Livewire: any;
  }
}
