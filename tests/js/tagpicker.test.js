// Tag picker (resources/js/tagpicker.js) — run with `yarn test:js`.
// A tiny fake DOM: just enough for initTagPickers and outside-click closing.
import { test } from 'node:test';
import assert from 'node:assert/strict';

function el(cls = '') {
    const classes = new Set(cls.split(' ').filter(Boolean));
    const listeners = {};
    const node = {
        children: [],
        parent: null,
        dataset: {},
        textContent: '',
        classList: {
            add: (c) => classes.add(c),
            toggle: (c, force) => {
                const on = force === undefined ? !classes.has(c) : force;
                if (on) classes.add(c); else classes.delete(c);
                return on;
            },
            contains: (c) => classes.has(c),
        },
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
        dispatch: (type, e = {}) => (listeners[type] || []).forEach((fn) => fn({ target: node, ...e })),
        append(...kids) { kids.forEach((k) => { k.parent = node; node.children.push(k); }); return node; },
        contains(other) {
            for (let n = other; n; n = n.parent) if (n === node) return true;
            return false;
        },
        querySelector(sel) {
            const cls = sel.replace(/^\./, '');
            for (const k of node.children) {
                if (k.classList.contains(cls)) return k;
                const hit = k.querySelector(sel);
                if (hit) return hit;
            }
            return null;
        },
        querySelectorAll: () => [],
    };
    return node;
}

function picker() {
    const root = el('picker');
    const toggle = el('tag-picker-toggle');
    const menu = el('tag-picker-menu d-none');
    const label = el('tag-picker-label');
    const options = el('tag-picker-options');
    root.append(toggle, menu, label, options);
    return { root, toggle, menu };
}

let pickers = [];
const docListeners = [];
globalThis.document = {
    addEventListener: (type, fn) => docListeners.push({ type, fn }),
    querySelectorAll: (sel) => (sel === '[data-tag-picker]' ? pickers.map((p) => p.root) : []),
};

const { initTagPickers } = await import('../../resources/js/tagpicker.js');

test('repeated turbo:load inits add one document listener in total', () => {
    for (let visit = 0; visit < 5; visit++) {
        pickers = [picker()]; // each visit renders a fresh page
        initTagPickers();
    }
    assert.equal(docListeners.filter((l) => l.type === 'click').length, 1);
});

test('a click outside closes open pickers on the current page; inside keeps it open', () => {
    pickers = [picker(), picker()];
    initTagPickers();
    const [a, b] = pickers;
    a.toggle.dispatch('click');
    b.toggle.dispatch('click');
    assert.equal(a.menu.classList.contains('d-none'), false);

    const click = (target) => docListeners.forEach((l) => l.fn({ target }));
    click(a.menu); // inside a, outside b
    assert.equal(a.menu.classList.contains('d-none'), false);
    assert.equal(b.menu.classList.contains('d-none'), true);

    click(el('elsewhere'));
    assert.equal(a.menu.classList.contains('d-none'), true);
});
