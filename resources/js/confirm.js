/**
 * Promise-based confirmation modal — a styled, theme-aware replacement for the
 * native window.confirm(), which is inconsistent (and unreliable in installed
 * iOS PWAs) and can't be styled. Resolves true if the user confirms.
 */
import { Modal } from 'bootstrap';

/**
 * @param {string} message
 * @param {{title?: string, confirmText?: string, cancelText?: string, danger?: boolean}} [opts]
 * @returns {Promise<boolean>}
 */
export function confirmDialog(message, opts = {}) {
    const {
        title = 'Are you sure?',
        confirmText = 'Confirm',
        cancelText = 'Cancel',
        danger = false,
    } = opts;

    return new Promise((resolve) => {
        const el = document.createElement('div');
        el.className = 'modal fade';
        el.tabIndex = -1;
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-role="cancel"></button>
                        <button type="button" class="btn" data-role="confirm"></button>
                    </div>
                </div>
            </div>`;

        el.querySelector('.modal-title').textContent = title;
        el.querySelector('.modal-body').textContent = message;

        const cancelBtn = el.querySelector('[data-role="cancel"]');
        const confirmBtn = el.querySelector('[data-role="confirm"]');
        cancelBtn.textContent = cancelText;
        confirmBtn.textContent = confirmText;
        confirmBtn.classList.add(danger ? 'btn-danger' : 'btn-primary');

        document.body.appendChild(el);
        const modal = new Modal(el);
        let confirmed = false;

        confirmBtn.addEventListener('click', () => { confirmed = true; modal.hide(); });
        el.addEventListener('shown.bs.modal', () => confirmBtn.focus(), { once: true });
        el.addEventListener('hidden.bs.modal', () => {
            el.remove();
            resolve(confirmed);
        }, { once: true });

        modal.show();
    });
}
