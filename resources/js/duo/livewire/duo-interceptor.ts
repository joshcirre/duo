import type { DuoDatabase } from '../core/database';
import type { SyncQueue } from '../sync/queue';
import type { DuoAlpineIntegration } from './alpine-integration';

/**
 * Simple Duo interceptor for Livewire components marked with UsesDuo trait
 */
export class DuoLivewireInterceptor {
  private db: DuoDatabase;
  private syncQueue: SyncQueue;
  private debug: boolean;
  private alpineIntegration: DuoAlpineIntegration;

  constructor(
    db: DuoDatabase,
    syncQueue: SyncQueue,
    debug = false,
    alpineIntegration: DuoAlpineIntegration
  ) {
    this.db = db;
    this.syncQueue = syncQueue;
    this.debug = debug;
    this.alpineIntegration = alpineIntegration;
  }

  /**
   * Initialize the interceptor
   */
  initialize(): void {
    if (this.debug) {
      console.log('[Duo] Waiting for Livewire to initialize...');
    }

    // Wait for Livewire to be available
    document.addEventListener('livewire:init', () => {
      if (this.debug) {
        console.log('[Duo] Livewire initialized, setting up Duo interceptor');
      }

      this.setupInterceptor();
    });
  }

  /**
   * Setup the Livewire interceptor
   */
  private setupInterceptor(): void {
    if (typeof window.Livewire === 'undefined') {
      console.warn('[Duo] Livewire is not available');
      return;
    }

    // Hook into component initialization
    window.Livewire.hook('component.init', ({ component }: any) => {
      this.initializeComponent(component);
    });

    // Intercept duo-action events dispatched from Alpine
    // Use both document and window to catch bubbled events
    const handleDuoAction = async (e: Event) => {
      console.log('[Duo] duo-action event received!', e);
      const customEvent = e as CustomEvent;
      const { method, params } = customEvent.detail;

      console.log('[Duo] 🎯 Intercepting duo-action:', method, params);

      // Find the component element - look for any Duo-enabled component on the page
      const componentEl = document.querySelector('[data-duo-enabled][wire\\:id]');

      if (!componentEl) {
        console.warn('[Duo] Could not find Duo-enabled Livewire component');
        return;
      }

      const wireId = componentEl.getAttribute('wire:id');
      const component = window.Livewire.find(wireId);

      if (!component) {
        console.warn('[Duo] Could not find Livewire component instance');
        return;
      }

      // Handle it locally in IndexedDB
      const call = { method, params };
      await this.handleCallLocally(component, call);

      // Call the component's duoSync() method to update its reactive data
      if (typeof (window as any).Alpine !== 'undefined' && componentEl) {
        const alpineData = (window as any).Alpine.$data(componentEl);
        if (alpineData && typeof alpineData.duoSync === 'function') {
          console.log('[Duo] Calling component duoSync()');
          await alpineData.duoSync();
        } else {
          console.warn('[Duo] Component does not have duoSync() method');
        }
      }
    };

    // Register the listener on both document and window
    document.addEventListener('duo-action', handleDuoAction);
    window.addEventListener('duo-action', handleDuoAction);
    console.log('[Duo] Registered duo-action event listeners on document and window');

    // Intercept duo-action submits (transformed from wire:submit)
    document.addEventListener('submit', async (e) => {
      const target = e.target as HTMLFormElement;
      const duoElement = target.closest('[data-duo-action][data-duo-trigger="submit"]');

      if (!duoElement) return;

      const action = duoElement.getAttribute('data-duo-action');
      if (!action) return;

      const componentEl = duoElement.closest('[wire\\:id]');
      if (!componentEl) return;

      const wireId = componentEl.getAttribute('wire:id');
      const component = window.Livewire.find(wireId);

      if (!component) return;

      if (this.debug) {
        console.log('[Duo] 🎯 Intercepting duo-submit:', action);
      }

      e.preventDefault();
      e.stopPropagation();

      // Extract form data from inputs with data-duo-model
      const formData = this.extractFormData(target);

      if (this.debug) {
        console.log('[Duo] Form data extracted:', formData);
      }

      const call = { method: action, params: [], formData };
      await this.handleCallLocally(component, call);

      // Call the component's duoSync() method to update its reactive data
      const formComponentEl = target.closest('[data-duo-enabled][wire\\:id]');
      if (typeof (window as any).Alpine !== 'undefined' && formComponentEl) {
        const alpineData = (window as any).Alpine.$data(formComponentEl);
        if (alpineData && typeof alpineData.duoSync === 'function') {
          console.log('[Duo] Calling component duoSync() after form submit');
          await alpineData.duoSync();
        }
      }

      // Clear the form after successful submission
      target.reset();
    });

    // Hook into responses to cache Eloquent model operations
    window.Livewire.hook('message.received', async ({ component, message }: any) => {
      await this.handleResponse(component, message);
    });

    // Log commit payload to see what data we have
    window.Livewire.hook('commit.prepare', ({ component, commit }: any) => {
      if (this.debug && this.isComponentDuoEnabled(component)) {
        console.log('[Duo] Commit prepare:', {
          component: component.name,
          commit,
          state: component.$wire?.$get?.('$all'),
        });
      }
    });

    if (this.debug) {
      console.log('[Duo] Interceptor hooks registered');
    }
  }

  /**
   * Initialize a Duo-enabled component
   */
  private async initializeComponent(component: any): Promise<void> {
    // Check if component has Duo enabled
    if (!this.isComponentDuoEnabled(component)) {
      return;
    }

    if (this.debug) {
      console.log('[Duo] Duo-enabled component initialized:', component.name);
      console.log('[Duo] Component state:', component.$wire?.$get?.('$all'));
    }

    // Cache any models that came from the server
    const componentData = component.$wire?.$get?.('$all') || {};
    await this.cacheModelsFromData(componentData);

    // Note: We don't hydrate here anymore - the component's init() method
    // in x-data will call duoSync() after Alpine initializes
  }

  /**
   * Handle Livewire response and extract model data
   */
  private async handleResponse(component: any, message: any): Promise<void> {
    if (!this.isComponentDuoEnabled(component)) {
      return;
    }

    if (this.debug) {
      console.log('[Duo] Message received:', {
        component: component.name,
        message,
        response: message.response,
      });
    }

    // Extract data from response
    const responseData = message.response?.serverMemo?.data || message.response?.effects?.returns || {};

    // Look for model data in the response and cache it
    await this.cacheModelsFromData(responseData);

    // Also check the component's current state
    const componentData = component.$wire?.$get?.('$all') || {};
    await this.cacheModelsFromData(componentData);

    // Component will sync via duoSync() in its init() method
  }

  /**
   * Cache models found in data
   */
  private async cacheModelsFromData(data: any): Promise<void> {
    if (!data || typeof data !== 'object') return;

    for (const [key, value] of Object.entries(data)) {
      // Check if this is a model or collection of models
      if (Array.isArray(value)) {
        // It's a collection
        for (const item of value) {
          if (this.isModelData(item)) {
            await this.cacheModel(item);
          }
        }
      } else if (this.isModelData(value)) {
        // It's a single model
        await this.cacheModel(value);
      }
    }
  }

  /**
   * Cache a single model to IndexedDB
   */
  private async cacheModel(model: any): Promise<void> {
    const storeName = this.getStoreNameForModel(model);
    if (!storeName) {
      if (this.debug) {
        console.log('[Duo] Could not determine store for model:', model);
      }
      return;
    }

    const store = this.db.getStore(storeName);
    if (!store) {
      if (this.debug) {
        console.log('[Duo] Store not found:', storeName);
      }
      return;
    }

    try {
      await store.put({
        ...model,
        _duo_synced_at: Date.now(),
      });

      if (this.debug) {
        console.log('[Duo] ✅ Successfully cached model to IndexedDB:', {
          storeName,
          id: model.id,
          model
        });

        // Verify it was saved
        const saved = await store.get(model.id);
        console.log('[Duo] ✅ Verified in IndexedDB:', saved);
      }
    } catch (error) {
      console.error('[Duo] ❌ Error caching model:', error);
    }
  }

  /**
   * Handle Livewire commits (actions/updates)
   * Returns true if we handled it locally and should prevent network request
   */
  private async handleCommit(component: any, commit: any, respond: any): Promise<boolean> {
    // Check if this component uses Duo
    if (!this.isComponentDuoEnabled(component)) {
      return false; // Let normal Livewire flow happen
    }

    if (this.debug) {
      console.log('[Duo] Intercepting commit for Duo component:', {
        component: component.name,
        calls: commit.calls,
      });
    }

    // Handle each call locally
    const calls = Array.isArray(commit.calls) ? commit.calls : [];
    let handledLocally = false;

    for (const call of calls) {
      const wasHandled = await this.handleCallLocally(component, call);
      if (wasHandled) {
        handledLocally = true;
      }
    }

    // Component will sync via duoSync() which is called after handleCallLocally

    // Return true to prevent network request if we handled it
    return handledLocally;
  }

  /**
   * Extract form data from a form element
   */
  private extractFormData(form: HTMLFormElement): Record<string, any> {
    const formData: Record<string, any> = {};
    const inputs = form.querySelectorAll('[data-duo-model]');

    inputs.forEach((input: any) => {
      const modelName = input.getAttribute('data-duo-model');
      if (modelName) {
        formData[modelName] = input.value || '';
      }
    });

    return formData;
  }

  /**
   * Handle a method call locally (write to IndexedDB first)
   * Returns true if we handled it locally
   */
  private async handleCallLocally(component: any, call: any): Promise<boolean> {
    // call is an object like { method: 'addTodo', params: [...], formData: {...} }
    const method = call.method || call[0];
    const params = call.params || call[1] || [];
    const formData = call.formData || {};

    console.log('[Duo] Handling call locally:', { method, params, formData });

    const storeName = this.getStoreNameForComponent(component);
    if (!storeName) {
      console.warn('[Duo] No store found for component');
      return false;
    }

    console.log('[Duo] Using store:', storeName);

    const store = this.db.getStore(storeName);
    if (!store) {
      console.warn('[Duo] Store not found:', storeName);
      return false;
    }

    // Handle common CRUD operations
    if (method === 'addTodo' || method.startsWith('create') || method.startsWith('add')) {
      // Use form data if available, otherwise try to extract from component
      const data = Object.keys(formData).length > 0
        ? this.transformFormDataToModel(formData, method)
        : this.extractDataFromComponent(component, method);

      if (data) {
        // Add to IndexedDB with temporary ID
        const tempId = Date.now();
        await store.put({
          id: tempId,
          ...data,
          created_at: new Date().toISOString(),
          updated_at: new Date().toISOString(),
          _duo_pending_sync: true,
          _duo_temp_id: true,
        });

        if (this.debug) {
          console.log('[Duo] Added to IndexedDB:', data);
        }
        return true;
      }
    }

    if (method.startsWith('toggle') || method.startsWith('update')) {
      console.log('[Duo] Toggle/Update operation detected');
      // Extract ID from params
      const id = params && params.length > 0 ? params[0] : null;
      console.log('[Duo] ID from params:', id);

      if (id) {
        const existing = await store.get(id);
        console.log('[Duo] Existing record:', existing);

        if (existing) {
          // Toggle or update the record
          const updated = {
            ...existing,
            completed: method.startsWith('toggle') ? !existing.completed : existing.completed,
            updated_at: new Date().toISOString(),
            _duo_pending_sync: true,
          };

          await store.put(updated);
          console.log('[Duo] ✅ Updated in IndexedDB:', updated);
          return true;
        } else {
          console.warn('[Duo] ❌ Record not found in IndexedDB:', id);
        }
      }
    }

    if (method.startsWith('delete') || method.startsWith('remove')) {
      console.log('[Duo] Delete operation detected');
      const id = params && params.length > 0 ? params[0] : null;
      console.log('[Duo] ID to delete:', id);

      if (id) {
        await store.delete(id);
        console.log('[Duo] ✅ Deleted from IndexedDB:', id);
        return true;
      }
    }

    return false;
  }

  /**
   * Load data from IndexedDB and populate component
   */
  private async hydrateFromIndexedDB(component: any): Promise<void> {
    const storeName = this.getStoreNameForComponent(component);
    if (!storeName) return;

    const store = this.db.getStore(storeName);
    if (!store) return;

    try {
      const data = await store.toArray();

      // Sync with Alpine if the component has Alpine data
      if (component.el) {
        await this.alpineIntegration.syncFromIndexedDB(component.el, storeName, this.db);

        if (this.debug) {
          console.log(`[Duo] Synced Alpine data with ${data.length} records from IndexedDB`);
        }
      }

      // Also update Livewire component state for backward compatibility
      const dataProperty = this.findDataProperty(component);
      if (dataProperty && component.$wire) {
        component.$wire.$set(dataProperty, data);

        if (this.debug) {
          console.log(`[Duo] Hydrated ${dataProperty} with ${data.length} records from IndexedDB`);
        }
      }
    } catch (error) {
      if (this.debug) {
        console.error('[Duo] Error hydrating from IndexedDB:', error);
      }
    }
  }

  /**
   * Transform form data to model properties
   */
  private transformFormDataToModel(formData: Record<string, any>, method: string): Record<string, any> | null {
    const modelData: Record<string, any> = {};

    // For addTodo, transform newTodoTitle -> title, newTodoDescription -> description
    for (const [key, value] of Object.entries(formData)) {
      if (key.startsWith('new')) {
        // Convert newTodoTitle -> title, newTodoDescription -> description
        const fieldName = key.replace(/^new[A-Z][a-z]+/, '').toLowerCase();
        if (fieldName && value) {
          modelData[fieldName] = value;
        }
      } else {
        // Use as-is
        modelData[key] = value;
      }
    }

    return Object.keys(modelData).length > 0 ? modelData : null;
  }

  /**
   * Extract data from component properties for creating/updating
   */
  private extractDataFromComponent(component: any, method: string): Record<string, any> | null {
    // For addTodo, look for newTodo* properties
    if (method === 'addTodo' || method.startsWith('add')) {
      const data: Record<string, any> = {};
      const props = component.$wire?.$get?.('$all') || {};

      for (const [key, value] of Object.entries(props)) {
        if (key.startsWith('new') && !key.includes('$')) {
          // Convert newTodoTitle -> title
          const fieldName = key.replace(/^new[A-Z][a-z]+/, '').toLowerCase();
          if (fieldName && value) {
            data[fieldName] = value;
          }
        }
      }

      return Object.keys(data).length > 0 ? data : null;
    }

    return null;
  }

  /**
   * Find the property that holds the data collection
   */
  private findDataProperty(component: any): string | null {
    const props = component.$wire?.$get?.('$all') || {};
    const commonNames = ['todos', 'items', 'records', 'data', 'models'];

    for (const name of commonNames) {
      if (props[name] !== undefined && Array.isArray(props[name])) {
        return name;
      }
    }

    return null;
  }

  /**
   * Get store name for a component
   */
  private getStoreNameForComponent(component: any): string | null {
    // Try to get from component metadata
    // For now, just return the first available store
    const stores = this.db.getAllStores();
    for (const [storeName] of stores) {
      return storeName;
    }

    return null;
  }

  /**
   * Check if component has Duo enabled
   */
  private isComponentDuoEnabled(component: any): boolean {
    // Check if component's DOM element has data-duo-enabled attribute
    // This attribute is added by the Duo trait's renderedDuo() method
    const el = component.el;
    if (el && el.hasAttribute && el.hasAttribute('data-duo-enabled')) {
      return el.getAttribute('data-duo-enabled') === 'true';
    }

    // Fallback: check component metadata (if available)
    return component.effects?.duo?.enabled === true ||
           component.serverMemo?.data?.duoEnabled === true;
  }

  /**
   * Check if data looks like an Eloquent model
   */
  private isModelData(data: any): boolean {
    return (
      typeof data === 'object' &&
      data !== null &&
      'id' in data &&
      !Array.isArray(data)
    );
  }

  /**
   * Get store name for a model instance
   */
  private getStoreNameForModel(model: any): string | null {
    // Get all available stores
    const stores = this.db.getAllStores();

    // For each store, check if its config matches this model's structure
    for (const [storeName, store] of stores) {
      const config = this.db.getStoreConfig(storeName);
      if (!config) continue;

      // Check if the model has the expected primary key
      if (config.primaryKey in model) {
        // This is likely the right store
        return storeName;
      }
    }

    // Fallback: just return the first store if we only have one
    if (stores.size === 1) {
      return Array.from(stores.keys())[0];
    }

    return null;
  }
}

// Extend Window interface
declare global {
  interface Window {
    Livewire: any;
  }
}
