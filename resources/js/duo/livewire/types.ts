/**
 * Shapes shared between the server (DuoServiceProvider::extractDuoMetadata,
 * DuoSynth) and the client.
 */

export interface DuoSchemaField {
  type: string; // 'string' | 'number' | 'boolean' | 'date' | 'object' | 'blob'
  nullable: boolean;
  default: any;
  autoIncrement?: boolean;
}

export interface DuoModelInfo {
  store: string;
  table: string;
  primaryKey: string;
  type?: 'model' | 'collection';
  schema?: Record<string, DuoSchemaField>;
  fillable?: string[];
}

export interface DuoMethodMeta {
  params: Array<{ name: string; type: string | null; isModel: boolean }>;
  operation: 'create' | 'update' | 'delete' | null;
  /** Columns the method flips: 'completed' => ! $todo->completed */
  toggles?: string[];
  /** Component property → column: 'title' => $this->newTodoTitle */
  mapping?: Record<string, string>;
  /** Column → literal written on create: 'completed' => false */
  defaults?: Record<string, any>;
}

export interface DuoComponentMeta {
  enabled: boolean;
  component: string;
  models: Record<string, DuoModelInfo>;
  methods: Record<string, DuoMethodMeta>;
}

export interface DuoOperation {
  type: 'create' | 'update' | 'delete';
  store: string;
  table: string;
  key: any;
  data: Record<string, any>;
}

export interface DuoEffect {
  enabled: boolean;
  meta: DuoComponentMeta;
  state: Record<string, any[]>;
  operations?: DuoOperation[];
}

export function parseComponentMeta(el: Element | null): DuoComponentMeta | null {
  const raw = el?.getAttribute('data-duo-meta');
  if (!raw) return null;

  try {
    return JSON.parse(raw) as DuoComponentMeta;
  } catch (e) {
    console.warn('[Duo] Failed to parse component meta:', e);
    return null;
  }
}
