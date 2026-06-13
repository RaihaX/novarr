import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.scss',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                // Use the modern Sass API (the legacy JS API is what triggers
                // the legacy-js-api deprecation flood on every build).
                api: 'modern',
                // Bootstrap 5.3's internals still use @import and deprecated
                // color/math functions; nothing we can fix until Bootstrap 6.
                quietDeps: true,
                // Our own entrypoint must keep @import (Bootstrap variable
                // overrides rely on global scope, which @use does not allow).
                silenceDeprecations: ['import'],
            },
        },
    },
    build: {
        manifest: 'manifest.json',
    },
    server: {
        hmr: {
            host: 'localhost',
        },
    },
});
