// Copy buttons: <button data-copy-target="#inputId" hidden>. The button is
// shown only when copying can work (JS on); without JS the link is still a
// selectable read-only input. `data-select-on-focus` inputs select their
// whole value on focus, so a long link is easy to copy by hand too.

/** Copy text: the async Clipboard API, else a selected input + execCommand. Resolves true when copied. */
export async function copyText(text, { clipboard = globalThis.navigator?.clipboard, fallback } = {}) {
    if (clipboard?.writeText) {
        try {
            await clipboard.writeText(text);
            return true;
        } catch (e) {
            // Not allowed here (http:// origin, permissions): try the fallback.
        }
    }
    return fallback ? Boolean(fallback()) : false;
}

/** Selected-input fallback (works on plain http:// where the Clipboard API is missing). */
function execCopy(input) {
    try {
        input.focus();
        input.select();
        return document.execCommand && document.execCommand('copy');
    } catch (e) {
        return false;
    }
}

export function initCopyButtons(root = document) {
    root.querySelectorAll('[data-select-on-focus]').forEach((input) => {
        if (input.dataset.selectBound) return;
        input.dataset.selectBound = '1';
        input.addEventListener('focus', () => input.select());
    });

    root.querySelectorAll('[data-copy-target]').forEach((button) => {
        if (button.dataset.copyBound) return;
        const input = root.querySelector(button.dataset.copyTarget);
        if (!input) return;
        button.dataset.copyBound = '1';
        button.hidden = false;
        const label = button.textContent;
        let timer = null;

        button.addEventListener('click', async () => {
            const ok = await copyText(input.value, { fallback: () => execCopy(input) });
            button.textContent = ok ? (button.dataset.copiedText || 'Copied') : 'Select and copy';
            if (!ok) input.select();
            clearTimeout(timer);
            timer = setTimeout(() => { button.textContent = label; }, 2000);
        });
    });
}
