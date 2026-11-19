// "Check for new chapters" from inside the reader (the Next block when the
// next chapter is not downloaded yet, and the empty-chapter state). The same
// mechanism as the series page's reader button (novels/show.blade.php): POST
// /api/v1/novels/{id}/check starts the background check (the web session
// signs the call in), then GET is polled until it settles.

const POLL_MS = 3000;
const GIVE_UP_MS = 30 * 60 * 1000;

export function initCheckNew(ctx) {
    const { signal } = ctx;
    const buttons = [...document.querySelectorAll('[data-check-novel]')];
    if (!buttons.length) return;
    let running = false;

    function setBusy(busy) {
        buttons.forEach((btn) => {
            btn.disabled = busy;
            btn.classList.toggle('is-busy', busy);
            const label = btn.querySelector('.check-label');
            if (label) label.textContent = busy ? 'Checking…' : 'Check for new chapters';
        });
    }

    async function run(novelId) {
        if (running) return;
        running = true;
        setBusy(true);
        const N = window.Novarr;
        const url = `/api/v1/novels/${novelId}/check`;
        const headers = {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        };
        try {
            let res = await fetch(url, { method: 'POST', headers });
            let data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || `Could not start the check (${res.status}).`);
            let check = data.check || {};
            const deadline = Date.now() + GIVE_UP_MS;
            while (check.status === 'running' && Date.now() < deadline) {
                await new Promise((r) => setTimeout(r, POLL_MS));
                if (signal.aborted) return;   // left the page; the check carries on server-side
                res = await fetch(url, { headers });
                data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || `Could not read the check (${res.status}).`);
                check = data.check || {};
            }
            if (signal.aborted) return;
            if (check.status === 'done') {
                N?.showToast(check.message || 'Checked for new chapters.', 'success');
                // Reload this chapter: the next one may be downloaded now.
                N?.softRefresh?.(1200);
            } else {
                N?.showToast(check.message || 'The check did not finish. Try again later.', 'danger');
            }
        } catch (err) {
            if (!signal.aborted) {
                N?.showToast(navigator.onLine === false ? 'Checking for new chapters needs a connection.' : err.message, 'danger');
            }
        } finally {
            running = false;
            if (!signal.aborted) setBusy(false);
        }
    }

    buttons.forEach((btn) => btn.addEventListener('click', () => run(btn.dataset.checkNovel), { signal }));
}
