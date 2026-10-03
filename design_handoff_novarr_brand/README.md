# Handoff: Novarr brand & theme pack

## Overview
Novarr is a self-hosted personal web-novel library ("Sonarr, but for novels"): it scrapes
novels from translation sites, tracks reading progress, generates ePubs and surfaces a health
dashboard for failing sources. Audience is one technical user plus family. It must feel like
polished self-hosted software (Sonarr / Plex / Overseerr caliber), not a commercial bookstore.

This bundle is the visual system for an uplift of the existing app: logo suite, dark-first
colour tokens (+ light companion), a type scale, a component sheet, a reader screen, app icons
and a default ePub cover template.

Personality: calm, bookish, quietly technical. A reading app first, an automation tool second.
Dark mode is canonical — light mode is a companion, not a peer.

## About the Design Files
The files in this bundle are **design references created in HTML** — prototypes showing the
intended look, not production code to lift verbatim. The task is to **recreate these designs in
the Novarr codebase's existing environment** (Bootstrap-flavoured server-rendered templates +
SCSS, judging from the screenshots) using its established patterns. Port the *tokens* exactly;
port the *markup* idiomatically.

`_variables.scss` in this folder is the exception: it is meant to be used directly, replacing
or extending the existing variables file.

## Fidelity
**High-fidelity.** Colours, typography, spacing, radii and states are final. Recreate pixel-
accurately. The one deliberately loose area is illustration/imagery: there is none, by design.

## Screens / Views

### 1. Logo suite
- **Mark — "Serial"**: four flat horizontal bars, read as lines of text / a chapter list.
  Three indigo bars stepping down in length, the last (shortest) bar in amber — the reading
  accent. Radius 0, no gradient, no tile. Indigo `#6470FF`; amber `#F0B429`.
  Geometry on a 32×32 grid (SVG source in `novarr-mark.svg`):

  | Bar | Path | Colour |
  |---|---|---|
  | 1 | `M5 5h22v4H5z` (x5 y5 w22 h4) | `#6470FF` |
  | 2 | `M5 11.5h22v4H5z` (x5 y11.5 w22 h4) | `#6470FF` |
  | 3 | `M5 18h15v4H5z` (x5 y18 w15 h4) | `#6470FF` |
  | 4 | `M5 24.5h8v4H5z` (x5 y24.5 w8 h4) | `#F0B429` |

  Bars are 4 units tall on a 6.5-unit pitch; the visible bbox is x5–27, y5–28.5 (centre 16, 16.75).
- **Favicon cut** (`novarr-mark-favicon.svg`, `public/favicon.svg`): below **20px** the 2.5-unit
  gaps close up, so use the heavier three-bar cut — `M4 4h24v6H4z`, `M4 13h24v6H4z` in indigo and
  `M4 22h11v6H4z` in amber. It lands on whole pixels at 32px. The 16/48px frames of
  `favicon.ico` are hand-hinted to the pixel grid (16: 3px bars, 2px gaps; 48: four bars,
  6px on a 10px pitch).
- **Mono variant** (`novarr-mark-mono.svg`): single `currentColor`; the last bar stays but drops to
  55% opacity. Same rule for the three-bar cut.
- **Light grounds**: amber drops to `#C98A00` for contrast (last bar and the wordmark's full stop).
  Indigo is unchanged.
- **Wordmark**: "NOVARR" uppercase, Geist 600, letter-spacing 0.17em, followed by a full stop
  in `$brand-amber` (`#C98A00` on light grounds). Unchanged from the previous suite.
- **Lockup** (`novarr-lockup-dark.svg`, `novarr-lockup-light.svg`, `public/logo.svg`): mark and
  wordmark centred on one horizontal axis, 16px visible gap from the bars' right edge to the N;
  the wordmark is outlined (no font dependency). At 44px the 32-grid is ×1.375 and the wordmark
  is 30px. In the navbar: 28px mark + 16px/600 wordmark on a 12px gap.
- **App icons**: `novarr-app-icon-512.svg` — Serial mark on `#0F1216`, visible bars ≈62% of the
  canvas, centred inside a 16% safe margin; square, corners are left to the OS mask.
  `novarr-app-icon-maskable-512.svg` — full-bleed indigo with the bars knocked out in `#0F1216`
  and the last bar amber, bars within the centre 50% (well inside the 80% maskable safe zone).
- **Legibility**: verified at 28px (navbar), 18px (ePub cover footer, 96px at output) and
  16px (favicon cut).

### 2. Colour system
See `_variables.scss` for the authoritative list. Notes on intent:
- The body background is deepened from the current `#16181D` to `#0F1216` so surfaces can
  step up twice (`#161A20` raised, `#1C222A` alt) without card-on-card mush.
- One accent (indigo) carries all primary action; amber is reserved for *reading* signals
  (bookmark, reading-progress bar) and for the warning status. Do not use amber for actions.
- Queued/pending is cyan `#4CC4D1`, not blue — blue collides with the link colour, which is
  the main readability problem in the current dashboard.
- Status colours are used as a triad: text colour at full value, fill at 12% alpha, border at
  35% alpha. Same recipe for every badge, panel and progress bar.

### 3. Typography
Recommendation adopted in the mocks: **retire Inter for the UI**.
- UI face: **Geist** (300–700). Tabular figures and tighter caps read better in dense tables.
- Reading face: **Literata** (400/500/600 + italic). Cut for long-form screen reading; holds up
  at low brightness where a UI sans starts to buzz.
- Mono: **Geist Mono** for counts, timestamps, chapter numbers, source hostnames.
- Inter remains a legitimate stack fallback: `Geist, Inter, system-ui, sans-serif`.

| Role | Family | Size | Weight | Line-height | Tracking |
|---|---|---|---|---|---|
| Page title | Geist | 32px | 600 | 1.15 | -0.02em |
| Section header | Geist | 18px | 600 | 1.3 | -0.01em |
| Card title | Geist | 15px | 600 | 1.3 | 0 |
| Body | Geist | 14px | 400 | 1.55 | 0 |
| Caption / label | Geist | 12px | 500 | 1.4 | 0.06em, uppercase |
| Micro label | Geist | 11px | 600 | 1.4 | 0.14em, uppercase |
| Mono data | Geist Mono | 12–13px | 400 | 1.4 | 0 |
| Chapter title | Literata | 34px | 600 | 1.2 | -0.015em |
| Reader body | Literata | 19px | 400 | 1.75 | 0 |

Reader measure: 66–72ch (680px column at 19px).

### 4. Component sheet (dark palette)
Global: border-radius **4px** on interactive controls and cards; **0px** on badges and progress
bars. One 1px hairline (`$border`), no shadows anywhere except the navbar (none needed — it
sits on a divider).

- **Navbar**: 56px tall, `$surface` fill, 1px `$border` bottom. Brand lockup at 28px mark +
  15px wordmark. Nav items 14px, `$text-muted`, 7px/12px padding; the active item gets
  `$surface-alt` fill, `$text` colour and a 2px `$accent` inset underline
  (`box-shadow: inset 0 -2px 0 $accent`). Search field 240px, `$bg` fill, 1px `$border`,
  Lucide `search` icon at 14px in `$text-muted`.
- **Novel card**: `$surface`, 1px `$border`, radius 4. Cover thumb 72×104 (2:3), 1px border,
  placeholder = `linear-gradient(160deg,#1C222A,#0F1216)` with the mono mark at 18px, 50%
  opacity, bottom-left. Title 15/600 in `$link`; meta 12px `$text-muted`; status badge below.
  Footer row: mono 11px "READ 4 612 / 7 174" left, percentage right in `$brand-amber`; 4px
  track `$surface-alt` with an amber fill. Reading progress is always amber; *download*
  progress uses the status colour.
- **Needs Attention panel**: `$surface`, 1px `$border`, plus a **2px left border in
  `$warning`** and radius `0 4px 4px 0`. Header row 14/20px padding, fill
  `rgba(240,180,41,0.06)`, Lucide `triangle-alert` at 16px, title 15/600, count chip right
  (mono 11/600, warning triad). Each row: title 14/600 in `$link`; reason 13px
  `$text-muted` — exact copy "274 consecutive scrape runs failed — the source site may have
  changed"; third line mono 11px `$muted` with hostname and last-success date. Actions right:
  "Test source ↗" outlined in warning (border `rgba(240,180,41,0.45)`, hover fill 12%) and
  "Ignore" ghost with `$border` outline (hover `$surface-alt`). Rows split by 1px `$border`.
- **Buttons** (13px/500, 9px 16px padding, radius 4):
  - primary — `$accent` fill, `#fff` label, hover `#7480FF`, active `$accent-press`
  - secondary — transparent, 1px `#384253`, `$text` label, hover `$surface-alt`
  - danger — transparent, 1px `rgba(240,83,63,0.45)`, `$danger` label, hover 12% danger fill
  - ghost — `$text-muted` label, hover `$text` on `$surface-alt`
  - disabled — `$surface-alt` fill, `#5B6472` label, 1px `$border`, no hover
  All labels flush left when the button is wider than its label.
- **Badges**: 11px/600, tracking 0.04em, 3px 8px padding, radius 0, uppercase. Status triad as
  above. Five states: DOWNLOADED, QUEUED, NEEDS ATTENTION, FAILED, PAUSED (also used as
  ACTIVE/COMPLETED — map ACTIVE→success, COMPLETED→success, PAUSED→muted).
- **Table**: header row `$surface-alt`, 11px/600, tracking 0.08em, `$text-muted`, 11px/20px
  padding. Body rows `$surface`, 14px/20px padding, 1px `$border` top, hover `$surface-alt`.
  Columns in the mock: NAME (1fr, link 14/500) · AUTHOR (180px) · STATUS (120px, badge) ·
  PROGRESS (150px: 4px track + mono 11px percent) · CHAPTERS (110px, mono 12px, right-aligned).
  Progress fill takes the row's status colour.

### 5. Reader screen
- Chrome bar 52px, no fill, 1px `#1C222A` bottom. Left: "← <novel title>" 13px
  `$text-muted`, hover `$text`. Right: three 12px outlined controls (Aa / Contents / Focus).
- Directly under the bar: a **2px chapter-progress rail**, track `#1C222A`, fill
  `$brand-amber`, width = scroll position.
- Column 680px, centred, 64px top padding. Kicker mono 11px tracking 0.12em
  "CHAPTER 12 OF 323 · 9 MIN LEFT". Chapter title Literata 34/600. A 64×1px `$border` rule
  under the title.
- Body: Literata 19/1.75, colour `#D8DDE6` (a step softer than `$text` — reduces glare over
  long sessions), 26px paragraph gap, no indents.
- Footer nav above a 1px `#1C222A` rule: prev and next as stacked outlined blocks (mono 10px
  direction label + 13px chapter title), with a primary "Mark read & continue" between them.
- Sample prose in the mock is placeholder written for the mock — replace with real chapter text.

### 6. App icons & email
- **PWA icons**: see §1 — `any` icons are the Serial mark on `#0F1216` at ≈62%; the maskable
  icon is full-bleed indigo with knocked-out bars in the centre 50%.
- **Favicon**: ship 16/32/48 using the three-bar favicon cut below 20px (see §1).
- **Daily summary email header**: 600px table layout, `$bg` ground, 20px/24px padding, a **2px
  `$accent` bottom rule**, 26px mark + 14px wordmark left, mono 11px date/label right. Body:
  18/600 headline, then status chips. No web fonts — Georgia for serif, system sans otherwise;
  all tokens inline.

### 7. Default ePub cover
1600 × 2400 output (mocks shown at 300×450, scale ×5.33).
- Ground `#12151B` dark (default) or `#F7F8FA` light (e-ink variant).
- A 3px vertical gradient rule (`#6470FF → #9B6BFF`) bleeding off the top edge, inset from the
  left by the page margin (36/300 → 192/1600).
- Title in Literata 600, flush left, ragged right, never centred, starting 120/450 from the top.
  Size steps by length: ≤18 chars → 96px · ≤44 → 72px · ≤90 → 54px · beyond that truncate at a
  word boundary with an ellipsis. (Mock equivalents at 300px wide: 34 / 24 / 19px.)
- Author 12px `$text-muted` under the title, 14px gap.
- Footer above a 1px `$border` rule: 18px mark, "NOVARR." at 10px/600 tracking 0.16em in
  `$text-muted`, chapter count mono 10px right in `$muted`.

## Interactions & Behavior
- Hover is a fill change, never a size or shadow change. 120ms ease-out on
  `background-color` / `color` / `border-color` only.
- Pressed state on the primary is `$accent-press`; outlined variants deepen their tint.
- Focus is themed, never the browser default:
  `:focus-visible { outline: 2px solid $accent; outline-offset: 2px; }`
- "Test source ↗" opens the source URL in a new tab and, on return, should re-run the health
  check; while running, the button shows a spinner and the row badge flips to QUEUED.
- "Ignore" removes the row from the Needs Attention panel and sets the novel's
  `attention_ignored_until` — it must not pause downloads.
- Reader: chapter-progress rail updates on scroll (rAF-throttled); reading position persists per
  chapter; prev/next preload the adjacent chapter body.
- Progress bars animate width only, 240ms ease-out; never animate colour.
- Responsive: the component sheet assumes ≥1280px. Below 900px the table collapses to the novel
  card list; the reader column becomes `min(680px, 100vw - 48px)`.

## State Management
Server-rendered app, so mostly view state:
- `activeNav` — current section for the navbar underline.
- Per novel: `status` (active | completed | paused), `downloadState` (downloaded | queued |
  failed), `chaptersRead` / `chaptersTotal`, `consecutiveFailures`, `lastSuccessAt`,
  `attentionIgnoredUntil`.
- Needs Attention list = novels where `consecutiveFailures > threshold` and
  `attentionIgnoredUntil` is null or past.
- Reader: `chapterIndex`, `scrollRatio`, `fontSize` (15–24px), `measure` (56–80ch),
  `theme` (dark | light | sepia) — the last three persisted per user in localStorage.

## Design Tokens
Authoritative source: `_variables.scss` (dark canonical, light companion, status triad
helpers, type scale, spacing, radii). Spacing scale used throughout: 4 / 8 / 10 / 12 / 16 / 20 /
24 / 32 / 48 / 64px. Radii: 0 (badges, bars) · 4px (controls, cards) · 14px @180 → 40px @512
(app tile). Shadows: none.

## Assets
- `novarr-mark.svg` — Serial mark (four flat bars), 32×32 viewBox, no tile.
- `novarr-mark-favicon.svg` — heavier three-bar cut for sizes below 20px.
- `novarr-mark-mono.svg` — single-colour mark using `currentColor`, last bar at 55%.
- `novarr-lockup-dark.svg` / `novarr-lockup-light.svg` — horizontal lockups with the wordmark
  outlined (font-independent).
- `novarr-app-icon-512.svg` / `novarr-app-icon-maskable-512.svg` — PWA icons (any / maskable).
- Icons: **Lucide** (https://lucide.dev), 1.5–2px stroke. Used in the mocks: `search`,
  `triangle-alert`, `book-open`, `chevron-left`, `chevron-right`.
- Fonts: Geist, Geist Mono, Literata — all on Google Fonts, all OFL. Self-host for a
  self-hosted app.
- No photography or illustration in the system. Cover art comes from the sources; the ePub
  cover template above is the fallback.

## Files
- `Novarr Brand System.dc.html` — the full seven-artboard canvas. Open in a browser; this is
  the visual source of truth.
- `_variables.scss` — the tokens, ready to drop in.
- `*.svg` — the mark, lockups and app icon.
