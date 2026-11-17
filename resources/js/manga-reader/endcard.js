// The card after a chapter's last page (paged and vertical): the way on to
// the next chapter, or why there isn't one, and the way back to the novel.
// Plain links: Turbo Drive follows them, and nothing navigates on its own.

export const chapterUrl = (id, query = '') => '/chapters/' + id + query;

function el(tag, className, text) {
    const n = document.createElement(tag);
    if (className) n.className = className;
    if (text !== undefined) n.textContent = text;
    return n;
}

export function buildEndCard({ chapter, next, novelUrl, novelName }) {
    const card = el('section', 'mr-end');
    card.setAttribute('aria-label', 'End of chapter');

    card.append(el('p', 'mr-end-kicker', 'End of chapter'));
    card.append(el('h2', 'mr-end-title', chapter.label || 'Chapter'));

    const actions = el('div', 'mr-end-actions');
    if (next && next.downloaded) {
        const a = el('a', 'btn btn-primary mr-end-next');
        a.href = chapterUrl(next.id);
        a.textContent = 'Next: ' + (next.label || 'next chapter');
        a.dataset.endNext = '';
        actions.append(a);
    } else if (next) {
        card.append(el('p', 'mr-end-note', 'Next chapter not downloaded yet'));
        card.append(el('p', 'mr-end-sub', next.label || ''));
    } else {
        card.append(el('p', 'mr-end-note', "You're caught up"));
        card.append(el('p', 'mr-end-sub', 'No next chapter yet.'));
    }
    const back = el('a', 'btn btn-secondary mr-end-back');
    back.href = novelUrl;
    back.textContent = 'Back to ' + (novelName || 'the novel');
    actions.append(back);
    card.append(actions);
    return card;
}

/** "Page N didn't load" + Retry, over a page box. */
export function buildPageError(page, onRetry, iconTpl) {
    const box = el('div', 'mr-error');
    box.hidden = true;
    box.append(el('p', 'mr-error-text', `Page ${page} didn't load.`));
    const btn = el('button', 'btn btn-secondary btn-sm mr-retry');
    btn.type = 'button';
    btn.setAttribute('aria-label', `Retry page ${page}`);
    if (iconTpl) btn.append(iconTpl.content.cloneNode(true));
    btn.append(document.createTextNode(' Retry'));
    btn.addEventListener('click', (e) => { e.stopPropagation(); onRetry(); });
    box.append(btn);
    return box;
}
