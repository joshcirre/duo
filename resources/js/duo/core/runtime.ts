import type { DuoClient } from '../index';

/**
 * Process-wide handle to the initialized DuoClient.
 *
 * The Alpine `duo` scope can start evaluating before initializeDuo() has
 * opened IndexedDB, so consumers await whenReady() rather than assuming
 * window.duo exists.
 */

let client: DuoClient | null = null;
let resolveReady: (client: DuoClient) => void = () => {};

const ready = new Promise<DuoClient>((resolve) => {
  resolveReady = resolve;
});

export function markReady(instance: DuoClient): void {
  client = instance;
  resolveReady(instance);
}

export function whenReady(): Promise<DuoClient> {
  return ready;
}

export function getClient(): DuoClient | null {
  return client;
}
