/**
 * Shared artisan-command runner used by the Commands form and the novel
 * detail page. Replaces three diverging copies of the same fetch/poll logic
 * that previously lived inline in Blade templates.
 */

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const jsonHeaders = () => ({
    'X-CSRF-TOKEN': csrfToken(),
    'Accept': 'application/json',
    'Content-Type': 'application/json',
});

/**
 * Execute an artisan command via the web runner.
 *
 * @param {Object} payload  e.g. { command: 'scrape_chapters', novel_id: 3 }
 * @param {Object} options  { background: true } queues the command and polls
 *                          until it finishes; false runs it synchronously.
 * @returns {Promise<Object>} the result payload: { success, output, error, message }
 */
export async function executeCommand(payload, { background = true } = {}) {
    const url = background ? '/commands/execute-async' : '/commands/execute';

    const response = await fetch(url, {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify(payload),
    });

    const data = await response.json();

    if (background && data.success && data.job_id) {
        return pollJobStatus(data.job_id);
    }

    return data;
}

/**
 * Poll a queued command until it completes. Rejects on network failure or
 * when `timeout` elapses (a job that never reports back previously left the
 * old inline implementations polling forever).
 */
export function pollJobStatus(jobId, { interval = 2000, timeout = 30 * 60 * 1000 } = {}) {
    const deadline = Date.now() + timeout;

    return new Promise((resolve, reject) => {
        const timer = setInterval(async () => {
            if (Date.now() > deadline) {
                clearInterval(timer);
                reject(new Error('Timed out waiting for the command to finish.'));
                return;
            }

            try {
                const response = await fetch(`/commands/status/${jobId}`, {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();

                if (data.status === 'completed') {
                    clearInterval(timer);
                    resolve(data.result);
                }
            } catch (err) {
                clearInterval(timer);
                reject(err);
            }
        }, interval);
    });
}
