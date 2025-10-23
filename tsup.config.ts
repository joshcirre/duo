import { defineConfig } from 'tsup';

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
});
