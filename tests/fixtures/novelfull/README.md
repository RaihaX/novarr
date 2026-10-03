# novelfull.com fixtures

Saved from the live site on 2026-10-03 (via FlareSolverr) for the golden tests in
`tests/Feature/GoldenNovelFullTest.php`. `<script>`/`<style>` bodies and ad `<iframe>`s were
stripped and indentation collapsed; every element the parsers read is unchanged.

| File | Live URL | Used for |
|---|---|---|
| `novel-page-shadow-slave.html` | `/shadow-slave.html` | `data-novel-id`, metadata (title, author, cover, synopsis, latest chapter) |
| `ajax-chapter-option-shadow-slave.html` | `/ajax-chapter-option?novelId=1396` | full chapter list — all 3,203 `<option>`s (330 KB, not trimmed) |
| `ajax-legacy-endpoint-not-found.html` | `/ajax/chapter-option?novelId=1396` | the old endpoint's 200 "Not Found" page (junk-URL regression) |
| `chapter-1-nightmare-begins.html` | `/shadow-slave/chapter-1-nightmare-begins.html` | content: `<h3>` title, ad boxes, `.me😉` watermark tail, report notice |
| `chapter-1600-beast-farm.html` | `/shadow-slave/chapter-1600-beast-farm.html` | content |
| `search-shadow-slave.html` | `/search?keyword=shadow+slave` | Discover search cards (cover + author) |

`*.expected.json` are the golden outputs (regenerate with `GOLDEN_UPDATE=1`, then review).
The older `../novelfull-chapter-209-eight-paragraphs.html` is reused as well.
