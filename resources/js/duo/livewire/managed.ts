/**
 * Marks Livewire actions fired by Duo itself.
 *
 * Livewire runs action interceptors synchronously inside fireAction(), so a
 * depth counter around the call is enough for the global interceptor to tell
 * "Duo already handled this action" from "an author-written wire:click".
 */

let depth = 0;

export function withManagedAction<T>(fn: () => T): T {
  depth++;
  try {
    return fn();
  } finally {
    depth--;
  }
}

export function isManagedAction(): boolean {
  return depth > 0;
}
