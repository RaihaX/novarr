# Suwayomi fixtures

Small hand-written answers of Suwayomi-Server's GraphQL API
(`POST {base}/api/graphql`), shaped like the live answers of v2.4.2366
(verified 2026-11-17) and trimmed. `fetch-chapters.json` is constructed
to exercise the TOC rules (several scanlators per number, a count tie, an
upload-date tie, unnumbered chapters); `error.json` is the `errors` shape
Suwayomi answers (HTTP 200) when the site behind a source fails, with a
long Java stack trace. Page images come from `../mangadex/page.*`.
