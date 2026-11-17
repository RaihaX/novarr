--[[--
Reading info shown in the reader's status bar and on the Novel/Home
screens: chapters left, reading time left, clock, whether the reading
position has reached the server. Pure Lua (unit tested).

Time per chapter is measured on this device: active reading seconds per
finished chapter (gaps between page turns over 5 minutes and time while
suspended are not counted), a rolling window of the last 30 per novel,
values under 20 s discarded, over 90 min left out of the average. With
fewer than 3 such measurements it is
estimated from word counts at 250 words per minute.
]]

-- gettext inside KOReader; identity in the unit tests.
local has_gettext, gettext = pcall(require, "gettext")
local _ = has_gettext and gettext or function(text) return text end

local ReadingInfo = {}

ReadingInfo.MAX_GAP = 5 * 60         -- seconds; longer pauses are not reading
ReadingInfo.MIN_SAMPLE = 20          -- seconds; shorter "reads" are skims
ReadingInfo.MAX_SAMPLE = 90 * 60     -- seconds; longer ones are left out of the average
ReadingInfo.WINDOW = 30              -- finished chapters remembered per novel
ReadingInfo.MIN_SAMPLES = 3          -- measurements needed before trusting them
ReadingInfo.WORDS_PER_MINUTE = 250

--[[--
Chapters left after position `pos` in a chapter list: downloaded on the
server and not read (is_read(c) gives the effective state). Chapters the
server has not downloaded are not counted.
]]
function ReadingInfo.chaptersLeft(list, pos, is_read)
    local n = 0
    for i = (pos or 0) + 1, #(list or {}) do
        local c = list[i]
        if c.downloaded and not is_read(c) then n = n + 1 end
    end
    return n
end

--- Adds a finished chapter's active seconds to a sample list (in place).
function ReadingInfo.addSample(samples, seconds)
    seconds = tonumber(seconds)
    if not seconds or seconds < ReadingInfo.MIN_SAMPLE then return samples, false end
    table.insert(samples, math.floor(seconds + 0.5))
    while #samples > ReadingInfo.WINDOW do table.remove(samples, 1) end
    return samples, true
end

--[[--
Average seconds per chapter: measured when there are enough samples,
otherwise from the average word count (nil when unknown). Returns seconds
(or nil) and the source ("measured" / "words").
]]
function ReadingInfo.secondsPerChapter(samples, avg_words)
    -- Samples over MAX_SAMPLE (a chapter left open across a long pause) are left out,
    -- as on the Novel screen's stats (stats.lua).
    local sum, n = 0, 0
    for _i, s in ipairs(samples or {}) do
        if s >= ReadingInfo.MIN_SAMPLE and s <= ReadingInfo.MAX_SAMPLE then
            sum = sum + s
            n = n + 1
        end
    end
    if n >= ReadingInfo.MIN_SAMPLES then
        return sum / n, "measured"
    end
    avg_words = tonumber(avg_words)
    if avg_words and avg_words > 0 then
        return avg_words / ReadingInfo.WORDS_PER_MINUTE * 60, "words"
    end
    return nil
end

--- Average of the `word_count` keys present in a list of tables (nil if none).
function ReadingInfo.averageWords(rows)
    local sum, n = 0, 0
    for _i, r in ipairs(rows or {}) do
        local w = tonumber(r.word_count or r.words)
        if w and w > 0 then
            sum = sum + w
            n = n + 1
        end
    end
    if n == 0 then return nil end
    return sum / n
end

--- Seconds left in the novel: the rest of this chapter plus the chapters left.
function ReadingInfo.secondsLeft(per_chapter, chapters_left, percent)
    if not per_chapter then return nil end
    local rest = 1 - math.max(0, math.min(100, tonumber(percent) or 0)) / 100
    return per_chapter * (rest + (chapters_left or 0))
end

--- 2385 -> "2,385".
function ReadingInfo.thousands(n)
    local s = tostring(math.floor(tonumber(n) or 0))
    local sign, digits = s:match("^(-?)(%d+)$")
    if not digits then return s end
    local out = digits:reverse():gsub("(%d%d%d)", "%1,"):reverse()
    out = out:gsub("^,", "")
    return sign .. out
end

--- "45 min" under an hour (at least 1 min), then whole hours ("61 h").
function ReadingInfo.formatDuration(seconds)
    if not seconds then return nil end
    if seconds < 3600 then
        return string.format(_("%d min"), math.max(1, math.floor(seconds / 60 + 0.5)))
    end
    return string.format(_("%d h"), math.floor(seconds / 3600 + 0.5))
end

--- Long form for the Novel screen: "about 45 minutes" / "about 61 hours".
function ReadingInfo.formatDurationLong(seconds)
    if not seconds then return nil end
    if seconds < 3600 then
        local m = math.max(1, math.floor(seconds / 60 + 0.5))
        return m == 1 and _("about 1 minute") or string.format(_("about %d minutes"), m)
    end
    local h = math.floor(seconds / 3600 + 0.5)
    return h == 1 and _("about 1 hour") or string.format(_("about %d hours"), h)
end

--[[--
The status-bar text, e.g. "14:12 · 2,385 ch left · 61 h left". Parts are
left out when unknown/disabled; returns nil when nothing is left to show.
]]
function ReadingInfo.footerText(clock, chapters_left, seconds_left)
    local parts = {}
    if clock then table.insert(parts, clock) end
    if chapters_left then table.insert(parts, string.format(_("%s ch left"), ReadingInfo.thousands(chapters_left))) end
    local d = ReadingInfo.formatDuration(seconds_left)
    if d then table.insert(parts, string.format(_("%s left"), d)) end
    if #parts == 0 then return nil end
    return table.concat(parts, " \194\183 ") -- middle dot
end

--[[--
Active-reading stopwatch for one open chapter. tick(now) on every page
turn; pause() on suspend; seconds is the total.
]]
function ReadingInfo.newTimer(now)
    return { seconds = 0, last = now }
end

function ReadingInfo.tick(timer, now)
    if timer.last then
        local gap = now - timer.last
        if gap > 0 and gap <= ReadingInfo.MAX_GAP then
            timer.seconds = timer.seconds + gap
        end
    end
    timer.last = now
end

function ReadingInfo.pause(timer)
    timer.last = nil
end

--[[--
Status-line parts for "15:13 · ch 969 of 1,147 · 178 left · 9 h left".
Returns a list of {key, text}; keys: clock, ch, of, left, time, then the
optional sync part (see syncPart) last.
]]
function ReadingInfo.statusParts(clock, n, m, chapters_left, seconds_left, sync_part)
    local parts = {}
    if clock then table.insert(parts, { key = "clock", text = clock }) end
    if n then
        table.insert(parts, { key = "ch", text = string.format(_("ch %s"), ReadingInfo.thousands(n)) })
        if m then table.insert(parts, { key = "of", text = string.format(_("of %s"), ReadingInfo.thousands(m)) }) end
    end
    if chapters_left then
        table.insert(parts, { key = "left", text = string.format(_("%s left"), ReadingInfo.thousands(chapters_left)) })
    end
    local d = ReadingInfo.formatDuration(seconds_left)
    if d then table.insert(parts, { key = "time", text = string.format(_("%s left"), d) }) end
    if sync_part and sync_part.text then table.insert(parts, sync_part) end
    return parts
end

--[[--
The sync item of the status line. state = {queue (writes waiting),
chapters (finished chapters among them), last_sync_at (os.time() of the
last flush that emptied the queue, or nil)}; now: os.time(). Returns
{key, text} or nil:
  * writes waiting: "3 ch not synced" (finished chapters waiting) or
    "not synced" (key "unsynced", never dropped to fit the width);
  * nothing waiting: "synced just now" / "synced N min ago" / "synced N h
    ago" (under 48 h) / "synced N d ago" (key "sync"); nil when no sync is
    known yet.
]]
function ReadingInfo.syncPart(state, now)
    if type(state) ~= "table" then return nil end
    local queue = tonumber(state.queue) or 0
    if queue > 0 then
        local chapters = tonumber(state.chapters) or 0
        if chapters > 0 then
            return { key = "unsynced", text = string.format(_("%d ch not synced"), chapters) }
        end
        return { key = "unsynced", text = _("not synced") }
    end
    local at = tonumber(state.last_sync_at)
    if not at then return nil end
    -- A time in the future (the clock was changed) counts as just now.
    local ago = math.max(0, (tonumber(now) or at) - at)
    if ago < 60 then
        return { key = "sync", text = _("synced just now") }
    elseif ago < 3600 then
        return { key = "sync", text = string.format(_("synced %d min ago"), math.floor(ago / 60)) }
    elseif ago < 48 * 3600 then
        return { key = "sync", text = string.format(_("synced %d h ago"), math.floor(ago / 3600)) }
    end
    return { key = "sync", text = string.format(_("synced %d d ago"), math.floor(ago / 86400)) }
end

-- "ch 969" and "of 1,147" are joined with a space, everything else with " · ".
function ReadingInfo.joinParts(parts)
    local out = ""
    for i, p in ipairs(parts) do
        if i == 1 then out = p.text
        elseif p.key == "of" then out = out .. " " .. p.text
        else out = out .. " \194\183 " .. p.text end
    end
    return out ~= "" and out or nil
end

--[[--
Fits the status line into `budget` pixels (measure(text) -> pixels): drops
"of M" first, then "synced ... ago", then the clock ("not synced" stays).
Returns the text (nil when empty).
]]
function ReadingInfo.fitStatus(parts, measure, budget)
    local function without(key)
        local out = {}
        for _i, p in ipairs(parts) do if p.key ~= key then table.insert(out, p) end end
        return out
    end
    local text = ReadingInfo.joinParts(parts)
    if not text or not budget or not measure or measure(text) <= budget then return text end
    parts = without("of")
    text = ReadingInfo.joinParts(parts)
    if not text or measure(text) <= budget then return text end
    parts = without("sync")
    text = ReadingInfo.joinParts(parts)
    if not text or measure(text) <= budget then return text end
    parts = without("clock")
    return ReadingInfo.joinParts(parts)
end

return ReadingInfo
