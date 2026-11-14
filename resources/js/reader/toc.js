// ---- In-reader chapter list (offcanvas TOC) ----
// Current row: surface-alt fill + 2px amber rule (not Bootstrap's solid
// indigo .active). "Unread" narrows the list; "Jump to current" scrolls the
// list back to the chapter being read.
import { chLabel } from './format.js';

const TOC_WINDOW = 120;

export function initToc(ctx) {
    const { state, signal, sections, root: readerEl, dialogs: { holds, releaseDialog } } = ctx;
    const tocList = document.getElementById('tocList');
    const tocFilter = document.getElementById('tocFilter');
    const tocUnread = document.getElementById('tocUnread');
    const tocJump = document.getElementById('tocJump');
    const checkTpl = document.getElementById('readerIconCheck');
    let tocChapters = null;
    let tocUnreadOnly = false;

    function tocItem(c, currentId) {
        const isCurrent = c.id === currentId;
        const a = document.createElement('a');
        a.href = '/chapters/' + c.id;
        a.className = 'list-group-item list-group-item-action toc-row'
            + (isCurrent ? ' is-current' : '') + (!c.downloaded ? ' toc-pending' : '') + (c.read ? ' is-read' : '');
        if (isCurrent) a.setAttribute('aria-current', 'page');

        const mark = document.createElement('span');
        mark.className = 'toc-mark';
        if (c.read) {
            mark.appendChild(checkTpl.content.cloneNode(true));
            mark.title = 'Read';
        }
        const label = document.createElement('span');
        label.className = 'toc-label';
        label.textContent = chLabel(c, 200);
        if (c.note) {
            const note = document.createElement('span');
            note.className = 'badge badge-paused ms-2';   // NovelState::Paused (muted)
            note.textContent = 'note';
            note.title = "Author's note";
            label.appendChild(note);
        }
        a.append(mark, label);
        if (!c.downloaded) {
            const meta = document.createElement('span');
            meta.className = 'toc-meta';
            meta.textContent = 'pending';
            a.appendChild(meta);
        }
        a.addEventListener('click', (e) => {
            if (window.Turbo) { e.preventDefault(); window.Turbo.visit(a.href); }
        });
        return a;
    }

    function moreButton(text, onClick) {
        const more = document.createElement('button');
        more.type = 'button';
        more.className = 'list-group-item list-group-item-action toc-more';
        more.textContent = text;
        more.addEventListener('click', onClick);
        return more;
    }

    // Scroll the drawer list (never the page behind it) to the current row.
    function scrollToCurrent(behavior = 'auto') {
        const row = tocList.querySelector('.is-current');
        if (!row) return false;
        tocList.scrollTo({ top: row.offsetTop - tocList.clientHeight / 2 + row.offsetHeight / 2, behavior });
        return true;
    }

    function renderToc(filter = '') {
        if (!tocChapters) return;
        const currentId = sections[ctx.currentIdx].id;
        tocList.innerHTML = '';

        let rows = tocChapters;
        if (tocUnreadOnly) rows = rows.filter(c => !c.read || c.id === currentId);
        if (filter) {
            const f = filter.toLowerCase();
            rows = rows.filter(c =>
                String(c.chapter).startsWith(f) || (c.label || '').toLowerCase().includes(f)
            ).slice(0, 200);
            renderRows(rows, currentId);
            return;
        }
        const i = Math.max(0, rows.findIndex(c => c.id === currentId));
        const from = Math.max(0, i - TOC_WINDOW);
        const to = Math.min(rows.length, i + TOC_WINDOW);
        if (from > 0) tocList.appendChild(moreButton(`Show earlier chapters (${from})`, () => renderTocRange(rows, 0, to)));
        renderRows(rows.slice(from, to), currentId);
        if (to < rows.length) tocList.appendChild(moreButton(`Show later chapters (${rows.length - to})`, () => renderTocRange(rows, from, rows.length)));
        scrollToCurrent();
    }

    function renderTocRange(rows, from, to) {
        tocList.innerHTML = '';
        renderRows(rows.slice(from, to), sections[ctx.currentIdx].id);
        scrollToCurrent();
    }

    function renderRows(rows, currentId) {
        const frag = document.createDocumentFragment();
        rows.forEach(c => frag.appendChild(tocItem(c, currentId)));
        tocList.appendChild(frag);
        if (!rows.length) {
            tocList.innerHTML = '<div class="p-3 text-muted">' + (tocUnreadOnly ? 'No unread chapters match.' : 'No chapters match.') + '</div>';
        }
    }

    const tocPanelNode = document.getElementById('tocPanel');
    ctx.tocPanelNode = tocPanelNode;
    tocPanelNode.addEventListener('show.bs.offcanvas', async () => {
        if (tocChapters) { renderToc(tocFilter.value.trim()); return; }
        try {
            const res = await fetch(`/novels/${state.novelId}/chapters-json`, { headers: { Accept: 'application/json' } });
            tocChapters = (await res.json()).chapters;
            renderToc();
        } catch (err) {
            tocList.innerHTML = '<div class="p-3 tone-danger">Could not load the chapter list.</div>';
        }
    }, { signal });
    // Bootstrap's offcanvas already traps focus, closes on Esc, locks body
    // scroll and returns focus to #tocBtn; add inert on the page behind it.
    let tocHold = null;
    tocPanelNode.addEventListener('show.bs.offcanvas', () => {
        ctx.toggleSettings(false);
        tocHold = releaseDialog(tocHold, false);
        const inerted = [readerEl].filter(n => !n.inert);
        inerted.forEach(n => { n.inert = true; });
        tocHold = { el: tocPanelNode, trigger: null, inerted, release: () => {} };
        holds.add(tocHold);
    }, { signal });
    tocPanelNode.addEventListener('hidden.bs.offcanvas', () => { tocHold = releaseDialog(tocHold, false); }, { signal });
    // The drawer slides in; centre the current row once it has a layout.
    tocPanelNode.addEventListener('shown.bs.offcanvas', () => scrollToCurrent(), { signal });

    let tocFilterTimer = null;
    tocFilter.addEventListener('input', () => {
        clearTimeout(tocFilterTimer);
        tocFilterTimer = setTimeout(() => renderToc(tocFilter.value.trim()), 150);
    }, { signal });
    tocUnread.addEventListener('click', () => {
        tocUnreadOnly = !tocUnreadOnly;
        tocUnread.setAttribute('aria-pressed', tocUnreadOnly ? 'true' : 'false');
        tocUnread.classList.toggle('is-active', tocUnreadOnly);
        renderToc(tocFilter.value.trim());
    }, { signal });
    tocJump.addEventListener('click', () => {
        // The current row may be filtered out or outside the rendered window.
        if (!scrollToCurrent('smooth')) {
            tocFilter.value = '';
            renderToc();
        }
        tocList.querySelector('.is-current')?.focus({ preventScroll: true });
    }, { signal });

    return function cleanup() {
        clearTimeout(tocFilterTimer);
    };
}
