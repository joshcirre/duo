import type { Plugin } from 'vite';
import { resolve } from 'path';
import { existsSync, readFileSync } from 'fs';
import { execSync } from 'child_process';

export interface DuoPluginOptions {
  /**
   * Path to the Duo manifest file
   */
  manifestPath?: string;

  /**
   * Whether to watch for manifest changes and trigger reload
   */
  watch?: boolean;

  /**
   * Base path for Duo resources
   */
  basePath?: string;

  /**
   * Whether to automatically run php artisan duo:generate
   */
  autoGenerate?: boolean;
}

/**
 * Vite plugin for Duo - IndexedDB syncing for Laravel/Livewire
 */
export function duo(options: DuoPluginOptions = {}): Plugin {
  const {
    manifestPath = 'resources/js/duo/manifest.json',
    watch = true,
    basePath = process.cwd(),
    autoGenerate = true,
  } = options;

  const resolvedManifestPath = resolve(basePath, manifestPath);
  const VIRTUAL_MODULE_ID = 'virtual:duo-manifest';
  const RESOLVED_VIRTUAL_MODULE_ID = '\0' + VIRTUAL_MODULE_ID;

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

    transform(code, id) {
      // Inject Duo client initialization in Livewire components
      if (id.includes('@livewire') || id.includes('livewire/livewire.js')) {
        const duoImport = `import { initializeDuo } from '@joshcirre/duo/client';\ninitializeDuo();`;
        return {
          code: `${duoImport}\n${code}`,
          map: null,
        };
      }

      return null;
    },

    buildStart() {
      // Auto-generate manifest if it doesn't exist
      if (!existsSync(resolvedManifestPath) && autoGenerate) {
        console.log('[Duo] Manifest not found. Running php artisan duo:generate...');
        try {
          execSync('php artisan duo:generate', {
            cwd: basePath,
            stdio: 'inherit'
          });
          console.log('[Duo] Manifest generated successfully');
        } catch (error) {
          console.error('[Duo] Failed to generate manifest:', error);
          return;
        }
      }

      if (existsSync(resolvedManifestPath)) {
        this.addWatchFile(resolvedManifestPath);

        try {
          const manifest = JSON.parse(readFileSync(resolvedManifestPath, 'utf-8'));
          console.log(`[Duo] Loaded manifest with ${Object.keys(manifest).length} model(s)`);
        } catch (error) {
          console.warn('[Duo] Failed to parse manifest file:', error);
        }
      } else {
        console.warn(
          `[Duo] Manifest file not found at ${resolvedManifestPath}. Run 'php artisan duo:generate' to create it.`
        );
      }
    },
  };
}

export default duo;
