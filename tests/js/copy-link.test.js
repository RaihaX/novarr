// Copy buttons (resources/js/copy-link.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { copyText } from '../../resources/js/copy-link.js';

test('copyText uses the Clipboard API when it is there', async () => {
    const written = [];
    const ok = await copyText('https://novarr.test/invite/abc', {
        clipboard: { writeText: async (t) => { written.push(t); } },
        fallback: () => { throw new Error('not needed'); },
    });
    assert.equal(ok, true);
    assert.deepEqual(written, ['https://novarr.test/invite/abc']);
});

test('copyText falls back when the Clipboard API is missing or refused (plain http://)', async () => {
    let fellBack = 0;
    assert.equal(await copyText('x', { clipboard: undefined, fallback: () => { fellBack++; return true; } }), true);
    assert.equal(await copyText('x', {
        clipboard: { writeText: async () => { throw new Error('NotAllowedError'); } },
        fallback: () => { fellBack++; return true; },
    }), true);
    assert.equal(fellBack, 2);
});

test('copyText reports failure when nothing can copy', async () => {
    assert.equal(await copyText('x', { clipboard: undefined, fallback: () => false }), false);
    assert.equal(await copyText('x', { clipboard: undefined }), false);
});
