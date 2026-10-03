import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';

/**
 * Drop non-Latin script subsets from Fontsource stylesheets.
 *
 * The @fontsource-variable packages only ship all-subsets CSS (index.css /
 * wght*.css: cyrillic, cyrillic-ext, greek, vietnamese, symbols…), so the
 * subset can't be picked by import path. Novarr's content is English, so only
 * the latin + latin-ext @font-face rules are kept; their woff2 files are then
 * the only ones Vite emits (less CSS to parse, fewer files for the service
 * worker to cache). Characters outside those ranges use the stack fallback.
 */
const FONT_SUBSETS_KEPT = /-latin(-ext)?-/;

export function fontsourceLatinOnly() {
    return {
        name: 'novarr:fontsource-latin-only',
        enforce: 'pre',
        transform(code, id) {
            if (!/[\\/]@fontsource(-variable)?[\\/].+\.css$/.test(id)) return null;
            const out = code.replace(
                /(\/\*[^*]*\*\/\s*)?@font-face\s*\{[^}]*\}\s*/g,
                (block) => {
                    const src = block.match(/url\(([^)]+)\)/);
                    return !src || FONT_SUBSETS_KEPT.test(src[1]) ? block : '';
                },
            );
            return { code: out, map: null };
        },
    };
}

export default defineConfig({
    plugins: [
        fontsourceLatinOnly(),
        laravel({
            input: [
                'resources/css/app.scss',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
    resolve: {
        alias: [
            // The bare 'bootstrap' JS import resolves to our slim re-export of
            // only the plugins in use (see resources/js/bootstrap.js). Exact
            // match only: 'bootstrap/scss/…' and 'bootstrap/js/src/…' are
            // untouched.
            {
                find: /^bootstrap$/,
                replacement: fileURLToPath(new URL('./resources/js/bootstrap.js', import.meta.url)),
            },
        ],
    },
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
        // Evergreen browsers only (the PWA already needs service workers and
        // ES modules); no legacy transpilation.
        target: 'es2020',
        // Keep per-chunk CSS: the reader-only font CSS (Atkinson Hyperlegible)
        // is a dynamic import and must stay out of the global stylesheet.
        cssCodeSplit: true,
        // Every supported browser has native <link rel="modulepreload">; the
        // preload helper itself stays (it loads dynamic-import CSS).
        modulePreload: { polyfill: false },
        sourcemap: false,
        reportCompressedSize: true,
        rollupOptions: {
            output: {
                // Third-party code changes far less often than app code, so
                // it gets its own long-lived cached chunk.
                manualChunks(id) {
                    if (/[\\/]node_modules[\\/](@hotwired[\\/]turbo|bootstrap|@popperjs)[\\/]/.test(id)
                        || id.endsWith('/resources/js/bootstrap.js')) {
                        return 'vendor';
                    }
                    return undefined;
                },
            },
        },
    },
    server: {
        hmr: {
            host: 'localhost',
        },
    },
});
