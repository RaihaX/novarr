local T = ...
local RI = require("novarr.readinginfo")
local Store = require("novarr.store")

local list = {
    { id = 1, downloaded = true, read = true },
    { id = 2, downloaded = true, read = false },  -- open chapter
    { id = 3, downloaded = true, read = false },
    { id = 4, downloaded = false, read = false }, -- not on the server yet
    { id = 5, downloaded = true, read = true },
    { id = 6, downloaded = true, read = false },
}
local is_read = function(c) return c.read end

T.it("chapters left: unread, downloaded, after the current one", function()
    T.eq(RI.chaptersLeft(list, 2, is_read), 2)
    T.eq(RI.chaptersLeft(list, 0, is_read), 3)
    T.eq(RI.chaptersLeft(list, 6, is_read), 0)
    T.eq(RI.chaptersLeft({}, 0, is_read), 0)
end)

T.it("samples: rolling window of 30, ignores under 20 s", function()
    local s = {}
    T.eq(select(2, RI.addSample(s, 5)), false)
    T.eq(#s, 0)
    for i = 1, 35 do RI.addSample(s, 100 + i) end
    T.eq(#s, 30)
    T.eq(s[1], 106)
    T.eq(s[30], 135)
end)

T.it("seconds per chapter: measured after 3 samples, words before", function()
    T.eq(select(2, RI.secondsPerChapter({ 300, 600 }, 2500)), "words")
    T.eq(RI.secondsPerChapter({ 300, 600 }, 2500), 600) -- 2500 words at 250 wpm = 10 min
    T.eq({ RI.secondsPerChapter({ 300, 600, 900 }, 2500) }, { 600, "measured" })
    T.eq(RI.secondsPerChapter({}, nil), nil)
    T.eq({ RI.secondsPerChapter({ 300, 600, 900, 7000 }, 2500) }, { 600, "measured" }, "over 90 min left out")
    T.eq(select(2, RI.secondsPerChapter({ 300, 600, 7000 }, 2500)), "words", "two usable samples are not enough")
end)

T.it("average words from word_count or stored words", function()
    T.eq(RI.averageWords({ { word_count = 1000 }, { word_count = 3000 }, {} }), 2000)
    T.eq(RI.averageWords({ { words = 500 } }), 500)
    T.eq(RI.averageWords({ {}, { word_count = 0 } }), nil)
end)

T.it("time left = rest of this chapter + chapters left", function()
    T.eq(RI.secondsLeft(600, 2, 50), 1500)
    T.eq(RI.secondsLeft(600, 0, 100), 0)
    T.eq(RI.secondsLeft(nil, 2, 0), nil)
end)

T.it("formatting", function()
    T.eq(RI.thousands(2385), "2,385")
    T.eq(RI.thousands(1234567), "1,234,567")
    T.eq(RI.thousands(12), "12")
    T.eq(RI.formatDuration(45 * 60), "45 min")
    T.eq(RI.formatDuration(10), "1 min")
    T.eq(RI.formatDuration(61 * 3600 + 100), "61 h")
    T.eq(RI.formatDurationLong(3 * 3600), "about 3 hours")
    T.eq(RI.footerText("14:12", 2385, 61 * 3600), "14:12 \194\183 2,385 ch left \194\183 61 h left")
    T.eq(RI.footerText(nil, 3, nil), "3 ch left")
    T.eq(RI.footerText(nil, nil, nil), nil)
end)

T.it("active-reading timer ignores long gaps and pauses", function()
    local t = RI.newTimer(1000)
    RI.tick(t, 1060)        -- +60
    RI.tick(t, 1060 + 400)  -- gap over 5 min: not counted
    RI.tick(t, 1460 + 30)   -- +30
    RI.pause(t)             -- suspended
    RI.tick(t, 5000)        -- first tick after resume only restarts
    RI.tick(t, 5020)        -- +20
    T.eq(t.seconds, 110)
end)

T.it("stored chapters remember their word count", function()
    T.eq(Store.countWords({ "one two  three", "", "four" }), 4)
    T.eq(Store.countWords({}), nil)
end)

T.it("status line: clock · ch N of M · left · time", function()
    local parts = RI.statusParts("15:13", 969, 1147, 178, 9 * 3600)
    T.eq(RI.joinParts(parts), "15:13 \194\183 ch 969 of 1,147 \194\183 178 left \194\183 9 h left")
    T.eq(RI.joinParts(RI.statusParts(nil, nil, nil, 3, nil)), "3 left")
    T.eq(RI.joinParts(RI.statusParts(nil, nil, nil, nil, nil)), nil)
end)

T.it("status line fits the width: drop 'of M' first, then the clock", function()
    local parts = RI.statusParts("15:13", 969, 1147, 178, 9 * 3600)
    local measure = function(t) return #t end
    local full = RI.joinParts(parts)
    T.eq(RI.fitStatus(parts, measure, #full), full)
    T.eq(RI.fitStatus(parts, measure, #full - 1), "15:13 \194\183 ch 969 \194\183 178 left \194\183 9 h left")
    T.eq(RI.fitStatus(parts, measure, 20), "ch 969 \194\183 178 left \194\183 9 h left")
    T.eq(RI.fitStatus(parts, nil, 1), full) -- no measure: unchanged
end)
