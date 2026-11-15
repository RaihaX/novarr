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

return Model
