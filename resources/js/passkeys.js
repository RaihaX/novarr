// Passkeys (WebAuthn) in plain navigator.credentials — App\Services\Passkeys
// on the server. Four places, each marked with a data attribute:
//
//   [data-passkey-signin]       /login: "Sign in with a passkey" (one step)
//   [data-passkey-second-step]  /login/second-step: the second step
//   [data-passkey-confirm]      Account: "Confirm it's you" (public address)
//   [data-passkey-register]     Account: "Add a passkey"
//
// Each has data-options-url (POST → the options JSON, base64url fields),
// data-verify-url / data-store-url (POST the credential JSON), a hidden
// [data-passkey-button], [data-passkey-error] and [data-passkey-unsupported].
// The button is shown only where the browser can use passkeys (a secure
// context with PublicKeyCredential); the server already hides it all on
// plain-http addresses. Answers are JSON: {redirect} or {message, redirect?}.

/** base64url (no padding) → ArrayBuffer. */
export function b64urlToBuffer(value) {
    const b64 = String(value).replace(/-/g, '+').replace(/_/g, '/');
    const padded = b64 + '='.repeat((4 - (b64.length % 4)) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
}

/** ArrayBuffer / view → base64url (no padding). */
export function bufferToB64url(buffer) {
    const bytes = buffer instanceof ArrayBuffer ? new Uint8Array(buffer) : new Uint8Array(buffer.buffer, buffer.byteOffset, buffer.byteLength);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

const descriptors = (list) => (list || []).map((d) => ({ ...d, id: b64urlToBuffer(d.id) }));

/** The server's creation options → what navigator.credentials.create() takes. */
export function creationOptionsFromJSON(json) {
    return {
        ...json,
        challenge: b64urlToBuffer(json.challenge),
        user: { ...json.user, id: b64urlToBuffer(json.user.id) },
        excludeCredentials: descriptors(json.excludeCredentials),
    };
}

/** The server's request options → what navigator.credentials.get() takes. */
export function requestOptionsFromJSON(json) {
    const options = { ...json, challenge: b64urlToBuffer(json.challenge) };
    if (json.allowCredentials) options.allowCredentials = descriptors(json.allowCredentials);
    return options;
}

/** A PublicKeyCredential → the JSON the server reads (web-auth/webauthn-lib's shape). */
export function credentialToJSON(credential) {
    const r = credential.response;
    const response = { clientDataJSON: bufferToB64url(r.clientDataJSON) };
    if (r.attestationObject) {
        response.attestationObject = bufferToB64url(r.attestationObject);
        response.transports = typeof r.getTransports === 'function' ? r.getTransports() : [];
    } else {
        response.authenticatorData = bufferToB64url(r.authenticatorData);
        response.signature = bufferToB64url(r.signature);
        response.userHandle = r.userHandle ? bufferToB64url(r.userHandle) : null;
    }
    return {
        id: credential.id,
        rawId: bufferToB64url(credential.rawId),
        type: credential.type,
        response,
    };
}

/** Whether this browser can use passkeys on this page. */
export function passkeysSupported(win = globalThis.window) {
    return Boolean(win && win.isSecureContext && win.PublicKeyCredential && win.navigator?.credentials);
}

async function postJSON(url, body = {}) {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify(body),
    });
    let data = {};
    try {
        data = await res.json();
    } catch (e) {
        data = {};
    }
    return { ok: res.ok, status: res.status, data };
}

const NOT_DONE = 'The passkey was not used. Try again, or use another way.';

function bind(el, run) {
    if (el.dataset.passkeyBound) return;
    el.dataset.passkeyBound = '1';
    const button = el.querySelector('[data-passkey-button]');
    const error = el.querySelector('[data-passkey-error]');
    const unsupported = el.querySelector('[data-passkey-unsupported]');
    if (!button) return;
    if (!passkeysSupported()) {
        if (unsupported) unsupported.hidden = false;
        return;
    }
    button.hidden = false;
    const show = (message) => {
        if (!error) return;
        error.textContent = message || '';
        error.hidden = !message;
    };
    button.addEventListener('click', async () => {
        show('');
        button.disabled = true;
        try {
            const result = await run(el);
            if (result?.redirect) {
                window.location.assign(result.redirect);
                return;
            }
            show(result?.message || NOT_DONE);
        } catch (e) {
            // The person cancelled, or the browser refused (NotAllowedError).
            show(NOT_DONE);
        } finally {
            button.disabled = false;
        }
    });
}

/** Ask for an assertion and post it (sign in, second step, confirm). */
async function useOne(el, extra) {
    const options = await postJSON(el.dataset.optionsUrl);
    if (!options.ok) return options.data;
    const credential = await navigator.credentials.get({ publicKey: requestOptionsFromJSON(options.data) });
    const answer = await postJSON(el.dataset.verifyUrl, { ...extra, credential: credentialToJSON(credential) });
    return answer.data;
}

async function makeOne(el) {
    const options = await postJSON(el.dataset.optionsUrl);
    if (!options.ok) return options.data;
    const credential = await navigator.credentials.create({ publicKey: creationOptionsFromJSON(options.data) });
    const name = el.querySelector('[data-passkey-name]')?.value || '';
    const answer = await postJSON(el.dataset.storeUrl, { name, credential: credentialToJSON(credential) });
    return answer.data;
}

export function initPasskeys(root = document) {
    root.querySelectorAll('[data-passkey-signin]').forEach((el) => bind(el, (e) => useOne(e, {})));
    root.querySelectorAll('[data-passkey-second-step], [data-passkey-confirm]').forEach((el) => bind(el, (e) => useOne(e, { method: 'passkey' })));
    root.querySelectorAll('[data-passkey-register]').forEach((el) => bind(el, makeOne));
}
