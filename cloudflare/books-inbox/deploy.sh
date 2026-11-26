#!/usr/bin/env bash
# Upload the books-inbox Email Worker to Cloudflare (no wrangler needed).
#
#   CLOUDFLARE_API_TOKEN=…   a token with Account › Workers Scripts › Edit
#   CLOUDFLARE_ACCOUNT_ID=…
#   NOVARR_INBOX_URL=https://novarr.theedev.online/api/v1/books/inbox
#   NOVARR_INBOX_SECRET=…    the same value as NOVARR_BOOKS_INBOX_SECRET in Novarr's .env
#   ./deploy.sh
#
# Then, once, in the Cloudflare dashboard (theedev.online › Email › Email Routing):
# enable Email Routing (it adds the MX/SPF records) and add a custom address
# books@ → action "Send to a Worker" → novarr-books-inbox.
set -euo pipefail
cd "$(dirname "$0")"

: "${CLOUDFLARE_API_TOKEN:?}" "${CLOUDFLARE_ACCOUNT_ID:?}" "${NOVARR_INBOX_URL:?}" "${NOVARR_INBOX_SECRET:?}"
SCRIPT_NAME="${SCRIPT_NAME:-novarr-books-inbox}"

metadata=$(python3 -c '
import json, os
print(json.dumps({
    "main_module": "worker.js",
    "compatibility_date": "2026-11-01",
    "bindings": [
        {"type": "plain_text", "name": "NOVARR_INBOX_URL", "text": os.environ["NOVARR_INBOX_URL"]},
        {"type": "secret_text", "name": "NOVARR_INBOX_SECRET", "text": os.environ["NOVARR_INBOX_SECRET"]},
    ],
}))')

curl -sS --fail-with-body -X PUT \
    "https://api.cloudflare.com/client/v4/accounts/${CLOUDFLARE_ACCOUNT_ID}/workers/scripts/${SCRIPT_NAME}" \
    -H "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
    --form-string "metadata=${metadata}" \
    -F "worker.js=@worker.js;type=application/javascript+module" \
  | python3 -c 'import sys,json; d=json.load(sys.stdin); print("deployed" if d.get("success") else d.get("errors"))'
