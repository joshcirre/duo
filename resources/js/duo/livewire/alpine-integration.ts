/**
 * Alpine integration for Duo components
 * Manages reactive data for local-first rendering
 */

export class DuoAlpineIntegration {
  private componentStores = new Map<HTMLElement, any>();

  /**
   * Initialize Alpine data for a Duo component
   * Called from x-data directive in transformed HTML
   */
  public alpine(element: HTMLElement, initialData: any): any {
    console.log('[Duo Alpine] Initializing with data:', initialData);

    // Create reactive Alpine data store
    const store = {
      ...initialData,

      // Helper to get array data (like todos)
      getList(key: string) {
        return this[key] || [];
      },

      // Helper to update list data
      updateList(key: string, items: any[]) {
        this[key] = items;
      },

      // Helper to add item to list
      addToList(key: string, item: any) {
        if (!this[key]) this[key] = [];
        this[key] = [...this[key], item];
      },

      // Helper to remove item from list
      removeFromList(key: string, id: number) {
        if (!this[key]) return;
        this[key] = this[key].filter((item: any) => item.id !== id);
      },

      // Helper to update item in list
      updateInList(key: string, id: number, updates: any) {
        if (!this[key]) return;
        this[key] = this[key].map((item: any) =>
          item.id === id ? { ...item, ...updates } : item
        );
      },
    };

    // Store reference for later updates
    this.componentStores.set(element, store);

    // Sync initial server data to IndexedDB
    // This needs to happen AFTER Duo is initialized
    this.syncServerDataToIndexedDB(element, initialData);

    return store;
  }

  /**
   * Sync server data from x-data to IndexedDB on component mount
   */
  private async syncServerDataToIndexedDB(element: HTMLElement, data: any): Promise<void> {
    // Wait a bit for Duo to initialize
    setTimeout(async () => {
      try {
        const duo = (window as any).duo;
        if (!duo || !duo.getDatabase()) {
          console.warn('[Duo Alpine] Duo not initialized yet, skipping sync');
          return;
        }

        console.log('[Duo Alpine] Syncing server data to IndexedDB...');

        // Find array properties in the data (like 'todos')
        for (const key in data) {
          if (Array.isArray(data[key]) && data[key].length > 0) {
            const items = data[key];
            console.log(`[Duo Alpine] Found array property "${key}" with ${items.length} items`);

            // Determine the store name from the property name
            // e.g., 'todos' → 'App_Models_Todo'
            const singularName = key.endsWith('s') ? key.slice(0, -1) : key;
            const storeName = `App_Models_${singularName.charAt(0).toUpperCase()}${singularName.slice(1)}`;

            console.log(`[Duo Alpine] Syncing to store: ${storeName}`);

            const db = duo.getDatabase();
            const store = db?.getStore(storeName);

            if (store) {
              // Clear existing data first - server state is source of truth
              await store.clear();
              console.log(`[Duo Alpine] Cleared existing data from ${storeName}`);

              // Then sync fresh server data to IndexedDB
              await store.bulkPut(
                items.map((item: any) => ({
                  ...item,
                  _duo_synced_at: Date.now(),
                  _duo_pending_sync: false,
                }))
              );
              console.log(`[Duo Alpine] Synced ${items.length} items to ${storeName} (replaced all)`);
            } else {
              console.warn(`[Duo Alpine] Store not found: ${storeName}`);
            }
          }
        }
      } catch (error) {
        console.error('[Duo Alpine] Error syncing server data:', error);
      }
    }, 100); // Small delay to ensure Duo is initialized
  }

  /**
   * Get the Alpine store for a component element
   */
  public getStore(element: HTMLElement): any {
    return this.componentStores.get(element);
  }

  /**
   * Update Alpine data from IndexedDB
   */
  public async syncFromIndexedDB(
    element: HTMLElement,
    storeName: string,
    db: any
  ): Promise<void> {
    const store = db.getStore(storeName);
    if (!store) return;

    // Get all items from IndexedDB (Dexie uses toArray, not getAll)
    const items = await store.toArray();

    console.log('[Duo Alpine] Syncing from IndexedDB:', items.length, 'items');

    // Access Alpine's reactive scope directly via __x
    const alpineScope = (element as any).__x;

    if (!alpineScope) {
      console.error('[Duo Alpine] Element does not have Alpine scope (__x)');
      console.log('[Duo Alpine] Element:', element);
      return;
    }

    console.log('[Duo Alpine] Found Alpine scope, getting reactive data...');

    // Alpine stores reactive data in __x.$data (or sometimes __x.data)
    // We need to update it through Alpine's reactive system
    const reactiveData = alpineScope.$data || alpineScope.data;

    if (!reactiveData) {
      console.error('[Duo Alpine] Could not access reactive data from Alpine scope');
      return;
    }

    console.log('[Duo Alpine] Successfully accessed Alpine reactive data');
    console.log('[Duo Alpine] Current todos:', reactiveData.todos);

    // Find the array property (should be 'todos')
    const dataKey = this.findArrayProperty(reactiveData);

    if (dataKey) {
      console.log(`[Duo Alpine] Updating ${dataKey} with ${items.length} items`);

      // Update directly on Alpine's reactive data
      // This will trigger Alpine's reactivity
      reactiveData[dataKey] = items;

      console.log('[Duo Alpine] ✅ Updated reactive data');
      console.log('[Duo Alpine] New todos:', reactiveData.todos);
    } else {
      console.warn('[Duo Alpine] Could not find array property in reactive data');
      console.log('[Duo Alpine] Available keys:', Object.keys(reactiveData));
    }
  }

  /**
   * Find the first array property in the Alpine data
   */
  private findArrayProperty(data: any): string | null {
    for (const key in data) {
      if (Array.isArray(data[key]) && typeof data[key] !== 'function') {
        return key;
      }
    }
    return null;
  }
}
