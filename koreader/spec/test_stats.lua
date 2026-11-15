local T = ...
local Stats = require("novarr.stats")
local Store = require("novarr.store")

-- Local times (whatever the machine's time zone): 16 Nov 2026.
local function at(day, hour, min)
    return os.time({ year = 2026, month = 11, day = day, hour = hour, min = min or 0, sec = 0 })
end
local NOW = at(16, 14, 30)

T.it("day boundaries: local midnight, and 6 days back across a month start", function()
    T.eq(Stats.dayStart(NOW), at(16, 0))
    T.eq(Stats.dayStart(at(16, 0, 0)), at(16, 0))
    T.eq(Stats.daysBack(NOW, 6), at(10, 0))
    local first = os.time({ year = 2026, month = 12, day = 2, hour = 9 })
    T.eq(Stats.daysBack(first, 6), os.time({ year = 2026, month = 11, day = 26, hour = 0 }))
end)

T.it("counts: today from local midnight, last 7 days = today and the 6 days before", function()
    local list = {
        { t = at(16, 0, 0) },     -- midnight: today
        { t = at(16, 9) },
        { t = at(15, 23, 59) },   -- yesterday
        { t = at(10, 0, 0) },     -- 6 days back, at midnight: in the week
        { t = at(9, 23, 59) },    -- 7 days back: out
    }
    T.eq({ Stats.counts(list, NOW) }, { 2, 4 })
end)

T.it("counts: no data, junk, events in the future", function()
    T.eq({ Stats.counts({}, NOW) }, { 0, 0 })
    T.eq({ Stats.counts(nil, NOW) }, { 0, 0 })
    T.eq({ Stats.counts({ "x", { t = "no" }, { t = NOW + 7200 } }, NOW) }, { 0, 0 }, "a clock set back later does not count")
end)

T.it("add: kept sorted, old ones dropped (8 days), at most 300", function()
    local list = { { t = at(1, 10) } }
    Stats.add(list, NOW, 300.4)
    T.eq(#list, 1, "the 1 Nov event is older than 8 days")
    T.eq(list[1], { t = NOW, s = 300 })
    Stats.add(list, NOW - 60, nil)
    T.eq(list[1].t, NOW - 60)
    T.eq(list[1].s, nil)
    local many = {}
    for i = 1, 320 do Stats.add(many, NOW - 320 + i, 100) end
    T.eq(#many, 300)
    T.eq(many[1].t, NOW - 300 + 1, "the oldest went")
end)

T.it("average: outliers out (under 20 s, over 90 min), needs 3 usable", function()
    T.eq({ Stats.average({ 300, 600, 900 }) }, { 600, 3 })
    T.eq({ Stats.average({ 300, 600, 5 , 6000, 900 }) }, { 600, 3 })
    T.eq({ Stats.average({ 300, 600, 10 }) }, { nil, 2 })
    T.eq(Stats.average({}), nil)
    T.eq(Stats.average(nil), nil)
    T.truthy(Stats.usable(20))
    T.truthy(Stats.usable(5400))
    T.falsy(Stats.usable(5401))
    T.falsy(Stats.usable(nil))
end)

T.it("durations: the recorded samples first, else the events'", function()
    T.eq(Stats.durations({ 100, 200 }, { { t = 1, s = 9 } }), { 100, 200 })
    T.eq(Stats.durations({}, { { t = 1, s = 300 }, { t = 2 }, { t = 3, s = 400 } }), { 300, 400 })
    T.eq(Stats.durations(nil, nil), {})
end)

T.it("catch up and formatting", function()
    T.eq(Stats.catchUp(25, 600), 15000)
    T.eq(Stats.catchUp(25, nil), nil)
    T.eq(Stats.catchUp(nil, 600), nil)
    T.eq(Stats.formatDuration(10), "1 min")
    T.eq(Stats.formatDuration(359), "6 min")
    T.eq(Stats.formatDuration(3600), "1 h")
    T.eq(Stats.formatDuration(15000), "4.2 h")
    T.eq(Stats.formatDuration(40 * 3600), "40 h")
    T.eq(Stats.formatDuration(nil), nil)
end)

T.it("lines: both, only counts, only the average, nothing", function()
    T.eq(Stats.lines({ today = 2, week = 9, average = 360, catch_up = 3 * 3600, unread = 30 }),
        { "Read today: 2 · last 7 days: 9", "About 6 min per chapter · about 3 h to catch up" })
    T.eq(Stats.lines({ today = 0, week = 3 }), { "Read today: 0 · last 7 days: 3 · time per chapter: —" })
    T.eq(Stats.lines({ today = 0, week = 0, average = 360, unread = 0 }), { "About 6 min per chapter · all caught up" })
    T.eq(Stats.lines({ today = 0, week = 0, average = 360 }), { "About 6 min per chapter" }, "chapter list unknown")
    T.eq(Stats.lines({ today = 0, week = 0 }), {})
end)

T.it("store: stats kept per novel, survive a reload, empty novels not written", function()
    local Json = require("novarr.json")
    local lfs = require("libkoreader-lfs")
    local dir = T.tmpdir()
    local s = Store.new({ root = dir, lfs = lfs, json = Json, now = function() return 0 end })
    Stats.add(s:getStats(2), NOW, 300)
    s:getStats(3) -- touched, empty
    s:saveStats()
    s:forget()
    T.eq(#s:getStats(2), 1)
    T.eq(s:getStats(2)[1].s, 300)
    local raw = Json.decode(s:readFile(dir .. "/stats.json"))
    T.eq(raw["3"], nil)
    s:clearStats()
    T.eq(#s:getStats(2), 0)
end)
