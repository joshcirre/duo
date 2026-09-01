import { liveQuery } from 'dexie';
import type { DuoRecord } from '../core/database';
import { getClient, whenReady } from '../core/runtime';
import type { DuoComponentMeta, DuoMethodMeta, DuoModelInfo } from '../livewire/types';
import { parseComponentMeta } from '../livewire/types';
import type { ExpectedOp } from '../sync/pending';

/**
 * The Alpine `duo` scope that server-compiled templates bind to.
 *
 * The Duo Blade precompiler turns
 *   wire:model="title"            → x-model="duo.form.title"
 *   wire:submit="save"            → x-on:submit.prevent="duo.submit('save')"
 *   wire:click="remove(1)"        → x-on:click="duo.call('remove', [1])"
 *   @forelse($todos as $todo)     → <template x-for="item in duo.todos">
 * and the render hook puts x-data="duo" on the component root, so this
 * component is what those expressions resolve against.
 *
 * Reads come from IndexedDB via Dexie liveQuery. Writes apply optimistically
 * to IndexedDB, then run the author's real Livewire method through $wire; the
 * server's DuoOperationCollector reports what actually happened and the
 * interceptor reconciles.
 */
export function registerDuoAlpineComponent(Alpine: any): void {
  if (!Alpine || Alpine.__duoRegistered) return;
  Alpine.__duoRegistered = true;

  Alpine.data('duo', (extra: Record<string, any> = {}) => createDuoScope(extra));
}

function createDuoScope(extra: Record<string, any>) {
  let el: HTMLElement | null = null;
  let wire: any = null;
  let meta: DuoComponentMeta | null = null;
  let scope: any = null;
  const subscriptions: Array<{ unsubscribe(): void }> = [];

  const onOnline = () => {
    if (scope) scope.duo.online = true;
  };
  const onOffline = () => {
    if (scope) scope.duo.online = false;
  };

  const duo: Record<string, any> = {
    form: {} as Record<string, any>,
    online: typeof navigator !== 'undefined' ? navigator.onLine : true,
    ready: false,

    /**
     * Handle a compiled wire:submit. Form fields become deferred Livewire
     * property updates followed by the method call — the same request
     * Livewire itself would have sent.
     */
    async submit(method: string): Promise<void> {
      const runtime = getRuntime();
      if (!runtime) return fallback(method, []);

      const { db, queue } = runtime;
      const model = primaryCollection(meta!);
      const methodMeta = meta!.methods?.[method];
      const updates: Record<string, any> = { ...this.form };
      const expects: ExpectedOp[] = [];

      if (model) {
        const store = db.getStore(model.store);
        if (store) {
          const key = -Date.now();
          await store.put(buildTempRecord(updates, model, methodMeta, key));
          expects.push({ type: 'create', store: model.store, key });
        }
      }

      for (const field of Object.keys(this.form)) {
        this.form[field] = resetValue(this.form[field]);
      }

      const entry = queue.add({
        component: meta!.component,
        componentId: componentId(),
        method,
        params: [],
        updates,
        expects,
      });

      if (!this.online) {
        queue.mark(entry.id, 'queued');
        return;
      }

      const result = await queue.fire(entry, wire);

      if (result.ok) {
        // Respect whatever the method did to its properties (reset or not).
        for (const field of Object.keys(this.form)) {
          this.form[field] = readWireValue(wire, field);
        }
      } else if (!result.network) {
        // Validation or authorization failed: give the user their input back
        // so they can correct it. Livewire has already rendered the errors.
        Object.assign(this.form, updates);
      }
    },

    /**
     * Handle a compiled wire:click. Applies the inferred operation to the
     * local record first, then runs the real Livewire method.
     */
    async call(method: string, args: any[] = []): Promise<void> {
      const runtime = getRuntime();
      if (!runtime) return fallback(method, args);

      const methodMeta = meta!.methods?.[method];
      const operation = methodMeta?.operation;

      if (operation === 'create') {
        return this.submit(method);
      }

      const { db, queue } = runtime;
      const model = primaryCollection(meta!);
      const store = model ? db.getStore(model.store) : undefined;
      const rollback: Array<{ store: string; key: any; record: DuoRecord | null }> = [];
      const expects: ExpectedOp[] = [];
      const key = args[0];
      const hasKey = key !== undefined && key !== null && typeof key !== 'object';

      if (model && store && hasKey && (operation === 'update' || operation === 'delete')) {
        const existing = await store.get(key);

        if (existing) {
          const isLocalOnly = !!existing._duo_pending_sync && existing._duo_operation === 'create';

          if (isLocalOnly) {
            // The server has never seen this record, so there is nothing to
            // call. A delete cancels the pending create outright; an update
            // is applied locally and folded into the create when it replays.
            if (operation === 'delete') {
              await store.delete(key);
              queue.removeByTemp(model.store, key);
            } else {
              await store.put(applyUpdate(existing, methodMeta, args));
            }
            return;
          }

          rollback.push({ store: model.store, key, record: existing });
          expects.push({ type: operation, store: model.store, key });

          if (operation === 'delete') {
            await store.put({ ...existing, _duo_pending_sync: 1, _duo_operation: 'delete' });
          } else {
            await store.put({
              ...applyUpdate(existing, methodMeta, args),
              updated_at: new Date().toISOString(),
              _duo_pending_sync: 1,
              _duo_operation: 'update',
            });
          }
        }
      }

      const entry = queue.add({
        component: meta!.component,
        componentId: componentId(),
        method,
        params: args,
        expects,
        rollback,
      });

      if (!this.online) {
        queue.mark(entry.id, 'queued');
        return;
      }

      await queue.fire(entry, wire);
    },
  };

  function getRuntime() {
    const client = getClient();
    const db = client?.getDatabase();
    const queue = client?.getActionQueue();

    if (!client || !db || !queue || !meta || !wire) {
      return null;
    }

    return { client, db, queue };
  }

  /** Before Duo is ready, behave exactly like plain Livewire. */
  function fallback(method: string, args: any[]): Promise<void> {
    if (!wire) return Promise.resolve();
    for (const [field, value] of Object.entries(duo.form)) {
      wire.$set(field, value, false);
    }
    return Promise.resolve(wire[method](...args)).then(() => undefined);
  }

  function componentId(): string | null {
    return wire?.__instance?.id ?? el?.getAttribute('wire:id') ?? null;
  }

  async function boot(reactive: any): Promise<void> {
    const client = await whenReady();
    const db = client.getDatabase();
    if (!db || !meta) return;

    for (const [property, info] of Object.entries(meta.models)) {
      const store = db.getStore(info.store);
      if (!store) {
        console.warn(`[Duo] Store "${info.store}" is not in the manifest — run duo:generate`);
        continue;
      }

      if (info.type === 'collection') {
        const subscription = liveQuery(async () => {
          const rows = await store.toArray();
          return rows.filter((row: DuoRecord) => !isPendingDelete(row));
        }).subscribe({
          next: (rows: DuoRecord[]) => {
            reactive.duo[property] = sortRows(rows, info.primaryKey);
          },
          error: (e: unknown) => console.warn('[Duo] liveQuery error:', e),
        });
        subscriptions.push(subscription);
      } else {
        const subscription = liveQuery(async () => {
          const rows = await store.toArray();
          return rows.find((row: DuoRecord) => !isPendingDelete(row)) ?? null;
        }).subscribe({
          next: (row: DuoRecord | null) => {
            reactive.duo[property] = row;
          },
          error: (e: unknown) => console.warn('[Duo] liveQuery error:', e),
        });
        subscriptions.push(subscription);
      }
    }

    reactive.duo.ready = true;

    if (reactive.duo.online) {
      void client.getActionQueue()?.replay(meta.component);
    }
  }

  return {
    ...extra,
    duo,

    init(this: any) {
      el = this.$el;
      wire = this.$wire;
      scope = this;
      meta = parseComponentMeta(el);

      // Shape the reactive state synchronously, before Alpine walks the
      // children that bind to duo.todos / duo.form.title.
      if (meta) {
        for (const [property, info] of Object.entries(meta.models)) {
          this.duo[property] = info.type === 'collection' ? [] : null;
        }
      }

      for (const field of discoverFormFields(el)) {
        if (!(field in this.duo.form)) {
          this.duo.form[field] = readWireValue(wire, field);
        }
      }

      window.addEventListener('online', onOnline);
      window.addEventListener('offline', onOffline);

      if (typeof extra.init === 'function') {
        extra.init.call(this);
      }

      if (meta) {
        boot(this).catch((e) => console.error('[Duo] Failed to boot component:', e));
      }
    },

    destroy(this: any) {
      for (const subscription of subscriptions) subscription.unsubscribe();
      subscriptions.length = 0;

      window.removeEventListener('online', onOnline);
      window.removeEventListener('offline', onOffline);

      if (typeof extra.destroy === 'function') {
        extra.destroy.call(this);
      }
    },
  };
}

function primaryCollection(meta: DuoComponentMeta): DuoModelInfo | null {
  const models = Object.values(meta.models ?? {});
  return models.find((m) => m.type === 'collection') ?? models[0] ?? null;
}

function discoverFormFields(root: HTMLElement | null): string[] {
  if (!root) return [];

  const fields: string[] = [];
  root.querySelectorAll('[x-model]').forEach((node) => {
    const expression = node.getAttribute('x-model') ?? '';
    if (expression.startsWith('duo.form.')) {
      fields.push(expression.slice('duo.form.'.length));
    }
  });

  return fields;
}

function readWireValue(wire: any, field: string): any {
  try {
    const value = wire?.$get?.(field);
    return value === undefined || value === null ? '' : value;
  } catch {
    return '';
  }
}

function resetValue(current: any): any {
  if (typeof current === 'boolean') return false;
  if (typeof current === 'number') return 0;
  if (Array.isArray(current)) return [];
  return '';
}

/**
 * Build the optimistic record for a create. Component properties are mapped
 * to columns using what the server inferred from the method body, falling
 * back to same-name matches against the model schema.
 */
function buildTempRecord(
  updates: Record<string, any>,
  model: DuoModelInfo,
  methodMeta: DuoMethodMeta | undefined,
  key: any
): DuoRecord {
  const columns = new Set<string>(Object.keys(model.schema ?? {}));
  for (const column of model.fillable ?? []) columns.add(column);

  const record: DuoRecord = { ...(methodMeta?.defaults ?? {}) };

  for (const [property, value] of Object.entries(updates)) {
    const mapped = methodMeta?.mapping?.[property];
    const column = mapped ?? (columns.size === 0 || columns.has(property) ? property : null);
    if (column) record[column] = value;
  }

  const now = new Date().toISOString();
  record[model.primaryKey] = key;
  record.created_at = now;
  record.updated_at = now;
  record._duo_pending_sync = 1;
  record._duo_operation = 'create';

  return record;
}

function applyUpdate(existing: DuoRecord, methodMeta: DuoMethodMeta | undefined, args: any[]): DuoRecord {
  const updated: DuoRecord = { ...existing };

  for (const column of methodMeta?.toggles ?? []) {
    updated[column] = !existing[column];
  }

  if (args.length > 1 && args[1] && typeof args[1] === 'object') {
    Object.assign(updated, args[1]);
  }

  return updated;
}

function isPendingDelete(row: DuoRecord): boolean {
  return row._duo_operation === 'delete' && !!row._duo_pending_sync;
}

function sortRows(rows: DuoRecord[], primaryKey: string): DuoRecord[] {
  return rows.sort((a, b) => {
    if (a.created_at && b.created_at) {
      const diff = new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
      if (diff !== 0) return diff;
    }
    return (Number(b[primaryKey]) || 0) - (Number(a[primaryKey]) || 0);
  });
}
