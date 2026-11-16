--[[--
Reading stats for the Novel screen (pure Lua, unit tested): chapters read
today and in the last 7 days, the average time per chapter and the time to
catch up.

What is counted: a chapter finished on this device by reading it to its end
(paging through its last page; the same moment the read mark is set). Marks
set by hand or in bulk, and reading on other devices, are not reading
sessions and are not counted. Each finished chapter is one event
{t = os.time() seconds, s = active reading seconds or nil}.

* "Today" starts at local midnight; "the last 7 days" is today and the six
  days before it (calendar days, local time).
* Events are kept per novel for KEEP_DAYS days and at most MAX_EVENTS (the
  newest), so the store stays small.
* Average time per chapter: from the measured active reading seconds of
  finished chapters (the timer already leaves out pauses over 5 minutes and
  time asleep). Durations under MIN_S (a skim, a chapter paged through) or
  over MAX_S (a chapter left open across a long pause that slipped through)
  are left out; at least MIN_SAMPLES must remain, else there is no average.
* Time to catch up: unread chapters x the average (nothing without an average).
]]

local has_gettext, gettext = pcall(require, "gettext")
local _ = has_gettext and gettext or function(text) return text end

local function template(text, ...)
    local args = { ... }
    return (text:gsub("%%(%d)", function(n) return tostring(args[tonumber(n)]) end))
end

local Stats = {}

Stats.KEEP_DAYS = 8
Stats.MAX_EVENTS = 300
Stats.MIN_S = 20
Stats.MAX_S = 90 * 60 -- the same as readinginfo.lua MAX_SAMPLE
Stats.MIN_SAMPLES = 3

--- Local midnight (seconds) of the day of time t.
function Stats.dayStart(t)
    local d = os.date("*t", t)
    return os.time({ year = d.year, month = d.month, day = d.day, hour = 0, min = 0, sec = 0 })
end

--- Local midnight `days` calendar days before the day of t (0 = that day).
function Stats.daysBack(t, days)
    local d = os.date("*t", t)
    -- os.time normalises a day of 0 or less into the previous month.
    return os.time({ year = d.year, month = d.month, day = d.day - days, hour = 0, min = 0, sec = 0 })
end

--[[--
Records a finished chapter in a novel's event list (in place): t (seconds),
seconds (active reading, may be nil). Old and surplus events are dropped.
Returns the list.
]]
function Stats.add(list, t, seconds)
    seconds = tonumber(seconds)
    table.insert(list, { t = math.floor(t), s = seconds and math.floor(seconds + 0.5) or nil })
    return Stats.prune(list, t)
end

--- Drops events older than KEEP_DAYS and beyond MAX_EVENTS (the oldest go). In place; returns the list.
function Stats.prune(list, now)
    local cutoff = Stats.daysBack(now, Stats.KEEP_DAYS)
    local i = 1
    while i <= #list do
        local e = list[i]
        if type(e) ~= "table" or not tonumber(e.t) or e.t < cutoff then
            table.remove(list, i)
        else
            i = i + 1
        end
    end
    table.sort(list, function(a, b) return a.t < b.t end)
    while #list > Stats.MAX_EVENTS do table.remove(list, 1) end
    return list
end

--- Chapters finished today and in the last 7 days (today included).
function Stats.counts(list, now)
    local today, week = 0, 0
    local midnight = Stats.dayStart(now)
    local week_start = Stats.daysBack(now, 6)
    for _i, e in ipairs(list or {}) do
        local t = type(e) == "table" and tonumber(e.t)
        if t and t <= now + 60 then
            if t >= midnight then today = today + 1 end
            if t >= week_start then week = week + 1 end
        end
    end
    return today, week
end

--- Whether a duration counts for the average.
function Stats.usable(seconds)
    seconds = tonumber(seconds)
    return seconds ~= nil and seconds >= Stats.MIN_S and seconds <= Stats.MAX_S
end

--[[--
Average seconds per chapter from durations (a list of numbers): the usable
ones only; nil with fewer than MIN_SAMPLES. Returns average, samples used.
]]
function Stats.average(durations)
    local sum, n = 0, 0
    for _i, s in ipairs(durations or {}) do
        if Stats.usable(s) then
            sum = sum + tonumber(s)
            n = n + 1
        end
    end
    if n < Stats.MIN_SAMPLES then return nil, n end
    return sum / n, n
end

--[[--
Durations to average: the measured times of finished chapters already kept
by readinginfo (`samples`, the last 30, no dates) when there are any, else
those of the events.
]]
function Stats.durations(samples, events)
    if samples and #samples > 0 then return samples end
    local out = {}
    for _i, e in ipairs(events or {}) do
        if type(e) == "table" and e.s then table.insert(out, e.s) end
    end
    return out
end

--- Seconds to read `unread` chapters at `average` seconds each (nil without an average).
function Stats.catchUp(unread, average)
    if not average or not unread then return nil end
    return unread * average
end

--- "6 min" under an hour, then "2.5 h" under 10 h, then whole hours.
function Stats.formatDuration(seconds)
    if not seconds then return nil end
    if seconds < 3600 then
        return template(_("%1 min"), math.max(1, math.floor(seconds / 60 + 0.5)))
    end
    local h = seconds / 3600
    if h < 10 then
        local tenth = math.floor(h * 10 + 0.5) / 10
        if tenth == math.floor(tenth) then return template(_("%1 h"), math.floor(tenth)) end
        return template(_("%1 h"), string.format("%.1f", tenth))
    end
    return template(_("%1 h"), math.floor(h + 0.5))
end

--[[--
The Novel screen's lines, compact. s: {today, week, average, catch_up,
unread}. Returns a list of 0, 1 or 2 strings:
  "Read today: 2 · last 7 days: 9"     (when anything was read in 7 days)
  "About 6 min per chapter · about 3 h to catch up"
A part without data is "—" when the line is shown at all; a line with no
data at all is left out.
]]
function Stats.lines(s)
    local out = {}
    if (s.week or 0) > 0 then
        table.insert(out, template(_("Read today: %1 \194\183 last 7 days: %2"), s.today or 0, s.week))
    end
    if s.average then
        local line = template(_("About %1 per chapter"), Stats.formatDuration(s.average))
        if s.catch_up and (s.unread or 0) > 0 then
            line = line .. " \194\183 " .. template(_("about %1 to catch up"), Stats.formatDuration(s.catch_up))
        elseif s.unread == 0 then
            line = line .. " \194\183 " .. _("all caught up")
        end
        table.insert(out, line)
    elseif (s.week or 0) > 0 then
        out[1] = out[1] .. " \194\183 " .. _("time per chapter: \226\128\148")
    end
    return out
end

return Stats
