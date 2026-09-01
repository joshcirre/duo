import { beforeEach, describe, expect, it } from 'vitest';
import { isManagedAction, withManagedAction } from '../../resources/js/duo/livewire/managed';
import {
  clearExpectedCreates,
  expectCreate,
  takeExpectedCreate,
  unexpectCreate,
} from '../../resources/js/duo/sync/pending';

describe('pending create expectations', () => {
  beforeEach(() => clearExpectedCreates());

  it('pairs server creates with temp keys in FIFO order per store', () => {
    expectCreate('todos', -1);
    expectCreate('todos', -2);
    expectCreate('notes', -9);

    expect(takeExpectedCreate('todos')).toBe(-1);
    expect(takeExpectedCreate('notes')).toBe(-9);
    expect(takeExpectedCreate('todos')).toBe(-2);
    expect(takeExpectedCreate('todos')).toBeUndefined();
  });

  it('withdraws a specific expectation without disturbing the others', () => {
    expectCreate('todos', -1);
    expectCreate('todos', -2);
    expectCreate('todos', -3);

    unexpectCreate('todos', -2);
    unexpectCreate('todos', -404);

    expect(takeExpectedCreate('todos')).toBe(-1);
    expect(takeExpectedCreate('todos')).toBe(-3);
  });
});

describe('managed action marker', () => {
  it('is only set while the wrapped call runs synchronously', () => {
    expect(isManagedAction()).toBe(false);

    const seen = withManagedAction(() => isManagedAction());

    expect(seen).toBe(true);
    expect(isManagedAction()).toBe(false);
  });

  it('resets even when the wrapped call throws', () => {
    expect(() =>
      withManagedAction(() => {
        throw new Error('boom');
      })
    ).toThrow('boom');

    expect(isManagedAction()).toBe(false);
  });

  it('does not extend past the synchronous part of an async call', async () => {
    let insideAwait: boolean | null = null;

    const promise = withManagedAction(async () => {
      await Promise.resolve();
      insideAwait = isManagedAction();
    });

    expect(isManagedAction()).toBe(false);
    await promise;
    expect(insideAwait).toBe(false);
  });
});
