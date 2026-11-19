# novelfire.net fixtures

Saved from the live site on 2026-11-20 with plain GETs (a desktop browser User-Agent; no
FlareSolverr needed — the site was not challenging requests) for the golden tests in
`tests/Feature/GoldenNovelFireTest.php`. `<script>`/`<style>` elements and SVG `<symbol>`s were
stripped and indentation collapsed; every element the parsers read is unchanged.

| File | Live URL | Used for |
|---|---|---|
| `search-shadow-slave.html` | `/search?keyword=shadow+slave` | Discover search (20 results + a "Some Popular Novels" block that must be ignored) |
| `search-no-results.html` | `/search?keyword=zzqxvjwq+nonexistent` | empty search (only the popular block) |
| `book-shadow-slave.html` | `/book/shadow-slave` | metadata (title, author, cover, synopsis, chapter count, status, genres) |
| `chapters-shadow-slave-error-desc-page-1.html`, `…-page-2.html` | `/book/shadow-slave-error/chapters?page=N&sort_by=desc` | full TOC of a 108-chapter book (2 pages) |
| `chapters-shadow-slave-desc-page-1.html` | `/book/shadow-slave/chapters?page=1&sort_by=desc` | incremental TOC stop; pagination to page 33 |
| `chapter-shadow-slave-1.html` | `/book/shadow-slave/chapter-1` | content (a-ads boxes inside `#content`) |
| `chapter-the-legendary-mechanic-500.html` | `/book/the-legendary-mechanic/chapter-500` | content (`<h4>` heading inside `#content`, translator credit line) |
| `redirect-302-shadow-slave-3252.html` | `/book/shadow-slave/chapter-3252` | the 302 body the site sometimes answers a chapter with |
| `redirect-readnovel-loader.html` | `https://readnovel.site/novel/shadow-slave/chapter-3252` | where that 302 leads: a script redirect to novelphoenix.com (Cloudflare beacon removed) |

`*.expected.json` are the golden outputs (regenerate with `GOLDEN_UPDATE=1`, then review).
