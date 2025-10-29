import { defineConfig } from 'tsup';
import { copyFileSync, mkdirSync } from 'fs';
import { join } from 'path';

export default defineConfig({
  entry: {
    'index': 'vite-plugin/src/index.ts',
    'client': 'resources/js/duo/index.ts',
  },
  outDir: 'dist',
  format: ['esm'],
  dts: true,
  clean: true,
  sourcemap: true,
  external: ['vite', 'dexie'],
  treeshake: true,
  async onSuccess() {
    // Copy service worker to dist for publishing
    try {
      mkdirSync(join('dist', 'public'), { recursive: true });
      copyFileSync(
        join('resources', 'js', 'duo', 'service-worker.js'),
        join('dist', 'public', 'duo-sw.js')
      );
      console.log('✓ Service worker copied to dist/public/duo-sw.js');
    } catch (error) {
      console.error('Failed to copy service worker:', error);
    }
  },
});
