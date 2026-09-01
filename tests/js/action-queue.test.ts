import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ActionQueue, type QueuedAction } from '../../resources/js/duo/sync/action-queue';
import {
  clearExpectations,
  consumeExpectedMutation,
  isExpected,
  takeExpectedCreate,
} from '../../resources/js/duo/sync/pending';

type Row = Record<string, any>;

function fakeDb(stores: Record<string, Map<any, Row>>) {
  return {
    getStore(name: string) {
      const rows = stores[name];
      if (!rows) return undefined;
      return {
        async get(key: any) {
          return rows.get(key);
        },
        async put(row: Row) {
          rows.set(row.id, row);
        },
        async delete(key: any) {
          rows.delete(key);
        },
        async toArray() {
          return [...rows.values()];
        },
      };
    },
  } as any;
}

function fakeWire(behavior: (method: string, params: any[]) => Promise<any>) {
  const sets: Array<[string, any, boolean]> = [];
  const wire: any = new Proxy(
    {},
    {
      get(_target, prop: string) {
        if (prop === '$set') return (k: string, v: any, live: boolean) => sets.push([k, v, live]);
        if (prop === '__instance') return { id: 'comp-1' };
        return (...params: any[]) => behavior(prop, params);
      },
    }
  );
  return { wire, sets };
}

const KEY = 'duo:test';

describe('ActionQueue', () => {
  beforeEach(() => {
    localStorage.clear();
    clearExpectations();
  });

  it('persists added entries and restores them on construction', () => {
    const db = fakeDb({});
    const queue = new ActionQueue(db, { storageKey: KEY });

    const entry = queue.add({ component: 'todo-list', componentId: 'c1', method: 'addTodo', params: [] });

    expect(entry.status).toBe('inflight');
    expect(JSON.parse(localStorage.getItem(KEY)!)).toHaveLength(1);

    const restored = new ActionQueue(db, { storageKey: KEY });
    expect(restored.size).toBe(1);
  });

  it('treats inflight entries from a previous page load as queued', () => {
    const stale: QueuedAction = {
      id: 'old',
      component: 'todo-list',
      componentId: 'c1',
      method: 'addTodo',
      params: [],
      updates: {},
      expects: [],
      rollback: [],
      status: 'inflight',
      session: 'some-other-session',
      createdAt: 1,
      attempts: 1,
    };
    localStorage.setItem(KEY, JSON.stringify([stale]));

    const queue = new ActionQueue(fakeDb({}), { storageKey: KEY });

    expect(queue.all()[0].status).toBe('queued');
    expect(queue.pendingCount).toBe(1);
  });

  it('fires through $wire with deferred property updates and removes the entry on success', async () => {
    const queue = new ActionQueue(fakeDb({}), { storageKey: KEY });
    // The server "responds" with the create; the interceptor consumes the expectation.
    const { wire, sets } = fakeWire(async () => {
      expect(takeExpectedCreate('todos')).toBe(-1);
      return 'ok';
    });
    const synced = vi.fn();
    window.addEventListener('duo-synced', synced);

    const entry = queue.add({
      component: 'todo-list',
      componentId: 'c1',
      method: 'addTodo',
      params: [],
      updates: { newTodoTitle: 'Milk' },
      expects: [{ type: 'create', store: 'todos', key: -1 }],
    });

    const result = await queue.fire(entry, wire);

    expect(result).toEqual({ ok: true });
    expect(sets).toEqual([['newTodoTitle', 'Milk', false]]);
    expect(queue.size).toBe(0);
    expect(synced).toHaveBeenCalledTimes(1);
  });

  it('rolls back when the promise resolves but the server never reported the expected operation', async () => {
    // Livewire resolves the action promise on a validation failure; the only
    // reliable signal is the missing operation in the response effect.
    const todos = new Map<any, Row>([[-1, { id: -1, title: 'ab', _duo_pending_sync: 1, _duo_operation: 'create' }]]);
    const queue = new ActionQueue(fakeDb({ todos }), { storageKey: KEY });
    const { wire } = fakeWire(async () => undefined);
    const synced = vi.fn();
    window.addEventListener('duo-synced', synced);

    const entry = queue.add({
      component: 'todo-list',
      componentId: 'c1',
      method: 'addTodo',
      params: [],
      expects: [{ type: 'create', store: 'todos', key: -1 }],
    });

    const result = await queue.fire(entry, wire);

    expect(result).toMatchObject({ ok: false, network: false, unperformed: [{ type: 'create', store: 'todos', key: -1 }] });
    expect(todos.has(-1)).toBe(false);
    expect(queue.size).toBe(0);
    expect(synced).not.toHaveBeenCalled();
    expect(isExpected({ type: 'create', store: 'todos', key: -1 })).toBe(false);
  });

  it('confirms updates and deletes by key and leaves server-reconciled records alone on rollback', async () => {
    const todos = new Map<any, Row>([
      [7, { id: 7, completed: true, _duo_pending_sync: 1, _duo_operation: 'update' }],
      [8, { id: 8, completed: true, _duo_pending_sync: 0 }],
    ]);
    const queue = new ActionQueue(fakeDb({ todos }), { storageKey: KEY });
    const { wire } = fakeWire(async () => {
      // Server reports the update on 8 but takes a different branch for 7.
      expect(consumeExpectedMutation('update', 'todos', 8)).toBe(true);
      return 'ok';
    });

    const entry = queue.add({
      component: 'todo-list',
      componentId: 'c1',
      method: 'toggleBoth',
      params: [7, 8],
      expects: [
        { type: 'update', store: 'todos', key: 7 },
        { type: 'update', store: 'todos', key: 8 },
      ],
      rollback: [
        { store: 'todos', key: 7, record: { id: 7, completed: false, _duo_pending_sync: 0 } },
        { store: 'todos', key: 8, record: { id: 8, completed: false, _duo_pending_sync: 0 } },
      ],
    });

    const result = await queue.fire(entry, wire);

    expect(result).toMatchObject({ ok: false, network: false, unperformed: [{ type: 'update', store: 'todos', key: 7 }] });
    // 7 was still pending locally → restored; 8 was already reconciled by the server → untouched.
    expect(todos.get(7)).toEqual({ id: 7, completed: false, _duo_pending_sync: 0 });
    expect(todos.get(8)).toEqual({ id: 8, completed: true, _duo_pending_sync: 0 });
  });

  it('keeps the entry queued on a network failure and withdraws the expected create', async () => {
    const queue = new ActionQueue(fakeDb({}), { storageKey: KEY });
    const { wire } = fakeWire(async () => {
      throw { status: null, body: null, json: null, errors: null };
    });

    const entry = queue.add({
      component: 'todo-list',
      componentId: 'c1',
      method: 'addTodo',
      params: [],
      expects: [{ type: 'create', store: 'todos', key: -1 }],
    });

    const result = await queue.fire(entry, wire);

    expect(result).toEqual({ ok: false, network: true, error: expect.anything() });
    expect(queue.all()[0].status).toBe('queued');
    expect(queue.all()[0].attempts).toBe(1);
    expect(takeExpectedCreate('todos')).toBeUndefined();
  });

  it('rolls back optimistic changes and drops the entry when the server rejects the action', async () => {
    const todos = new Map<any, Row>([
      [-1, { id: -1, title: 'temp', _duo_pending_sync: 1, _duo_operation: 'create' }],
      [7, { id: 7, title: 'real', completed: true, _duo_pending_sync: 1, _duo_operation: 'update' }],
    ]);
    const queue = new ActionQueue(fakeDb({ todos }), { storageKey: KEY });
    const { wire } = fakeWire(async () => {
      throw { status: 422, errors: { newTodoTitle: ['required'] } };
    });

    const entry = queue.add({
      component: 'todo-list',
      componentId: 'c1',
      method: 'addTodo',
      params: [],
      expects: [{ type: 'create', store: 'todos', key: -1 }],
      rollback: [{ store: 'todos', key: 7, record: { id: 7, title: 'real', completed: false, _duo_pending_sync: 0 } }],
    });

    const result = await queue.fire(entry, wire);

    expect(result).toMatchObject({ ok: false, network: false });
    expect(todos.has(-1)).toBe(false);
    expect(todos.get(7)).toEqual({ id: 7, title: 'real', completed: false, _duo_pending_sync: 0 });
    expect(queue.size).toBe(0);
  });

  it('removes the queued action that created a temp record', () => {
    const queue = new ActionQueue(fakeDb({}), { storageKey: KEY });
    queue.add({ component: 'a', componentId: null, method: 'x', params: [], expects: [{ type: 'create', store: 'todos', key: -5 }] });
    queue.add({ component: 'a', componentId: null, method: 'y', params: [] });

    expect(queue.removeByTemp('todos', -5)).toBe(true);
    expect(queue.removeByTemp('todos', -99)).toBe(false);
    expect(queue.all().map((e) => e.method)).toEqual(['y']);
  });

  it('replays queued entries against live components and stops at the first network failure', async () => {
    const queue = new ActionQueue(fakeDb({}), { storageKey: KEY });
    const calls: string[] = [];
    let failNext = false;
    const { wire } = fakeWire(async (method) => {
      calls.push(method);
      if (failNext) throw { status: null };
      return 'ok';
    });

    // Livewire.find() / getByName() return $wire proxies, not Component objects.
    (window as any).Livewire = {
      find: (id: string) => (id === 'c1' ? wire : undefined),
      getByName: () => [],
    };

    const first = queue.add({ component: 'todo-list', componentId: 'c1', method: 'first', params: [], status: 'queued' });
    const second = queue.add({ component: 'todo-list', componentId: 'c1', method: 'second', params: [], status: 'queued' });
    queue.add({ component: 'other', componentId: 'gone', method: 'orphan', params: [], status: 'queued' });
    queue.add({ component: 'todo-list', componentId: 'c1', method: 'inflight', params: [] });

    await queue.replay();
    expect(calls).toEqual(['first', 'second']);
    expect(queue.all().map((e) => e.method)).toEqual(['orphan', 'inflight']);

    queue.add({ component: 'todo-list', componentId: 'c1', method: 'third', params: [], status: 'queued' });
    queue.add({ component: 'todo-list', componentId: 'c1', method: 'fourth', params: [], status: 'queued' });
    failNext = true;
    calls.length = 0;

    await queue.replay();
    expect(calls).toEqual(['third']);
    expect(queue.all().filter((e) => e.status === 'queued').map((e) => e.method)).toEqual(['orphan', 'third', 'fourth']);

    expect(first.status).toBe('inflight');
    expect(second.status).toBe('inflight');
  });

  it('falls back to lookup by component name when the id is from a previous page load', async () => {
    const queue = new ActionQueue(fakeDb({}), { storageKey: KEY });
    const calls: string[] = [];
    const { wire } = fakeWire(async (method) => {
      calls.push(method);
      return 'ok';
    });

    (window as any).Livewire = {
      find: () => undefined,
      getByName: (name: string) => (name === 'todo-list' ? [wire] : []),
    };

    queue.add({ component: 'todo-list', componentId: 'stale-id', method: 'addTodo', params: [], status: 'queued' });

    await queue.replay();

    expect(calls).toEqual(['addTodo']);
    expect(queue.size).toBe(0);
  });

  it('keeps an entry queued and reports loudly when firing throws a non-Livewire error', async () => {
    const queue = new ActionQueue(fakeDb({}), { storageKey: KEY });
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    const brokenWire = {}; // no $set, no methods

    const entry = queue.add({
      component: 'todo-list',
      componentId: 'c1',
      method: 'addTodo',
      params: [],
      updates: { title: 'x' },
      expects: [{ type: 'create', store: 'todos', key: -1 }],
    });

    const result = await queue.fire(entry, brokenWire);

    expect(result).toMatchObject({ ok: false, network: true });
    expect(queue.all()[0].status).toBe('queued');
    expect(consoleError).toHaveBeenCalledTimes(1);
    expect(isExpected({ type: 'create', store: 'todos', key: -1 })).toBe(false);

    consoleError.mockRestore();
  });

  it('survives a storage that throws', () => {
    const broken = {
      getItem: () => {
        throw new Error('blocked');
      },
      setItem: () => {
        throw new Error('blocked');
      },
    } as unknown as Storage;

    const queue = new ActionQueue(fakeDb({}), { storageKey: KEY, storage: broken });
    const entry = queue.add({ component: 'a', componentId: null, method: 'x', params: [] });

    expect(entry.status).toBe('inflight');
    expect(queue.size).toBe(1);
  });
});
