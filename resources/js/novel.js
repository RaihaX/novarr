/**
 * Novel page — the chapter list (resources/views/novels/show.blade.php).
 *
 *   · Filter: a text filter over chapter label / number plus state chips
 *     (All / Unread / Queued / Failed). The server renders one page of 50
 *     rows; the first time a filter is used the whole list is fetched ONCE
 *     from novels.chapters_json (a conditional GET: a repeat answers 304)
 *     and filtered in memory, so typing never makes a request. Matches are
 *     rendered 100 at a time ("Show more"), so 2,000+ chapters stay cheap.
 *     Clearing the filter brings the server page back untouched.
 *   · Enter on a number in the filter opens that chapter (the jump route).
 *   · "Jump to chapter N" and the progress card's "Show" buttons.
 *   · Row selection, Mark read / unread, and the scoped Mark-read menu
 *     (moved here from the page's inline script so they also work on
 *     filtered rows).
 *
 * Turbo: initNovelPage() runs on every turbo:load and binds a given list
 * element once (a WeakSet, not a data attribute, so a restored Turbo
 * snapshot — a fresh element — is bound again and starts unfiltered).
 */
import { showToast } from './toast';
import { confirmDialog } from './confirm';
import { deviceHeaders } from './device';

const BATCH = 100;
const LABEL_LIMIT = 90;
const bound = new WeakSet();

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
}[ch]));

// Same truncation as Str::limit($label, 90).
const limit = (s) => {
    const str = String(s ?? '');
    return str.length > LABEL_LIMIT ? `${str.slice(0, LABEL_LIMIT).trimEnd()}...` : str;
};

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

const STATE_TESTS = {
    all: () => true,
    unread: (c) => c.downloaded && !c.read,
    queued: (c) => c.state === 'queued',
    failed: (c) => c.state === 'failed',
    // A reader's one "not downloaded" chip (queued and failed alike).
    pending: (c) => !c.downloaded,
};

class ChapterList {
    constructor(root) {
        this.root = root;
        this.table = root.querySelector('#chapterTable');
        this.serverBody = root.querySelector('#chRows');
        this.filteredBody = root.querySelector('#chFiltered');
        this.input = root.querySelector('#chFilter');
        this.form = root.querySelector('[data-chapter-filter-form]');
        this.status = root.querySelector('#chFilterStatus');
        this.moreWrap = root.querySelector('#chFilterMore');
        this.moreBtn = root.querySelector('#chShowMore');
        this.pager = root.querySelector('#chPager');
        this.chips = [...root.querySelectorAll('[data-state-filter]')];
        this.selectAll = root.querySelector('#chSelectAll');
        this.bulkBar = root.querySelector('#chBulkBar');

        this.novelId = parseInt(root.dataset.novelId, 10);
        this.continueId = parseInt(root.dataset.continueId || '0', 10);
        this.bookCol = root.dataset.bookCol === '1';
        // Failure reasons are the admin's (a reader cannot act on them).
        this.showReasons = root.dataset.reasons !== '0';
        this.filter = 'all';
        this.data = null;       // Promise<chapters[]> once loaded
        this.matches = [];
        this.shown = 0;
        this.seq = 0;
        this.timer = null;

        this.readIcon = document.getElementById('checkIconTpl')?.innerHTML.trim() || '';
        this.badges = {};
        document.getElementById('chStatusTpl')?.content.querySelectorAll('[data-tpl]').forEach((el) => {
            const copy = el.cloneNode(true);
            const key = copy.dataset.tpl;
            copy.removeAttribute('data-tpl');
            this.badges[key] = copy.outerHTML;
        });

        this.reset();
        this.bind();
    }

    // ---- Filter ---------------------------------------------------------

    reset() {
        if (this.input) this.input.value = '';
        this.setChip('all');
        this.showServer();
    }

    bind() {
        this.input?.addEventListener('input', () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.apply(), 120);
        });
        this.input?.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.input.value) {
                e.preventDefault();
                this.input.value = '';
                this.apply();
            }
        });
        // Enter on a chapter number goes to the jump route (opens the reader);
        // any other text just filters, so the submit is swallowed.
        this.form?.addEventListener('submit', (e) => {
            if (!/^\d+(\.\d+)?$/.test(this.input.value.trim())) e.preventDefault();
        });

        this.chips.forEach((chip) => chip.addEventListener('click', () => {
            this.setChip(chip.dataset.stateFilter);
            this.apply();
        }));

        this.moreBtn?.addEventListener('click', () => this.renderMore());

        // Progress-card shortcuts ("3 failed · Show") live outside the list.
        document.querySelectorAll('[data-show-chapters]').forEach((btn) => btn.addEventListener('click', () => {
            const chip = this.chips.find((c) => c.dataset.stateFilter === btn.dataset.showChapters);
            if (!chip) return;
            this.setChip(chip.dataset.stateFilter);
            this.apply();
            this.root.scrollIntoView({ block: 'start' });
            chip.focus({ preventScroll: true });
        }));

        // "Jump to chapter N": on the page that already holds it, clear any
        // filter and scroll to the row instead of reloading.
        const jump = this.root.querySelector('[data-jump-current]');
        jump?.addEventListener('click', (e) => {
            const page = parseInt(new URLSearchParams(location.search).get('page') || '1', 10);
            if (page !== parseInt(jump.dataset.jumpPage, 10)) return;
            const row = document.getElementById(`ch-${this.continueId}`);
            if (!row) return;
            e.preventDefault();
            this.reset();
            row.scrollIntoView({ block: 'center' });
            (row.querySelector('a') || row.querySelector('input'))?.focus({ preventScroll: true });
        });

        this.bindSelection();
    }

    setChip(key) {
        this.filter = STATE_TESTS[key] ? key : 'all';
        this.chips.forEach((c) => c.setAttribute('aria-pressed', String(c.dataset.stateFilter === this.filter)));
    }

    load() {
        if (!this.data) {
            this.data = fetch(this.root.dataset.jsonUrl, { headers: { Accept: 'application/json' } })
                .then((res) => {
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    return res.json();
                })
                .then((json) => json.chapters || [])
                .catch((err) => {
                    this.data = null;
                    throw err;
                });
        }
        return this.data;
    }

    async apply() {
        const q = (this.input?.value || '').trim().toLowerCase();
        if (!q && this.filter === 'all') {
            this.seq++;
            this.showServer();
            return;
        }

        const seq = ++this.seq;
        this.setStatus('Loading chapters…');
        let chapters;
        try {
            chapters = await this.load();
        } catch (err) {
            if (seq === this.seq) {
                this.setStatus('');
                showToast(`Could not load the chapter list: ${err.message}`, 'danger');
            }
            return;
        }
        if (seq !== this.seq) return; // a newer keystroke won

        const test = STATE_TESTS[this.filter];
        const exact = [];
        const rest = [];
        for (const c of chapters) {
            if (!test(c)) continue;
            if (q) {
                const num = String(c.chapter);
                if (num === q) { exact.push(c); continue; }
                if (!num.startsWith(q) && !String(c.label || '').toLowerCase().includes(q)) continue;
            }
            rest.push(c);
        }
        // A typed number's own chapter first, then the rest in reading order.
        this.matches = exact.concat(rest);
        this.shown = 0;

        this.serverBody.hidden = true;
        if (this.pager) this.pager.hidden = true;
        this.filteredBody.hidden = false;
        this.filteredBody.innerHTML = '';
        this.renderMore();
        this.clearSelection();
    }

    renderMore() {
        const next = this.matches.slice(this.shown, this.shown + BATCH);
        if (!this.matches.length) {
            const cols = this.bookCol ? 6 : 5;
            this.filteredBody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted py-4">No chapters match.</td></tr>`;
        } else {
            this.filteredBody.insertAdjacentHTML('beforeend', next.map((c) => this.rowHtml(c)).join(''));
        }
        this.shown += next.length;

        const total = this.matches.length;
        const remaining = total - this.shown;
        if (this.moreWrap) this.moreWrap.hidden = remaining <= 0;
        if (this.moreBtn) this.moreBtn.textContent = `Show ${Math.min(BATCH, remaining).toLocaleString()} more`;
        this.setStatus(total === 0
            ? 'No chapters match this filter.'
            : `Showing ${this.shown.toLocaleString()} of ${total.toLocaleString()} matching ${total === 1 ? 'chapter' : 'chapters'}.`);
    }

    showServer() {
        this.serverBody.hidden = false;
        if (this.pager) this.pager.hidden = false;
        this.filteredBody.hidden = true;
        this.filteredBody.innerHTML = '';
        if (this.moreWrap) this.moreWrap.hidden = true;
        this.matches = [];
        this.setStatus('');
        this.clearSelection();
    }

    setStatus(text) {
        if (this.status) {
            this.status.textContent = text;
            this.status.hidden = text === '';
        }
    }

    // Mirrors the server-rendered row in novels/show.blade.php.
    rowHtml(c) {
        const current = c.id === this.continueId;
        const href = this.root.dataset.chapterUrl.replace('__ID__', c.id);
        const cls = `chapter-row is-${c.state}${c.read ? ' is-read' : ''}${current ? ' is-current' : ''}`;
        const title = c.downloaded
            ? `<a href="${esc(href)}" class="chapter-link">${esc(limit(c.label))}</a>`
            : `<span class="chapter-label">${esc(limit(c.label))}</span>`;
        // Manga: a downloaded chapter's page count (null for text).
        const pages = c.pages
            ? ` <span class="ch-pages text-muted small ms-1">${c.pages} ${c.pages === 1 ? 'page' : 'pages'}</span>`
            : '';
        const reason = c.state === 'failed' && this.showReasons
            ? `<span class="ch-reason">${esc(c.reason)}${c.attempts > 1 ? ` · ${c.attempts} attempts` : ''}</span>`
            : '';
        let status = current ? '<span class="ch-current">Up next</span>' : '';
        if (c.review) status += this.badges.review || '';
        else if (c.state === 'failed') status += this.badges.failed || '';
        else if (c.state === 'queued') status += this.badges.queued || '';
        if (c.read) status += this.readMark();

        return `<tr class="${cls}" data-id="${c.id}">`
            + `<td class="ch-select"><input type="checkbox" class="form-check-input ch-check" value="${c.id}" aria-label="Select chapter ${esc(c.chapter)}"></td>`
            + `<td class="ch-num"><span class="ch-num-prefix">Ch. </span>${esc(c.chapter)}</td>`
            + (this.bookCol ? `<td class="ch-book"><span class="ch-num-prefix">Book </span>${c.book ? esc(c.book) : '—'}</td>` : '')
            + `<td class="ch-title">${title}${pages}${c.note ? this.badges.note || '' : ''}${reason}</td>`
            + `<td class="ch-status">${status}</td>`
            + `<td class="ch-date">${esc(c.date || '—')}</td>`
            + '</tr>';
    }

    readMark() {
        return `<span class="ch-read">${this.readIcon}Read</span>`;
    }

    // ---- Selection and Mark read ---------------------------------------

    visibleChecks() {
        return [...this.root.querySelectorAll('#chapterTable > tbody:not([hidden]) .ch-check')];
    }

    selected() {
        return this.visibleChecks().filter((c) => c.checked);
    }

    refreshBulk() {
        const n = this.selected().length;
        const all = this.visibleChecks().length;
        this.bulkBar?.classList.toggle('d-flex', n > 0);
        const count = this.root.querySelector('#chBulkCount');
        if (count) count.textContent = `${n} selected`;
        if (this.selectAll) {
            this.selectAll.checked = n > 0 && n === all;
            this.selectAll.indeterminate = n > 0 && n < all;
        }
    }

    clearSelection() {
        this.root.querySelectorAll('.ch-check').forEach((c) => { c.checked = false; });
        this.refreshBulk();
    }

    bindSelection() {
        this.table?.addEventListener('change', (e) => {
            if (e.target.classList?.contains('ch-check')) this.refreshBulk();
        });
        this.selectAll?.addEventListener('change', () => {
            this.visibleChecks().forEach((c) => { c.checked = this.selectAll.checked; });
            this.refreshBulk();
        });

        // Phones: "Select" reveals the row checkboxes; turning it off clears them.
        const toggle = this.root.querySelector('#chSelectToggle');
        toggle?.addEventListener('click', () => {
            const on = !this.root.classList.contains('is-selecting');
            this.root.classList.toggle('is-selecting', on);
            toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
            toggle.textContent = on ? 'Done' : 'Select';
            if (!on) this.clearSelection();
        });

        const on = (id, fn) => this.root.querySelector(`#${id}`)?.addEventListener('click', fn);
        on('chMarkRead', () => this.bulkRead(true));
        on('chMarkUnread', () => this.bulkRead(false));
        on('chReadUpTo', () => this.bulkReadScope('up_to', true));
        on('chReadFrom', () => this.bulkReadScope('from', true));
        on('chReadAll', () => this.bulkReadScope('all', true));
        on('chUnreadAll', () => this.bulkReadScope('all', false));
    }

    // Toggle a row's read mark in place, so the list keeps its scroll position.
    setRowRead(checkbox, read) {
        const row = checkbox.closest('tr');
        const cell = row?.querySelector('td.ch-status');
        if (!cell) return;
        row.classList.toggle('is-read', read);
        const mark = cell.querySelector('.ch-read');
        if (read && !mark) cell.insertAdjacentHTML('beforeend', this.readMark());
        else if (!read && mark) mark.remove();
    }

    async post(body) {
        const response = await fetch(this.root.dataset.bulkUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...deviceHeaders(),
            },
            body: JSON.stringify(body),
        });
        // Read state changed: the next filter fetches a fresh list (new ETag).
        this.data = null;
        return response.json();
    }

    async bulkRead(read) {
        const boxes = this.selected();
        const ids = boxes.map((c) => c.value);
        if (!ids.length) return;
        try {
            const data = await this.post({ ids, read });
            if (!data.success) {
                showToast(data.message || 'Failed to update chapters.', 'danger');
                return;
            }
            boxes.forEach((c) => { this.setRowRead(c, read); c.checked = false; });
            this.refreshBulk();
            showToast(
                data.queued
                    ? 'Saved offline — will sync when you reconnect.'
                    : `Marked ${ids.length} chapter(s) as ${read ? 'read' : 'unread'}.`,
                data.queued ? 'info' : 'success'
            );
        } catch (err) {
            showToast(`Error: ${err.message}`, 'danger');
        }
    }

    // Whole novel, or everything up to / from one selected anchor chapter.
    // The server updates every page; the DOM update covers the rows shown.
    async bulkReadScope(scope, read) {
        let anchorId = null;
        if (scope === 'up_to' || scope === 'from') {
            const sel = this.selected();
            if (sel.length !== 1) {
                showToast('Select exactly one chapter as the starting point first.', 'warning');
                return;
            }
            anchorId = parseInt(sel[0].value, 10);
        }

        if (scope === 'all') {
            const ok = await confirmDialog(
                `Mark ALL chapters as ${read ? 'read' : 'unread'}?`,
                { title: read ? 'Mark all read' : 'Mark all unread', confirmText: 'Continue', danger: !read }
            );
            if (!ok) return;
        }

        try {
            // Only send the anchor when there is one.
            const body = { read, scope, novel_id: this.novelId };
            if (anchorId !== null) body.anchor_id = anchorId;
            const data = await this.post(body);
            if (!data.success) {
                showToast(data.message || 'Failed to update chapters.', 'danger');
                return;
            }
            const boxes = this.visibleChecks();
            let affected = boxes;
            if (anchorId !== null) {
                const idx = boxes.findIndex((c) => parseInt(c.value, 10) === anchorId);
                affected = scope === 'up_to' ? boxes.slice(0, idx + 1) : boxes.slice(idx);
            }
            affected.forEach((c) => this.setRowRead(c, read));
            this.clearSelection();
            showToast(
                data.queued
                    ? 'Saved offline — will sync when you reconnect.'
                    : `Marked ${data.count} chapter(s) as ${read ? 'read' : 'unread'}.`,
                data.queued ? 'info' : 'success'
            );
        } catch (err) {
            showToast(`Error: ${err.message}`, 'danger');
        }
    }
}

export function initNovelPage() {
    const root = document.querySelector('[data-novel-chapters]');
    if (!root || bound.has(root)) return;
    bound.add(root);
    new ChapterList(root);
}
