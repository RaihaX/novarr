import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.scss',
                'resources/css/vendor.css',
                'resources/js/app.js',
                'resources/js/vendor.js',
            ],
            refresh: true,
        }),
    ],
    build: {
        manifest: true,
    },
    server: {
        hmr: {
            host: 'localhost',
        },
    },
});
