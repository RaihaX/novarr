// Guards for the Performance stream's bundle trims: the slim Bootstrap JS
// and SCSS entries, and the Fontsource latin-only subset filter.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('../../', import.meta.url).pathname;
const read = (p) => readFileSync(join(root, p), 'utf8');

function walk(dir, exts, out = []) {
    for (const name of readdirSync(join(root, dir))) {
        const rel = join(dir, name);
        if (statSync(join(root, rel)).isDirectory()) walk(rel, exts, out);
        else if (exts.some((e) => name.endsWith(e))) out.push(rel);
    }
    return out;
}

const sources = [...walk('resources/views', ['.blade.php']), ...walk('resources/js', ['.js'])]
    .map((f) => [f, read(f)]);

test('every Bootstrap JS plugin the views/js use is exported by the slim bootstrap.js', () => {
    const exported = new Set(
        (read('resources/js/bootstrap.js').match(/export \{([^}]+)\}/)?.[1] ?? '')
            .split(',').map((s) => s.trim()).filter(Boolean),
    );
    const plugin = {
        alert: 'Alert', button: 'Button', carousel: 'Carousel', collapse: 'Collapse',
        dropdown: 'Dropdown', modal: 'Modal', offcanvas: 'Offcanvas', popover: 'Popover',
        scrollspy: 'ScrollSpy', tab: 'Tab', pill: 'Tab', list: 'Tab', toast: 'Toast', tooltip: 'Tooltip',
    };
    const needed = new Map();
    for (const [file, src] of sources) {
        if (file.endsWith('bootstrap.js')) continue;
        for (const m of src.matchAll(/data-bs-(?:toggle|dismiss|ride|spy)="([a-z]+)"/g)) {
            if (plugin[m[1]]) needed.set(plugin[m[1]], file);
        }
        for (const m of src.matchAll(/bootstrap\??\.([A-Z][A-Za-z]+)/g)) needed.set(m[1], file);
        for (const m of src.matchAll(/import\s*\{([^}]+)\}\s*from\s*'bootstrap'/g)) {
            for (const n of m[1].split(',')) needed.set(n.trim(), file);
        }
    }
    for (const [name, file] of needed) {
        assert.ok(exported.has(name), `${file} uses Bootstrap ${name}; add it to resources/js/bootstrap.js`);
    }
});

test('_bootstrap-trim.scss imports Bootstrap modules in bootstrap.scss order', () => {
    const order = [...read('node_modules/bootstrap/scss/bootstrap.scss').matchAll(/^@import "([^"]+)";/gm)]
        .map((m) => m[1]).filter((m) => m !== 'mixins/banner');
    const trim = [...read('resources/css/_bootstrap-trim.scss').matchAll(/^@import 'bootstrap\/scss\/([^']+)';/gm)]
        .map((m) => m[1]);
    for (const core of ['functions', 'variables', 'variables-dark', 'maps', 'mixins', 'utilities',
        'root', 'reboot', 'type', 'containers', 'grid', 'helpers', 'utilities/api']) {
        assert.ok(trim.includes(core), `core module ${core} must stay imported`);
    }
    let last = -1;
    for (const m of trim) {
        const i = order.indexOf(m);
        assert.ok(i > last, `${m} is unknown or out of order`);
        last = i;
    }
});

test('Fontsource filter keeps only latin + latin-ext faces', async () => {
    const { fontsourceLatinOnly } = await import('../../vite.config.js');
    const plugin = fontsourceLatinOnly();
    const id = join(root, 'node_modules/@fontsource-variable/geist/index.css');
    const css = read('node_modules/@fontsource-variable/geist/index.css');
    const { code } = plugin.transform(css, id);
    const files = [...code.matchAll(/url\(([^)]+)\)/g)].map((m) => m[1]);
    assert.deepEqual(files.sort(), [
        './files/geist-latin-ext-wght-normal.woff2',
        './files/geist-latin-wght-normal.woff2',
    ]);
    assert.equal((code.match(/@font-face/g) || []).length, 2);
    assert.equal(plugin.transform('a{}', '/x/resources/css/app.css'), null, 'non-Fontsource CSS untouched');
});
