local T = ...
local P = require("novarr.wifipolicy")

local NOW = 10 * 3600 * 1000

-- A snapshot where everything is saved and Wi-Fi has been on for a minute.
local function online(o)
    local s = {
        enabled = true, managing = true, wifi_on = true, connected = true,
        off_by_novarr = false, on_by_novarr = true,
        queue = 0, finished_unsynced = 0,
        target = 100, ahead = 100, missing = 0, has_list = true, list_refreshed = true,
        busy = false, now = NOW, on_since = NOW - 60000, last_progress = NOW - 5000,
        window_done = true,
    }
    for k, v in pairs(o or {}) do s[k] = v end
    return s
end

-- Wi-Fi off by Novarr while reading, buffer comfortable.
local function offline(o)
    local s = online({ wifi_on = false, connected = false, off_by_novarr = true, on_by_novarr = false,
        on_since = nil, window_done = false, ahead = 60, list_refreshed = false })
    for k, v in pairs(o or {}) do s[k] = v end
    return s
end

T.it("low-water mark: 25 % of the target, at least 10; 0 with prefetch off", function()
    T.eq(P.lowWater(100), 25)
    T.eq(P.lowWater(200), 50)
    T.eq(P.lowWater(20), 10)
    T.eq(P.lowWater(50), 13)
    T.eq(P.lowWater(0), 0)
end)

T.it("backoff: 1, 5, 15 min, then 15 min", function()
    T.eq(P.backoffMs(1), 60000)
    T.eq(P.backoffMs(2), 300000)
    T.eq(P.backoffMs(3), 900000)
    T.eq(P.backoffMs(7), 900000)
    T.eq(P.backoffMs(1, 0.1), 6000, "scaled")
end)

T.it("off: everything saved, buffer full, on long enough", function()
    local action, reason, flags = P.decide(online())
    T.eq(action, "off")
    T.contains(reason, "everything saved")
    T.falsy(flags.exhausted)
end)

T.it("off is held by each condition on its own", function()
    local cases = {
        { { queue = 1 }, "write(s) to send" },
        { { ahead = 99, missing = 1 }, "99 of 100 chapters ahead stored" },
        { { busy = true }, "a Novarr action" },
        { { on_since = NOW - 19000 }, "less than 20 s" },
        { { window_done = false }, "sync and list refresh" },
    }
    for _i, c in ipairs(cases) do
        local action, reason = P.decide(online(c[1]))
        T.eq(action, "nothing", c[2])
        T.contains(reason, c[2])
    end
end)

T.it("off: no more chapters on the server counts as full only after a list refresh in this window", function()
    T.eq(P.decide(online({ ahead = 12, missing = 0, list_refreshed = true })), "off")
    local _a, _r, flags = P.decide(online({ ahead = 12, missing = 0, list_refreshed = true }))
    T.truthy(flags.exhausted)
    T.eq(P.decide(online({ ahead = 12, missing = 0, list_refreshed = false })), "nothing")
    T.eq(P.decide(online({ ahead = 12, missing = 0, has_list = false })), "nothing", "no chapter list: can't tell")
end)

T.it("off: the free-space floor stopping prefetch counts as full", function()
    local action, _r, flags = P.decide(online({ ahead = 40, missing = 60, fill_blocked = true }))
    T.eq(action, "off")
    T.truthy(flags.exhausted)
end)

T.it("prefetch off: Wi-Fi is not switched off (every chapter needs the network)", function()
    local action, reason = P.decide(online({ target = 0, ahead = 0, missing = 0 }))
    T.eq(action, "nothing")
    T.contains(reason, "fetch ahead is off")
    T.eq(P.decide(offline({ target = 0, ahead = 0 })), "nothing", "and no low-water mark")
    T.eq(P.decide(offline({ target = 0, ahead = 0, finished_unsynced = 20 })), "on", "the 20-read sync still applies")
end)

T.it("nothing at all with the setting off or no Novarr chapter open (Wi-Fi on)", function()
    T.eq(P.decide(online({ enabled = false })), "nothing")
    T.eq(P.decide(offline({ enabled = false, ahead = 0, finished_unsynced = 50 })), "nothing")
    T.eq(P.decide(online({ managing = false })), "nothing")
    T.eq(P.decide(offline({ managing = false, ahead = 0 })), "nothing")
end)

T.it("on: low-water mark reached (<= 25 ahead with target 100)", function()
    T.eq(P.decide(offline({ ahead = 26 })), "nothing")
    local action, reason = P.decide(offline({ ahead = 25 }))
    T.eq(action, "on")
    T.contains(reason, "only 25 chapters ahead")
end)

T.it("on: 20 chapters finished since the last sync", function()
    T.eq(P.decide(offline({ finished_unsynced = 19 })), "nothing")
    local action, reason = P.decide(offline({ finished_unsynced = 20 }))
    T.eq(action, "on")
    T.contains(reason, "20 chapters read")
end)

T.it("on only when Novarr switched Wi-Fi off (a manual off is respected)", function()
    T.eq(P.decide(offline({ off_by_novarr = false, ahead = 0, finished_unsynced = 40 })), "nothing")
end)

T.it("on: not while a connection attempt is under way, nor during backoff", function()
    T.eq(P.decide(offline({ ahead = 5, connecting = true })), "nothing")
    local action, reason = P.decide(offline({ ahead = 5, backoff_until = NOW + 30000 }))
    T.eq(action, "nothing")
    T.contains(reason, "failed connection")
    T.eq(P.decide(offline({ ahead = 5, backoff_until = NOW - 1 })), "on")
end)

T.it("hysteresis: switched on at the low-water mark, it must fill to the target before going off", function()
    -- On at 25 ahead...
    T.eq(P.decide(offline({ ahead = 25 })), "on")
    -- ...connected, queue sent, still filling: stays on at every level below the target.
    for _i, ahead in ipairs({ 25, 26, 50, 75, 99 }) do
        T.eq(P.decide(online({ ahead = ahead, missing = 100 - ahead })), "nothing", ahead)
    end
    T.eq(P.decide(online({ ahead = 100 })), "off")
    -- Off again with a full buffer: not on again until the mark.
    for _i, ahead in ipairs({ 100, 60, 26 }) do
        T.eq(P.decide(offline({ ahead = ahead })), "nothing", ahead)
    end
end)

T.it("no flapping at the end of a novel: nothing to fetch suppresses the low-water mark for a while", function()
    local s = offline({ ahead = 5, exhausted_at = NOW - 60000 })
    T.eq(P.decide(s), "nothing")
    s.exhausted_at = NOW - P.EXHAUSTED_RECHECK_MS - 1
    T.eq(P.decide(s), "on", "re-checked after some hours")
    -- The 20-chapter sync still applies.
    T.eq(P.decide(offline({ ahead = 5, exhausted_at = NOW - 60000, finished_unsynced = 20 })), "on")
end)

T.it("stalled window: off as a failure only when Novarr switched Wi-Fi on", function()
    local stalled = { queue = 3, on_since = NOW - P.STALL_MS - 1, last_progress = NOW - P.STALL_IDLE_MS - 1 }
    local action, reason, flags = P.decide(online(stalled))
    T.eq(action, "off")
    T.truthy(flags.failure)
    T.contains(reason, "no progress")
    stalled.on_by_novarr = false
    T.eq(P.decide(online(stalled)), "nothing", "the user's Wi-Fi is left on")
    T.eq(P.decide(online({ queue = 3, on_since = NOW - P.STALL_MS - 1, last_progress = NOW - 1000 })), "nothing",
        "still making progress")
    T.eq(P.decide(online({ queue = 3, busy = true, on_since = NOW - P.STALL_MS - 1, last_progress = NOW - P.STALL_IDLE_MS - 1 })),
        "nothing", "not while the user is doing something")
end)

T.it("durations scale for tests", function()
    local d = P.durations(0.1)
    T.eq(d.min_on, 2000)
    T.eq(P.decide(online({ d = d, on_since = NOW - 2500 })), "off")
    T.eq(P.decide(online({ d = d, on_since = NOW - 1500 })), "nothing")
end)

T.it("observe: Novarr's own changes are not manual ones", function()
    local m = {}
    T.eq(P.observe(m, true), nil, "first look: baseline")
    m.pending = "off"
    T.eq(P.observe(m, true), nil, "still switching off")
    T.eq(P.observe(m, false), "done_off")
    T.eq(P.observe(m, false), nil)
    m.pending = "on"
    T.eq(P.observe(m, false), nil, "connecting takes a while")
    T.eq(P.observe(m, true), "done_on")
end)

T.it("observe: changes Novarr did not ask for are the user's", function()
    local m = { expected = false }
    T.eq(P.observe(m, true), "manual_on")
    T.eq(P.observe(m, true), nil)
    T.eq(P.observe(m, false), "manual_off")
    T.eq(m.expected, false)
    -- After a wake-up the baseline is taken again (KOReader's own sleep handling is not the user).
    m.expected = nil
    T.eq(P.observe(m, true), nil)
end)

T.it("on: a sync is due (woke up, idle) with writes queued", function()
    local action, reason = P.decide(offline({ sync_due = "woke up", queue = 1 }))
    T.eq(action, "on")
    T.contains(reason, "unsent reading position (woke up)")
    T.eq(P.decide(offline({ sync_due = "idle", queue = 3 })), "on")
    T.eq(P.decide(offline({ queue = 3 })), "nothing", "no sync due: the queue alone does not switch on")
end)

T.it("sync due: not with an empty queue, during backoff, a manual off or nothing open", function()
    T.eq(P.decide(offline({ sync_due = "idle", queue = 0 })), "nothing", "empty queue")
    local action, reason = P.decide(offline({ sync_due = "idle", queue = 2, backoff_until = NOW + 30000 }))
    T.eq(action, "nothing", "backoff")
    T.contains(reason, "failed connection")
    T.eq(P.decide(offline({ sync_due = "idle", queue = 2, off_by_novarr = false })), "nothing", "the user switched it off")
    T.eq(P.decide(offline({ sync_due = "idle", queue = 2, managing = false })), "nothing", "no Novarr chapter open")
    T.eq(P.decide(offline({ sync_due = "idle", queue = 2, enabled = false })), "nothing", "setting off")
    T.eq(P.decide(offline({ sync_due = "idle", queue = 2, connecting = true })), "nothing", "a connection under way")
    T.eq(P.decide(offline({ sync_due = "idle", queue = 2, backoff_until = NOW - 1 })), "on", "after the backoff")
end)

T.it("sync due comes before the 20-chapter rule and works with prefetch off", function()
    local _a, reason = P.decide(offline({ sync_due = "woke up", queue = 20, finished_unsynced = 20 }))
    T.contains(reason, "unsent reading position")
    T.eq(P.decide(offline({ sync_due = "idle", queue = 1, target = 0, ahead = 0 })), "on")
end)

T.it("sleep sync timeout: 20 s, scaled", function()
    T.eq(P.SLEEP_SYNC_TIMEOUT_MS, 20000)
    T.eq(P.durations().sleep_sync_timeout, 20000)
    T.eq(P.durations(0.1).sleep_sync_timeout, 2000)
end)

T.it("on: a latest-position check is due while Wi-Fi is off by Novarr", function()
    local action, reason = P.decide(offline({ position_due = "woke up" }))
    T.eq(action, "on")
    T.eq(reason, "latest position check (woke up)")
    local _a, why = P.decide(offline({ position_due = "opened by KOReader" }))
    T.eq(why, "latest position check (opened by KOReader)")
    T.eq(P.decide(offline()), "nothing", "nothing due: the buffer alone does not switch on")
    -- Also with nothing queued and prefetch off.
    T.eq(P.decide(offline({ position_due = "woke up", queue = 0, target = 0, ahead = 0 })), "on")
    -- An unsent position is named first.
    local _b, first = P.decide(offline({ position_due = "woke up", sync_due = "woke up", queue = 1 }))
    T.contains(first, "unsent reading position")
end)

T.it("position due: not during backoff, a manual off, nothing open or the setting off", function()
    local action, reason = P.decide(offline({ position_due = "woke up", backoff_until = NOW + 30000 }))
    T.eq(action, "nothing", "backoff")
    T.contains(reason, "failed connection")
    T.eq(P.decide(offline({ position_due = "woke up", backoff_until = NOW - 1 })), "on", "after the backoff")
    local _a, why = P.decide(offline({ position_due = "woke up", off_by_novarr = false }))
    T.eq(why, "Wi-Fi was not switched off by Novarr")
    T.eq(P.decide(offline({ position_due = "woke up", managing = false })), "nothing", "no Novarr chapter open")
    T.eq(P.decide(offline({ position_due = "woke up", enabled = false })), "nothing", "setting off")
    T.eq(P.decide(offline({ position_due = "woke up", connecting = true })), "nothing", "a connection under way")
    -- Wi-Fi on: the due check never keeps it on (the ordinary off rule applies).
    T.eq(P.decide(online({ position_due = "woke up" })), "off")
end)
