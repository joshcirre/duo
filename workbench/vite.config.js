import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// The workbench app is served by the testbench skeleton, so built assets
// (and the dev-server hot file) must land in the skeleton's public directory.
const skeletonPublic = 'vendor/orchestra/testbench-core/laravel/public';

export default defineConfig({
    plugins: [
        laravel({
            input: ['workbench/resources/js/app.js'],
            publicDirectory: skeletonPublic,
            refresh: ['workbench/resources/views/**'],
        }),
    ],
    resolve: {
        alias: {
            '@': '/workbench/resources/js',
        },
    },
});
