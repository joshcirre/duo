import type { DuoDatabase, DuoRecord } from '../core/database';
import type { ActionQueue } from '../sync/action-queue';
import { consumeExpectedMutation, takeExpectedCreate } from '../sync/pending';
import { isManagedAction } from './managed';
import type { DuoComponentMeta, DuoEffect, DuoModelInfo, DuoOperation } from './types';
import { parseComponentMeta } from './types';

export interface DuoInterceptorConfig {
  debug?: boolean;
}

/**
 * Bridges Livewire's request lifecycle and IndexedDB.
 *
 * - Every response from a WithDuo component carries a `duo` effect (built by
 *   DuoSynth) with the component's model state and the exact Eloquent
 *   operations that ran. That effect is the only server → client channel.
 * - Actions fired by plain wire:* directives on WithDuo components that were
 *   not compiled by the Blade precompiler are queued for replay when the
 *   browser is offline or the request fails.
 */
export class DuoLivewireInterceptor {
  private componentMeta = new Map<string, DuoComponentMeta>();
  private observer?: MutationObserver;
  private debug: boolean;

  constructor(
    private db: DuoDatabase,
    private queue: ActionQueue,
    config: DuoInterceptorConfig = {}
  ) {
    this.debug = config.debug ?? false;
  }

  initialize(): void {
    if (typeof window === 'undefined') return;

    if (typeof window.Livewire?.interceptMessage === 'function') {
      this.attachHooks();
      return;
    }

    document.addEventListener('livewire:init', () => this.attachHooks(), { once: true });
  }

  destroy(): void {
    this.observer?.disconnect();
    this.observer = undefined;
  }

  private attachHooks(): void {
    if (typeof window.Livewire?.interceptMessage !== 'function') {
      console.error(
        '[Duo] Livewire 4 is required — window.Livewire.interceptMessage is not available. ' +
          'Upgrade to livewire/livewire ^4.0.'
      );
      return;
    }

    if (this.debug) console.log('[Duo] Attaching Livewire interceptors');

    this.discoverDuoComponents();
    this.observeNewComponents();
    this.setupEffectsCaching();
    this.setupActionInterception();

    // Components that initialized before we attached still carry their
    // initial `duo` effect on the instance.
    for (const component of window.Livewire.all?.() ?? []) {
      this.cacheInitialEffect(component);
    }
  }

  private discoverDuoComponents(): void {
    document.querySelectorAll('[data-duo-enabled="true"]').forEach((el) => {
      const wireId = el.getAttribute('wire:id');
      const meta = parseComponentMeta(el);
      if (!wireId || !meta) return;

      if (!this.componentMeta.has(wireId) && this.debug) {
        console.log(`[Duo] Discovered component: ${meta.component}`, meta);
      }

      this.componentMeta.set(wireId, meta);
    });
  }

  private observeNewComponents(): void {
    if (this.observer) return;

    this.observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (!(node instanceof HTMLElement)) continue;
          if (node.hasAttribute('data-duo-enabled') || node.querySelector('[data-duo-enabled="true"]')) {
            this.discoverDuoComponents();
            return;
          }
        }
      }
    });

    this.observer.observe(document.body, { childList: true, subtree: true });
  }

  /**
   * Read the `duo` effect from every Livewire response and merge it into
   * IndexedDB. On first render the same effect is inscribed on the element
   * (wire:effects), which component.init exposes.
   */
  private setupEffectsCaching(): void {
    window.Livewire.interceptMessage(({ message, onSuccess }: any) => {
      onSuccess(({ payload }: any) => {
        const duo: DuoEffect | undefined = payload?.effects?.duo;
        if (duo?.enabled) {
          void this.cacheFromEffect(message?.component, duo);
        }
      });
    });

    window.Livewire.hook('component.init', ({ component }: any) => {
      this.cacheInitialEffect(component);
    });
  }

  private cacheInitialEffect(component: any): void {
    const duo: DuoEffect | undefined = component?.effects?.duo;
    if (duo?.enabled) {
      void this.cacheFromEffect(component, duo);
    }
  }

  /**
   * Queue actions from uncompiled WithDuo components when they cannot reach
   * the server. Actions Duo fires itself are already tracked by the queue.
   */
  private setupActionInterception(): void {
    window.Livewire.interceptAction(({ action, onFailure }: any) => {
      if (isManagedAction()) return;

      const component = action?.component;
      if (!component) return;

      const meta = this.componentMeta.get(component.id);
      if (!meta) return;

      if (typeof action.name !== 'string' || action.name.startsWith('$')) return;

      const snapshot = () => ({
        component: meta.component,
        componentId: component.id as string,
        method: action.name as string,
        params: (action.params ?? []) as any[],
        updates: dirtyUpdates(component),
        status: 'queued' as const,
      });

      if (!navigator.onLine) {
        if (this.debug) console.log(`[Duo] Offline — queuing action: ${action.name}`, action.params);
        this.queue.add(snapshot());
        action.cancel();
        return;
      }

      onFailure(() => {
        if (this.debug) console.log(`[Duo] Request failed — queuing action: ${action.name}`);
        this.queue.add(snapshot());
      });
    });
  }

  /**
   * Apply a `duo` effect: first the operations the server reports (exact),
   * then a merge of the component's current model state (authoritative for
   * everything not pending locally).
   */
  private async cacheFromEffect(component: any, duo: DuoEffect): Promise<void> {
    const meta = duo.meta;
    if (!meta) return;

    const wireId = component?.id ?? component?.el?.getAttribute?.('wire:id');
    if (wireId) {
      this.componentMeta.set(wireId, meta);
    }

    if (duo.operations && duo.operations.length > 0) {
      await this.applyServerOperations(duo.operations);
    }

    for (const [property, modelInfo] of Object.entries(meta.models)) {
      const data = duo.state?.[property];
      if (!Array.isArray(data)) continue;

      await this.mergeServerState(modelInfo, data);
    }
  }

  /**
   * Operations captured by the server's DuoOperationCollector. A create
   * replaces the optimistic temp record that was registered for it.
   *
   * Expectations are consumed synchronously, before any IndexedDB work, so
   * that by the time Livewire resolves the action promise the queue can tell
   * which of its expected operations the server actually reported.
   */
  private async applyServerOperations(operations: DuoOperation[]): Promise<void> {
    const planned = operations.map((op) => ({
      op,
      tempKey: op.type === 'create' ? takeExpectedCreate(op.store) : undefined,
      expected: op.type === 'create' ? true : consumeExpectedMutation(op.type, op.store, op.key),
    }));

    for (const { op, tempKey } of planned) {
      const store = this.db.getStore(op.store);
      if (!store) continue;

      try {
        switch (op.type) {
          case 'create': {
            await this.db.transaction('rw', store, async () => {
              if (tempKey !== undefined && tempKey !== op.key) {
                await store.delete(tempKey);
              }
              await store.put({ ...op.data, _duo_synced_at: Date.now(), _duo_pending_sync: 0 });
            });
            break;
          }
          case 'update':
            await store.put({ ...op.data, _duo_synced_at: Date.now(), _duo_pending_sync: 0 });
            break;
          case 'delete':
            await store.delete(op.key);
            break;
        }

        window.dispatchEvent(
          new CustomEvent('duo:mutation', {
            detail: { store: op.store, table: op.table, type: op.type, data: op.data, offline: false },
          })
        );

        if (this.debug) {
          console.log(`[Duo] Applied server operation: ${op.type} on ${op.store}`, op.data);
        }
      } catch (e) {
        console.warn('[Duo] Failed to apply server operation:', e);
      }
    }
  }

  /**
   * Server is the source of truth for synced records; records pending a
   * local sync are never overwritten or removed here.
   */
  private async mergeServerState(modelInfo: DuoModelInfo, serverData: any[]): Promise<void> {
    const store = this.db.getStore(modelInfo.store);
    if (!store) return;

    const pk = modelInfo.primaryKey;

    try {
      const existing = await store.toArray();
      const pendingKeys = new Set(
        existing.filter((row: DuoRecord) => !!row._duo_pending_sync).map((row: DuoRecord) => row[pk])
      );
      const serverKeys = new Set(serverData.map((row: any) => row[pk]));

      const toUpsert = serverData
        .filter((row: any) => !pendingKeys.has(row[pk]))
        .map((row: any) => ({ ...row, _duo_synced_at: Date.now(), _duo_pending_sync: 0 }));

      const toDelete = existing
        .filter((row: DuoRecord) => !serverKeys.has(row[pk]) && !pendingKeys.has(row[pk]))
        .map((row: DuoRecord) => row[pk]);

      await this.db.transaction('rw', store, async () => {
        if (toDelete.length > 0) await store.bulkDelete(toDelete);
        if (toUpsert.length > 0) await store.bulkPut(toUpsert);
      });

      if (this.debug) {
        console.log(
          `[Duo] Merged ${toUpsert.length} server records, preserved ${pendingKeys.size} pending, removed ${toDelete.length} stale (${modelInfo.store})`
        );
      }
    } catch (e) {
      console.warn('[Duo] Failed to merge server state:', e);
    }
  }

  getComponentMeta(): Map<string, DuoComponentMeta> {
    return this.componentMeta;
  }
}

/** Shallow diff of a component's unsent property changes. */
function dirtyUpdates(component: any): Record<string, any> {
  const updates: Record<string, any> = {};
  const canonical = component?.canonical ?? {};
  const ephemeral = component?.ephemeral ?? {};

  for (const key of Object.keys(ephemeral)) {
    if (JSON.stringify(canonical[key]) !== JSON.stringify(ephemeral[key])) {
      updates[key] = ephemeral[key];
    }
  }

  return updates;
}

declare global {
  interface Window {
    Livewire: any;
    Alpine: any;
  }
}
