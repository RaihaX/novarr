/**
 * Novarr books inbox — a Cloudflare Email Worker.
 *
 * Cloudflare Email Routing sends every email for the books address
 * (books@theedev.online) here. The Worker posts the raw email to Novarr
 * (App\Http\Controllers\Api\V1\BookInboxController), which matches the sender
 * to a reader, puts each attached .epub in the owner's approval queue and
 * replies to the sender.
 *
 * Bindings (see deploy.sh):
 *   NOVARR_INBOX_URL     https://novarr.atomicinsights.com.au/api/v1/books/inbox  (plain text)
 *   NOVARR_INBOX_SECRET  the shared secret, also NOVARR_BOOKS_INBOX_SECRET in Novarr's .env (secret)
 *
 * Outcomes:
 *   Novarr 200            accepted (or an automatic email, dropped quietly)
 *   Novarr 422            not a reader: the email is bounced with Novarr's reason
 *   anything else / down  bounced with "try again later", so the sender knows it did not arrive
 */
export default {
  async email(message, env) {
    let response;
    try {
      response = await fetch(env.NOVARR_INBOX_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'message/rfc822',
          'X-Novarr-Inbox-Secret': env.NOVARR_INBOX_SECRET,
          'X-Novarr-Envelope-From': message.from,
        },
        body: message.raw,
        // A stream body needs half-duplex in the Workers runtime.
        duplex: 'half',
      });
    } catch (error) {
      console.error('Novarr unreachable', String(error));
      message.setReject('Novarr could not be reached. Please try again later.');
      return;
    }

    if (response.ok) {
      return;
    }

    let reason = 'Novarr could not take this email. Please try again later, or upload the book on the website.';
    try {
      const body = await response.json();
      if (response.status === 422 && body && typeof body.message === 'string') {
        reason = body.message;
      }
    } catch (_) {
      // not JSON: keep the generic reason
    }
    console.warn('Novarr refused the email', response.status, message.from);
    message.setReject(reason);
  },
};
