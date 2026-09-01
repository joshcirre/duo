import type { DuoDatabase, DuoRecord } from '../core/database';
import { withManagedAction } from '../livewire/managed';
import { type ExpectedOp, expectOperation, isExpected, withdrawExpectation } from './pending';

export interface RollbackRef {
  store: string;
  key: any;
  /** The record as it was before the optimistic change; null if it did not exist. */
  record: DuoRecord | null;
}

export type QueuedActionStatus = 'inflight' | 'queued';

/**
 * A Livewire action Duo is responsible for delivering.
 *
 * `inflight` entries have been handed to Livewire and are awaiting a response.
 * `queued` entries could not be sent (offline or network failure) and are
 * replayed when connectivity returns.
 */
export interface QueuedAction {
  id: string;
  component: string;
  componentId: string | null;
  method: string;
  params: any[];
  /** Component properties to set (deferred) before calling the method. */
  updates: Record<string, any>;
  /** Operations the server must report for this action to count as done. */
  expects: ExpectedOp[];
  /** Prior state to restore if the server does not perform the action. */
  rollback: RollbackRef[];
  status: QueuedActionStatus;
  session: string;
  createdAt: number;
  attempts: number;
}

export type NewQueuedAction = Pick<QueuedAction, 'component' | 'componentId' | 'method' | 'params'> &
  Partial<Pick<QueuedAction, 'updates' | 'expects' | 'rollback' | 'status'>>;

export type FireResult =
  | { ok: true }
  | { ok: false; network: true; error: unknown }
  | { ok: false; network: false; error: unknown; unperformed: ExpectedOp[] };

export interface ActionQueueConfig {
  storageKey: string;
  debug?: boolean;
  /** Override for tests; defaults to window.localStorage. */
  storage?: Storage | null;
}

const SESSION = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;

/**
 * Persistent queue of Livewire actions.
 *
 * Instead of a bespoke REST sync layer, Duo replays the author's own Livewire
 * methods — so validation, authorization, and events run exactly as written.
 * The queue lives in localStorage so it survives reloads without touching the
 * IndexedDB schema.
 */
export class ActionQueue {
  private entries: QueuedAction[] = [];
  private replaying = false;
  private listeners = new Set<() => void>();
  private storage: Storage | null;
  private onlineHandler = () => {
    void this.replay();
  };

  constructor(
    private db: DuoDatabase,
    private config: ActionQueueConfig
  ) {
    this.storage = config.storage === undefined ? resolveStorage() : config.storage;
    this.entries = this.load();

    // Anything a previous page left "inflight" never got a response we saw.
    // Replaying risks a duplicate; dropping risks losing the user's work.
    for (const entry of this.entries) {
      if (entry.status === 'inflight' && entry.session !== SESSION) {
        entry.status = 'queued';
      }
    }
    this.persist();
  }

  listen(): void {
    window.addEventListener('online', this.onlineHandler);
  }

  destroy(): void {
    window.removeEventListener('online', this.onlineHandler);
    this.listeners.clear();
  }

  add(partial: NewQueuedAction): QueuedAction {
    const entry: QueuedAction = {
      id: `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 9)}`,
      component: partial.component,
      componentId: partial.componentId ?? null,
      method: partial.method,
      params: partial.params ?? [],
      updates: partial.updates ?? {},
      expects: partial.expects ?? [],
      rollback: partial.rollback ?? [],
      status: partial.status ?? 'inflight',
      session: SESSION,
      createdAt: Date.now(),
      attempts: 0,
    };

    this.entries.push(entry);
    this.persist();

    if (this.config.debug) {
      console.log(`[Duo] Action ${entry.status}: ${entry.component}.${entry.method}`, entry);
    }

    return entry;
  }

  mark(id: string, status: QueuedActionStatus): void {
    const entry = this.entries.find((e) => e.id === id);
    if (!entry) return;
    entry.status = status;
    this.persist();
  }

  remove(id: string): void {
    const before = this.entries.length;
    this.entries = this.entries.filter((e) => e.id !== id);
    if (this.entries.length !== before) this.persist();
  }

  /**
   * Drop the queued action that created a temp record — used when the user
   * deletes something that only ever existed locally.
   */
  removeByTemp(store: string, key: any): boolean {
    const entry = this.entries.find((e) =>
      e.expects.some((op) => op.type === 'create' && op.store === store && op.key === key)
    );
    if (!entry) return false;
    this.remove(entry.id);
    return true;
  }

  all(): QueuedAction[] {
    return [...this.entries];
  }

  get size(): number {
    return this.entries.length;
  }

  get pendingCount(): number {
    return this.entries.filter((e) => e.status === 'queued').length;
  }

  /** Compatibility shim for code written against the old SyncQueue. */
  getSyncStatus(): { isOnline: boolean; pendingCount: number; isProcessing: boolean } {
    return {
      isOnline: typeof navigator !== 'undefined' ? navigator.onLine : true,
      pendingCount: this.size,
      isProcessing: this.replaying,
    };
  }

  onChange(listener: () => void): () => void {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  /**
   * Send an action through Livewire via the component's $wire proxy.
   *
   * The server's operations arrive through the `duo` effect and reconcile
   * IndexedDB; the interceptor consumes the expectations registered here as
   * it applies them. After the action settles, any expectation still standing
   * means the server did not perform it (validation failed, a policy denied
   * it, the method took another branch), so the optimistic change is rolled
   * back — regardless of whether Livewire resolved or rejected the promise.
   * A network failure leaves the entry queued for replay.
   */
  async fire(entry: QueuedAction, wire: any): Promise<FireResult> {
    entry.status = 'inflight';
    entry.session = SESSION;
    entry.attempts++;
    this.persist();

    for (const op of entry.expects) {
      expectOperation(op);
    }

    let error: unknown = null;

    try {
      for (const [property, value] of Object.entries(entry.updates)) {
        wire.$set(property, value, false);
      }

      await withManagedAction(() => wire[entry.method](...entry.params));
    } catch (e) {
      error = e ?? new Error('Livewire action failed');
    }

    // Livewire rejects with { status: null } when the request never reached
    // the server (offline, aborted) and with a numeric status when it did.
    // Anything else is a bug on our side, not a verdict from the server.
    const isLivewireRejection = error !== null && typeof error === 'object' && 'status' in (error as object);
    const status = isLivewireRejection ? (error as any).status : undefined;
    const networkFailure = isLivewireRejection && status === null;
    const unexpectedError = error !== null && !isLivewireRejection;

    if (networkFailure || unexpectedError) {
      for (const op of entry.expects) withdrawExpectation(op);

      entry.status = 'queued';
      this.persist();

      if (unexpectedError) {
        console.error(`[Duo] Failed to fire ${entry.component}.${entry.method}; left queued`, error);
      } else if (this.config.debug) {
        console.log(`[Duo] Network failure — queued: ${entry.component}.${entry.method}`);
      }

      return { ok: false, network: true, error };
    }

    const unperformed = entry.expects.filter((op) => isExpected(op));
    for (const op of unperformed) withdrawExpectation(op);

    if (error === null && unperformed.length === 0) {
      this.remove(entry.id);

      window.dispatchEvent(new CustomEvent('duo-synced', { detail: { action: entry } }));

      if (this.config.debug) {
        console.log(`[Duo] Action delivered: ${entry.component}.${entry.method}`);
      }

      return { ok: true };
    }

    if (this.config.debug) {
      const why = error !== null ? `rejected with status ${status}` : 'did not report the expected operation';
      console.warn(`[Duo] Server ${why} for ${entry.component}.${entry.method} — rolling back`, error ?? unperformed);
    }

    await this.rollback(entry);
    this.remove(entry.id);

    return { ok: false, network: false, error: error ?? { status: 422, unperformed }, unperformed };
  }

  /**
   * Undo the optimistic changes for an entry. Records the server has already
   * reconciled (no longer pending locally) are left alone.
   */
  async rollback(entry: QueuedAction): Promise<void> {
    for (const op of entry.expects) {
      if (op.type !== 'create') continue;
      const store = this.db.getStore(op.store);
      if (!store) continue;

      const current = await store.get(op.key);
      if (current && current._duo_pending_sync) {
        await store.delete(op.key);
      }
    }

    for (const ref of entry.rollback) {
      const store = this.db.getStore(ref.store);
      if (!store) continue;

      const current = await store.get(ref.key);
      if (current && !current._duo_pending_sync) continue;

      if (ref.record) {
        await store.put(ref.record);
      } else {
        await store.delete(ref.key);
      }
    }
  }

  /**
   * Clear local pending markers no queued action accounts for — debris from a
   * crash between the optimistic write and the queue write, or from an older
   * Duo build. Orphaned temp creates are removed; orphaned pending updates and
   * deletes are un-flagged so the next server state merge reconciles them.
   */
  async reconcileOrphans(): Promise<number> {
    const tracked = new Set<string>();
    for (const entry of this.entries) {
      for (const op of entry.expects) tracked.add(`${op.store}|${String(op.key)}`);
    }

    let cleaned = 0;

    for (const [storeName, store] of this.db.getAllStores()) {
      const pk = this.db.getStoreConfig(storeName)?.primaryKey ?? 'id';
      const rows = await store.toArray();

      for (const row of rows) {
        if (!row._duo_pending_sync) continue;
        if (tracked.has(`${storeName}|${String(row[pk])}`)) continue;

        if (row._duo_operation === 'create') {
          await store.delete(row[pk]);
        } else {
          const { _duo_operation, ...rest } = row;
          await store.put({ ...rest, _duo_pending_sync: 0 });
        }
        cleaned++;
      }
    }

    if (cleaned > 0 && this.config.debug) {
      console.log(`[Duo] Reconciled ${cleaned} orphaned pending record(s)`);
    }

    return cleaned;
  }

  /**
   * Replay queued actions against the live Livewire components on the page.
   * Stops at the first network failure (still offline). Entries whose
   * component is not on the current page stay queued until it is.
   */
  async replay(componentName?: string): Promise<void> {
    if (this.replaying) return;
    if (typeof navigator !== 'undefined' && !navigator.onLine) return;

    this.replaying = true;
    this.emit();

    try {
      for (const entry of [...this.entries]) {
        if (entry.status !== 'queued') continue;
        if (componentName && entry.component !== componentName) continue;

        const wire = this.findWire(entry);
        if (!wire) continue;

        const result = await this.fire(entry, wire);
        if (!result.ok && result.network) break;
      }
    } finally {
      this.replaying = false;
      this.emit();
    }
  }

  /**
   * Locate the $wire proxy for an entry's component. Livewire.find() and
   * Livewire.getByName() both return $wire proxies (not Component objects).
   * An id from a previous page load will not resolve; fall back to the first
   * component on the page with the same name.
   */
  private findWire(entry: QueuedAction): any | null {
    const Livewire = (window as any).Livewire;
    if (!Livewire) return null;

    if (entry.componentId) {
      try {
        const wire = Livewire.find(entry.componentId);
        if (wire) return wire;
      } catch {
        // fall through to lookup by name
      }
    }

    const byName = Livewire.getByName?.(entry.component);
    return byName?.[0] ?? null;
  }

  private load(): QueuedAction[] {
    if (!this.storage) return [];

    try {
      const raw = this.storage.getItem(this.config.storageKey);
      const parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  }

  private persist(): void {
    if (this.storage) {
      try {
        this.storage.setItem(this.config.storageKey, JSON.stringify(this.entries));
      } catch (e) {
        console.warn('[Duo] Failed to persist action queue:', e);
      }
    }

    this.emit();
  }

  private emit(): void {
    for (const listener of this.listeners) listener();
  }
}

function resolveStorage(): Storage | null {
  try {
    return typeof window !== 'undefined' ? window.localStorage : null;
  } catch {
    return null;
  }
}
