/**
 * Command palette — ⌘K / Ctrl-K anywhere, "/" outside text fields, or the
 * navbar search field. One input, grouped results:
 *
 *   Chapters    "ascending 142"           → open that chapter
 *   Novels      name / author             → open the novel
 *   Commands    "scrape toc <novel>", "download chapters <novel>",
 *               "refresh metadata <novel>" → run via executeCommand()
 *   Go to       settings, health, logs …  → navigate
 *   Theme       "theme light|dark|system"
 *   Search      "Search chapters for …"   → /search?q=
 *
 * ↑/↓ move, Enter opens, Tab / Shift+Tab jump between groups, Esc closes.
 * Novels and chapters come from GET /palette?q= (150 ms debounce); the rest
 * is matched here. Recent picks are remembered in localStorage.
 *
 * Markup: resources/views/partials/palette.blade.php (an .nv-sheet, so the
 * focus trap / inert / Esc plumbing is resources/js/modal.js).
 *
 * The pure helpers (fuzzyScore, parseCommand, buildGroups …) touch no DOM
 * and are unit-tested in tests/js/palette.test.js.
 */

export const RECENT_KEY = 'novarr_palette_recent';
const RECENT_MAX = 6;
const DEBOUNCE_MS = 150;

// ---------------------------------------------------------------------------
// Pure helpers
// ---------------------------------------------------------------------------

/**
 * Fuzzy score of `query` against `text` (higher is better, -1 = no match).
 * Substring matches win (earlier and word-start positions score higher);
 * otherwise every query character must appear in order, with gaps costing.
 */
export function fuzzyScore(query, text) {
    const q = String(query ?? '').toLowerCase().trim();
    const t = String(text ?? '').toLowerCase();
    if (!q) return 0;
    if (!t) return -1;

    const at = t.indexOf(q);
    if (at !== -1) {
        const wordStart = at === 0 || /[\s\-_/.:(]/.test(t[at - 1]);
        return 1000 - at * 2 + (wordStart ? 200 : 0) + (at === 0 ? 100 : 0) - (t.length - q.length) * 0.1;
    }

    // Ordered subsequence (spaces in the query are free).
    let score = 500;
    let ti = 0;
    let last = -1;
    for (const ch of q.replace(/\s+/g, '')) {
        const found = t.indexOf(ch, ti);
        if (found === -1) return -1;
        if (last !== -1) score -= Math.min(found - last - 1, 10) * 8;
        if (found === 0 || /[\s\-_/.:(]/.test(t[found - 1])) score += 15;
        last = found;
        ti = found + 1;
    }
    return score > 0 ? score : 1;
}

/** Best score across several haystacks. */
function bestScore(q, ...texts) {
    return Math.max(-1, ...texts.filter(Boolean).map((t) => fuzzyScore(q, t)));
}

/** Commands that act on one novel. `command` is the CommandController key. */
export const NOVEL_COMMANDS = [
    { command: 'toc', verb: 'scrape toc', title: 'Scrape table of contents', icon: 'refresh-cw' },
    { command: 'chapter', verb: 'download chapters', title: 'Download chapters', icon: 'download' },
    { command: 'metadata', verb: 'refresh metadata', title: 'Refresh metadata', icon: 'refresh-cw' },
];

/**
 * "scrape toc ascending" → { cmd, rest: 'ascending' }. A query that is only a
 * (partial) verb returns { cmd, rest: '', partial: true } so the verb can be
 * offered as a completion.
 */
export function parseCommand(query) {
    const q = String(query ?? '').toLowerCase().replace(/\s+/g, ' ').trimStart();
    for (const cmd of NOVEL_COMMANDS) {
        if (q.startsWith(cmd.verb + ' ')) {
            return { cmd, rest: q.slice(cmd.verb.length + 1).trim(), partial: false };
        }
    }
    return null;
}

/** Text the server should search novels for. */
export function serverQuery(query) {
    const parsed = parseCommand(query);
    return (parsed ? parsed.rest : String(query ?? '')).trim();
}

const THEMES = [
    { value: 'system', title: 'Theme: system', icon: 'sun-moon', keywords: 'theme system auto os appearance' },
    { value: 'light', title: 'Theme: light', icon: 'sun', keywords: 'theme light day appearance' },
    { value: 'dark', title: 'Theme: dark', icon: 'moon', keywords: 'theme dark night appearance' },
];

function escapeRegExp(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Build the grouped, ordered result list.
 *
 * @param {string} query
 * @param {{novels?: Array, chapters?: Array}|null} data  server results for serverQuery(query)
 * @param {{nav?: Array, recents?: Array, searchUrl?: string}} ctx
 * @returns {Array<{id: string, label: string, items: Array}>}
 */
export function buildGroups(query, data, { nav = [], recents = [], searchUrl = '/search' } = {}) {
    const q = String(query ?? '').trim();
    const novels = data?.novels ?? [];
    const chapters = data?.chapters ?? [];
    const groups = [];
    const push = (id, label, items) => { if (items.length) groups.push({ id, label, items }); };

    const navItems = (filter) => nav
        .map((n) => ({ n, s: filter ? bestScore(q, n.title, n.keywords) : 0 }))
        .filter(({ s }) => s >= 0)
        .sort((a, b) => b.s - a.s)
        .map(({ n }) => ({
            id: `nav:${n.url}`, kind: 'url', url: n.url, title: n.title,
            meta: n.meta || '', icon: n.icon || 'arrow-right',
        }));

    if (!q) {
        push('recent', 'Recent', recents.slice(0, RECENT_MAX).map((r) => ({ ...r, id: `recent:${r.id}` })));
        push('nav', 'Go to', navItems(false));
        push('theme', 'Theme', THEMES.map((t) => ({
            id: `theme:${t.value}`, kind: 'theme', value: t.value, title: t.title, icon: t.icon,
        })));
        return groups;
    }

    const parsed = parseCommand(q);

    // Commands: an exact verb + novel words → one entry per matching novel;
    // otherwise offer verbs that fuzzy-match as completions.
    const commandItems = [];
    if (parsed) {
        novels.slice(0, 5).forEach((n) => commandItems.push({
            id: `cmd:${parsed.cmd.command}:${n.id}`, kind: 'command', command: parsed.cmd.command,
            novelId: n.id, novelName: n.name, title: `${parsed.cmd.title} — ${n.name}`,
            meta: n.author || '', icon: parsed.cmd.icon,
        }));
        if (!parsed.rest) {
            commandItems.push({
                id: `complete:${parsed.cmd.verb}`, kind: 'complete', value: parsed.cmd.verb + ' ',
                title: `${parsed.cmd.title}…`, meta: 'Type a novel name', icon: parsed.cmd.icon,
            });
        }
    } else {
        NOVEL_COMMANDS
            .map((c) => ({ c, s: bestScore(q, c.verb, c.title) }))
            .filter(({ s }) => s >= 400)
            .sort((a, b) => b.s - a.s)
            .forEach(({ c }) => commandItems.push({
                id: `complete:${c.verb}`, kind: 'complete', value: c.verb + ' ',
                title: `${c.title}…`, meta: `${c.verb} <novel>`, icon: c.icon,
            }));
    }

    const chapterItems = chapters.map((c) => ({
        id: `chapter:${c.id}`, kind: 'url', url: c.url,
        title: `${c.novel} · ${c.label || 'Chapter ' + c.number}`,
        meta: c.downloaded ? (c.read ? 'Read' : 'Downloaded') : 'Not downloaded yet',
        aside: `CH ${c.number}`, icon: 'file-text',
    }));

    const novelItems = parsed ? [] : novels.map((n) => ({
        id: `novel:${n.id}`, kind: 'url', url: n.url, title: n.name,
        meta: n.author || '', aside: n.progress > 0 ? `${n.progress}%` : '', icon: 'book-open',
    }));

    const themeItems = THEMES
        .map((t) => ({ t, s: bestScore(q, t.title, t.keywords) }))
        .filter(({ s }) => s >= 400)
        .sort((a, b) => b.s - a.s)
        .map(({ t }) => ({ id: `theme:${t.value}`, kind: 'theme', value: t.value, title: t.title, icon: t.icon }));

    if (chapterItems.length) push('chapters', 'Chapters', chapterItems);
    push('novels', 'Novels', novelItems);
    push('commands', 'Commands', commandItems);
    if (!parsed) {
        push('nav', 'Go to', navItems(true).filter((_, i) => i < 6));
        push('theme', 'Theme', themeItems);
    }
    if (!parsed && q.length >= 2) {
        const sep = searchUrl.includes('?') ? '&' : '?';
        push('search', 'Search', [{
            id: 'search:all', kind: 'url', url: `${searchUrl}${sep}q=${encodeURIComponent(q)}`,
            title: `Search chapters for “${q}”`, meta: 'Full-text search', icon: 'search',
        }]);
    }
    return groups;
}

/** Flat list of items in display order. */
export function flatten(groups) {
    return groups.flatMap((g) => g.items);
}

/** Index of the first item of the next (dir 1) or previous (dir -1) group. */
export function groupJump(groups, activeIndex, dir) {
    if (!groups.length) return -1;
    const starts = [];
    let n = 0;
    for (const g of groups) { starts.push(n); n += g.items.length; }
    let gi = 0;
    for (let i = 0; i < starts.length; i++) if (activeIndex >= starts[i]) gi = i;
    const next = (gi + dir + starts.length) % starts.length;
    return starts[next];
}

/** Add an item to the recents list (most recent first, de-duplicated). */
export function pushRecent(list, item, max = RECENT_MAX) {
    // Commands are deliberately not remembered: Ctrl-K then Enter would
    // otherwise re-run the last scrape with no confirmation.
    if (!item || !['url', 'theme'].includes(item.kind)) return list;
    const clean = { ...item, id: String(item.id).replace(/^recent:/, '') };
    return [clean, ...list.filter((r) => r.id !== clean.id)].slice(0, max);
}

/** HTML-escape, then underline the query's first literal occurrence. */
export function highlight(text, query) {
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const t = String(text ?? '');
    const q = String(query ?? '').trim();
    if (!q) return esc(t);
    const m = t.match(new RegExp(escapeRegExp(q), 'i'));
    if (!m) return esc(t);
    return esc(t.slice(0, m.index)) + '<mark>' + esc(m[0]) + '</mark>' + esc(t.slice(m.index + m[0].length));
}

// ---------------------------------------------------------------------------
// DOM
// ---------------------------------------------------------------------------

function readRecents() {
    try {
        const v = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
        return Array.isArray(v) ? v.filter((r) => r && r.id && r.title) : [];
    } catch (_) {
        return [];
    }
}

function writeRecents(list) {
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch (_) { /* ignore */ }
}

const state = {
    groups: [],
    items: [],
    active: 0,
    seq: 0,
    timer: null,
    abort: null,
    cache: new Map(),
};

const $ = (id) => document.getElementById(id);

function root() { return $('palette'); }

function iconHtml(name) {
    const tpl = $('paletteIcons');
    const node = tpl?.content?.querySelector(`[data-icon="${name}"]`)
        || tpl?.content?.querySelector('[data-icon="arrow-right"]');
    return node ? node.innerHTML : '';
}

function navConfig() {
    try { return JSON.parse($('paletteNav')?.textContent || '{}'); } catch (_) { return {}; }
}

function render(query) {
    const list = $('paletteList');
    const input = $('paletteInput');
    if (!list || !input) return;

    if (!state.items.length) {
        const q = query.trim();
        const { searchUrl = '/search' } = navConfig();
        list.innerHTML = q
            ? `<div class="palette-empty">No matches for “${highlight(q, '')}”.${q.length >= 2 ? ` <a href="${searchUrl}?q=${encodeURIComponent(q)}">Search chapters instead</a>` : ''}</div>`
            : '<div class="palette-empty">Start typing a novel, a chapter (“ascending 142”), or a command.</div>';
        input.removeAttribute('aria-activedescendant');
        return;
    }

    let i = 0;
    const enter = '<span class="palette-enter kbd-hint" aria-hidden="true">↵</span>';
    list.innerHTML = state.groups.map((g) => `
        <div class="palette-group" role="group" aria-labelledby="palette-g-${g.id}">
            <div class="palette-group-label" id="palette-g-${g.id}" role="presentation">${g.label}</div>
            ${g.items.map((item) => {
                const idx = i++;
                const hl = item.kind === 'url' && !item.id.startsWith('search:') ? query : '';
                return `
                <div class="palette-option${idx === state.active ? ' is-active' : ''}" role="option"
                     id="palette-opt-${idx}" data-index="${idx}" aria-selected="${idx === state.active}">
                    ${iconHtml(item.icon)}
                    <span class="palette-option-body">
                        <span class="palette-option-title">${highlight(item.title, hl)}</span>
                        ${item.meta ? `<span class="palette-option-meta">${highlight(item.meta, '')}</span>` : ''}
                    </span>
                    ${item.aside ? `<span class="palette-option-aside">${highlight(item.aside, '')}</span>` : ''}
                    ${enter}
                </div>`;
            }).join('')}
        </div>`).join('');

    input.setAttribute('aria-activedescendant', `palette-opt-${state.active}`);
}

function setActive(index, scroll = true) {
    if (!state.items.length) return;
    const n = state.items.length;
    state.active = ((index % n) + n) % n;
    const list = $('paletteList');
    list?.querySelectorAll('.palette-option').forEach((el) => {
        const on = Number(el.dataset.index) === state.active;
        el.classList.toggle('is-active', on);
        el.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on && scroll) el.scrollIntoView({ block: 'nearest' });
    });
    $('paletteInput')?.setAttribute('aria-activedescendant', `palette-opt-${state.active}`);
}

function update(query, data) {
    const cfg = navConfig();
    state.groups = buildGroups(query, data, {
        nav: cfg.nav || [], recents: readRecents(), searchUrl: cfg.searchUrl || '/search',
    });
    state.items = flatten(state.groups);
    state.active = 0; // first result pre-selected
    render(query);
}

async function fetchData(q) {
    if (state.cache.has(q)) return state.cache.get(q);
    state.abort?.abort();
    state.abort = new AbortController();
    const res = await fetch(`/palette?q=${encodeURIComponent(q)}`, {
        headers: { Accept: 'application/json' },
        signal: state.abort.signal,
    });
    if (!res.ok) throw new Error(`palette ${res.status}`);
    const data = await res.json();
    state.cache.set(q, data);
    if (state.cache.size > 40) state.cache.delete(state.cache.keys().next().value);
    return data;
}

function onInput() {
    const query = $('paletteInput')?.value ?? '';
    const sq = serverQuery(query);
    const seq = ++state.seq;
    clearTimeout(state.timer);

    // Local groups render immediately (keeps the list stable while typing);
    // novels/chapters fill in once the debounced request lands.
    const all = root()?.querySelector('.palette-foot-all');
    if (all) {
        const { searchUrl = '/search' } = navConfig();
        all.href = query.trim() ? `${searchUrl}?q=${encodeURIComponent(query.trim())}` : searchUrl;
    }

    const cached = state.cache.get(sq);
    update(query, cached ?? null);
    if (!sq || cached) { root()?.classList.remove('is-loading'); return; }

    root()?.classList.add('is-loading');
    state.timer = setTimeout(async () => {
        try {
            const data = await fetchData(sq);
            if (seq === state.seq) update(query, data);
        } catch (e) {
            if (e.name !== 'AbortError' && seq === state.seq) update(query, null);
        } finally {
            if (seq === state.seq) root()?.classList.remove('is-loading');
        }
    }, DEBOUNCE_MS);
}

function visit(url) {
    if (window.Turbo?.visit) window.Turbo.visit(url);
    else window.location.href = url;
}

function activate(item) {
    if (!item) return;
    const input = $('paletteInput');

    if (item.kind === 'complete') {
        input.value = item.value;
        input.focus();
        onInput();
        return;
    }

    writeRecents(pushRecent(readRecents(), item));
    closePalette({ restoreFocus: item.kind !== 'url' });

    if (item.kind === 'url') {
        visit(item.url);
    } else if (item.kind === 'theme') {
        window.Novarr?.setTheme?.(item.value);
    } else if (item.kind === 'command') {
        runCommand(item);
    }
}

async function runCommand(item) {
    const N = window.Novarr || {};
    const label = item.title;
    N.showToast?.(`Started: ${label}`, 'info');
    try {
        const result = await N.executeCommand({ command: item.command, novel_id: item.novelId });
        if (result?.success) N.showToast?.(`Finished: ${label}`, 'success');
        else N.showToast?.(`Failed: ${label}${result?.error || result?.message ? ' — ' + (result.error || result.message) : ''}`, 'danger');
    } catch (e) {
        N.showToast?.(`Failed: ${label} — ${e.message}`, 'danger');
    }
}

function onKeydown(e) {
    if (e.isComposing) return;
    switch (e.key) {
        case 'ArrowDown':
            e.preventDefault();
            setActive(state.active + 1);
            break;
        case 'ArrowUp':
            e.preventDefault();
            setActive(state.active - 1);
            break;
        case 'Enter':
            e.preventDefault();
            activate(state.items[state.active]);
            break;
        case 'Tab':
            // Tab cycles groups (the focus trap would otherwise move focus).
            e.preventDefault();
            e.stopPropagation();
            if (state.groups.length) setActive(groupJump(state.groups, state.active, e.shiftKey ? -1 : 1));
            break;
        default:
    }
}

export function isPaletteOpen() {
    const el = root();
    return !!el && !el.hidden && el.classList.contains('is-open');
}

/** Open the palette, optionally pre-filled. */
export function openPalette(initial = '', { opener = null } = {}) {
    const el = root();
    const input = $('paletteInput');
    if (!el || !input) return;
    if (isPaletteOpen()) { input.focus(); return; }

    input.value = initial;
    // Another focus trap (the More sheet, the reader's Aa sheet or "?"
    // overlay) marks everything else inert, the palette included. Release
    // it first so the palette can take focus.
    try { window.Novarr?.closeAll?.({ restoreFocus: false }); } catch (_) { /* ignore */ }
    window.Novarr?.openSheet?.(el, { opener, initialFocus: input });
    input.setAttribute('aria-expanded', 'true');
    onInput();
    // Caret at the end of any pre-filled text.
    try { input.setSelectionRange(input.value.length, input.value.length); } catch (_) { /* ignore */ }
}

export function closePalette({ restoreFocus = true } = {}) {
    const el = root();
    if (!el || !isPaletteOpen()) return;
    clearTimeout(state.timer);
    state.abort?.abort();
    $('paletteInput')?.setAttribute('aria-expanded', 'false');
    window.Novarr?.closeSheet?.(el, { restoreFocus });
}

function isEditable(t) {
    return !!t && (t.isContentEditable
        || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName)
        || !!t.closest?.('[contenteditable="true"]'));
}

export function initPalette() {
    // Global shortcuts.
    document.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            if (isPaletteOpen()) closePalette();
            else openPalette('');
            return;
        }
        if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey && !isEditable(e.target) && !isPaletteOpen()
            && !document.body.classList.contains('nv-modal-open')) {
            e.preventDefault();
            openPalette('');
        }
    });

    // Delegated: [data-palette-open] buttons, input + list inside the palette.
    document.addEventListener('click', (e) => {
        const opener = e.target.closest?.('[data-palette-open]');
        if (opener) {
            e.preventDefault();
            openPalette('', { opener });
            return;
        }
        const option = e.target.closest?.('#paletteList .palette-option');
        if (option) activate(state.items[Number(option.dataset.index)]);
    });

    document.addEventListener('mousemove', (e) => {
        const option = e.target.closest?.('#paletteList .palette-option');
        if (option && Number(option.dataset.index) !== state.active) setActive(Number(option.dataset.index), false);
    });

    document.addEventListener('input', (e) => {
        if (e.target?.id === 'paletteInput') onInput();
    });

    // Capture phase: runs before the focus trap's Tab handling on the sheet.
    document.addEventListener('keydown', (e) => {
        if (e.target?.id === 'paletteInput') onKeydown(e);
    }, true);

    document.addEventListener('nv:sheet-close', (e) => {
        if (e.target?.id === 'palette') $('paletteInput')?.setAttribute('aria-expanded', 'false');
    });
}
