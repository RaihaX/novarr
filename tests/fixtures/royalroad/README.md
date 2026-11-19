# www.royalroad.com fixtures

Saved from the live site on 2026-11-20 (through `App\Scraping\Fetcher`) for the golden tests in
`tests/Feature/GoldenRoyalRoadTest.php`. `<script>`/`<style>` blocks were stripped except the
`window.chapters` / `ld+json` scripts and the anti-piracy `display: none; speak: never` style
blocks; `<iframe>`/`<svg>`/stylesheet links were dropped, CSRF token values blanked and
indentation collapsed. Every element the parsers read is unchanged.

| File | Live URL | Used for |
|---|---|---|
| `search-mother-of-learning.html` | `/fictions/search?title=mother+of+learning` | Discover search items (20) |
| `search-no-results.html` | `/fictions/search?title=qzxjvwqkbt` | empty result page → `[]` |
| `weekly-popular.html` | `/fictions/weekly-popular` | `discover('popular')` (not offered on Discover) |
| `complete.html` | `/fictions/complete` | `discover('completed')` (rows carry no status label) |
| `fiction-21220-mother-of-learning.html` | `/fiction/21220/mother-of-learning` | TOC (109; unnumbered Epilogue / Afterword / announcement at the end), metadata (Completed), table fallback |
| `fiction-33844-the-runesmith.html` | `/fiction/33844/the-runesmith` | long TOC (731; two unnumbered entries first). **Trimmed:** chapter-table rows 6–726 cut (the `window.chapters` JSON keeps all 731) |
| `fiction-26294-he-who-fights-with-monsters-stub.html` | `/fiction/26294/he-who-fights-with-monsters` | a STUB: 28 chapters left, "Chapter 1035" twice, status label "STUB" |
| `chapter-301778-good-morning-brother.html` | MoL chapter 1 | content; author note after the chapter; hidden anti-piracy `<span>` |
| `chapter-4376982-taking-damage.html` | The Runesmith chapter 729 | content; author note before the chapter; hidden anti-piracy `<span>` |

`testHiddenParagraphIsStrippedOnlyThroughThePageSelectors` edits the MoL chapter in memory
into a **synthetic** variant (the hidden `<span>` turned into a `<p>`); no file holds it.

`*.expected.json` are the golden outputs (regenerate with `GOLDEN_UPDATE=1`, then review).
