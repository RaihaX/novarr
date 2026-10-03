# Novel Arrow fixtures

JSON API responses for Shadow Slave, saved from the live site on 2026-10-03, for
`tests/Feature/GoldenNovelArrowTest.php`.

| File | Live URL (`https://novelarrow.com/api-web/…`) | Notes |
|---|---|---|
| `api-chapter-1-nightmare-begins.json` | `novels/shadow-slave/chapters/chapter-1-nightmare-begins` | verbatim (re-indented) |
| `api-chapter-1600-beast-farm.json` | `novels/shadow-slave/chapters/chapter-1600-beast-farm` | verbatim (re-indented) |
| `api-chapter-3203-ultima-ratio-regum.json` | `novels/shadow-slave/chapters/chapter-3203-ultima-ratio-regum` | verbatim; the source itself splits one sentence over two `<p>`s |
| `api-chapters-shadow-slave.json` | `novels/shadow-slave/chapters?sort=asc` | **reconstructed and trimmed** — see below |

`api-chapters-shadow-slave.json`: the raw chapter-list response was not saved, so it was rebuilt
from the live run's parsed TOC (label = `chapter_name`, last URL segment = `chapter_id`, which is
exactly what `novelArrowChapterArchive()` reads) and trimmed to 78 of the 3,206 items: chapters
1–40, 2228–2238 (including the API's real duplicate listings of 2232–2234) and 3180–3203
(including the number-less "Chapter Entertaining Guest" = 3189).
