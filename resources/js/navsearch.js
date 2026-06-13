/**
 * Navbar quick-search: debounced novel-name autocomplete. Arrow keys move
 * through results, Enter on a highlighted result opens it; a plain Enter
 * submits the form to the full-text search page.
 */
export function initNavSearch() {
    const input = document.getElementById('navSearch');
    const menu = document.getElementById('navSearchResults');
    if (!input || !menu) return;

    let timer = null;
    let items = [];
    let active = -1;

    const close = () => { menu.classList.add('d-none'); menu.classList.remove('show'); active = -1; };

    function render() {
        if (!items.length) { close(); return; }
        menu.innerHTML = items.map((n, i) => `
            <a href="${n.url}" class="dropdown-item text-truncate ${i === active ? 'active' : ''}" data-i="${i}">
                ${escapeHtml(n.name)}${n.author ? ` <span class="text-muted small">· ${escapeHtml(n.author)}</span>` : ''}
            </a>`).join('');
        menu.classList.remove('d-none');
        menu.classList.add('show');
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    async function fetchSuggestions() {
        const q = input.value.trim();
        if (q.length < 2) { close(); return; }
        try {
            const response = await fetch(`/search/suggest?q=${encodeURIComponent(q)}`, {
                headers: { 'Accept': 'application/json' },
            });
            items = await response.json();
            active = -1;
            render();
        } catch {
            close();
        }
    }

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(fetchSuggestions, 200);
    });

    input.addEventListener('keydown', (e) => {
        if (menu.classList.contains('d-none')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, items.length - 1); render(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, -1); render(); }
        else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); window.location.href = items[active].url; }
        else if (e.key === 'Escape') { close(); }
    });

    document.addEventListener('click', (e) => {
        if (!menu.contains(e.target) && e.target !== input) close();
    });
}
