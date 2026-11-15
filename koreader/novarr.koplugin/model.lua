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
Starts at the resume chapter when given (unread mode), skips chapters the
server has not downloaded and ones already stored (stored_set[id]).
is_read(c) gives the effective read state.
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
        local read = c.read
        if is_read then read = is_read(c) end
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

--- Label of a chapter ref (never empty).
function Model.chapterLabel(ref)
    if not ref then return "" end
    if ref.label and ref.label ~= "" then return ref.label end
    if ref.chapter then return "Chapter " .. tostring(ref.chapter) end
    return "Chapter"
end

-- ---------------------------------------------------------------- resume rule

--[[--
The chapter "Continue" opens, from the server's snapshot and this device's
own reading (overlay entries, which only exist until a server snapshot that
includes them arrives, so they are always newer than the cached snapshot).

    list            chapter list (reading order), rows {id, downloaded, read}
    server_id       the server's resume_chapter_id (may be nil)
    overlay         { ["<chapterId>"] = {read=bool|nil, progress=n|nil, at=ms} }
    is_read(row)    effective read state (server row + overlay)

Rule:
  * R = the furthest chapter this device marked read; Rn = the first
    downloaded unread chapter after it.
  * base = the further (in reading order) of Rn and the server's chapter.
  * P = the part-read chapter (0 < progress < 100, not read) this device
    touched most recently. A read chapter opened again (say, by paging back
    into it) is not "being read" and never moves Continue back. If P was touched after the latest read
    mark (or there is none), the user is reading P right now: P wins.
  * Otherwise base. Marking a chapter unread never moves it.
  * With no server chapter and nothing local: the first downloaded unread.
Returns a chapter id or nil.
]]
function Model.resolveResume(list, server_id, overlay, is_read)
    list = list or {}
    local pos = {}
    for i, c in ipairs(list) do pos[c.id] = i end
    local read_pos, read_at = 0, nil
    local part_pos, part_at = nil, nil
    for key, e in pairs(overlay or {}) do
        local p = pos[tonumber(key)]
        if p and type(e) == "table" then
            local at = tonumber(e.at) or 0
            if e.read == true then
                if p > read_pos then read_pos = p end
                if not read_at or at > read_at then read_at = at end
            elseif e.read == nil and e.progress and e.progress > 0 and e.progress < 100
                    and not is_read(list[p]) then
                if not part_at or at > part_at or (at == part_at and p > part_pos) then
                    part_pos, part_at = p, at
                end
            end
        end
    end
    if part_pos and (not read_at or part_at > read_at) then
        return list[part_pos].id
    end
    local next_pos
    if read_pos > 0 then
        for i = read_pos + 1, #list do
            local c = list[i]
            if c.downloaded and not is_read(c) then next_pos = i break end
        end
    end
    local server_pos = server_id and pos[server_id] or nil
    if next_pos and (not server_pos or next_pos > server_pos) then return list[next_pos].id end
    if server_pos then return server_id end
    if part_pos then return list[part_pos].id end
    for _i, c in ipairs(list) do
        if c.downloaded and not is_read(c) then return c.id end
    end
    return server_id
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

return Model
