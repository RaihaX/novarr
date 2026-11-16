local T = ...
local W = require("novarr.wifitime")

-- Noon on a fixed local day, and n days later.
local function day(n, h)
    return os.time({ year = 2026, month = 11, day = 10 + n, hour = h or 12, min = 0, sec = 0 })
end

T.it("segments: time to read, and to Wi-Fi when it started with Wi-Fi on", function()
    local data = {}
    local t = day(0)
    local seg = W.start(t, false)
    T.eq(W.add(data, seg, t + 40, true), 40)  -- Wi-Fi came on
    T.eq(W.add(data, seg, t + 100, false), 60) -- on for 60 s
    T.eq(W.add(data, seg, t + 130), 30)
    local read, wifi = W.totals(data, t + 130)
    T.eq(read, 130)
    T.eq(wifi, 60)
    T.eq(data.days[W.dayKey(t)], { read = 130, wifi = 60 })
end)

T.it("a segment counts at most 5 minutes (left open, not read)", function()
    local data = {}
    local t = day(0)
    local seg = W.start(t, true)
    T.eq(W.add(data, seg, t + 3600), W.MAX_GAP_S)
    T.eq({ W.totals(data, t + 3600) }, { 300, 300 })
    T.eq(W.add(data, seg, t + 3600), 0, "no time, nothing")
    T.eq(W.add(data, nil, t), 0)
    T.eq(W.add(data, W.start(t + 10, false), t), 0, "the clock went back")
end)

T.it("7 days kept: older days dropped, totals over the last 7", function()
    local data = {}
    for n = 0, 9 do
        local seg = W.start(day(n) - 100, n % 2 == 0)
        W.add(data, seg, day(n))
    end
    local read, wifi = W.totals(data, day(9))
    T.eq(read, 700)
    T.eq(wifi, 300) -- days 4, 6, 8 within 3..9
    local n = 0
    for _k in pairs(data.days) do n = n + 1 end
    T.eq(n, 7)
    T.eq(data.days[W.dayKey(day(2))], nil)
    -- Looking later (nothing new): only what is still within 7 days counts.
    T.eq((W.totals(data, day(12))), 400)
end)

T.it("garbage in the stored data is ignored", function()
    T.eq({ W.totals({}, day(0)) }, { 0, 0 })
    T.eq({ W.totals({ days = "x" }, day(0)) }, { 0, 0 })
    local data = { days = { [W.dayKey(day(0))] = { read = "60", wifi = 999 }, junk = 5 } }
    T.eq({ W.totals(data, day(0)) }, { 60, 60 }, "Wi-Fi never more than the reading")
    W.add(data, W.start(day(0), false), day(0) + 10)
    T.eq(data.days.junk, nil)
end)

T.it("format", function()
    T.eq(W.format(0), "0 min")
    T.eq(W.format(14 * 60 + 20), "14 min")
    T.eq(W.format(3600), "1 h")
    T.eq(W.format(6 * 3600 + 10 * 60), "6 h 10 min")
end)

T.it("the Settings line: hidden under 10 min of reading", function()
    local data = { days = { [W.dayKey(day(0))] = { read = 599, wifi = 100 } } }
    T.eq(W.line(data, day(0)), nil)
    data.days[W.dayKey(day(0))].read = 6 * 3600 + 10 * 60
    data.days[W.dayKey(day(0))].wifi = 14 * 60
    T.eq(W.line(data, day(0)), "Wi-Fi on 14 min of 6 h 10 min reading (last 7 days)")
    T.eq(W.line({}, day(0)), nil)
end)

T.it("saved at most once a minute", function()
    T.truthy(W.saveDue(nil, 100))
    T.falsy(W.saveDue(100, 159))
    T.truthy(W.saveDue(100, 160))
end)
