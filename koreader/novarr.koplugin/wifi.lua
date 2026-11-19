--[[--
"Turn Wi-Fi off while reading when everything is saved": the KOReader side
of wifipolicy.lua (which holds the rules and their tests).

While a Novarr chapter is open and the setting is on, Wifi.evaluate() looks
at the queue, the chapters stored ahead and the Wi-Fi state after the
events that change them (a chapter opened or finished, the network coming
up or going down, a prefetch run or a sync ending, waking up), plus a slow
timer while Wi-Fi is on. Nothing is polled while Wi-Fi is off.

Switching off goes through NetworkMgr:disableWifi(cb, true), what KOReader's
own Wi-Fi menu entry does minus its "Turning off Wi-Fi…" box: it also clears
KOReader's wifi_was_on, so KOReader does not bring Wi-Fi back by itself on
the next start or wake-up. wifi_off_by_novarr (a plugin setting, kept over
restarts) remembers that Novarr did it.

Switching on (only while wifi_off_by_novarr is set; otherwise KOReader's own
"Turn on Wi-Fi?" behaviour stays) is asynchronous and never blocks reading:
on devices with Wi-Fi restore (Kindle) the way KOReader restores Wi-Fi at
start-up, NetworkMgr:restoreWifiAsync() plus its connectivity check (which
broadcasts NetworkConnected and sets wifi_was_on again); elsewhere
NetworkMgr:enableWifi(cb) without user interaction. A connection that does
not come within CONNECT_TIMEOUT is a failure: quiet, logged, retried after
1, 5, then 15 minutes.

The reading position is not left waiting for the next window:
  * Waking up, or 3 minutes without a page turn (reading.lua), with writes
    queued: requestSync() marks a sync as due (wifipolicy's sync_due), so
    Wi-Fi switched off by Novarr comes on for an ordinary window.
  * A latest-position check due after waking up (5+ minutes asleep) or for
    a chapter KOReader opened by itself (position.lua): requestPosition()
    marks it (wifipolicy's position_due), so Wi-Fi switched off by Novarr
    comes on for an ordinary window, in which position.lua asks the server
    (after NetworkConnected). A check due for an open from Novarr's screens
    never switches Wi-Fi on.
  * Going to sleep: sleepSync() sends the queue at once when connected
    (setting on or off). When Novarr has Wi-Fi switched off and the device
    keeps running into sleep with Wi-Fi restore (Kindle: KOReader's
    scheduled tasks go on for some seconds after the Suspend event, until
    the kernel suspends, which nothing can delay), the radio is switched on
    just to send it and off again afterwards, whatever happened, within
    SLEEP_SYNC_TIMEOUT. That is best effort, its own small state
    (Wifi.sleep): no online window, no failure count or backoff, and
    wifi_off_by_novarr stays set throughout. Waking up, leaving the book or
    the reader closing cancel what is left of it. Elsewhere (Kobo...)
    KOReader switches Wi-Fi off itself before Suspend, so only the connected
    case applies.

The backend is injectable (Wifi.setBackend) so the autopilot can emulate
Wi-Fi on the desktop, with a connect delay and failures.
]]

local UIManager = require("ui/uimanager")
local logger = require("logger")

local Policy = require("novarr.wifipolicy")

local Wifi = {
    scale = 1,                           -- test hook: every duration multiplied by this
    m = { expected = nil, pending = nil }, -- what Novarr last set / asked for (manual change detection)
    connecting = nil,                    -- {since, reason, ok = {}, fail = {}}
    on_by_novarr = false,
    window = nil,                        -- {on_since, list_refreshed, background_done, update_ran, last_progress}
    failures = 0,
    backoff_until = nil,
    exhausted_at = nil,
    sync_due = nil,                      -- why the queue should be sent soon (wifipolicy's sync_due)
    position_due = nil,                  -- why a latest-position check waits for Wi-Fi (wifipolicy's position_due)
    sleep = nil,                         -- {since}: sending before sleep, Wi-Fi switched on for it
    last = nil,                          -- {action, reason, at}
    events = {},                         -- recent switches, for dumps
}

Wifi.TICK_MS = 15 * 1000
Wifi.NOTICE_AFTER = 2 -- seconds before "Getting the next chapter…" appears

local function S() return require("novarr.session") end
local function now() return S().nowMs() end
local function log(text) logger.info("Novarr Wi-Fi: " .. text) end

local function event(kind, reason)
    table.insert(Wifi.events, { at = now(), kind = kind, reason = reason })
    while #Wifi.events > 40 do table.remove(Wifi.events, 1) end
end

-- ---------------------------------------------------------------- backends

local function networkMgr() return require("ui/network/manager") end

--- KOReader's NetworkMgr (the device's own Wi-Fi).
Wifi.realBackend = {
    name = "networkmgr",
    isOn = function()
        local NM = networkMgr()
        local ok, v = pcall(NM.isWifiOn, NM)
        return ok and v and true or false
    end,
    isConnected = function()
        local NM = networkMgr()
        local ok, v = pcall(NM.isConnected, NM)
        return ok and v and true or false
    end,
    -- KOReader is already connecting (its own prompt, a restore...).
    busy = function()
        local NM = networkMgr()
        return NM.pending_connection or NM.pending_connectivity_check or false
    end,
    -- Kindle: Wi-Fi is restored without a scan list, and stays up into sleep.
    hasWifiRestore = function()
        local Device = require("device")
        return Device.hasWifiRestore and Device:hasWifiRestore() and true or false
    end,
    turnOff = function(cb)
        networkMgr():disableWifi(cb, true)
    end,
    turnOn = function(cb)
        local NM = networkMgr()
        local Device = require("device")
        if Device.hasWifiRestore and Device:hasWifiRestore() then
            -- As KOReader restores Wi-Fi at start-up: no scan list, no popup.
            NM:restoreWifiAsync()
            NM:scheduleConnectivityCheck(cb)
        else
            NM:enableWifi(cb, false)
        end
    end,
    -- What KOReader's Wi-Fi menu entry does (autopilot "wifi on|off").
    userToggle = function(on)
        local NM = networkMgr()
        if on then NM:toggleWifiOn(nil, nil, true) else NM:toggleWifiOff(nil, true) end
    end,
    --[[ Leaving Novarr: switch the radio back on and return at once (no
    callback, nothing waited for). With Wi-Fi restore (Kindle) this is
    KOReader's start-up restore, a quick lipc call there; wifi_was_on is set
    again (Novarr's switch-off had cleared it), as if Novarr had never
    switched it off. exiting: KOReader is going away, so no connectivity
    check is scheduled. ]]
    restoreNow = function(exiting)
        local NM = networkMgr()
        local Device = require("device")
        if Device.hasWifiRestore and Device:hasWifiRestore() then
            NM:restoreWifiAsync()
            G_reader_settings:makeTrue("wifi_was_on")
            if not exiting then NM:scheduleConnectivityCheck() end
        else
            NM:enableWifi(nil, false)
        end
    end,
}

Wifi.backend = Wifi.realBackend

function Wifi.setBackend(b)
    Wifi.backend = b or Wifi.realBackend
    Wifi.m = { expected = nil, pending = nil }
end

--[[--
A pretend Wi-Fi for the desktop (autopilot): on/off in memory, connecting
takes `delay` seconds, the next `fail` attempts never connect. Sends the
NetworkConnected / NetworkDisconnected events KOReader would. Acts as a
device with Wi-Fi restore (a Kindle) unless opts.restore is false.
]]
function Wifi.fakeBackend(opts)
    opts = opts or {}
    local Event = require("ui/event")
    local b = { name = "fake", on = opts.on ~= false, delay = opts.delay or 2, fail = opts.fail or 0, attempts = 0,
        restore = opts.restore ~= false }
    b.isOn = function() return b.on end
    b.isConnected = function() return b.on end
    b.busy = function() return false end
    b.hasWifiRestore = function() return b.restore end
    b.turnOff = function(cb)
        b.on = false
        UIManager:scheduleIn(0.5, function()
            UIManager:broadcastEvent(Event:new("NetworkDisconnected"))
            if cb then cb() end
        end)
    end
    b.turnOn = function(cb)
        b.attempts = b.attempts + 1
        local failing = b.fail > 0
        if failing then b.fail = b.fail - 1 end
        UIManager:scheduleIn(b.delay, function()
            if failing then
                logger.info("Novarr Wi-Fi (fake): connection attempt failed")
                return
            end
            b.on = true
            UIManager:broadcastEvent(Event:new("NetworkConnected"))
            if cb then cb() end
        end)
    end
    b.restoreNow = function()
        b.turnOn(nil)
    end
    b.userToggle = function(on)
        b.on = on and true or false
        UIManager:broadcastEvent(Event:new(on and "NetworkConnected" or "NetworkDisconnected"))
    end
    return b
end

-- ---------------------------------------------------------------- state

function Wifi.enabled()
    return S():get("wifi_auto_off") ~= false
end

function Wifi.offByNovarr()
    return S():get("wifi_off_by_novarr") == true
end

local function setOffByNovarr(v)
    if Wifi.offByNovarr() ~= (v and true or false) then
        if v then S():set("wifi_off_by_novarr", true) else S():unset("wifi_off_by_novarr") end
    end
end

function Wifi.managing()
    local Session = S()
    return Wifi.enabled() and Session.reader ~= nil and Session.reader.reading ~= nil
end

local function newWindow()
    Wifi.window = { on_since = now(), last_progress = now() }
    -- The window's own work (Reading.background) sends the queue; position.lua
    -- asks for the latest position once connected (main.lua's onNetworkConnected).
    Wifi.sync_due = nil
    Wifi.position_due = nil
end

--- Something was gained in this online window (a chapter stored, writes sent).
function Wifi.progress()
    if Wifi.window then Wifi.window.last_progress = now() end
end

-- Notices manual changes (KOReader's menu, its own prompt) and keeps the window in step.
local function observe()
    local on = Wifi.backend.isOn()
    local ev = Policy.observe(Wifi.m, on)
    if ev == "manual_on" then
        log("switched on outside Novarr: Novarr may switch it off again when everything is saved")
        event("manual_on")
        Wifi.on_by_novarr = false
        newWindow()
    elseif ev == "manual_off" then
        log("switched off outside Novarr: Novarr leaves it off")
        event("manual_off")
        setOffByNovarr(false)
        Wifi.on_by_novarr = false
    end
    if Wifi.sleep then
        -- Switched on only to send before sleep: still Novarr's, no window.
        return on
    end
    if on and Wifi.m.pending ~= "off" then
        -- Whoever switched it on, it is not "off by Novarr" any more
        -- (unless Novarr is switching it off right now).
        setOffByNovarr(false)
        if not Wifi.window then newWindow() end
    elseif not on then
        Wifi.window = nil
    end
    return on
end

-- A user-started network action: chapter downloads, an EPUB being built,
-- a plugin update being installed, a check for new chapters the server is
-- running, a Discover search being followed, or any Novarr screen open (Discover included; Sync, the EPUB
-- download and chapter fetches block KOReader while they run, so nothing is
-- decided meanwhile).
local function busy(Session)
    if Session.download_job then return true end
    local C = package.loaded["novarr.checks"]
    if type(C) == "table" and C.anyActive() then return true end
    local E = package.loaded["novarr.epub"]
    if type(E) == "table" and E.current_flow and E.current_flow.state ~= "done" then return true end
    local U = package.loaded["novarr.ui_update"]
    if type(U) == "table" and U.installing then return true end
    local D = package.loaded["novarr.ui_discover"]
    if type(D) == "table" and D.following() then return true end
    -- Linking this Kindle: the server is asked every few seconds until the code is entered.
    local A = package.loaded["novarr.ui_auth"]
    if type(A) == "table" and A.active() then return true end
    return #Session.menus > 0
end

--- The snapshot wifipolicy.decide() works on.
function Wifi.snapshot()
    local Session = S()
    local r = Session.reader and Session.reader.reading
    local target = tonumber(Session:get("prefetch")) or 0
    local w = Wifi.window or {}
    local sync = Session:syncState()
    local not_linked = Session:needsLink()
    if not_linked then
        -- Not linked (auth.lua): queued writes can't be sent, so they are no
        -- reason to keep or switch Wi-Fi on (they wait for the link).
        sync = { queue = 0, chapters = 0 }
    end
    -- The latest-position check expired (or was dropped): no reason for Wi-Fi any more.
    if Wifi.position_due and not require("novarr.position").pendingDue(Session) then Wifi.position_due = nil end
    local s = {
        enabled = Wifi.enabled(),
        managing = Wifi.managing(),
        wifi_on = Wifi.backend.isOn(),
        connected = Wifi.backend.isConnected(),
        connecting = Wifi.connecting ~= nil,
        off_by_novarr = Wifi.offByNovarr(),
        on_by_novarr = Wifi.on_by_novarr,
        queue = sync.queue,
        finished_unsynced = sync.chapters,
        sync_due = Wifi.sync_due,
        position_due = Wifi.position_due,
        target = target,
        busy = busy(Session),
        now = now(),
        on_since = w.on_since,
        last_progress = w.last_progress,
        list_refreshed = w.list_refreshed,
        window_done = w.background_done and true or false,
        backoff_until = Wifi.backoff_until,
        exhausted_at = Wifi.exhausted_at,
        d = Policy.durations(Wifi.scale),
        has_list = false,
        ahead = 0,
        missing = 0,
    }
    if r then
        local info = require("novarr.reading").aheadInfo(r, target)
        s.has_list, s.ahead, s.missing = info.has_list, info.ahead, info.missing
        local free = Session:freeBytes()
        -- Not linked: nothing can be fetched either.
        s.fill_blocked = (free ~= nil and free < Session.MIN_FREE_BYTES) or not_linked
    end
    s.not_linked = not_linked
    return s
end

-- ---------------------------------------------------------------- timers

local tick_task, flush_task, timeout_task, backoff_task
local sleep_task -- the sleep sync's timeout: never cancelled by unscheduleAll()

local function scheduleTick(s)
    if not tick_task then
        tick_task = function() S().guard(Wifi.evaluate, "timer") end
    end
    UIManager:unschedule(tick_task)
    local d = Policy.durations(Wifi.scale)
    local wait = Wifi.TICK_MS * math.min(1, Wifi.scale)
    if s.on_since and s.now - s.on_since < d.min_on then
        wait = math.min(wait, d.min_on - (s.now - s.on_since) + 200)
    end
    UIManager:scheduleIn(math.max(0.5, wait / 1000), tick_task)
end

local function scheduleFlush()
    if not flush_task then
        flush_task = function()
            S().guard(function()
                local Session = S()
                local reader = Session.reader
                local r = reader and reader.reading
                -- Keep page turns smooth, as prefetching does.
                if r and r.last_turn and now() - r.last_turn < 1500 then
                    UIManager:scheduleIn(1.6, flush_task)
                    return
                end
                local res = Session:quietFlush(200)
                if res and (res.sent or 0) + (res.dropped or 0) > 0 then Wifi.progress() end
                Wifi.evaluate("writes sent")
            end)
        end
    end
    UIManager:unschedule(flush_task)
    UIManager:scheduleIn(2, flush_task)
end

local function unscheduleAll()
    for _i, t in ipairs({ tick_task, flush_task }) do
        if t then UIManager:unschedule(t) end
    end
end

-- ---------------------------------------------------------------- switching

local function switchOff(reason, flags)
    flags = flags or {}
    log("switching off (" .. reason .. ")")
    event("off", reason)
    unscheduleAll()
    Wifi.m.pending = "off"
    setOffByNovarr(true)
    Wifi.on_by_novarr = false
    Wifi.window = nil
    Wifi.exhausted_at = flags.exhausted and now() or nil
    if flags.failure then
        Wifi.failures = Wifi.failures + 1
        Wifi.backoff_until = now() + Policy.backoffMs(Wifi.failures, Wifi.scale)
        log(string.format("next try in %d s at the earliest", math.floor((Wifi.backoff_until - now()) / 1000 + 0.5)))
    else
        Wifi.failures = 0
        Wifi.backoff_until = nil
    end
    Wifi.backend.turnOff(function()
        S().guard(function()
            observe()
        end)
    end)
end

local function runAll(list)
    for _i, fn in ipairs(list or {}) do S().guard(fn) end
end

local function connected()
    local c = Wifi.connecting
    if not c then return end
    Wifi.connecting = nil
    if timeout_task then UIManager:unschedule(timeout_task) end
    log(string.format("connected after %d ms (%s)", now() - c.since, c.reason))
    event("connected", c.reason)
    Wifi.m.pending = nil
    Wifi.m.expected = true
    setOffByNovarr(false)
    Wifi.on_by_novarr = true
    Wifi.failures = 0
    Wifi.backoff_until = nil
    Wifi.exhausted_at = nil
    newWindow()
    runAll(c.ok)
    Wifi.windowStart()
    -- A latest-position check waiting for this connection (also when no
    -- NetworkConnected event follows: the connection found at a poll).
    S().guard(function()
        local Position = require("novarr.position")
        if Position.pendingDue(S()) then Position.schedule(Position.NETWORK_DELAY, "Wi-Fi connected") end
    end)
end

local function failed()
    local c = Wifi.connecting
    if not c then return end
    Wifi.connecting = nil
    Wifi.failures = Wifi.failures + 1
    Wifi.backoff_until = now() + Policy.backoffMs(Wifi.failures, Wifi.scale)
    log(string.format("couldn't connect within %d s (%s); next try in %d s at the earliest",
        math.floor((now() - c.since) / 1000), c.reason, math.floor((Wifi.backoff_until - now()) / 1000 + 0.5)))
    event("connect_failed", c.reason)
    Wifi.m.pending = nil
    if Wifi.backend.isOn() then
        -- The radio came on but no connection: switch it back off, it is still Novarr's.
        Wifi.m.pending = "off"
        Wifi.backend.turnOff()
    else
        Wifi.m.expected = false
    end
    setOffByNovarr(true)
    if not backoff_task then
        backoff_task = function() S().guard(Wifi.evaluate, "retry after a failed connection") end
    end
    UIManager:unschedule(backoff_task)
    UIManager:scheduleIn((Wifi.backoff_until - now()) / 1000 + 0.2, backoff_task)
    runAll(c.fail)
end

--[[--
Switches Wi-Fi on (without asking: only called while Novarr had switched it
off). on_ok / on_fail run when connected / when the attempt failed.
]]
local function switchOn(reason, on_ok, on_fail)
    if Wifi.connecting then
        table.insert(Wifi.connecting.ok, on_ok)
        table.insert(Wifi.connecting.fail, on_fail)
        return
    end
    log("switching on (" .. reason .. ")")
    event("on", reason)
    Wifi.connecting = { since = now(), reason = reason, ok = { on_ok }, fail = { on_fail } }
    Wifi.m.pending = "on"
    if not timeout_task then
        timeout_task = function()
            S().guard(function()
                if Wifi.connecting and Wifi.backend.isConnected() then connected() else failed() end
            end)
        end
    end
    UIManager:unschedule(timeout_task)
    UIManager:scheduleIn(Policy.durations(Wifi.scale).connect_timeout / 1000, timeout_task)
    if Wifi.backend.busy() then
        log("KOReader is already connecting; waiting for it")
        return
    end
    Wifi.backend.turnOn(function() S().guard(connected) end)
end

-- ---------------------------------------------------------------- the decision

local last_key

--- Looks at everything and switches Wi-Fi off or on when the rules say so.
function Wifi.evaluate(why)
    if not Wifi.enabled() then return end
    -- Sending before sleep: that owns the radio until it is done or cancelled.
    if Wifi.sleep then return end
    observe()
    local s = Wifi.snapshot()
    if s.queue == 0 then
        Wifi.sync_due = nil
        s.sync_due = nil
    end
    local action, reason, flags = Policy.decide(s)
    Wifi.last = { action = action, reason = reason, at = s.now, why = why }
    if action == "off" then
        last_key = nil
        switchOff(reason, flags)
        return
    elseif action == "on" then
        last_key = nil
        switchOn(reason)
        return
    end
    -- Log what holds Wi-Fi in its state only when that changes (not every chapter of a fill).
    local key = (s.wifi_on and "on:" or "off:") .. reason:gsub("%d+", "#")
    if key ~= last_key and s.managing then
        last_key = key
        log((s.wifi_on and "staying on: " or "staying off: ") .. reason)
    end
    if s.managing and s.wifi_on then
        if s.queue > 0 and s.connected then scheduleFlush() end
        scheduleTick(s)
    end
end

--[[--
A user-started action (or the reader at a chapter it does not have) needs
the network while Novarr has Wi-Fi switched off: switch it on silently and
run fn once connected. opts.notice: text shown when it takes more than 2 s.
Returns false when it can't (setting off, not switched off by Novarr): the
caller then does what it did before.
]]
function Wifi.restore(reason, fn, on_fail, opts)
    opts = opts or {}
    if not Wifi.canRestore() then return false end
    local notice, notice_task
    if opts.notice then
        notice_task = function()
            local InfoMessage = require("ui/widget/infomessage")
            notice = InfoMessage:new{ text = opts.notice, dismissable = true }
            UIManager:show(notice)
        end
        UIManager:scheduleIn(Wifi.NOTICE_AFTER, notice_task)
    end
    local function closeNotice()
        if notice_task then UIManager:unschedule(notice_task) end
        if notice then UIManager:close(notice) notice = nil end
    end
    switchOn(reason, function()
        closeNotice()
        if fn then fn() end
    end, function()
        closeNotice()
        if on_fail then on_fail() end
    end)
    return true
end

--[[--
Asks for the queue to be sent soon (woken up, idle): with Wi-Fi switched off
by Novarr while reading, it comes on (wifipolicy's sync_due). Nothing is due
with an empty queue. Evaluates either way.
]]
function Wifi.requestSync(reason)
    local Session = S()
    if Session.queue and Session.queue:count() > 0 then
        Wifi.sync_due = reason
    else
        Wifi.sync_due = nil
    end
    Wifi.evaluate(reason)
end

--[[--
Asks for an online window for a latest-position check (position.lua: woke
up, a chapter KOReader opened): with Wi-Fi switched off by Novarr while
reading, it comes on (wifipolicy's position_due). Evaluates.
]]
function Wifi.requestPosition(reason)
    Wifi.position_due = reason
    Wifi.evaluate(reason)
end

--- Whether Novarr may switch Wi-Fi on without asking now.
function Wifi.canRestore()
    if not Wifi.enabled() or not Wifi.offByNovarr() then return false end
    if Wifi.connecting then return true end
    observe()
    return Wifi.offByNovarr() and not Wifi.backend.isConnected()
end

-- ---------------------------------------------------------------- events

--[[--
A new online window while reading: the queue is sent, the chapter list
refreshed, the server address re-checked, the buffer topped up (all in
Reading.background), then the off condition applies again.
]]
function Wifi.windowStart()
    local Session = S()
    if not Wifi.managing() then return end
    local plugin = Session.reader
    if plugin and plugin.background_task then
        UIManager:unschedule(plugin.background_task)
        UIManager:scheduleIn(1, plugin.background_task)
    end
    Wifi.evaluate("connected")
end

function Wifi.onNetworkConnected()
    if not Wifi.enabled() then return end
    if Wifi.sleep then return end -- the sleep sync's connection (sleepConnected sends)
    if Wifi.connecting then
        connected()
        return
    end
    local had = Wifi.window ~= nil
    observe()
    -- Also when the window opened before the connection came (the radio was
    -- already on at wake-up): its work has not run yet.
    if not had or (Wifi.window and not Wifi.window.background_done) then Wifi.windowStart() end
end

function Wifi.onNetworkDisconnected()
    if not Wifi.enabled() then return end
    Wifi.evaluate("network disconnected")
end

-- ---------------------------------------------------------------- sleep sync

local function sleepElapsed()
    return Wifi.sleep and now() - Wifi.sleep.since or 0
end

-- The sleep sync is over (sent, failed or timed out): the radio goes off again, still Novarr's.
local function sleepDone(outcome)
    if not Wifi.sleep then return end
    local elapsed = sleepElapsed()
    Wifi.sleep = nil
    if sleep_task then UIManager:unschedule(sleep_task) end
    log(string.format("going to sleep: %s after %d ms; switching off again", outcome, elapsed))
    event("sleep_off", outcome)
    Wifi.window = nil
    Wifi.on_by_novarr = false
    Wifi.m.pending = "off"
    setOffByNovarr(true)
    local ok, err = pcall(Wifi.backend.turnOff, function()
        S().guard(observe)
    end)
    if not ok then log("couldn't switch it off: " .. tostring(err)) end
end

local function sleepConnected()
    if not Wifi.sleep then return end
    local res = S():quietFlush(50)
    if res then
        sleepDone(string.format("sent %d, %d left", (res.sent or 0) + (res.dropped or 0), res.remaining or 0))
    else
        sleepDone("not connected")
    end
end

-- Waking up, leaving the book, the reader closing: what is left of the sleep sync is dropped.
local function cancelSleep(why)
    if not Wifi.sleep then return false end
    log(string.format("going to sleep: sending cut short after %d ms (%s)", sleepElapsed(), why))
    event("sleep_cancel", why)
    Wifi.sleep = nil
    if sleep_task then UIManager:unschedule(sleep_task) end
    return true
end

--[[--
Going to sleep (Suspend, before Wifi.onSuspend): sends the queued writes.
Connected: at once (whatever the setting). Wi-Fi switched off by Novarr
with the setting on, on a device with Wi-Fi restore (Kindle, where
KOReader's tasks run on for a few seconds into sleep): the radio is switched
on just for this and off again afterwards (see the header). Otherwise
(Wi-Fi off by the user, no Wi-Fi restore) nothing happens. Never shows
anything.
]]
function Wifi.sleepSync()
    local Session = S()
    if not Session:serverUrl() or not Session.queue or Session.queue:count() == 0 then return end
    if Session:needsLink() then return end -- not linked (auth.lua): nothing can be sent
    if Wifi.sleep then return end
    if Session:isConnected() then
        local t0 = now()
        local res = Session:quietFlush(50)
        if res then
            log(string.format("going to sleep: sent %d, %d left (%d ms)",
                (res.sent or 0) + (res.dropped or 0), res.remaining or 0, now() - t0))
        end
        return
    end
    -- A switch-on already under way: Wifi.onSuspend leaves it alone.
    if Wifi.connecting then return end
    if not Wifi.enabled() or not Wifi.offByNovarr() then return end
    if not (Wifi.backend.hasWifiRestore and Wifi.backend.hasWifiRestore()) then return end
    observe() -- switched on by the user meanwhile: the flag is cleared there
    if not Wifi.offByNovarr() or Wifi.backend.isOn() then return end
    local d = Policy.durations(Wifi.scale)
    log(string.format("going to sleep with %d write(s) unsent: switching on to send them", Session.queue:count()))
    event("sleep_on", "going to sleep")
    Wifi.sleep = { since = now() }
    Wifi.m.pending = "on"
    if not sleep_task then
        sleep_task = function()
            S().guard(function()
                if not Wifi.sleep then return end
                if S():isConnected() then sleepConnected() else sleepDone("no connection in time") end
            end)
        end
    end
    UIManager:unschedule(sleep_task)
    UIManager:scheduleIn(d.sleep_sync_timeout / 1000, sleep_task)
    local ok, err = pcall(Wifi.backend.turnOn, function() S().guard(sleepConnected) end)
    if not ok then sleepDone("couldn't switch on: " .. tostring(err)) end
end

function Wifi.onSuspend()
    if not Wifi.enabled() then return end
    unscheduleAll()
    -- KOReader switches Wi-Fi off for sleep; when Novarr had switched it on,
    -- it may switch it on again later without asking.
    if Wifi.on_by_novarr and Wifi.backend.isOn() then setOffByNovarr(true) end
    Wifi.m.expected = nil -- what is found after waking up is not a user change
end

function Wifi.onResume()
    local had_sleep = cancelSleep("woke up")
    if not Wifi.enabled() then return end
    Wifi.m.expected = nil
    if had_sleep and Wifi.backend.isOn() then
        -- The sleep sync's Wi-Fi is on: an ordinary online window from here
        -- (the flag is cleared and the window started in observe()).
        observe()
        Wifi.on_by_novarr = true
        if Wifi.managing() then Wifi.windowStart() end
        return
    end
    local Session = S()
    if Session.queue and Session.queue:count() > 0 then
        Wifi.requestSync("woke up")
    else
        Wifi.evaluate("resume")
    end
end

--- The reader closed (not a switch to the next file): stop managing (restoreOnLeave follows).
function Wifi.stop()
    cancelSleep("reader closed")
    unscheduleAll()
    last_key = nil
end

--[[--
Leaving Novarr's reading: the Novarr book closed for anything but the next
Novarr file (to Home, to the file manager, another book), or KOReader
exiting / restarting (opts.exiting). When Wi-Fi is off *because Novarr
switched it off* (and the setting is on), it is switched back on silently,
without waiting for the connection and without any message, and the flag is
cleared. A switch-on under way is taken over (its timeout and retries are
dropped). When the user switched Wi-Fi off, or it is on, nothing happens.
Returns true when Wi-Fi was switched on.
]]
function Wifi.restoreOnLeave(why, opts)
    opts = opts or {}
    cancelSleep(tostring(why))
    unscheduleAll()
    last_key = nil
    if backoff_task then UIManager:unschedule(backoff_task) end
    -- opts.force: the setting was just switched off (or is off after an
    -- update) while Wi-Fi is still off by Novarr.
    if not (Wifi.enabled() or opts.force) or not Wifi.offByNovarr() then return false end
    if Wifi.connecting then
        -- Already on its way: let it come, but no failure handling (it would switch it off again).
        if timeout_task then UIManager:unschedule(timeout_task) end
        Wifi.connecting = nil
    end
    observe() -- on already (KOReader's own restore...): the flag is cleared there
    if not Wifi.offByNovarr() then return false end
    log("switching on again (" .. tostring(why) .. ": Novarr had switched it off)")
    event("restored", why)
    setOffByNovarr(false)
    Wifi.on_by_novarr = false
    Wifi.window = nil
    Wifi.failures = 0
    Wifi.backoff_until = nil
    Wifi.exhausted_at = nil
    Wifi.m.pending = "on"
    local ok, err = pcall(Wifi.backend.restoreNow or Wifi.backend.turnOn, opts.exiting and true or nil)
    if not ok then log("couldn't switch it on: " .. tostring(err)) end
    return ok
end

--[[--
The setting is off but Wi-Fi is still off by Novarr (switched off in
Settings just now, or by the 1.7.0 update): switch it on and leave it alone.
]]
function Wifi.releaseIfDisabled(why)
    if Wifi.enabled() or not Wifi.offByNovarr() then return false end
    Wifi.stop()
    return Wifi.restoreOnLeave(why or "the setting is off", { force = true })
end

-- ---------------------------------------------------------------- debug

function Wifi.dump()
    local s = Wifi.snapshot()
    return {
        enabled = s.enabled,
        managing = s.managing,
        backend = Wifi.backend.name,
        wifi_on = s.wifi_on,
        connected = s.connected,
        connecting = Wifi.connecting and Wifi.connecting.reason or nil,
        off_by_novarr = s.off_by_novarr,
        on_by_novarr = Wifi.on_by_novarr,
        failures = Wifi.failures,
        backoff_until = Wifi.backoff_until,
        exhausted_at = Wifi.exhausted_at,
        sync_due = Wifi.sync_due,
        position_due = Wifi.position_due,
        sleep = Wifi.sleep,
        queue = s.queue,
        busy = s.busy,
        finished_unsynced = s.finished_unsynced,
        target = s.target,
        ahead = s.ahead,
        missing = s.missing,
        low_water = Policy.lowWater(s.target),
        window = Wifi.window,
        last = Wifi.last,
        scale = Wifi.scale,
        events = Wifi.events,
        wifi_was_on = G_reader_settings and G_reader_settings:readSetting("wifi_was_on"),
    }
end

return Wifi
