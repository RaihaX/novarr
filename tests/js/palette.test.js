// Command palette + theme helpers (resources/js/palette.js, theme.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    fuzzyScore, parseCommand, serverQuery, buildGroups, flatten, groupJump, pushRecent, highlight, novelMeta,
} from '../../resources/js/palette.js';
import { resolve, nextPref } from '../../resources/js/theme.js';

const nav = [
    { title: 'Settings', url: '/settings', keywords: 'preferences config' },
    { title: 'Health', url: '/health', keywords: 'status sources' },
    { title: 'Logs', url: '/logs', keywords: 'errors' },
];
const novel = { id: 2, name: 'Ascending the Nine Heavens', author: 'Feng', url: '/novels/2', progress: 75 };

test('fuzzyScore ranks prefix > word start > infix > subsequence, rejects non-matches', () => {
    const prefix = fuzzyScore('asc', 'Ascending the Nine Heavens');
    const word = fuzzyScore('nine', 'Ascending the Nine Heavens');
    const infix = fuzzyScore('ending', 'Ascending the Nine Heavens');
    const subseq = fuzzyScore('atnh', 'Ascending the Nine Heavens');
    assert.ok(prefix > word && word > infix && infix > subseq && subseq > 0);
    assert.equal(fuzzyScore('xyz', 'Settings'), -1);
    assert.equal(fuzzyScore('', 'anything'), 0);
});

test('parseCommand recognises the three novel commands and their novel words', () => {
    assert.deepEqual(
        (({ cmd, rest }) => ({ command: cmd.command, rest }))(parseCommand('scrape toc Ascending')),
        { command: 'toc', rest: 'ascending' });
    assert.equal(parseCommand('download chapters asc').cmd.command, 'chapter');
    assert.equal(parseCommand('refresh metadata  nine ').cmd.command, 'metadata');
    assert.equal(parseCommand('ascending 142'), null);
    assert.equal(serverQuery('scrape toc ascending'), 'ascending');
    assert.equal(serverQuery(' ascending 142 '), 'ascending 142');
});

test('empty query shows recents, navigation and theme groups', () => {
    const groups = buildGroups('', null, { nav, recents: [{ id: 'nav:/logs', kind: 'url', url: '/logs', title: 'Logs' }] });
    assert.deepEqual(groups.map((g) => g.id), ['recent', 'nav', 'theme']);
    assert.equal(groups[0].items[0].url, '/logs');
});

test('chapter results come first when the query parses as "<novel> <number>"', () => {
    const data = {
        novels: [],
        chapters: [{ id: 13, novel: 'Ascending the Nine Heavens', number: 142, label: 'Chapter 142', url: '/chapters/13', downloaded: true, read: false }],
    };
    const groups = buildGroups('ascending 142', data, { nav });
    assert.equal(groups[0].id, 'chapters');
    assert.equal(flatten(groups)[0].url, '/chapters/13');
    assert.equal(groups.at(-1).id, 'search');
});

test('a command verb + novel turns novels into runnable commands', () => {
    const groups = buildGroups('scrape toc asc', { novels: [novel], chapters: [] }, { nav });
    assert.deepEqual(groups.map((g) => g.id), ['commands']);
    const [item] = groups[0].items;
    assert.equal(item.kind, 'command');
    assert.equal(item.command, 'toc');
    assert.equal(item.novelId, 2);
});

test('a partial verb is offered as a completion; nav and theme match fuzzily', () => {
    const groups = buildGroups('scrape', null, { nav });
    assert.equal(groups.find((g) => g.id === 'commands').items[0].kind, 'complete');
    const settings = buildGroups('sett', null, { nav });
    assert.equal(settings.find((g) => g.id === 'nav').items[0].url, '/settings');
    const theme = buildGroups('theme light', null, { nav });
    assert.equal(theme.find((g) => g.id === 'theme').items[0].value, 'light');
});

test('novel results carry progress as the aside and the search fallback is escaped', () => {
    const groups = buildGroups('asc&', { novels: [novel], chapters: [] }, { nav, searchUrl: '/search' });
    assert.equal(groups.find((g) => g.id === 'novels').items[0].aside, '75%');
    assert.equal(groups.find((g) => g.id === 'search').items[0].url, '/search?q=asc%26');
});

test('groupJump cycles group starts both ways', () => {
    const groups = [{ items: [1, 2] }, { items: [3] }, { items: [4, 5, 6] }];
    assert.equal(groupJump(groups, 0, 1), 2);
    assert.equal(groupJump(groups, 1, 1), 2);
    assert.equal(groupJump(groups, 3, 1), 0);
    assert.equal(groupJump(groups, 0, -1), 3);
});

test('pushRecent de-duplicates, caps and ignores completions', () => {
    let list = [];
    for (let i = 0; i < 8; i++) list = pushRecent(list, { id: `nav:/${i}`, kind: 'url', title: `${i}` });
    assert.equal(list.length, 6);
    assert.equal(list[0].id, 'nav:/7');
    list = pushRecent(list, { id: 'recent:nav:/5', kind: 'url', title: '5' });
    assert.equal(list[0].id, 'nav:/5');
    assert.equal(list.filter((r) => r.id === 'nav:/5').length, 1);
    assert.equal(pushRecent(list, { id: 'complete:x', kind: 'complete', title: 'x' }), list);
});

test('highlight escapes HTML and marks the match', () => {
    assert.equal(highlight('<b>Ascend</b>', 'asc'), '&lt;b&gt;<mark>Asc</mark>end&lt;/b&gt;');
    assert.equal(highlight('a.b', '.'), 'a<mark>.</mark>b');
});

test('theme: system follows the OS, explicit choices win, cycle is system → light → dark', () => {
    assert.equal(resolve('system', true), 'light');
    assert.equal(resolve('system', false), 'dark');
    assert.equal(resolve('dark', true), 'dark');
    assert.equal(resolve('light', false), 'light');
    assert.equal(nextPref('system'), 'light');
    assert.equal(nextPref('light'), 'dark');
    assert.equal(nextPref('dark'), 'system');
    assert.equal(nextPref('bogus'), 'system');
});

test('novel rows show the origin label after the author', () => {
    assert.equal(novelMeta({ author: 'Singshong', origin_label: 'Translated · Korean' }), 'Singshong · Translated · Korean');
    assert.equal(novelMeta({ author: 'Feng', origin_label: null }), 'Feng');
    assert.equal(novelMeta({ author: null, origin_label: 'Original · English' }), 'Original · English');
    assert.equal(novelMeta({}), '');

    const groups = buildGroups('ascending', { novels: [{ ...novel, origin_label: 'Translated · Chinese' }], chapters: [] });
    const item = groups.find((g) => g.id === 'novels').items[0];
    assert.equal(item.meta, 'Feng · Translated · Chinese');
});
