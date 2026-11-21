// Passkeys (resources/js/passkeys.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    b64urlToBuffer, bufferToB64url, creationOptionsFromJSON, requestOptionsFromJSON,
    credentialToJSON, passkeysSupported,
} from '../../resources/js/passkeys.js';

const bytes = (buf) => Array.from(new Uint8Array(buf));

test('base64url round-trips every byte value, without padding', () => {
    const all = new Uint8Array(256).map((_, i) => i);
    const encoded = bufferToB64url(all.buffer);
    assert.equal(/[+/=]/.test(encoded), false);
    assert.deepEqual(bytes(b64urlToBuffer(encoded)), Array.from(all));
    assert.equal(bufferToB64url(new Uint8Array([0xfb, 0xff]).buffer), '-_8');
    assert.deepEqual(bytes(b64urlToBuffer('-_8')), [0xfb, 0xff]);
});

test('creation options: challenge, user id and excluded ids become buffers', () => {
    const options = creationOptionsFromJSON({
        rp: { id: 'read.example.com', name: 'Novarr' },
        user: { id: 'AQID', name: 'sam', displayName: 'Sam' },
        challenge: 'BAUG',
        pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
        excludeCredentials: [{ type: 'public-key', id: 'BwgJ', transports: ['internal'] }],
        authenticatorSelection: { residentKey: 'required', userVerification: 'required' },
    });
    assert.deepEqual(bytes(options.challenge), [4, 5, 6]);
    assert.deepEqual(bytes(options.user.id), [1, 2, 3]);
    assert.equal(options.user.name, 'sam');
    assert.deepEqual(bytes(options.excludeCredentials[0].id), [7, 8, 9]);
    assert.deepEqual(options.excludeCredentials[0].transports, ['internal']);
    assert.equal(options.authenticatorSelection.userVerification, 'required');
});

test('request options: allowCredentials only when the server sent them', () => {
    const discoverable = requestOptionsFromJSON({ challenge: 'AQID', rpId: 'read.example.com', userVerification: 'required' });
    assert.equal('allowCredentials' in discoverable, false);
    assert.deepEqual(bytes(discoverable.challenge), [1, 2, 3]);
    const known = requestOptionsFromJSON({ challenge: 'AQID', allowCredentials: [{ type: 'public-key', id: 'BAUG' }] });
    assert.deepEqual(bytes(known.allowCredentials[0].id), [4, 5, 6]);
});

test('an assertion is sent with every field base64url and the user handle', () => {
    const json = credentialToJSON({
        id: 'AQID', rawId: new Uint8Array([1, 2, 3]).buffer, type: 'public-key',
        response: {
            clientDataJSON: new Uint8Array([123, 125]).buffer,
            authenticatorData: new Uint8Array([9]).buffer,
            signature: new Uint8Array([8]).buffer,
            userHandle: new Uint8Array([7]).buffer,
        },
    });
    assert.deepEqual(json, {
        id: 'AQID', rawId: 'AQID', type: 'public-key',
        response: { clientDataJSON: 'e30', authenticatorData: 'CQ', signature: 'CA', userHandle: 'Bw' },
    });
});

test('an attestation is sent with its transports (and none when the browser cannot say)', () => {
    const response = { clientDataJSON: new Uint8Array([1]).buffer, attestationObject: new Uint8Array([2]).buffer };
    const withTransports = credentialToJSON({
        id: 'AQ', rawId: new Uint8Array([1]).buffer, type: 'public-key',
        response: { ...response, getTransports: () => ['internal', 'hybrid'] },
    });
    assert.deepEqual(withTransports.response, { clientDataJSON: 'AQ', attestationObject: 'Ag', transports: ['internal', 'hybrid'] });
    assert.deepEqual(credentialToJSON({ id: 'AQ', rawId: new Uint8Array([1]).buffer, type: 'public-key', response }).response.transports, []);
});

test('passkeys only in a secure context with WebAuthn', () => {
    const credentials = {};
    assert.equal(passkeysSupported({ isSecureContext: true, PublicKeyCredential: function () {}, navigator: { credentials } }), true);
    assert.equal(passkeysSupported({ isSecureContext: false, PublicKeyCredential: function () {}, navigator: { credentials } }), false);
    assert.equal(passkeysSupported({ isSecureContext: true, navigator: { credentials } }), false);
    assert.equal(passkeysSupported(undefined), false);
});
