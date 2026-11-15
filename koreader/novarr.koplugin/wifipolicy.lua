--[[--
When Novarr switches Wi-Fi off and on while a Novarr chapter is being read
(the "Turn Wi-Fi off while reading when everything is saved" setting). Pure
logic: the caller (wifi.lua) gathers a snapshot and acts on the answer.

decide(s) -> "off" | "on" | "nothing", reason, flags

Off, when every one of these holds (Wi-Fi on, chapters to fetch ahead not Off):
  * the write queue is empty (everything sent);
  * the buffer is full: chapters stored ahead >= the prefetch target, or
    nothing more can be fetched (no fetchable chapter missing and the
    chapter list was refreshed in this online window, or the free-space
    floor stops prefetching);
  * no user-started network action is running;
  * Wi-Fi has been on for at least MIN_ON_MS in this window;
  * the online window's work ran (queue flush, list refresh, daily update
    check when due).
  Also off ("stalled", counted as a failure) when Novarr itself switched
  Wi-Fi on and the window makes no progress (server unreachable...).

On, only while Wi-Fi is off *because Novarr switched it off* (the reader
needing a chapter that is not stored, and user actions, switch it on at
once through wifi.lua's restore(), under the same "off by Novarr" rule):
  * >= SYNC_AFTER chapters finished and not yet sent;
  * chapters stored ahead <= the low-water mark (25 % of the target, at
    least 10), unless the last window ended with nothing more to fetch
    (then only after EXHAUSTED_RECHECK_MS);
  the last two not while a connect failure backs off (1, 5, 15 min).

Hysteresis comes from the two thresholds: switched on at the low-water mark,
the buffer must be full again before the off condition holds.

observe(m, wifi_on) tells Novarr's own changes from the user's (KOReader
menu): m = {expected, pending}. A change Novarr did not ask for is "manual".
]]

local WifiPolicy = {}

WifiPolicy.MIN_ON_MS = 20 * 1000
WifiPolicy.SYNC_AFTER = 20
WifiPolicy.LOW_WATER_SHARE = 0.25
WifiPolicy.LOW_WATER_MIN = 10
WifiPolicy.STALL_MS = 2 * 60 * 1000          -- on this long ...
WifiPolicy.STALL_IDLE_MS = 60 * 1000         -- ... and nothing gained for this long
WifiPolicy.CONNECT_TIMEOUT_MS = 60 * 1000
WifiPolicy.BACKOFF_S = { 60, 5 * 60, 15 * 60 }
WifiPolicy.EXHAUSTED_RECHECK_MS = 6 * 3600 * 1000

--- The low-water mark for a prefetch target (0 when prefetching is off).
function WifiPolicy.lowWater(target)
    target = tonumber(target) or 0
    if target <= 0 then return 0 end
    return math.max(WifiPolicy.LOW_WATER_MIN, math.ceil(target * WifiPolicy.LOW_WATER_SHARE))
end

--- Wait (ms) after the n-th connect failure in a row: 1, 5, 15 min, then 15 min.
function WifiPolicy.backoffMs(failures, scale)
    local n = math.max(1, math.min(tonumber(failures) or 1, #WifiPolicy.BACKOFF_S))
    return math.floor(WifiPolicy.BACKOFF_S[n] * 1000 * (scale or 1))
end

--- The durations, scaled (tests run them faster).
function WifiPolicy.durations(scale)
    scale = scale or 1
    return {
        min_on = WifiPolicy.MIN_ON_MS * scale,
        stall = WifiPolicy.STALL_MS * scale,
        stall_idle = WifiPolicy.STALL_IDLE_MS * scale,
        connect_timeout = WifiPolicy.CONNECT_TIMEOUT_MS * scale,
        exhausted_recheck = WifiPolicy.EXHAUSTED_RECHECK_MS * scale,
    }
end

--[[--
Whether the buffer is full. s: target, ahead, missing (fetchable chapters
not stored within the target), list_refreshed, fill_blocked, has_list.
Returns full, exhausted (full only because nothing more can be fetched).
]]
function WifiPolicy.full(s)
    local target = tonumber(s.target) or 0
    if target <= 0 then return true, false end
    if not s.has_list then return false, false end
    if (s.ahead or 0) >= target then return true, false end
    if s.fill_blocked then return true, true end
    if (s.missing or 0) == 0 and s.list_refreshed then return true, true end
    return false, false
end

--[[--
s (snapshot):
    enabled, managing         setting on, a Novarr chapter open in the reader
    wifi_on, connected        radio on, connection up
    connecting                a switch-on Novarr asked for is under way
    off_by_novarr             Wi-Fi is off because Novarr switched it off
    on_by_novarr              Novarr switched it on in this window
    queue, finished_unsynced  queued writes, chapters finished not yet sent
    target, ahead, missing, has_list, list_refreshed, fill_blocked
    busy                      a user-started network action is running
    now, on_since, last_progress   ms
    window_done               the window's background work ran
    backoff_until             ms
    exhausted_at              ms when the last window ended with nothing more to fetch
    d                         durations (see durations())
Returns action, reason, flags {failure = true when switching off a stalled window,
exhausted = true when the buffer is full only because nothing more can be fetched}.
]]
function WifiPolicy.decide(s)
    local d = s.d or WifiPolicy.durations()
    local now = s.now or 0
    if not s.enabled then return "nothing", "setting off" end
    if s.connecting then return "nothing", "a connection is under way" end
    if s.wifi_on then
        if not s.managing then return "nothing", "no Novarr chapter open" end
        -- Nothing is kept ahead: every chapter needs the network, so Wi-Fi is left alone.
        if (tonumber(s.target) or 0) <= 0 then return "nothing", "chapters to fetch ahead is off" end
        local full, exhausted = WifiPolicy.full(s)
        local why
        if (s.queue or 0) > 0 then
            why = string.format("%d write(s) to send", s.queue)
        elseif not full then
            why = string.format("%d of %d chapters ahead stored", s.ahead or 0, tonumber(s.target) or 0)
        elseif s.busy then
            why = "a Novarr action is using the network"
        elseif not s.on_since or now - s.on_since < d.min_on then
            why = "Wi-Fi on for less than " .. math.floor(d.min_on / 1000) .. " s"
        elseif not s.window_done then
            why = "sync and list refresh not done yet"
        end
        if not why then
            return "off", string.format("everything saved: queue empty, %d of %d chapters ahead stored%s, Wi-Fi on %d s",
                s.ahead or 0, tonumber(s.target) or 0, exhausted and " (nothing more to fetch)" or "",
                math.floor((now - s.on_since) / 1000)), { exhausted = exhausted }
        end
        if s.on_by_novarr and not s.busy and s.on_since and now - s.on_since >= d.stall
                and now - (s.last_progress or s.on_since) >= d.stall_idle then
            return "off", "no progress for " .. math.floor((now - (s.last_progress or s.on_since)) / 1000) .. " s (" .. why .. ")",
                { failure = true }
        end
        return "nothing", why
    end
    -- Wi-Fi is off.
    if not s.off_by_novarr then return "nothing", "Wi-Fi was not switched off by Novarr" end
    if not s.managing then return "nothing", "no Novarr chapter open" end
    if s.backoff_until and now < s.backoff_until then
        return "nothing", "waiting " .. math.ceil((s.backoff_until - now) / 1000) .. " s after a failed connection"
    end
    if (s.finished_unsynced or 0) >= WifiPolicy.SYNC_AFTER then
        return "on", string.format("%d chapters read since the last sync", s.finished_unsynced)
    end
    local low = WifiPolicy.lowWater(s.target)
    if low > 0 and s.has_list and (s.ahead or 0) <= low then
        if s.exhausted_at and now - s.exhausted_at < d.exhausted_recheck then
            return "nothing", "nothing more to fetch at the last check"
        end
        return "on", string.format("only %d chapters ahead stored (low-water mark %d)", s.ahead or 0, low)
    end
    return "nothing", string.format("%d chapters ahead stored", s.ahead or 0)
end

--[[--
Tells Novarr's own Wi-Fi changes from the user's. m = {expected = bool|nil,
pending = "on"|"off"|nil}; wifi_on: the radio now. Returns one of
"manual_on", "manual_off", "done_on", "done_off" or nil, and updates m.
While a change Novarr asked for is pending, the state in between is not a
user change.
]]
function WifiPolicy.observe(m, wifi_on)
    wifi_on = wifi_on and true or false
    if m.pending then
        local want = m.pending == "on"
        if wifi_on == want then
            m.pending = nil
            m.expected = wifi_on
            return want and "done_on" or "done_off"
        end
        return nil
    end
    if m.expected == nil then
        m.expected = wifi_on
        return nil
    end
    if wifi_on ~= m.expected then
        m.expected = wifi_on
        return wifi_on and "manual_on" or "manual_off"
    end
    return nil
end

return WifiPolicy
