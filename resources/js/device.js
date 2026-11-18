// This browser's device identity for the "latest position" prompt
// (contract: device_id 8–64 chars of [A-Za-z0-9_-], device_name ≤ 40 chars).
//
// The id is generated once (prefix `w_`) and kept in localStorage AND in the
// `novarr_device` / `novarr_device_name` cookies, so plain page navigations
// (the reader's GET auto-mark) carry it too. app.js calls initDevice() on
// every page, before the first reader navigation can happen. Every storage
// touch is guarded: private windows and blocked site data throw, and the
// worst case is a fresh id for this page view (the server then stores the
// write with that id, or with no device at all).
//
// Read-state writes attach the identity with deviceHeaders() (fetch) or
// appendDeviceFields() (navigator.sendBeacon FormData, where headers are
// impossible).

export const DEVICE_KEY = 'novarr_device';
export const DEVICE_COOKIE = 'novarr_device';
export const DEVICE_NAME_COOKIE = 'novarr_device_name';
export const HEADER_ID = 'X-Novarr-Device';
export const HEADER_NAME = 'X-Novarr-Device-Name';
const COOKIE_MAX_AGE = 2 * 365 * 24 * 60 * 60;   // two years, in seconds
const VALID_ID = /^[A-Za-z0-9_-]{8,64}$/;
const MAX_NAME = 40;

export const isValidDeviceId = (id) => typeof id === 'string' && VALID_ID.test(id);

/**
 * A short human name for this browser from its user agent / platform:
 * "Web · iPhone", "Web · iPad", "Web · Android", "Web · Mac",
 * "Web · Windows", "Web · Linux", else "Web". Pure.
 *
 * iPadOS 13+ reports a Mac user agent; a "Mac" with a touch screen
 * (maxTouchPoints > 1) is an iPad.
 */
export function deviceNameFrom({ userAgent = '', platform = '', maxTouchPoints = 0 } = {}) {
    const ua = String(userAgent || '');
    const pf = String(platform || '');
    const both = ua + ' ' + pf;
    let os = null;
    if (/iPad/i.test(both)) os = 'iPad';
    else if (/iPhone|iPod/i.test(both)) os = 'iPhone';
    else if (/Android/i.test(ua)) os = 'Android';
    else if (/Windows|Win32|Win64/i.test(both)) os = 'Windows';
    else if (/Macintosh|Mac OS X|MacIntel|macOS/i.test(both)) os = maxTouchPoints > 1 ? 'iPad' : 'Mac';
    else if (/CrOS/i.test(ua)) os = null;   // ChromeOS: plain "Web"
    else if (/Linux|X11/i.test(both)) os = 'Linux';
    return os ? 'Web · ' + os : 'Web';
}

/** A new random id: `w_` + 24 hex chars from crypto.getRandomValues. */
export function generateDeviceId(cryptoObj = globalThis.crypto) {
    const bytes = new Uint8Array(12);
    if (cryptoObj?.getRandomValues) {
        cryptoObj.getRandomValues(bytes);
    } else {
        // No Web Crypto (very old browser): still unique enough for one user.
        for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    return 'w_' + Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

/** A cookie's (URL-decoded) value from a document.cookie string, or null. */
export function readCookie(cookieString, name) {
    for (const part of String(cookieString || '').split(';')) {
        const eq = part.indexOf('=');
        if (eq === -1) continue;
        if (part.slice(0, eq).trim() !== name) continue;
        try {
            return decodeURIComponent(part.slice(eq + 1).trim());
        } catch (e) {
            return null;
        }
    }
    return null;
}

function cookieString(name, value) {
    return `${name}=${encodeURIComponent(value)}; path=/; max-age=${COOKIE_MAX_AGE}; SameSite=Lax`;
}

let current = null;

/**
 * Load (or create) this browser's identity and mirror it to localStorage and
 * the cookies. Idempotent per page; pass `force: true` (tests) to re-read.
 * Environment is injectable for tests; defaults to the browser globals.
 *
 * @returns {{ id: string, name: string }}
 */
export function initDevice({
    storage = safeGlobal('localStorage'),
    doc = typeof document !== 'undefined' ? document : null,
    nav = typeof navigator !== 'undefined' ? navigator : null,
    cryptoObj = globalThis.crypto,
    force = false,
} = {}) {
    if (current && !force) return current;

    let id = null;
    try { id = storage?.getItem(DEVICE_KEY) ?? null; } catch (e) { id = null; }
    if (!isValidDeviceId(id)) {
        // localStorage cleared (or blocked) but the cookie survived: keep it.
        let fromCookie = null;
        try { fromCookie = readCookie(doc?.cookie, DEVICE_COOKIE); } catch (e) { fromCookie = null; }
        id = isValidDeviceId(fromCookie) ? fromCookie : generateDeviceId(cryptoObj);
    }

    const name = deviceNameFrom({
        userAgent: nav?.userAgent,
        platform: nav?.userAgentData?.platform || nav?.platform,
        maxTouchPoints: nav?.maxTouchPoints || 0,
    }).slice(0, MAX_NAME);

    try { storage?.setItem(DEVICE_KEY, id); } catch (e) { /* storage blocked */ }
    if (doc) {
        try {
            doc.cookie = cookieString(DEVICE_COOKIE, id);
            doc.cookie = cookieString(DEVICE_NAME_COOKIE, name);
        } catch (e) { /* cookies blocked (sandboxed frame) */ }
    }

    current = { id, name };
    return current;
}

/** The identity, initialising on first use. Never throws. */
export function getDevice() {
    try {
        return initDevice();
    } catch (e) {
        return null;
    }
}

/**
 * A header value must be a ByteString: fetch sends each code unit as one
 * byte, so "Web · Mac" would go out as Latin-1 (invalid UTF-8 on the
 * server). Spell the UTF-8 bytes out as code units 0–255 instead.
 */
export function toHeaderValue(str) {
    const bytes = new TextEncoder().encode(String(str));
    let out = '';
    for (const b of bytes) out += String.fromCharCode(b);
    return out;
}

/** `X-Novarr-Device` / `X-Novarr-Device-Name` for a fetch write ({} when unknown). */
export function deviceHeaders(device = getDevice()) {
    if (!device || !isValidDeviceId(device.id)) return {};
    const h = { [HEADER_ID]: device.id };
    if (device.name) h[HEADER_NAME] = toHeaderValue(device.name);
    return h;
}

/** `device` / `device_name` fields on a sendBeacon FormData. Returns `form`. */
export function appendDeviceFields(form, device = getDevice()) {
    if (!device || !isValidDeviceId(device.id)) return form;
    form.append('device', device.id);
    if (device.name) form.append('device_name', device.name);
    return form;
}

/** Tests only: forget the cached identity. */
export function resetDeviceForTests() {
    current = null;
}

function safeGlobal(name) {
    try {
        return typeof window !== 'undefined' ? window[name] : (globalThis[name] ?? null);
    } catch (e) {
        return null;
    }
}
