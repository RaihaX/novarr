--[[--
How much of the reading time had Wi-Fi on (Settings: "Wi-Fi on 14 min of
6 h 10 min reading (last 7 days)"). Pure logic, unit tested; reading.lua
feeds it, session.lua keeps the data (store: wifitime.json).

What is counted: time with a Novarr chapter open and the device awake.
No timer of its own: the time between two moments the plugin already
sees (the chapter ready, a page turn, Wi-Fi going on or off, sleep,
closing) is one segment, added to the day it ends on, with the Wi-Fi state
it started with (every Wi-Fi change ends a segment, so a segment is all on
or all off). A segment counts at most MAX_GAP_S: a chapter left open
without a page turn for longer is not being read.

    data = {days = {["YYYY-MM-DD"] = {read = s, wifi = s}}}   (persisted)
    seg  = WifiTime.start(now, wifi_on)                       (memory)
    WifiTime.add(data, seg, now, wifi_on)  ends the segment, starts the next one
    WifiTime.totals(data, now)             read, wifi over the last 7 days
    WifiTime.line(data, now)               the Settings line, nil under MIN_SHOW_S

Days are local calendar days; KEEP_DAYS of them are kept (today and the
six before), older ones are dropped whenever something is added.
]]

local has_gettext, gettext = pcall(require, "gettext")
local _ = has_gettext and gettext or function(text) return text end

local function template(text, ...)
    local args = { ... }
    return (text:gsub("%%(%d)", function(n) return tostring(args[tonumber(n)]) end))
end

local WifiTime = {}

WifiTime.KEEP_DAYS = 7
WifiTime.MAX_GAP_S = 5 * 60
WifiTime.MIN_SHOW_S = 10 * 60
WifiTime.SAVE_EVERY_S = 60

--- "YYYY-MM-DD" of the local day `days` calendar days before the day of t.
function WifiTime.dayKey(t, days)
    local d = os.date("*t", t)
    return os.date("%Y-%m-%d", os.time({ year = d.year, month = d.month, day = d.day - (days or 0), hour = 12 }))
end

--- A new segment from now.
function WifiTime.start(now, wifi_on)
    return { since = now, wifi = wifi_on and true or false }
end

--- Drops days older than KEEP_DAYS (and anything malformed). Returns data.
function WifiTime.prune(data, now)
    if type(data.days) ~= "table" then data.days = {} end
    local keep = {}
    for i = 0, WifiTime.KEEP_DAYS - 1 do keep[WifiTime.dayKey(now, i)] = true end
    for k, v in pairs(data.days) do
        if not keep[k] or type(v) ~= "table" then data.days[k] = nil end
    end
    return data
end

--[[--
Ends the segment at now (its time, at most MAX_GAP_S, goes to today's
bucket; to Wi-Fi as well when it started with Wi-Fi on) and starts the next
one in place with wifi_on (nil: unchanged). Returns the seconds added.
]]
function WifiTime.add(data, seg, now, wifi_on)
    if not seg then return 0 end
    local secs = math.max(0, math.min(now - (seg.since or now), WifiTime.MAX_GAP_S))
    secs = math.floor(secs)
    if secs > 0 then
        WifiTime.prune(data, now)
        local key = WifiTime.dayKey(now, 0)
        local day = data.days[key] or { read = 0, wifi = 0 }
        day.read = (tonumber(day.read) or 0) + secs
        if seg.wifi then day.wifi = (tonumber(day.wifi) or 0) + secs end
        data.days[key] = day
    end
    seg.since = now
    if wifi_on ~= nil then seg.wifi = wifi_on and true or false end
    return secs
end

--- Reading seconds and the part with Wi-Fi on over the last KEEP_DAYS days.
function WifiTime.totals(data, now)
    local read, wifi = 0, 0
    local days = type(data) == "table" and type(data.days) == "table" and data.days or {}
    for i = 0, WifiTime.KEEP_DAYS - 1 do
        local d = days[WifiTime.dayKey(now, i)]
        if type(d) == "table" then
            read = read + (tonumber(d.read) or 0)
            wifi = wifi + math.min(tonumber(d.wifi) or 0, tonumber(d.read) or 0)
        end
    end
    return read, wifi
end

--- "45 min", "6 h 10 min", "2 h".
function WifiTime.format(seconds)
    local m = math.floor((seconds or 0) / 60 + 0.5)
    if m < 60 then return template(_("%1 min"), m) end
    local h, rest = math.floor(m / 60), m % 60
    if rest == 0 then return template(_("%1 h"), h) end
    return template(_("%1 h %2 min"), h, rest)
end

--- The Settings line, or nil while there is less than MIN_SHOW_S of reading.
function WifiTime.line(data, now)
    local read, wifi = WifiTime.totals(data, now)
    if read < WifiTime.MIN_SHOW_S then return nil end
    return template(_("Wi-Fi on %1 of %2 reading (last 7 days)"), WifiTime.format(wifi), WifiTime.format(read))
end

--- Whether it is time to write the data again (at most every SAVE_EVERY_S).
function WifiTime.saveDue(last_saved, now)
    return not last_saved or now - last_saved >= WifiTime.SAVE_EVERY_S
end

function WifiTime.dump(data, now)
    local read, wifi = WifiTime.totals(data, now)
    return { read = read, wifi = wifi, line = WifiTime.line(data, now), days = data.days }
end

return WifiTime
