import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/avatar-3d.js', 'resources/js/argument-map.js', 'resources/js/lesson-player.js', 'resources/js/timemap/index.js', 'resources/js/lesson-map.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        // The hero "launch" animation dynamically imports three.js (~1.7 MB). Vite would emit a
        // <link rel="modulepreload"> for that chunk on every page that loads app.js — including the
        // landing page — pulling 1.7 MB nobody needs until they actually click. Disabling preload
        // keeps the dynamic import working but defers the download to on-demand.
        modulePreload: false,
    },
    server: {
        host: '127.0.0.1',
        port: 5173,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        proxy: {
            '/fonts': 'http://127.0.0.1:8000',
        },
    },
});
