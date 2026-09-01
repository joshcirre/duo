/**
 * Tracks the server operations Duo expects a response to report.
 *
 * Before an action is sent, the queue registers what it optimistically did:
 * "create on this temp key", "update on key 7", "delete on key 7". When the
 * response's `duo` effect arrives, the interceptor consumes matching
 * expectations. Anything left unconsumed after the action finishes means the
 * server did not perform that operation — validation failed, authorization
 * denied, or the method simply took a different path — and the optimistic
 * change is rolled back.
 *
 * Creates are paired FIFO per store (the server has no way to echo a temp
 * key); updates and deletes are paired by exact key.
 */

export interface ExpectedOp {
  type: 'create' | 'update' | 'delete';
  store: string;
  key: any;
}

const creates = new Map<string, any[]>();
const mutations = new Set<string>();

function mutationId(type: string, store: string, key: any): string {
  return `${type}|${store}|${String(key)}`;
}

export function expectOperation(op: ExpectedOp): void {
  if (op.type === 'create') {
    const list = creates.get(op.store) ?? [];
    list.push(op.key);
    creates.set(op.store, list);
    return;
  }

  mutations.add(mutationId(op.type, op.store, op.key));
}

/** Pair a server-reported create with the oldest expected temp key. */
export function takeExpectedCreate(store: string): any | undefined {
  const list = creates.get(store);
  if (!list || list.length === 0) return undefined;
  return list.shift();
}

/** Pair a server-reported update/delete with its expectation, if any. */
export function consumeExpectedMutation(type: 'update' | 'delete', store: string, key: any): boolean {
  return mutations.delete(mutationId(type, store, key));
}

export function withdrawExpectation(op: ExpectedOp): void {
  if (op.type === 'create') {
    const list = creates.get(op.store);
    if (!list) return;
    const index = list.indexOf(op.key);
    if (index !== -1) list.splice(index, 1);
    return;
  }

  mutations.delete(mutationId(op.type, op.store, op.key));
}

export function isExpected(op: ExpectedOp): boolean {
  if (op.type === 'create') {
    return (creates.get(op.store) ?? []).includes(op.key);
  }

  return mutations.has(mutationId(op.type, op.store, op.key));
}

export function clearExpectations(): void {
  creates.clear();
  mutations.clear();
}

// Backwards-compatible aliases used by earlier code paths.
export const expectCreate = (store: string, key: any) => expectOperation({ type: 'create', store, key });
export const unexpectCreate = (store: string, key: any) => withdrawExpectation({ type: 'create', store, key });
export const clearExpectedCreates = clearExpectations;
