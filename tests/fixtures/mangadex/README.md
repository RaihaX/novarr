# MangaDex fixtures

Answers of the public API (api.mangadex.org), captured 2026-11-17 with a
plain `Novarr/1.0` User-Agent and trimmed. Used by `MangaDexSourceTest`,
`MangaDownloadTest` and `MangaReaderApiTest`.

- `feed-page-1.json`, `feed-page-2.json` — `GET /manga/{id}/feed`. Built from
  real Chainsaw Man entries (Viz external chapter, Mangastream scans) plus
  edited copies so one feed covers: an `externalUrl` chapter, a zero-page
  chapter, the same number from two groups, a decimal chapter (2.5), two
  oneshots, a tie between two groups, and pagination (`total` 507 → a second
  page at offset 500).
- `feed-short.json` — the real feed of a two-chapter series (9bb2d279…).
- `manga.json` — `GET /manga/{id}?includes[]=cover_art&includes[]=author&includes[]=artist`
  for the same series (author biographies trimmed).
- `at-home.json` — `GET /at-home/server/{chapterId}`, three pages kept.
- `discover-popular.json`, `discover-search.json` — `GET /manga` lists
  (two entries each, relationships/tags trimmed).
- `page.png`, `page.jpg`, `page.webp` — tiny generated 8×12 images standing
  in for page images.
