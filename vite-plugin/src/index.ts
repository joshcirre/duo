import type { Plugin, HmrContext } from 'vite';
import type { PluginContext } from 'rollup';
import { resolve } from 'path';
import { existsSync, readFileSync } from 'fs';
import { exec } from 'child_process';
import { promisify } from 'util';
import { minimatch } from 'minimatch';
import osPath from 'path';

const execAsync = promisify(exec);

export interface DuoPluginOptions {
  /**
   * Path to the Duo manifest file
   */
  manifestPath?: string;

  /**
   * Whether to watch for model changes and regenerate manifest
   */
  watch?: boolean;

  /**
   * Glob patterns to watch for model changes
   */
  patterns?: string[];

  /**
   * Base path for Duo resources
   */
  basePath?: string;

  /**
   * Whether to automatically run php artisan duo:generate
   */
  autoGenerate?: boolean;

  /**
   * Custom artisan command to run
   */
  command?: string;

  /**
   * Path to your main JavaScript entry file (relative to basePath)
   * Duo will automatically inject initialization code into this file
   */
  entry?: string;

  /**
   * Whether to automatically inject Duo initialization code
   * Set to false if you want to manually initialize Duo
   */
  autoInject?: boolean;
}

/**
 * Vite plugin for Duo - IndexedDB syncing for Laravel/Livewire
 */
export function duo(options: DuoPluginOptions = {}): Plugin {
  const {
    manifestPath = 'resources/js/duo/manifest.json',
    watch = true,
    patterns = ['app/Models/**/*.php'],
    basePath = process.cwd(),
    autoGenerate = true,
    command = 'php artisan duo:generate',
    entry = 'resources/js/app.js',
    autoInject = true,
  } = options;

  const resolvedManifestPath = resolve(basePath, manifestPath);
  const VIRTUAL_MODULE_ID = 'virtual:duo-manifest';
  const RESOLVED_VIRTUAL_MODULE_ID = '\0' + VIRTUAL_MODULE_ID;

  // Normalize patterns for cross-platform compatibility
  const normalizedPatterns = patterns.map((pattern) => pattern.replace(/\\/g, '/'));

  // Normalize entry path for cross-platform compatibility
  const resolvedEntryPath = resolve(basePath, entry);
  const normalizedEntryPath = resolvedEntryPath.replace(/\\/g, '/');

  let context: PluginContext;

  const runGenerate = async (reason?: string) => {
    if (!autoGenerate) return;

    if (reason) {
      context.info(`[Duo] ${reason}, regenerating manifest...`);
    }

    try {
      await execAsync(command, { cwd: basePath });

      if (existsSync(resolvedManifestPath)) {
        const manifest = JSON.parse(readFileSync(resolvedManifestPath, 'utf-8'));
        context.info(`[Duo] Manifest generated with ${Object.keys(manifest).length} model(s)`);
      }
    } catch (error) {
      context.error('[Duo] Failed to generate manifest: ' + error);
    }
  };

  const shouldRegenerate = (file: string, server: HmrContext['server']): boolean => {
    const normalizedFile = file.replace(/\\/g, '/');

    return normalizedPatterns.some((pattern) => {
      const resolvedPattern = osPath
        .resolve(server.config.root, pattern)
        .replace(/\\/g, '/');

      return minimatch(normalizedFile, resolvedPattern);
    });
  };

  return {
    name: 'vite-plugin-duo',

    resolveId(id) {
      if (id === VIRTUAL_MODULE_ID) {
        return RESOLVED_VIRTUAL_MODULE_ID;
      }
    },

    load(id) {
      if (id === RESOLVED_VIRTUAL_MODULE_ID) {
        if (existsSync(resolvedManifestPath)) {
          const manifest = readFileSync(resolvedManifestPath, 'utf-8');
          return `export default ${manifest}`;
        }
        return `export default {}`;
      }
    },

    config() {
      return {
        optimizeDeps: {
          include: ['dexie'],
        },
      };
    },

    configureServer(server) {
      if (!watch) {
        return;
      }

      // Watch the manifest file for changes
      server.watcher.add(resolvedManifestPath);

      server.watcher.on('change', (path) => {
        if (path === resolvedManifestPath) {
          server.ws.send({
            type: 'full-reload',
            path: '*',
          });
        }
      });
    },

    async handleHotUpdate({ file, server }) {
      if (watch && shouldRegenerate(file, server)) {
        await runGenerate('Model file changed');
      }
    },

    transform(code, id) {
      if (!autoInject) {
        return null;
      }

      // Normalize the file ID for cross-platform comparison
      const normalizedId = id.replace(/\\/g, '/');

      // Check if this is the user's entry file
      if (normalizedId === normalizedEntryPath || normalizedId.endsWith('/' + entry)) {
        // Inject Duo initialization at the top of the entry file
        const duoInit = `import { initializeDuo } from '@joshcirre/vite-plugin-duo/client';
import manifest from 'virtual:duo-manifest';

// Auto-initialized by Duo Vite plugin
initializeDuo({
  manifest,
  debug: import.meta.env.DEV,
  syncInterval: 5000,
  maxRetries: 3,
}).catch((error) => {
  console.error('[Duo] Initialization failed:', error);
});

`;
        return {
          code: `${duoInit}${code}`,
          map: null,
        };
      }

      return null;
    },

    async buildStart() {
      context = this;

      // Auto-generate manifest at build start
      if (autoGenerate) {
        await runGenerate(!existsSync(resolvedManifestPath) ? 'Manifest not found' : undefined);
      }

      if (existsSync(resolvedManifestPath)) {
        this.addWatchFile(resolvedManifestPath);

        try {
          const manifest = JSON.parse(readFileSync(resolvedManifestPath, 'utf-8'));
          if (!autoGenerate) {
            context.info(`[Duo] Loaded manifest with ${Object.keys(manifest).length} model(s)`);
          }
        } catch (error) {
          context.warn('[Duo] Failed to parse manifest file: ' + error);
        }
      } else if (!autoGenerate) {
        context.warn(
          `[Duo] Manifest file not found at ${resolvedManifestPath}. Run 'php artisan duo:generate' to create it.`
        );
      }
    },
  };
}

export default duo;
