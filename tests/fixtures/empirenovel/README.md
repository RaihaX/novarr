# empirenovel.com fixtures

Saved from the live site on 2026-10-03 for `tests/Feature/GoldenEmpireNovelTest.php`.
`<script>`/`<style>` bodies and ad `<iframe>`s were stripped; structure is unchanged.

| File | Live URL (`https://www.empirenovel.com/novel/…`) | Notes |
|---|---|---|
| `toc-taming-the-villainesses-page-1.html` | `taming-the-villainesses?page=1` (FlareSolverr) | pagination links up to `?page=12`; newest chapters 814–843 + "First Chapter" |
| `toc-taming-the-villainesses-page-2.html` | `…?page=2` (plain client + cf_clearance) | the source's own hole: 813…799 then 455…441 |
| `toc-taming-the-villainesses-page-6.html` | `…?page=6` | |
| `toc-taming-the-villainesses-page-12.html` | `…?page=12` | last page, chapters 1–13 |
| `toc-page-not-captured.html` | — | **synthetic** stand-in for the TOC pages that were not saved (3–5, 7–11, and Shadow Slave 2–107): a list page with no chapter rows |
| `toc-shadow-slave-page-1.html` | `shadow-slave?page=1` | 107 list pages |
| `chapter-1-taming-the-villainesses.html` | `taming-the-villainesses/1` | content in `#read-novel`, "Quality checked by" credit |
| `chapter-500-not-found.html` | `taming-the-villainesses/500` | the "Not Found" page served for a hole |

Because only four of the twelve TOC pages exist, the golden TOC has 103 rows instead of the live
343; the test still proves the walk requests every page 2–12.
