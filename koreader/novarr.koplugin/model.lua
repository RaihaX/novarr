--[[--
Pure helpers over API data: library filtering and search, chapter list
positions, download selections, clean-up candidates, formatting.
No KOReader dependencies (unit tested).
]]

local Model = {}

Model.FILTERS = { "all", "reading", "unread", "new", "completed", "downloaded" }

-- ---------------------------------------------------------------- library

local function lower(s)
    return (s or ""):lower()
end

--- Case-insensitive match of a query against a novel's name and author.
function Model.matchesQuery(novel, query)
    if not query or query == "" then return true end
    local q = lower(query)
    if lower(novel.name):find(q, 1, true) then return true end
    if novel.author and lower(novel.author):find(q, 1, true) then return true end
    return false
end

--[[--
Whether a novel passes a filter:
    all         every novel
    reading     started (some chapter read) and not finished
    unread      nothing read yet
    new         has new chapters
    completed   the novel itself is complete (author finished it)
    downloaded  has chapters stored on this device (downloaded_set[id])
]]
function Model.matchesFilter(novel, filter, downloaded_set)
    filter = filter or "all"
    local read = tonumber(novel.chapters_read) or 0
    if filter == "all" then
        return true
    elseif filter == "reading" then
        return read > 0 and not novel.finished
    elseif filter == "unread" then
        return read == 0
    elseif filter == "new" then
        return (tonumber(novel.chapters_new) or 0) > 0
    elseif filter == "completed" then
        return novel.completed == true
    elseif filter == "downloaded" then
        return downloaded_set ~= nil and downloaded_set[novel.id] == true
    end
    return true
end

--- Filters and searches the library, keeping the API's order.
function Model.filterNovels(novels, filter, query, downloaded_set)
    local out = {}
    for _i, novel in ipairs(novels or {}) do
        if type(novel) == "table" and novel.id
                and Model.matchesFilter(novel, filter, downloaded_set)
                and Model.matchesQuery(novel, query) then
            table.insert(out, novel)
        end
    end
    return out
end

-- ---------------------------------------------------------------- books

--[[--
Books (1.12.0): a server with books lists them with the web novels
(GET novels?medium=text), each summary carrying medium = "novel" | "book".
A book reads like a novel but is complete: it never gets new chapters.
Summaries without a medium (older servers) are novels.
]]
function Model.isBook(novel)
    return type(novel) == "table" and novel.medium == "book"
end

--- Whether the library holds at least one book.
function Model.hasBooks(novels)
    for _i, n in ipairs(novels or {}) do
        if Model.isBook(n) then return true end
    end
    return false
end

-- ---------------------------------------------------------------- media

--[[--
The media a series can be (1.14.0): "novel" (web novel, the default),
"book", "manga" and "comic". Manga and comics are page media: their
chapters (issues, volumes) are page images, read here as a CBZ, never as
text, with no EPUB and no highlights.
]]
Model.MEDIA = { "novel", "book", "manga", "comic" }

local KNOWN_MEDIA = { novel = true, book = true, manga = true, comic = true }
local PAGED_MEDIA = { manga = true, comic = true }

--- A series' medium (a summary or a medium string): one of Model.MEDIA; anything else (an older server) is "novel".
function Model.medium(novel_or_medium)
    local m = novel_or_medium
    if type(m) == "table" then m = m.medium end
    if type(m) == "string" and KNOWN_MEDIA[m] then return m end
    return "novel"
end

--- Whether a medium (or a summary) is read as pages (manga, comic): a CBZ, never text.
function Model.isPaged(novel_or_medium)
    local m = novel_or_medium
    if type(m) == "table" then m = m.medium end
    return type(m) == "string" and PAGED_MEDIA[m] == true
end

local LABELS = {
    novel = { "Novel", "Novels" },
    book = { "Book", "Books" },
    manga = { "Manga", "Manga" },
    comic = { "Comic", "Comics" },
}

--- A medium's English label, singular ("Comic") or plural ("Comics"); the UI passes it through gettext.
function Model.mediumLabel(medium, plural)
    local l = LABELS[Model.medium(medium)]
    return plural and l[2] or l[1]
end

--[[--
The page direction to give a paging chapter (1.14.0): "rtl" or "ltr" to
set, or nil to leave KOReader's own setting alone. A comic reads left to
right unless its series says "rtl" (reading_mode of GET
chapters/{id}/pages); a manga keeps KOReader's setting, as in 1.13.0.
]]
function Model.readingDirection(medium, reading_mode)
    if Model.medium(medium) ~= "comic" then return nil end
    return reading_mode == "rtl" and "rtl" or "ltr"
end

--[[--
The library's books ("book"), manga ("manga", 1.13.0: listed when the
server sends them as CBZ), comics ("comic", 1.14.0, likewise) or web
novels ("novel", the default), in the API's order.
]]
function Model.byMedium(novels, medium)
    local want = Model.medium(medium)
    local out = {}
    for _i, n in ipairs(novels or {}) do
        if type(n) == "table" and Model.medium(n) == want then table.insert(out, n) end
    end
    return out
end

--- Whether the library holds at least one manga (1.13.0).
function Model.hasManga(novels)
    for _i, n in ipairs(novels or {}) do
        if type(n) == "table" and n.medium == "manga" then return true end
    end
    return false
end

--- Whether the library holds at least one comic (1.14.0).
function Model.hasComics(novels)
    for _i, n in ipairs(novels or {}) do
        if type(n) == "table" and n.medium == "comic" then return true end
    end
    return false
end

--[[--
What Home's shelf is built from: every novel, and the books being read
(some chapter read, or read on this device: last_local[id]) - so thousands
of unopened books never bury the web novels; they are in Library > Books.
]]
function Model.homeNovels(novels, last_local)
    last_local = last_local or {}
    local out = {}
    for _i, n in ipairs(novels or {}) do
        if type(n) == "table" and n.id then
            -- Manga (1.13.0) and comics (1.14.0) like books: on the shelf once started, else in their Library section.
            local apart = Model.isBook(n) or Model.isPaged(n)
            if not apart or (tonumber(n.chapters_read) or 0) > 0 or last_local[n.id] ~= nil then
                table.insert(out, n)
            end
        end
    end
    return out
end

-- ---------------------------------------------------------------- chapters

--- 1-based position of a chapter id in a chapter list, or nil.
function Model.indexOf(chapters, chapter_id)
    if not chapter_id then return nil end
    for i, c in ipairs(chapters or {}) do
        if c.id == chapter_id then return i end
    end
    return nil
end

--- Page (1-based) that shows item `index` when `perpage` items fit a page.
function Model.pageFor(index, perpage)
    if not index or index < 1 or not perpage or perpage < 1 then return 1 end
    return math.floor((index - 1) / perpage) + 1
end

--[[--
Where a chapter list should open: the resume chapter if known, else the
first unread downloaded chapter, else the top. Returns an index (1-based).
]]
function Model.resumeIndex(chapters, resume_id, is_read)
    local idx = Model.indexOf(chapters, resume_id)
    if idx then return idx end
    for i, c in ipairs(chapters or {}) do
        local read = c.read
        if is_read then read = is_read(c) end
        if c.downloaded and not read then return i end
    end
    return 1
end

--- Finds the list position for "Go to chapter N": exact chapter number first, else position N.
function Model.findChapterNumber(chapters, number)
    number = tonumber(number)
    if not number then return nil end
    for i, c in ipairs(chapters or {}) do
        if tonumber(c.chapter) == number then return i end
    end
    if number >= 1 and number <= #(chapters or {}) and number == math.floor(number) then
        return number
    end
    return nil
end

--[[--
Chapter ids to download for a "Download for offline" choice.
mode: "unread" (next `limit` unread, limit nil = all unread) or "all".
Starts at the resume chapter when given (unread mode) — included even when
it is marked read but still being read (Model.unfinished: the web reader
marks read on open) — skips chapters the server has not downloaded and ones
already stored (stored_set[id]). is_read(c) gives the effective read state
and progress.
]]
function Model.downloadSelection(chapters, mode, limit, resume_id, stored_set, is_read)
    local out = {}
    stored_set = stored_set or {}
    local start = 1
    if mode == "unread" and resume_id then
        start = Model.indexOf(chapters, resume_id) or 1
    end
    for i = start, #(chapters or {}) do
        local c = chapters[i]
        local read, progress = c.read, c.read_progress
        if is_read then read, progress = is_read(c) end
        if read and i == start and resume_id and c.id == resume_id and Model.unfinished(read, progress) then
            read = false
        end
        if c.downloaded and not stored_set[c.id] and (mode == "all" or not read) then
            table.insert(out, c.id)
            if limit and #out >= limit then break end
        end
    end
    return out
end

--[[--
Chapters that follow `chapter_id` in reading order (up to `count`), that
the server has downloaded. Used for prefetching.
]]
function Model.nextChapters(chapters, chapter_id, count)
    local out = {}
    local idx = Model.indexOf(chapters, chapter_id)
    if not idx then return out end
    for i = idx + 1, #chapters do
        if #out >= count then break end
        if chapters[i].downloaded then table.insert(out, chapters[i].id) end
    end
    return out
end

--[[--
Stored chapters that may be deleted after reading. `stored` is a list of
{id, order} (order = position in reading order, higher = later), `state(id)`
returns read (bool), progress (int|nil). Keeps the `keep` most recent read
chapters (by order), never touches unread or part-read chapters, nor
`current_id`, nor anything after `current_order` (the open chapter's
position) in reading order. Returns a list of ids.
]]
function Model.removalCandidates(stored, state, keep, current_id, current_order)
    keep = keep or 2
    local read_ones = {}
    for _i, s in ipairs(stored or {}) do
        -- Chapters after the open one are never removed: they come next.
        if s.id ~= current_id and (not current_order or s.order < current_order) then
            -- Only chapters marked read qualify; unread and part-read ones
            -- (read == false, whatever their progress) are always kept.
            local read = state(s.id)
            if read then table.insert(read_ones, s) end
        end
    end
    table.sort(read_ones, function(a, b)
        if a.order == b.order then return a.id > b.id end
        return a.order > b.order
    end)
    local out = {}
    for i = keep + 1, #read_ones do
        table.insert(out, read_ones[i].id)
    end
    table.sort(out)
    return out
end

-- ---------------------------------------------------------------- formatting

--- Parses "2026-11-15T03:20:11Z" (UTC) into epoch seconds, or nil.
function Model.parseIso(s)
    if type(s) ~= "string" then return nil end
    local y, mo, d, h, mi, sec = s:match("^(%d%d%d%d)-(%d%d)-(%d%d)T(%d%d):(%d%d):(%d%d)")
    if not y then return nil end
    y, mo, d = tonumber(y), tonumber(mo), tonumber(d)
    h, mi, sec = tonumber(h), tonumber(mi), tonumber(sec)
    -- Days from civil (Howard Hinnant's algorithm), avoids os.time's local time zone.
    local yy = mo <= 2 and y - 1 or y
    local era = math.floor(yy / 400)
    local yoe = yy - era * 400
    local mp = (mo + 9) % 12
    local doy = math.floor((153 * mp + 2) / 5) + d - 1
    local doe = yoe * 365 + math.floor(yoe / 4) - math.floor(yoe / 100) + doy
    local days = era * 146097 + doe - 719468
    return days * 86400 + h * 3600 + mi * 60 + sec
end

function Model.formatBytes(bytes)
    bytes = tonumber(bytes) or 0
    if bytes < 1024 then return string.format("%d B", bytes) end
    if bytes < 1024 * 1024 then return string.format("%.0f KB", bytes / 1024) end
    if bytes < 1024 * 1024 * 1024 then return string.format("%.1f MB", bytes / (1024 * 1024)) end
    return string.format("%.2f GB", bytes / (1024 * 1024 * 1024))
end

--- "read/total" text for a novel summary, with a local adjustment.
function Model.readCountText(novel, delta)
    local read = (tonumber(novel.chapters_read) or 0) + (delta or 0)
    local total = tonumber(novel.chapters_total) or 0
    if read < 0 then read = 0 end
    if read > total then read = total end
    return string.format("%d/%d", read, total)
end

--[[--
The chapter after (dir = 1) or before (dir = -1) list position `pos` that
can be read: the server has its text (row.downloaded) or stored(id) says it
is on this device. Returns that row (nil when there is none) and, going
forward, the first row passed on the way: a chapter that is neither on the
server nor stored (the reader must not jump past it without a word). Going
backward nothing is reported as passed (paging back skips gaps as before).
]]
function Model.neighbourIn(list, pos, dir, stored)
    local passed
    local i = (pos or 0) + dir
    while list and list[i] do
        local c = list[i]
        if c.downloaded or (stored and stored(c.id)) then return c, passed end
        if dir > 0 and not passed then passed = c end
        i = i + dir
    end
    return nil, passed
end

--- Label of a chapter ref (never empty).
function Model.chapterLabel(ref)
    if not ref then return "" end
    if ref.label and ref.label ~= "" then return ref.label end
    if ref.chapter then return "Chapter " .. tostring(ref.chapter) end
    return "Chapter"
end

-- ---------------------------------------------------------------- resume rule

--[[--
The chapter "Continue" opens: the rule every device shares (the server's
App\Services\ResumePoint, which answers resume_chapter_id) applied to the
server's answer plus this device's own reading not sent yet (overlay
entries, which only exist until a server snapshot that includes them
arrives, so they are newer than anything the snapshot knows).

    list            chapter list (reading order), rows {id, downloaded, read, read_progress}
    server_id       the server's resume_chapter_id (may be nil)
    overlay         { ["<chapterId>"] = {read=bool|nil, progress=n|nil, at=ms,
                      reading_at=ms|nil, hand=true|nil} }
    state(row)      effective read (bool) and progress (0..100|nil): server
                    row + overlay (Store.effective)

Rule:
  * The anchor is this device's most recent REAL reading: the overlay entry
    with the latest reading time (ties: the furthest in reading order) on a
    downloaded chapter that is now read, or unread with a position of 1 % or
    more. A chapter marked unread, or opened at 0 %, is not reading. Only
    reading counts (position writes and reaching a chapter's end: the entry's
    `reading_at`): a hand mark (mark read / unread, "mark all up to here",
    "mark all as read": `hand`, no `reading_at`) changes the read state but
    never makes a chapter the anchor. Reading after a hand mark makes the
    chapter a candidate again; a hand mark after reading keeps the reading's
    time. (Entries written before 1.10.2 carry neither field and count with
    their `at`, as before.) The plugin has no look-up reads.
  * No anchor: the server's chapter (it applied the same rule) - unless the
    local marks finished it (it was unread, or read but unfinished, and is
    now read and finished, e.g. "mark all up to here" covered it): then it
    stands in for the anchor in the last rule, which is what the server
    will answer. Without a server chapter, the first downloaded unread
    chapter.
  * An unfinished anchor is resumed: unread at 1-99 %, or read below 90 % (a
    read chapter being re-read; the web reader marks read on open).
  * Otherwise the first downloaded unread chapter after it; none (caught
    up): the last downloaded chapter, as the server answers. Earlier
    unread chapters and older part-read ones never pull back.
Returns a chapter id or nil.
]]
Model.READ_UNFINISHED_BELOW = 90

--- Is a chapter in this state still being read (rule above)?
function Model.unfinished(read, progress)
    progress = tonumber(progress)
    if not progress then return false end
    if read then return progress < Model.READ_UNFINISHED_BELOW end
    return progress >= 1 and progress <= 99
end

--- Time (ms) of an overlay entry's real reading, or nil for a hand mark only (rule above).
function Model.readingTime(e)
    if type(e) ~= "table" then return nil end
    if e.reading_at ~= nil then return tonumber(e.reading_at) or 0 end
    if e.hand then return nil end
    return tonumber(e.at) or 0 -- written before 1.10.2
end

function Model.resolveResume(list, server_id, overlay, state)
    list = list or {}
    local pos = {}
    for i, c in ipairs(list) do pos[c.id] = i end
    local anchor_pos, anchor_at = nil, nil
    for key, e in pairs(overlay or {}) do
        local p = pos[tonumber(key)]
        local at = Model.readingTime(e)
        if p and at and list[p].downloaded then
            local read, progress = state(list[p])
            if read or (tonumber(progress) or 0) >= 1 then
                if not anchor_at or at > anchor_at or (at == anchor_at and p > anchor_pos) then
                    anchor_pos, anchor_at = p, at
                end
            end
        end
    end
    if not anchor_pos then
        if not server_id then
            for _i, c in ipairs(list) do
                if c.downloaded and not state(c) then return c.id end
            end
            return nil
        end
        -- The server's chapter, unless the local marks finished it (rule above).
        local p = pos[server_id]
        if not p then return server_id end
        local row = list[p]
        local was_finished = row.read and not Model.unfinished(true, row.read_progress)
        local read, progress = state(row)
        local is_finished = read and not Model.unfinished(true, progress)
        if was_finished or not is_finished then return server_id end
        anchor_pos = p
    end
    local anchor = list[anchor_pos]
    if Model.unfinished(state(anchor)) then return anchor.id end
    local last
    for i = anchor_pos + 1, #list do
        local c = list[i]
        if c.downloaded then
            if not state(c) then return c.id end
            last = c
        end
    end
    -- Caught up: the last downloaded chapter (the anchor when nothing follows it).
    return (last or anchor).id
end

--[[--
"Chapter N of M": N = position of the chapter among the chapters the server
has downloaded (reading order), M = how many there are. Label numbers can be
irregular, so they are not used. Returns n, m (n nil when not downloaded).
]]
function Model.ordinal(list, chapter_id)
    local n, m = nil, 0
    for _i, c in ipairs(list or {}) do
        if c.downloaded then
            m = m + 1
            if c.id == chapter_id then n = m end
        end
    end
    return n, m
end

-- ---------------------------------------------------------------- home shelf

--[[--
Order of the Home shelf. novels: the library in the server's order.
last_local[id] = ms of this device's latest reading in that novel (may be
nil). Returns hero (novel or nil), hero_started (bool), rest (list):
  1. novels in progress (started, not finished), most recently read first
     (this device's reading counts when newer than the server's last_read_at)
  2. novels with new chapters
  3. the rest, in the server's order
The hero is the first in-progress novel; with none, the first novel of the
library ("Start reading").
]]
function Model.shelfOrder(novels, last_local, parse_time)
    last_local = last_local or {}
    parse_time = parse_time or Model.parseIso
    local progress, fresh, others = {}, {}, {}
    for i, n in ipairs(novels or {}) do
        if type(n) == "table" and n.id then
            local read = tonumber(n.chapters_read) or 0
            local started = (read > 0 or last_local[n.id] ~= nil) and not n.finished
            if started then
                local t = (parse_time(n.last_read_at) or 0) * 1000
                if last_local[n.id] and last_local[n.id] > t then t = last_local[n.id] end
                table.insert(progress, { novel = n, t = t, i = i })
            elseif (tonumber(n.chapters_new) or 0) > 0 then
                table.insert(fresh, n)
            else
                table.insert(others, n)
            end
        end
    end
    table.sort(progress, function(a, b)
        if a.t == b.t then return a.i < b.i end
        return a.t > b.t
    end)
    local rest = {}
    local hero, started
    for _i, p in ipairs(progress) do
        if not hero then hero, started = p.novel, true else table.insert(rest, p.novel) end
    end
    for _i, n in ipairs(fresh) do table.insert(rest, n) end
    for _i, n in ipairs(others) do table.insert(rest, n) end
    if not hero and #rest > 0 then
        hero, started = table.remove(rest, 1), false
    end
    return hero, started or false, rest
end

--[[--
Splits `count` grid items into pages: the first page holds `first_cap`
(the hero takes room there), later pages `cap`. Returns a list of
{first, last} index ranges (always at least one page).
]]
function Model.shelfPages(count, first_cap, cap)
    first_cap = math.max(0, first_cap or 0)
    cap = math.max(1, cap or 1)
    local pages = { { first = 1, last = math.min(count, first_cap) } }
    local i = first_cap + 1
    while i <= count do
        table.insert(pages, { first = i, last = math.min(count, i + cap - 1) })
        i = i + cap
    end
    return pages
end

-- ---------------------------------------------------------------- one tap

--[[--
What tapping a novel on Home/Library does: "open" (its resume chapter) or
"novel" (the Novel screen, when there is nothing to resume: nothing
downloaded on the server). Returns action, chapter_id.
]]
function Model.tapTarget(novel, resume_id)
    if not novel then return "novel" end
    local downloaded = tonumber(novel.chapters_downloaded)
    if not resume_id or (downloaded ~= nil and downloaded <= 0) then return "novel" end
    return "open", resume_id
end

-- ---------------------------------------------------------------- prefetch pacing

--[[--
Prefetch pacing: how many seconds to wait before the next fetch, 0 to go
now. Never within `quiet_ms` of a page turn (the fetch blocks the UI for a
moment), and the first one not before `start_ms` after the chapter opened.
]]
function Model.prefetchWait(now_ms, ready_at_ms, last_turn_ms, start_ms, quiet_ms)
    start_ms = start_ms or 5000
    quiet_ms = quiet_ms or 1500
    local wait = 0
    if ready_at_ms and now_ms - ready_at_ms < start_ms then
        wait = (start_ms - (now_ms - ready_at_ms)) / 1000
    end
    if last_turn_ms and now_ms - last_turn_ms < quiet_ms then
        local w = (quiet_ms - (now_ms - last_turn_ms)) / 1000
        if w > wait then wait = w end
    end
    return wait
end

-- ---------------------------------------------------------------- latest position

-- Two places in the same chapter closer than this (percentage points) are "the same place".
Model.POSITION_SAME_PLACE = 5

--[[--
Whether to offer "Go to latest" (the client rule shared with the web and iOS
apps). o:
    position        the server's latest position (table) or nil/false
    device_id       this device's id
    chapter_id      the chapter open here
    local_progress  where the reader is in it (0..100, nil = start)
    local_newer     this device holds reading of the novel newer than the position
    declined_at     the position `at` the user said "Stay here" to (nil: none)
    can_open        the position's chapter can be opened here
Returns true, or false plus a short reason (for the log).
]]
function Model.shouldOfferPosition(o)
    local p = o.position
    if type(p) ~= "table" or not tonumber(p.chapter_id) then return false, "no position" end
    if type(p.device_id) ~= "string" or p.device_id == "" then return false, "no device" end
    if p.device_id == o.device_id then return false, "read here" end
    if o.local_newer then return false, "newer reading here" end
    if tonumber(p.chapter_id) == tonumber(o.chapter_id) then
        local pp = tonumber(p.progress)
        if not pp then return false, "same chapter" end
        if math.abs(pp - (tonumber(o.local_progress) or 0)) < Model.POSITION_SAME_PLACE then
            return false, "same place"
        end
    end
    if o.declined_at ~= nil and o.declined_at == p.at then return false, "declined" end
    if not o.can_open then return false, "cannot open" end
    return true
end

local function fill(text, ...)
    local args = { ... }
    return (text:gsub("%%(%d)", function(i) return tostring(args[tonumber(i)]) end))
end

--[[--
"just now", "5 minutes ago", "2 hours ago", "yesterday", "3 days ago" for an
age in seconds. tr translates (gettext's _; default: none).
]]
function Model.relativeTime(seconds, tr)
    tr = tr or function(s) return s end
    local s = math.max(0, math.floor(tonumber(seconds) or 0))
    if s < 60 then return tr("just now") end
    local m = math.floor(s / 60)
    if m < 60 then
        if m == 1 then return tr("1 minute ago") end
        return fill(tr("%1 minutes ago"), m)
    end
    local h = math.floor(m / 60)
    if h < 24 then
        if h == 1 then return tr("1 hour ago") end
        return fill(tr("%1 hours ago"), h)
    end
    local d = math.floor(h / 24)
    if d == 1 then return tr("yesterday") end
    return fill(tr("%1 days ago"), d)
end

--[[--
The question: "Chapter 124: The Gate · 40% was read on Kindle 2 hours ago.
Go there?" (the percentage only when it is 1..99).
]]
function Model.positionPromptText(p, tr)
    tr = tr or function(s) return s end
    local label = type(p.chapter_label) == "string" and p.chapter_label ~= "" and p.chapter_label or nil
    if not label then
        local n = p.chapter_number
        label = (n ~= nil and tostring(n) ~= "") and fill(tr("Chapter %1"), n) or tr("Chapter")
    end
    local progress = tonumber(p.progress)
    if progress and progress >= 1 and progress <= 99 then
        label = label .. " \194\183 " .. math.floor(progress) .. "%"
    end
    local device = type(p.device_name) == "string" and p.device_name ~= "" and p.device_name or tr("another device")
    return fill(tr("%1 was read on %2 %3. Go there?"), label, device, Model.relativeTime(p.age_seconds, tr))
end

-- ---------------------------------------------------------------- history

--[[--
History entries to remove: every Novarr chapter except `keep` (the chapter
being read). is_chapter(path) says whether a path is a Novarr chapter.
]]
function Model.historyToPrune(paths, keep, is_chapter)
    local out = {}
    for _i, p in ipairs(paths or {}) do
        if p ~= keep and is_chapter(p) then table.insert(out, p) end
    end
    return out
end

-- ---------------------------------------------------------------- leaving a book

local RETURN_SCREENS = {
    library = { "home", "library" },
    novel = { "home", "novel" },
    chapters = { "home", "chapters" },
}

--[[--
The Novarr screens to show, bottom to top, when the reader closes after a
chapter opened from Novarr: where the user came from (origin_kind: the
kind of the screen on top when the book was opened) with Home underneath.
Home, or an origin unknown or not recorded: Home alone.
]]
function Model.returnScreens(origin_kind)
    local screens = RETURN_SCREENS[origin_kind]
    if not screens then return { "home" } end
    return { screens[1], screens[2] }
end

return Model
