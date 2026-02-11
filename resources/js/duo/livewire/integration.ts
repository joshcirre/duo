import type { DuoDatabase } from '../core/database';
import type { SyncQueue } from '../sync/queue';

export interface LivewireIntegrationConfig {
  debug?: boolean;
}

/**
 * Livewire Integration — lightweight layer that hooks into Livewire lifecycle.
 *
 * This replaces the old fetch-intercepting approach. Instead of monkey-patching
 * window.fetch, we rely on Livewire's own hook system and the DuoLivewireInterceptor
 * for offline handling.
 */
export class LivewireIntegration {
  private db: DuoDatabase;
  private syncQueue: SyncQueue;
  private config: LivewireIntegrationConfig;

  constructor(db: DuoDatabase, syncQueue: SyncQueue, config: LivewireIntegrationConfig = {}) {
    this.db = db;
    this.syncQueue = syncQueue;
    this.config = {
      debug: false,
      ...config,
    };
  }

  initialize(): void {
    if (this.config.debug) {
      console.log('[Duo] Livewire integration initialized');
    }
  }

  destroy(): void {
    // No cleanup needed — we don't monkey-patch fetch anymore
  }
}

declare global {
  interface Window {
    Livewire: any;
  }
}
