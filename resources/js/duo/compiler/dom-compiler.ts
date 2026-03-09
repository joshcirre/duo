import type { DuoDatabase, DuoRecord } from '../core/database';
import type { SyncQueue } from '../sync/queue';
import { liveQuery } from 'dexie';

export interface DuoCompilerConfig {
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

export class DuoDomCompiler {
  private db: DuoDatabase;
  private syncQueue: SyncQueue;
  private debug: boolean;
  private observer?: MutationObserver;
  private subscriptions: Map<string, any> = new Map();

  constructor(db: DuoDatabase, syncQueue: SyncQueue, config: DuoCompilerConfig = {}) {
    this.db = db;
    this.syncQueue = syncQueue;
    this.debug = config.debug ?? false;
  }

  initialize(): void {
    if (typeof window === 'undefined') return;

    if (this.debug) {
      console.log('[Duo Compiler] Initializing...');
    }

    this.compileAll();
    this.observeNewComponents();

    document.addEventListener('livewire:navigated', () => {
      if (this.debug) {
        console.log('[Duo Compiler] livewire:navigated — recompiling');
      }
      this.compileAll();
    });
  }

  compileAll(): void {
    const roots = document.querySelectorAll(
      '[data-duo-enabled="true"]:not([data-duo-compiled])'
    );

    if (this.debug) {
      console.log(`[Duo Compiler] Found ${roots.length} uncompiled component(s)`);
    }

    roots.forEach((root) => {
      if (root instanceof HTMLElement) {
        this.compileComponent(root);
      }
    });
  }

  private compileComponent(root: HTMLElement): void {
    const metaStr = root.getAttribute('data-duo-meta');
    if (!metaStr) return;

    let meta: DuoComponentMeta;
    try {
      meta = JSON.parse(metaStr);
    } catch (e) {
      console.warn('[Duo Compiler] Failed to parse component meta:', e);
      return;
    }

    if (this.debug) {
      console.log(`[Duo Compiler] Compiling component: ${meta.component}`, meta);
    }

    root.setAttribute('data-duo-compiled', 'true');

    const Alpine = (window as any).Alpine;
    const needsAlpineReinit = Alpine && Alpine._started;

    if (needsAlpineReinit) {
      Alpine.destroyTree(root);
    }

    const formFields = this.discoverFormFields(root);

    this.rewriteWireSubmit(root);
    this.rewriteWireModels(root);
    this.rewriteWireClicks(root);
    this.compileCollections(root);
    this.injectAlpineController(root, meta, formFields);

    root.setAttribute('wire:ignore', '');

    if (needsAlpineReinit) {
      Alpine.initTree(root);
    }

    if (this.debug) {
      console.log(`[Duo Compiler] Component compiled: ${meta.component}`);
    }
  }

  private discoverFormFields(root: HTMLElement): Record<string, string> {
    const fields: Record<string, string> = {};

    root.querySelectorAll('*').forEach((el) => {
      for (const attr of el.attributes) {
        if (attr.name.startsWith('wire:model')) {
          const field = attr.value;
          if (field) {
            const value = (el as HTMLInputElement).value || '';
            fields[field] = value;
          }
        }
      }
    });

    return fields;
  }

  private rewriteWireSubmit(root: HTMLElement): void {
    const forms = root.querySelectorAll('*');
    forms.forEach((el) => {
      const attrsToProcess: { name: string; value: string }[] = [];
      for (const attr of el.attributes) {
        if (attr.name.startsWith('wire:submit')) {
          attrsToProcess.push({ name: attr.name, value: attr.value });
        }
      }

      if (attrsToProcess.length === 0) return;

      const method = attrsToProcess[0].value;
      attrsToProcess.forEach((a) => el.removeAttribute(a.name));
      el.setAttribute('@submit.prevent', `duo.submit('${method}')`);

      if (this.debug) {
        console.log(`[Duo Compiler] wire:submit="${method}" → @submit.prevent`);
      }
    });
  }

  private rewriteWireModels(root: HTMLElement): void {
    root.querySelectorAll('*').forEach((el) => {
      const attrsToRemove: string[] = [];
      let fieldName: string | null = null;

      for (const attr of el.attributes) {
        if (attr.name.startsWith('wire:model')) {
          fieldName = attr.value;
          attrsToRemove.push(attr.name);
        }
      }

      if (fieldName && attrsToRemove.length > 0) {
        attrsToRemove.forEach((a) => el.removeAttribute(a));
        el.setAttribute('x-model', `duo.form.${fieldName}`);

        if (this.debug) {
          console.log(`[Duo Compiler] wire:model="${fieldName}" → x-model`);
        }
      }
    });
  }

  private rewriteWireClicks(root: HTMLElement): void {
    root.querySelectorAll('*').forEach((el) => {
      if (el.closest('[data-duo-template]') && el !== el.closest('[data-duo-template]')) return;

      const attrsToProcess: { name: string; value: string }[] = [];
      for (const attr of el.attributes) {
        if (attr.name.startsWith('wire:click')) {
          attrsToProcess.push({ name: attr.name, value: attr.value });
        }
      }

      if (attrsToProcess.length === 0) return;

      // Skip elements inside a template (handled by compileCollections)
      if (el.closest('[data-duo-collection]')?.querySelector('[data-duo-template]')) {
        if (el.closest('[data-duo-template]')) return;
      }

      const wireClick = attrsToProcess[0].value;
      const parsed = this.parseMethodCall(wireClick);
      if (!parsed) return;

      attrsToProcess.forEach((a) => el.removeAttribute(a.name));

      const argsStr = parsed.args.map((a) => JSON.stringify(a)).join(', ');
      el.setAttribute('@click', `duo.call('${parsed.method}', [${argsStr}])`);

      if (this.debug) {
        console.log(`[Duo Compiler] wire:click="${wireClick}" → @click`);
      }
    });
  }

  private compileCollections(root: HTMLElement): void {
    const collections = root.querySelectorAll('[data-duo-collection]');
    collections.forEach((container) => {
      if (!(container instanceof HTMLElement)) return;

      const collectionName = container.getAttribute('data-duo-collection');
      const keyField = container.getAttribute('data-duo-key') || 'id';
      if (!collectionName) return;

      const templateEl = container.querySelector('[data-duo-template]');
      if (!templateEl) {
        if (this.debug) {
          console.warn(`[Duo Compiler] No [data-duo-template] in collection: ${collectionName}`);
        }
        return;
      }

      const templateClone = templateEl.cloneNode(true) as HTMLElement;
      templateClone.removeAttribute('data-duo-template');
      templateClone.removeAttribute('wire:key');

      this.rewriteTemplateBindings(templateClone);
      this.rewriteTemplateActions(templateClone);

      templateClone.setAttribute(':key', `item.${keyField}`);

      const xForTemplate = document.createElement('template');
      xForTemplate.setAttribute('x-for', `item in duo.${collectionName}`);
      xForTemplate.content.appendChild(templateClone);

      const emptyEl = container.querySelector('[data-duo-empty]');
      let emptyClone: HTMLElement | null = null;
      if (emptyEl instanceof HTMLElement) {
        emptyClone = emptyEl.cloneNode(true) as HTMLElement;
        emptyClone.removeAttribute('data-duo-empty');
        emptyClone.setAttribute('x-show', `duo.${collectionName}.length === 0`);
      }

      container.innerHTML = '';
      container.appendChild(xForTemplate);

      if (emptyClone) {
        container.appendChild(emptyClone);
      }

      container.setAttribute('wire:ignore', '');

      if (this.debug) {
        console.log(`[Duo Compiler] Collection compiled: ${collectionName}`);
      }
    });
  }

  private rewriteTemplateBindings(template: HTMLElement): void {
    template.querySelectorAll('[data-duo-text]').forEach((el) => {
      const field = el.getAttribute('data-duo-text');
      if (!field) return;
      el.setAttribute('x-text', `item.${field}`);
      el.removeAttribute('data-duo-text');
      el.textContent = '';
    });

    template.querySelectorAll('[data-duo-html]').forEach((el) => {
      const field = el.getAttribute('data-duo-html');
      if (!field) return;
      el.setAttribute('x-html', `item.${field}`);
      el.removeAttribute('data-duo-html');
      el.innerHTML = '';
    });

    template.querySelectorAll('[data-duo-show]').forEach((el) => {
      const field = el.getAttribute('data-duo-show');
      if (!field) return;
      el.setAttribute('x-show', `item.${field}`);
      el.removeAttribute('data-duo-show');
    });

    template.querySelectorAll('[data-duo-checked]').forEach((el) => {
      const field = el.getAttribute('data-duo-checked');
      if (!field) return;
      el.setAttribute(':checked', `item.${field}`);
      el.removeAttribute('data-duo-checked');
    });

    template.querySelectorAll('*').forEach((el) => {
      for (const attr of Array.from(el.attributes)) {
        if (attr.name.startsWith('data-duo-bind:')) {
          const boundAttr = attr.name.replace('data-duo-bind:', '');
          el.setAttribute(`:${boundAttr}`, `item.${attr.value}`);
          el.removeAttribute(attr.name);
        }
      }
    });

    template.querySelectorAll('[data-duo-class]').forEach((el) => {
      const expr = el.getAttribute('data-duo-class');
      if (!expr) return;
      el.setAttribute(':class', expr);
      el.removeAttribute('data-duo-class');
    });
  }

  private rewriteTemplateActions(template: HTMLElement): void {
    template.querySelectorAll('*').forEach((el) => {
      const attrsToProcess: { name: string; value: string }[] = [];
      for (const attr of el.attributes) {
        if (attr.name.startsWith('wire:click')) {
          attrsToProcess.push({ name: attr.name, value: attr.value });
        }
      }

      if (attrsToProcess.length === 0) return;

      const wireClick = attrsToProcess[0].value;
      const parsed = this.parseMethodCall(wireClick);
      if (!parsed) return;

      attrsToProcess.forEach((a) => el.removeAttribute(a.name));

      const args = parsed.args.map((arg) => {
        if (typeof arg === 'object' && arg !== null) return 'item.id';
        if (typeof arg === 'number') return 'item.id';
        return JSON.stringify(arg);
      });

      el.setAttribute('@click', `duo.call('${parsed.method}', [${args.join(', ')}])`);
    });
  }

  private injectAlpineController(
    root: HTMLElement,
    meta: DuoComponentMeta,
    formFields: Record<string, string>
  ): void {
    const existingXData = root.getAttribute('x-data');

    const componentId = `duo_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
    root.setAttribute('data-duo-id', componentId);

    (window as any).__duoControllers = (window as any).__duoControllers || {};
    (window as any).__duoControllers[componentId] = this.createController(meta, formFields);

    if (existingXData && existingXData.trim() !== '' && existingXData.trim() !== '{}') {
      root.setAttribute(
        'x-data',
        `{...${existingXData}, ...window.__duoControllers['${componentId}']()}`
      );
    } else {
      root.setAttribute(
        'x-data',
        `window.__duoControllers['${componentId}']()`
      );
    }

    const existingInit = root.getAttribute('x-init');
    if (existingInit) {
      root.setAttribute('x-init', `duo.init($data); ${existingInit}`);
    } else {
      root.setAttribute('x-init', 'duo.init($data)');
    }
  }

  private createController(
    meta: DuoComponentMeta,
    formFields: Record<string, string>
  ): () => any {
    const db = this.db;
    const syncQueue = this.syncQueue;
    const debug = this.debug;

    const collectionNames: string[] = [];
    for (const [propName, modelInfo] of Object.entries(meta.models)) {
      if (modelInfo.type === 'collection') {
        collectionNames.push(propName);
      }
    }

    return function () {
      const initialData: any = {
        form: { ...formFields },
        _subscriptions: [] as any[],
        _isOnline: navigator.onLine,
      };

      for (const name of collectionNames) {
        initialData[name] = [];
      }

      const duo: any = {
        ...initialData,

        init(proxy: any) {
          const reactive = proxy.duo;

          if (debug) {
            console.log('[Duo] Alpine controller init (reactive)');
          }

          window.addEventListener('online', () => {
            reactive._isOnline = true;
          });
          window.addEventListener('offline', () => {
            reactive._isOnline = false;
          });

          for (const [propName, modelInfo] of Object.entries(meta.models) as [string, DuoModelInfo][]) {
            if (modelInfo.type === 'collection') {
              const store = db.getStore(modelInfo.store);
              if (!store) {
                if (debug) console.warn(`[Duo] Store not found: ${modelInfo.store}`);
                continue;
              }

              const observable = liveQuery(async () => {
                const rows = await store.toArray();
                return rows.filter(
                  (r: DuoRecord) =>
                    !(r._duo_operation === 'delete' && r._duo_pending_sync)
                );
              });

              const sub = observable.subscribe({
                next: (rows: DuoRecord[]) => {
                  rows.sort((a: any, b: any) => {
                    if (a.created_at && b.created_at) {
                      return (
                        new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
                      );
                    }
                    return (b.id || 0) - (a.id || 0);
                  });
                  reactive[propName] = rows;
                  if (debug) {
                    console.log(`[Duo] LiveQuery: ${propName} = ${rows.length} items`);
                  }
                },
                error: (e: any) => console.warn('[Duo] liveQuery error:', e),
              });

              reactive._subscriptions.push(sub);
            } else if (modelInfo.type === 'model') {
              const store = db.getStore(modelInfo.store);
              if (!store) continue;

              reactive[propName] = null;

              const observable = liveQuery(async () => {
                const rows = await store.toArray();
                return rows[0] || null;
              });

              const sub = observable.subscribe({
                next: (row: DuoRecord | null) => {
                  reactive[propName] = row;
                },
                error: (e: any) => console.warn('[Duo] liveQuery error:', e),
              });

              reactive._subscriptions.push(sub);
            }
          }
        },

        destroy() {
          for (const sub of duo._subscriptions) {
            if (sub && typeof sub.unsubscribe === 'function') {
              sub.unsubscribe();
            }
          }
          duo._subscriptions = [];
        },

        async submit(method: string) {
          const formData = { ...duo.form };

          if (debug) {
            console.log(`[Duo] Submit: ${method}`, formData);
          }

          const modelEntry = Object.entries(meta.models)[0];
          if (!modelEntry) return;
          const [, modelInfo] = modelEntry;

          const store = db.getStore(modelInfo.store);
          if (!store) return;

          const tempId = Date.now();
          const record: DuoRecord = {
            id: tempId,
            ...formData,
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
            _duo_pending_sync: 1,
            _duo_operation: 'create' as const,
          };

          await store.put(record);

          await syncQueue.enqueue({
            storeName: modelInfo.store,
            operation: 'create',
            data: record,
          });

          for (const key of Object.keys(duo.form)) {
            duo.form[key] = '';
          }

          if (debug) {
            console.log('[Duo] Created locally:', record);
          }

          window.dispatchEvent(
            new CustomEvent('duo:mutation', {
              detail: {
                store: modelInfo.store,
                table: modelInfo.table,
                type: 'create',
                method,
                data: record,
                offline: !duo._isOnline,
              },
            })
          );
        },

        async call(method: string, args: any[]) {
          if (debug) {
            console.log(`[Duo] Call: ${method}`, args);
          }

          const modelEntry = Object.entries(meta.models)[0];
          if (!modelEntry) return;
          const [, modelInfo] = modelEntry;

          const store = db.getStore(modelInfo.store);
          if (!store) return;

          if (method.startsWith('delete') || method.startsWith('remove')) {
            const id = args[0];
            const existing = await store.get(id);
            if (!existing) return;

            await store.delete(id);

            await syncQueue.enqueue({
              storeName: modelInfo.store,
              operation: 'delete',
              data: existing,
            });

            if (debug) console.log('[Duo] Deleted locally:', id);

            window.dispatchEvent(
              new CustomEvent('duo:mutation', {
                detail: {
                  store: modelInfo.store,
                  table: modelInfo.table,
                  type: 'delete',
                  method,
                  data: { id },
                  offline: !duo._isOnline,
                },
              })
            );
          } else if (method.startsWith('toggle') || method.startsWith('update')) {
            const id = args[0];
            const existing = await store.get(id);
            if (!existing) return;

            const updated: DuoRecord = {
              ...existing,
              updated_at: new Date().toISOString(),
              _duo_pending_sync: 1,
              _duo_operation: 'update' as const,
            };

            if (method.startsWith('toggle')) {
              if ('completed' in existing) {
                updated.completed = !existing.completed;
              }
            } else if (args.length > 1 && typeof args[1] === 'object') {
              Object.assign(updated, args[1]);
            }

            await store.put(updated);

            await syncQueue.enqueue({
              storeName: modelInfo.store,
              operation: 'update',
              data: updated,
            });

            if (debug) console.log('[Duo] Updated locally:', id);

            window.dispatchEvent(
              new CustomEvent('duo:mutation', {
                detail: {
                  store: modelInfo.store,
                  table: modelInfo.table,
                  type: 'update',
                  method,
                  data: updated,
                  offline: !duo._isOnline,
                },
              })
            );
          } else if (
            method.startsWith('add') ||
            method.startsWith('create') ||
            method.startsWith('store')
          ) {
            await duo.submit(method);
          }
        },
      };

      return { duo };
    };
  }

  private parseMethodCall(expr: string): { method: string; args: any[] } | null {
    expr = expr.trim();

    const simpleMatch = expr.match(/^([a-zA-Z_][a-zA-Z0-9_]*)$/);
    if (simpleMatch) {
      return { method: simpleMatch[1], args: [] };
    }

    const callMatch = expr.match(/^([a-zA-Z_][a-zA-Z0-9_]*)\((.+)\)$/s);
    if (!callMatch) return null;

    const method = callMatch[1];
    const argsStr = callMatch[2].trim();

    try {
      const parsed = JSON.parse(`[${argsStr}]`);
      return { method, args: parsed };
    } catch {
      const args: any[] = [];
      const trimmed = argsStr.trim();

      if (trimmed === 'null') {
        args.push(null);
      } else if (trimmed === 'true') {
        args.push(true);
      } else if (trimmed === 'false') {
        args.push(false);
      } else if (/^-?\d+(\.\d+)?$/.test(trimmed)) {
        args.push(Number(trimmed));
      } else if (/^['"].*['"]$/.test(trimmed)) {
        args.push(trimmed.slice(1, -1));
      } else {
        try {
          const fixed = trimmed.replace(/&quot;/g, '"').replace(/&#039;/g, "'");
          args.push(JSON.parse(fixed));
        } catch {
          args.push(trimmed);
        }
      }

      return { method, args };
    }
  }

  private observeNewComponents(): void {
    if (this.observer) return;

    this.observer = new MutationObserver((mutations) => {
      let shouldCompile = false;
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (node instanceof HTMLElement) {
            if (
              node.hasAttribute('data-duo-enabled') &&
              !node.hasAttribute('data-duo-compiled')
            ) {
              shouldCompile = true;
            }
            if (node.querySelector?.('[data-duo-enabled="true"]:not([data-duo-compiled])')) {
              shouldCompile = true;
            }
          }
        }
      }
      if (shouldCompile) {
        requestAnimationFrame(() => this.compileAll());
      }
    });

    this.observer.observe(document.body, { childList: true, subtree: true });
  }

  destroy(): void {
    if (this.observer) {
      this.observer.disconnect();
      this.observer = undefined;
    }
    for (const sub of this.subscriptions.values()) {
      if (sub && typeof sub.unsubscribe === 'function') {
        sub.unsubscribe();
      }
    }
    this.subscriptions.clear();
  }
}

declare global {
  interface Window {
    Livewire: any;
    Alpine: any;
    __duoControllers: Record<string, () => any>;
  }
}
