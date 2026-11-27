# Plan: readers upload audiobooks (with the owner's approval)

Status: **planned, not started** (2026-11-27). Owner asked: "an upload audiobook for readers that
want to upload their own audiobook to be part of the Novarr library".

## The problem to design around

Audiobooks are 200 MB – 2 GB (sometimes a folder of 20–80 MP3s). The site takes at most
~50 MB per request (nginx `client_max_body_size 50m`, PHP `post_max_size 64M`), the Cloudflare
tunnel caps a request at 100 MB, and phones drop connections. So uploads must be **chunked and
resumable**, and the bytes must land on the **big HDD**, never the 49 GB system disk.

## Flow

1. **Reader uploads** — from a book's page ("Upload the audiobook", shown when the book has
   none) or the Audiobooks page (no book chosen: it is matched later). One file (`.m4b .m4a .mp3
   .aac .ogg .opus .flac`) or several files / a folder (browser folder picker + multi-select).
   Optional note to the owner.
2. **Server receives chunks** (8 MB each) into `{audiobooks root}/_uploads/{upload id}/` on the HDD,
   assembles each file, verifies size + SHA-256, then **probes it at once** (ffprobe through the
   existing `App\Audiobooks\AudioProbe`): not audio → refused with the reason, files deleted.
3. **Pending upload** → the owner's queue: **Downloads → Needs you → "Audiobooks to approve"**
   (+ email to the owner on the shared mail layout, an `AdminAlert` with cover card + "Review").
4. **Owner reviews** (`/audiobooks/uploads/{id}`): uploader, note, title / author / narrator from the
   tags, length, part count, chapter list, embedded cover, a 30-second **sample player** (streams
   the pending file, owner only), and Novarr's **suggested book** (`AudiobookMatcher` +
   Audible lookup). Actions: **Approve** (confirm / change the book, or standalone) · **Decline**
   (reason the reader sees).
5. **On approval** the files move into the normal pipeline: `AudiobookImporter` (with the chosen
   `novel_id`, `copy=false`), Audible enrichment, chapters, cover → the book shows **Listen**.
   The reader is emailed "Your audiobook is in the library" (shared mail layout, cover card,
   "Listen now"). On decline: files deleted, reader emailed the reason.
6. Duplicate guard: if the book already has an audiobook, or the fingerprint matches an existing
   one, the review page says so ("Already in the library — use the existing one / replace it").

## Server work

- **Migration** `audiobook_uploads`: id, user_id, novel_id (suggested/target, nullable), status
  (`uploading|pending|approved|declined|failed`), note, decline_reason, dir, total_bytes,
  received_bytes, files (json: name, size, sha256, done), probe (json: title, author, narrator,
  duration_ms, chapters, cover_path), fingerprint, audiobook_id (once imported), decided_by,
  decided_at, timestamps.
- **Chunk API** (reader, session or bearer; RouteAccess = reader):
  - `POST /api/v1/audiobook-uploads` {files:[{name,size}], novel_id?, note?} → 201 {upload:{id, chunk_size}}
    (checks: per-file ≤ 3 GB, total ≤ 6 GB, ≤ 200 files, allowed extensions, the reader's
    pending uploads ≤ 5, free space on the HDD ≥ total + 5 GB).
  - `PUT /api/v1/audiobook-uploads/{id}/files/{index}/chunks/{n}` (raw body ≤ 8 MB, Content-Range
    style offset) → 204; idempotent (re-sending a chunk is fine).
  - `GET /api/v1/audiobook-uploads/{id}` → which chunks are present (resume after a drop or reload).
  - `POST /api/v1/audiobook-uploads/{id}/complete` → assemble + verify + probe → 200 pending / 422 why.
  - `DELETE /api/v1/audiobook-uploads/{id}` → the reader cancels (files deleted).
- **Owner** (admin, home network): `GET /audiobooks/uploads/{id}` review page, `POST …/approve`
  {novel_id|null}, `POST …/decline` {reason}, `GET …/sample/{index}` (Range streaming, admin only).
- **Clean-up**: abandoned `uploading` older than 48 h and declined uploads are deleted by the
  nightly `downloads:tidy` (extend `App\Downloads\InboxTidy`); never touches pending ones.
- **Limits in config** (`novarr.audiobooks.uploads.*`): enabled, max_file_mb, max_total_mb,
  max_pending_per_reader, chunk_mb, min_free_gb.
- nginx/PHP: 8 MB chunks fit the current limits — **no server config change needed**.

## Web UI

- `resources/js/audiobook-upload.js` (+ `-logic.js`, tested): file/folder pickers, drag & drop,
  per-file + total progress bars, speed / time left, pause / resume, automatic retry with backoff,
  resume after reload (upload id kept in localStorage), "Don't close this tab" warning while sending.
- Upload page `/audiobooks/upload` (and the button on a book page pre-fills the book).
- Readers see their uploads under **Requests → My requests** (status chips: Uploading · Waiting for
  the owner · In the library · Declined).

## Android (1.15)

- "Upload an audiobook" on a book screen and the Listen tab: system file picker (multi-select),
  a foreground WorkManager upload using the same chunk API (resumable, survives the app closing,
  Wi-Fi-only option), progress notification. Share-to-Novarr also accepts audio files.

## Tests

Chunk assembly + resume + idempotent chunks; size/type/space/pending limits; probe refusal;
owner approve → imported + linked + reader email; decline → files gone + email; duplicate guard;
sample streaming admin-only; RouteAccess; tidy-up of abandoned uploads; JS logic (chunk planning,
retry/backoff, resume state). Android: chunk planner + resume logic unit tests.

## Order of work

1. Server: migration, chunk API, assembly/probe, owner review + approve/decline, emails, tidy.
2. Web upload page + book-page button + My requests status.
3. Deploy, test with a real 1 GB m4b and a folder of MP3s over the public address.
4. Android 1.15 upload + share.
