# Plan: categories (Mihon-style) + a category-first Home

Status: **planned, not started** (2026-11-27). Source: a reader's two screen-recorded videos
(transcribed locally with the homelab's Whisper, subgen). He compares Novarr with his manga app
(Mihon/Tachiyomi-style **categories**). His words, condensed:

- "If you hold on a manga or a book you can choose what **category** you want it in… and you can
  **mark them all as read or unread**."
- "When I'm scrolling through **Browse**… right now I click it, it opens and then I can add it. I'd
  like if you **hold it down → add to the category** you want… then it **greys out**."
- Home: "**remove the Continue** at the top" (he goes to Listen for listening), "**get rid of Want to
  read**" and "**Reading & listening**", "the **folders are actually the categories**" — "the front
  page really just needs to be a **summary of the categories**". Keep the "new chapters / new books"
  part at the bottom. "Other than that it is so sick."

## Model: categories = reading lists

Novarr already has per-reader reading lists ("folders" in Android 1.16) plus two built-ins
(Favourites, Want to read). **Call them Categories everywhere** (web + app), keep the data:

- Built-ins stay as categories the reader can **hide** (Favourites / Want to read keep working for
  people who use them; Ryan hides them).
- Add `position` (reader-defined order) and `hidden_on_home` to `reading_lists`; API to reorder
  (`PATCH /api/v1/lists/order {ids:[…]}`) and to toggle `hidden_on_home`.
- A series can be in several categories (as now). "Uncategorised" = in none (virtual, for the
  Library tab bar).

## 1. Long-press multi-select in the Library (Android first, web parity)

- Long-press any cover/row in Library (web novels, manga, books, audiobooks, and inside a
  category) → **selection mode**: top action bar with a count; tap more items to add/remove; "Select
  all"; back to exit.
- Actions: **Set categories…** (checkbox sheet of all categories incl. hidden built-ins + "New
  category"; tri-state when the selection differs; applies add/remove in one call), **Mark as read**,
  **Mark as unread** (whole series: existing `POST /api/v1/chapters/bulk-read`/ReadState::markMany per
  novel — add `POST /api/v1/novels/bulk {ids, action: mark_read|mark_unread}` if needed), **Download**
  (offline), **Remove download**.
- New API: `PUT /api/v1/lists/memberships {novel_ids:[…], add:[list ids], remove:[list ids]}` (one
  round trip for a multi-select; reader's own lists only).
- **Library tab bar** (Mihon style): All · each category in order · Uncategorised; a category tab
  shows its covers with unread badges.
- Web: the existing library "Select" mode gains **Set categories** and **Mark read/unread**; a
  right-click / long-press on a card enters selection on touch devices.

## 2. Long-press in Browse / Discover → add to a category

Browse items are usually **not in the library yet** (readers request; the owner approves).
- Long-press a Discover/search card → sheet "Add to category" (checkbox list). On confirm:
  - **already in the library** → membership added at once;
  - **not in the library** → a **request is made** (or joined — "Me too") and the chosen categories
    are **remembered on the request** (new `novel_request_categories (request_id, user_id,
    reading_list_id)`); when the request becomes available (`Requests::markAvailable*`), the series
    is added to those categories for each reader who chose them.
- The card then **greys out** with a small "Requested · <category>" / "In <category>" badge, so
  scrolling Browse shows what's been taken (as in his video).
- Same on the web Requests page (right-click / long-press a card).

## 3. Home = a summary of categories

New default Home (Android; web Home gets the same order):
1. Search (keep).
2. **Categories**: one section per visible category, in the reader's order — the category name +
   count + "See all", a horizontal row of covers (unread/progress badge on each). Empty categories
   are skipped. A "Manage categories" link (create, rename, reorder, hide built-ins).
3. **New chapters / new books** (keep — he likes it).
- **Removed by default**: the Continue strip, Favourites, Want to read, Reading & listening, the
  separate Folders grid (folders *are* the categories now). Each can be turned back on in Settings ›
  Home (so the friend who liked 1.16's layout can keep it).
- First run after the update: if a reader has no categories yet, Home shows a one-tap "Create your
  first category" and keeps 1.16's layout until they do.

## 4. Small things from the videos

- Category picker shows the **current membership ticked** (so re-opening it shows where it is).
- Selection mode works the same in the **Listen** tab (audiobooks into categories).
- Haptic feedback on long-press; undo snackbar for "Mark as read".

## Tests

Server: memberships bulk endpoint (own lists only, idempotent, built-ins included), order /
hidden_on_home, request-with-categories → added on availability (incl. Me-too joiners), bulk mark
read/unread, RouteAccess. Android: selection state machine, tri-state category sheet logic, Home
section builder (order, hidden, empty skip, first-run fallback), greyed-out Browse state. Web: JS
logic for selection + category sheet.

## Order of work

1. Server: list position/hidden_on_home, memberships bulk API, bulk mark read/unread, request
   categories + apply on availability.
2. Android 1.18: Library long-press selection + category sheet + tab bar; Browse long-press; new Home;
   Settings › Home toggles; rename "folders/lists" → "categories".
3. Web parity: library select actions, Requests card long-press/right-click, Home order.
4. Ship together; ask Ryan to test long-press in Library and Browse, and the new Home.
