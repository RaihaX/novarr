/**
 * Minimal Bootstrap toast helper — replaces native alert() calls.
 */
import { Toast } from 'bootstrap';

function container() {
    let el = document.getElementById('novarr-toasts');

    if (!el) {
        el = document.createElement('div');
        el.id = 'novarr-toasts';
        el.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        document.body.appendChild(el);
    }

    return el;
}

/**
 * @param {string} message
 * @param {'success'|'danger'|'warning'|'info'} type
 */
export function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-bg-${type} border-0`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', type === 'danger' ? 'assertive' : 'polite');
    toast.setAttribute('aria-atomic', 'true');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>`;
    toast.querySelector('.toast-body').textContent = message;

    container().appendChild(toast);
    toast.addEventListener('hidden.bs.toast', () => toast.remove());

    new Toast(toast, { delay: 5000 }).show();
}
