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
  } = options;

  const resolvedManifestPath = resolve(basePath, manifestPath);
  const VIRTUAL_MODULE_ID = 'virtual:duo-manifest';
  const RESOLVED_VIRTUAL_MODULE_ID = '\0' + VIRTUAL_MODULE_ID;

  // Normalize patterns for cross-platform compatibility
  const normalizedPatterns = patterns.map((pattern) => pattern.replace(/\\/g, '/'));

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
