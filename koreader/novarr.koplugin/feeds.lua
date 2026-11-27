--[[--
Pure helpers (1.13.0) for what the server tells the reader: the
notification feed ("Ready to read: X"), the new-chapters feed and the
reader's stats. No KOReader dependencies (unit tested: spec/test_feeds.lua).

Both feeds take an opaque cursor (`since`) and give the next one; it is
kept per server in the settings, so an item is shown once ("seen").
    GET notifications?since=  {cursor, has_more, items = [{id, kind, title, body, request_id, novel_id, at}]}
        kind: request_new | request_approved | request_declined | request_available
    GET updates?since=        {cursor, series = [{novel_id, name, medium, new_count, chapter_id, chapter_label, latest_at}]}
        (no cursor: empty; the first call only starts the feed)
]]

local Feeds = {}

Feeds.KEEP_NOTIFICATIONS = 30
Feeds.KEEP_UPDATES = 50

-- ---------------------------------------------------------------- notifications

--- The "ready to read" items (a request became available) that can be opened here.
function Feeds.readyItems(items)
    local out = {}
    for _i, it in ipairs(items or {}) do
        if type(it) == "table" and it.kind == "request_available" and tonumber(it.novel_id) then
            table.insert(out, it)
        end
    end
    return out
end

--[[--
The series name of a notification: the server's title is a sentence
("Ready to read: Name"); the part after the first ": " when there is one.
]]
function Feeds.subject(item)
    local title = type(item) == "table" and type(item.title) == "string" and item.title or ""
    local after = title:match("^[^:]+:%s+(.+)$")
    return after or title
end

--[[--
The notice for new "ready to read" items, or nil: "Ready to read: X" /
"Ready to read: X and 2 more" (tr: gettext, T: template).
]]
function Feeds.readyText(ready, tr, T)
    tr = tr or function(s) return s end
    T = T or function(s, a, b) return (s:gsub("%%1", tostring(a)):gsub("%%2", tostring(b or ""))) end
    if type(ready) ~= "table" or #ready == 0 then return nil end
    local name = Feeds.subject(ready[1])
    if #ready == 1 then return T(tr("Ready to read: %1"), name) end
    return T(tr("Ready to read: %1 and %2 more"), name, #ready - 1)
end

--[[--
Newest first, without repeats (by id), at most KEEP_NOTIFICATIONS: the
notifications kept on this device for the Notifications screen.
]]
function Feeds.mergeNotifications(stored, items)
    local out, seen = {}, {}
    local incoming = {}
    for _i, it in ipairs(items or {}) do
        if type(it) == "table" and it.id then table.insert(incoming, it) end
    end
    table.sort(incoming, function(a, b) return (tonumber(a.id) or 0) > (tonumber(b.id) or 0) end)
    for _i, list in ipairs({ incoming, stored or {} }) do
        for _j, it in ipairs(list) do
            local id = tonumber(it.id)
            if id and not seen[id] and #out < Feeds.KEEP_NOTIFICATIONS then
                seen[id] = true
                table.insert(out, it)
            end
        end
    end
    return out
end

-- ---------------------------------------------------------------- new chapters

--[[--
The new-chapters list kept on this device: one entry per series, the most
recent first. A series seen again adds its new count and takes the newer
chapter to start from; at most KEEP_UPDATES.
]]
function Feeds.mergeUpdates(stored, series)
    local by_id, out = {}, {}
    for _i, e in ipairs(stored or {}) do
        if type(e) == "table" and tonumber(e.novel_id) then
            local copy = {}
            for k, v in pairs(e) do copy[k] = v end
            by_id[tonumber(e.novel_id)] = copy
        end
    end
    for _i, s in ipairs(series or {}) do
        local id = type(s) == "table" and tonumber(s.novel_id) or nil
        if id then
            local old = by_id[id]
            local e = {}
            for k, v in pairs(s) do e[k] = v end
            e.novel_id = id
            e.new_count = (tonumber(s.new_count) or 0) + (old and tonumber(old.new_count) or 0)
            -- The chapter to start from stays the older (first unread) one.
            if old and old.chapter_id then
                e.chapter_id = old.chapter_id
                e.chapter_label = old.chapter_label
            end
            by_id[id] = e
        end
    end
    for _id, e in pairs(by_id) do table.insert(out, e) end
    table.sort(out, function(a, b)
        local ta, tb = tostring(a.latest_at or ""), tostring(b.latest_at or "")
        if ta ~= tb then return ta > tb end
        return a.novel_id > b.novel_id
    end)
    while #out > Feeds.KEEP_UPDATES do table.remove(out) end
    return out
end

--- The list without a series (opened, so its new chapters are being read). Returns a new list.
function Feeds.dropSeries(stored, novel_id)
    local out = {}
    for _i, e in ipairs(stored or {}) do
        if tonumber(e.novel_id) ~= novel_id then table.insert(out, e) end
    end
    return out
end

--[[--
Fallback for "New chapters" while the feed has nothing yet: the library's
series with new chapters (chapters_new > 0), most recently updated first,
as feed entries {novel_id, name, new_count}.
]]
function Feeds.fromLibrary(novels)
    local out = {}
    for _i, n in ipairs(novels or {}) do
        if type(n) == "table" and n.id and (tonumber(n.chapters_new) or 0) > 0 then
            table.insert(out, { novel_id = n.id, name = n.name, medium = n.medium,
                new_count = tonumber(n.chapters_new), latest_at = n.updated_at })
        end
    end
    table.sort(out, function(a, b)
        local ta, tb = tostring(a.latest_at or ""), tostring(b.latest_at or "")
        if ta ~= tb then return ta > tb end
        return a.novel_id > b.novel_id
    end)
    return out
end

-- ---------------------------------------------------------------- stats

local function num(v)
    if type(v) == "number" then return v end
    if type(v) == "string" then return tonumber(v) end
    return nil
end

local function thousands(n)
    local s = tostring(math.floor(n))
    local out = s:reverse():gsub("(%d%d%d)", "%1,"):reverse()
    return (out:gsub("^,", ""))
end
Feeds.thousands = thousands

-- {chapters, words} of a period table (or a bare number of chapters).
local function period(p)
    if type(p) == "number" then return { chapters = p } end
    if type(p) ~= "table" then return nil end
    local c = num(p.chapters) or num(p.chapters_read) or num(p.count)
    local w = num(p.words) or num(p.words_read)
    local m = num(p.minutes) or (num(p.seconds) and num(p.seconds) / 60) or nil
    if not c and not w and not m then return nil end
    return { chapters = c, words = w, minutes = m }
end

local function periodText(p, tr)
    local parts = {}
    if p.chapters then
        table.insert(parts, p.chapters == 1 and tr("1 chapter") or (thousands(p.chapters) .. " " .. tr("chapters")))
    end
    if p.words then table.insert(parts, thousands(p.words) .. " " .. tr("words")) end
    if p.minutes and p.minutes > 0 then
        local m = math.floor(p.minutes + 0.5)
        table.insert(parts, m >= 60 and string.format("%dh %02dm", math.floor(m / 60), m % 60) or (m .. " min"))
    end
    return table.concat(parts, " · ")
end

--[[--
The stats screen as plain lines (e-ink: text only). The stats endpoint is
new, so its answer is read leniently: {stats = {...}} or the object itself,
with today / week (this_week) / month / all_time (total) periods of
{chapters, words, minutes}, streak (days, or {current, best}) and
top_series (top) = [{name | title, chapters, words}]. Unknown parts are
left out; nil when nothing could be read.
]]
function Feeds.statsLines(data, tr)
    tr = tr or function(s) return s end
    if type(data) ~= "table" then return nil end
    local s = type(data.stats) == "table" and data.stats or data
    local lines = {}
    -- The server's real answer (GET /api/v1/stats, App\\Http\\Controllers\\Api\\V1\\StatsController):
    -- flat headline figures, the range totals, top_series. Read those first.
    local h = type(s.headline) == "table" and s.headline or nil
    if h then
        local function secs(x) local v = num(x) return v and v / 60 or nil end
        local rows = {
            { tr("Today"), { chapters = num(h.chapters_today), words = num(h.words_today), minutes = secs(h.listening_seconds_today) } },
            { tr("This week"), { chapters = num(h.chapters_week), minutes = secs(h.listening_seconds_week) } },
        }
        local rt = type(s.range_totals) == "table" and s.range_totals or nil
        if rt then
            table.insert(rows, { string.format(tr("Last %d days"), num(rt.days) or 30),
                { chapters = num(rt.chapters), words = num(rt.words), minutes = secs(rt.listening_seconds) } })
        end
        table.insert(rows, { tr("All time"), { chapters = num(h.chapters_read), words = num(h.words_read), minutes = secs(h.listening_seconds) } })
        for _i, r in ipairs(rows) do
            local v = r[2]
            if v.chapters or v.words or v.minutes then table.insert(lines, r[1] .. ": " .. periodText(v, tr)) end
        end
        s = { streak = { current = num(h.current_streak), best = num(h.best_streak) }, top_series = s.top_series }
    end
    local periods = h and {} or {
        { tr("Today"), s.today },
        { tr("This week"), s.week or s.this_week },
        { tr("This month"), s.month or s.this_month },
        { tr("All time"), s.all_time or s.total or s.lifetime },
    }
    for _i, p in ipairs(periods) do
        local v = period(p[2])
        if v then table.insert(lines, p[1] .. ": " .. periodText(v, tr)) end
    end
    local streak = s.streak
    local current, best
    if type(streak) == "table" then
        current, best = num(streak.current) or num(streak.days), num(streak.best) or num(streak.longest)
    else
        current = num(streak)
    end
    current = current or num(s.streak_days)
    if current then
        local text = tr("Streak") .. ": " .. (current == 1 and tr("1 day") or (thousands(current) .. " " .. tr("days")))
        if best and best > current then text = text .. " (" .. tr("best") .. " " .. thousands(best) .. ")" end
        table.insert(lines, text)
    end
    local top = s.top_series or s.top or s.series
    if type(top) == "table" and #top > 0 then
        table.insert(lines, "")
        table.insert(lines, tr("Top series"))
        for i, t in ipairs(top) do
            if i > 10 then break end
            if type(t) == "table" then
                local name = t.name or t.title or (type(t.novel) == "table" and t.novel.name) or "?"
                local v = period(t)
                table.insert(lines, string.format("%d. %s", i, name) .. (v and ("  ·  " .. periodText(v, tr)) or ""))
            end
        end
    end
    if #lines == 0 then return nil end
    return lines
end

return Feeds
