--[[--
"Read on another device": when another device has read a novel more
recently than this one, a question offers to go there:

    Chapter 124 · 40% was read on iPhone 2 hours ago. Go there?
    [Go to latest]  [Stay here]

The server is asked where the novel was read last (GET novels/{id}/position,
3 s at most); the rule is Model.shouldOfferPosition. It is asked at three
moments, never more often, and only with the setting position_prompt on
(the default):

  * Opening from Novarr's screens (a tap on Home/Library, Continue, a
    chapter in the list; Session:openChapter with opts.check_position),
    when already connected: asked BEFORE opening (beforeOpen). The question
    shows over the menu, and either answer opens the reader once: Go at the
    other device's place, Stay here (or a tap outside) the chapter that was
    tapped. No question: the chapter opens as usual. When the request fails,
    the server is not asked before opening again for FAIL_SKIP_MS (a reachable
    Wi-Fi with an unreachable server costs one short delay, not one per open).
    Not connected, or the request failed: the check becomes due ("opened").
  * Waking up after WAKE_MIN_SLEEP seconds or more with a Novarr chapter open
    (main.lua's resume task): due ("woke up").
  * A Novarr chapter KOReader opened by itself (the last book reopened at
    start-up, History, the file browser; Reading.onReady tells these from
    Novarr's own opens by Session.opening_path): due ("opened by KOReader").

A due check (Session.position_due, memory only) runs over the open chapter
(runDue: request, rule, question) once the chapter of that novel is ready,
the device awake, no question of ours showing and a connection up: a moment
after it became due, after the chapter is ready (Reading.onReady, an
in-document jump in Session:openJoined), and after the network came up
(main.lua's onNetworkConnected). No timer polls; after DUE_TTL_MS it is
dropped. Leaving the book drops it; the next/previous chapter keeps it, and
never makes one due. "woke up" and "opened by KOReader" also ask the Wi-Fi
management for a short online window when Novarr had switched Wi-Fi off
(wifi.lua's requestPosition, wifipolicy's position_due); "opened" never
switches Wi-Fi on.

    Go          that chapter opens at the other device's place, overriding
                KOReader's saved position for it (Session.forced_progress,
                applied once in Reading.onReady); the same chapter or one in
                the open group file: a jump inside the document.
    Stay here   the position's `at` is remembered per novel (setting
                position_declined): not asked again until a newer one.

Also this device's identity, sent with every write (api.lua, whatever the
setting): an id made once (k_ + 22 random characters, setting device_id) and
a short name ("Kindle", or KOReader's model name: "Kindle PaperWhite 5"; the
setting device_name, from Settings, wins).

The helpers taking a `settings` object (readSetting/saveSetting/flush, like
LuaSettings) or a Session-like table, and the pure ones, have no KOReader
dependency (unit tested); the rest loads KOReader modules on use.
]]

local Model = require("novarr.model")

local Position = {}

Position.CHECK_DELAY = 1           -- seconds after the chapter is ready / the check became due
Position.NETWORK_DELAY = 2.5       -- seconds after NetworkConnected (main.lua's queue flush, at 2 s, first)
Position.DUE_TTL_MS = 180000       -- a due check older than this is dropped
Position.FAIL_SKIP_MS = 60000      -- after a failed request before opening, none before opening for this long
Position.WAKE_MIN_SLEEP = 300      -- seconds asleep before waking up makes a check due
Position.FORCED_TTL_MS = 120000    -- a "Go" whose chapter did not open this soon is forgotten

-- Why a check is due. Only OPENED never switches Wi-Fi on.
Position.OPENED = "opened"
Position.WOKE_UP = "woke up"
Position.OPENED_BY_KOREADER = "opened by KOReader"
Position.DEFAULT_NAME = "Kindle"
Position.MAX_NAME = 40

local ID_CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789"

-- ---------------------------------------------------------------- device identity

--- Whether s is a usable device id (contract: 8..64 of [A-Za-z0-9_-]).
function Position.validId(s)
    return type(s) == "string" and #s >= 8 and #s <= 64 and s:match("^[%w_%-]+$") ~= nil
end

--[[--
A new device id: "k_" and 22 characters of [A-Za-z0-9]. random_bytes(n)
gives n random bytes (string) or nil; without it (or when it fails)
math.random is used, seeded from the clock.
]]
function Position.newDeviceId(random_bytes)
    local n = 22
    local bytes = random_bytes and random_bytes(n)
    if type(bytes) ~= "string" or #bytes < n then
        bytes = nil
        local seed = os.time()
        local ok, socket = pcall(require, "socket")
        if ok and type(socket) == "table" and socket.gettime then
            seed = math.floor((socket.gettime() * 1000000) % 2147483647)
        end
        math.randomseed(seed)
    end
    local out = { "k_" }
    for i = 1, n do
        local v = bytes and bytes:byte(i) or math.random(0, 255)
        local k = (v % #ID_CHARS) + 1
        out[#out + 1] = ID_CHARS:sub(k, k)
    end
    return table.concat(out)
end

--[[--
A short, readable name from KOReader's Device.model ("KindlePaperWhite5" ->
"Kindle PaperWhite 5", "KindleOasis3" -> "Kindle Oasis 3"). Anything odd or
empty: "Kindle". At most 40 characters.
]]
function Position.deviceName(model)
    if type(model) ~= "string" then return Position.DEFAULT_NAME end
    local name = model:gsub("[^%w _%-]", "")
    name = name:gsub("(%l)(%u)", "%1 %2"):gsub("(%a)(%d)", "%1 %2")
    name = name:gsub("Paper White", "PaperWhite"):gsub("%s+", " "):gsub("^%s+", ""):gsub("%s+$", "")
    if name == "" or not name:match("%a") then return Position.DEFAULT_NAME end
    if #name > Position.MAX_NAME then name = name:sub(1, Position.MAX_NAME):gsub("%s+$", "") end
    return name
end

--[[--
This device's id and name. The id is made once and kept in the settings
(device_id); a user-set device_name setting wins over the model name.
opts: model (KOReader's Device.model), random_bytes (see newDeviceId).
Returns id, name.
]]
function Position.identity(settings, opts)
    opts = opts or {}
    local id = settings:readSetting("device_id")
    if not Position.validId(id) then
        id = Position.newDeviceId(opts.random_bytes)
        settings:saveSetting("device_id", id)
        if settings.flush then settings:flush() end
    end
    local name = settings:readSetting("device_name")
    if type(name) ~= "string" or not name:match("%S") then
        name = Position.deviceName(opts.model)
    end
    return id, name:sub(1, Position.MAX_NAME)
end

--[[--
The name typed in Settings ("Name of this device"), cleaned for the header:
printable ASCII only (tabs and line breaks become spaces, anything else is
removed), spaces collapsed, trimmed, at most 40 characters. nil when nothing
is left (back to the model name).
]]
function Position.cleanName(s)
    if type(s) ~= "string" then return nil end
    -- Tabs and line breaks become spaces; anything else outside printable ASCII goes.
    local name = s:gsub("%s", " "):gsub("[^\32-\126]", ""):gsub(" +", " "):gsub("^ ", ""):gsub(" $", "")
    if #name > Position.MAX_NAME then name = name:sub(1, Position.MAX_NAME):gsub("%s+$", "") end
    if name == "" then return nil end
    return name
end

--- n bytes from /dev/urandom, or nil.
function Position.urandom(n)
    local f = io.open("/dev/urandom", "rb")
    if not f then return nil end
    local s = f:read(n)
    f:close()
    return s
end

-- ---------------------------------------------------------------- "Stay here"

--- The position `at` declined for a novel, or nil.
function Position.declinedAt(settings, novel_id)
    local t = settings:readSetting("position_declined")
    if type(t) ~= "table" then return nil end
    return t[tostring(novel_id)]
end

--- Remembers that the position at `at` was declined for a novel.
function Position.decline(settings, novel_id, at)
    if at == nil then return end
    local t = settings:readSetting("position_declined")
    if type(t) ~= "table" then t = {} end
    t[tostring(novel_id)] = at
    settings:saveSetting("position_declined", t)
    if settings.flush then settings:flush() end
end

-- ---------------------------------------------------------------- rule inputs

--[[--
Whether this device holds reading of a novel newer than the server's
position (age_seconds old): an overlay entry (local progress/read mark) or a
queued progress/read write recorded less than age_seconds ago. Ages, not
clocks, are compared (now_ms and the entries' `at` are this device's clock).
]]
function Position.localNewer(overlay, queue_items, novel_id, age_seconds, now_ms)
    local age = tonumber(age_seconds)
    if not age then return false end
    local limit = age * 1000
    local function newer(at)
        at = tonumber(at)
        return at ~= nil and now_ms - at < limit
    end
    for _k, e in pairs(overlay or {}) do
        if type(e) == "table" and (e.read == true or e.progress ~= nil) and newer(e.at) then return true end
    end
    for _i, q in ipairs(queue_items or {}) do
        if type(q) == "table" and q.novel_id == novel_id and newer(q.at)
                and (q.kind == "progress" or (q.kind == "set_read" and q.read == true)) then
            return true
        end
    end
    return false
end

--[[--
Everything the rule (Model.shouldOfferPosition) needs besides the position
and where this device is, the same way before opening and over an open
chapter. Session: device_id, store (getOverlay, hasChapter), queue (list),
settings, nowMs(), isConnected(). chapter_id / local_progress: the chapter
being opened or open here and this device's place in it (nil = start).
open_group: the chapter id set of the group file open for this novel, or nil.
]]
function Position.ruleInputs(Session, novel_id, p, chapter_id, local_progress, open_group)
    local pid = tonumber(p.chapter_id)
    local stored = pid ~= nil and Session.store:hasChapter(novel_id, pid) and true or false
    local in_file = pid ~= nil and type(open_group) == "table" and open_group[pid] ~= nil
    return {
        position = p,
        device_id = Session.device_id,
        chapter_id = chapter_id,
        local_progress = local_progress,
        local_newer = Position.localNewer(Session.store:getOverlay(novel_id), Session.queue:list(), novel_id,
            p.age_seconds, Session.nowMs()),
        declined_at = Position.declinedAt(Session.settings, novel_id),
        -- Opened the way any chapter is: stored here, or fetched (connected, and the server has its text).
        can_open = stored or in_file or (p.downloaded == true and Session:isConnected()),
    }
end

-- ---------------------------------------------------------------- the setting, waking up

--- Whether the question is on (setting position_prompt; unset is on). Session: get(key).
function Position.enabled(Session)
    return Session:get("position_prompt") ~= false
end

--- Whether waking up after seconds_asleep makes a check due.
function Position.wakeDue(seconds_asleep, enabled)
    local s = tonumber(seconds_asleep)
    return enabled == true and s ~= nil and s >= Position.WAKE_MIN_SLEEP
end

-- ---------------------------------------------------------------- a due check (bookkeeping)
-- On a Session-like table (position_due, nowMs()); no KOReader dependency.

-- Wi-Fi's copy (wifipolicy's position_due) goes with the due check; only when wifi.lua is loaded.
local function wifiForget()
    local W = package.loaded["novarr.wifi"]
    if type(W) == "table" then W.position_due = nil end
end

--- Makes a check due for a novel (replacing any other). Returns it.
function Position.markDue(Session, novel_id, reason)
    wifiForget() -- a new one asks for Wi-Fi itself (Position.due)
    Session.position_due = { novel_id = novel_id, reason = reason, at = Session.nowMs() }
    return Session.position_due
end

--- Drops the due check (only one for novel_id when given). Returns true when one was dropped.
function Position.clearDue(Session, novel_id)
    local d = Session.position_due
    if not d or (novel_id ~= nil and d.novel_id ~= novel_id) then return false end
    Session.position_due = nil
    wifiForget()
    return true
end

--[[--
The due check (for novel_id when given), or nil. One older than DUE_TTL_MS
is dropped here.
]]
function Position.pendingDue(Session, novel_id)
    local d = Session.position_due
    if not d then return nil end
    if Session.nowMs() - d.at > Position.DUE_TTL_MS then
        Session.position_due = nil
        wifiForget()
        return nil
    end
    if novel_id ~= nil and d.novel_id ~= novel_id then return nil end
    return d
end

--[[--
Whether the due check can run now: returns it, or nil plus why not (for the
log). It runs over a ready chapter of its novel (Session.reader.reading),
with the device awake (Session.asleep), no question of ours showing
(Session.position_box) and a connection (Session:isConnected()). A missing
connection leaves it due.
]]
function Position.dueReady(Session)
    local d = Position.pendingDue(Session)
    if not d then return nil, "nothing due" end
    local r = Session.reader and Session.reader.reading
    if not r or r.novel_id ~= d.novel_id then return nil, "its novel is not open" end
    if not r.ready then return nil, "the chapter is not ready" end
    if Session.asleep then return nil, "asleep" end
    if Session.position_box then return nil, "a question is showing" end
    if not Session:isConnected() then return nil, "not connected" end
    return d
end

--- Whether a request before opening failed less than FAIL_SKIP_MS ago.
function Position.recentFailure(Session)
    local t = Session.position_failed_at
    return t ~= nil and Session.nowMs() - t < Position.FAIL_SKIP_MS
end

-- ---------------------------------------------------------------- KOReader side

local function S() return require("novarr.session") end
local function R() return require("novarr.reading") end
local function log(text) require("logger").info("Novarr: latest position: " .. text) end

--[[--
Drops Session.position_box when that box is no longer on screen (closed by
something that ran neither of its callbacks), so a lost box can never block
later questions.
]]
function Position.forgetLostBox(Session, window_stack)
    local box = Session.position_box
    if not box then return end
    for _i, w in ipairs(window_stack or {}) do
        if type(w) == "table" and w.widget == box then return end
    end
    Session.position_box = nil
end

local function forgetLostBox()
    local ok, UIManager = pcall(require, "ui/uimanager")
    if ok and type(UIManager) == "table" and type(UIManager._window_stack) == "table" then
        Position.forgetLostBox(S(), UIManager._window_stack)
    end
end

-- The one scheduled runDue (there is at most one due check), and the reader
-- plugin instance it was scheduled with (Session.reader then, or nil).
local task, task_owner

--- Runs the due check `delay` seconds from now (replacing an earlier schedule).
function Position.schedule(delay, why)
    local UIManager = require("ui/uimanager")
    if task then UIManager:unschedule(task) end
    task_owner = S().reader
    task = function()
        task, task_owner = nil, nil
        S().guard(Position.runDue, why)
    end
    UIManager:scheduleIn(delay, task)
end

--[[--
Cancels the scheduled check. plugin: the reader plugin instance closing (its
own schedule only: an older instance closing late never cancels the one the
next chapter made); nil: whatever is scheduled.
]]
function Position.unschedule(plugin)
    if task and (plugin == nil or task_owner == nil or task_owner == plugin) then
        require("ui/uimanager"):unschedule(task)
        task, task_owner = nil, nil
    end
end

--[[--
A check is due for a novel (see the header). Nothing when the setting is
off. "woke up" / "opened by KOReader": with Wi-Fi switched off by Novarr,
the Wi-Fi management may switch it on for a moment (wifi.lua). Every reason
but "opened" (its chapter is not open yet: Reading.onReady schedules it)
runs a moment later.
]]
function Position.due(novel_id, reason)
    local Session = S()
    if not novel_id or not Position.enabled(Session) or not Session:serverUrl() then return end
    Position.markDue(Session, novel_id, reason)
    log("check due for novel " .. tostring(novel_id) .. " (" .. tostring(reason) .. ")")
    if reason == Position.OPENED then return end
    local Wifi = require("novarr.wifi")
    if Wifi.canRestore() then Wifi.requestPosition(reason) end
    Position.schedule(Position.CHECK_DELAY, reason)
end

--[[--
A Novarr chapter is ready (Reading.onReady) or was jumped to inside the open
group file (Session:openJoined). by_koreader: KOReader opened it by itself
(not Novarr's own open): a check becomes due. Otherwise one already due for
this novel runs a moment later.
]]
function Position.chapterReady(novel_id, by_koreader)
    local Session = S()
    if by_koreader then
        Position.due(novel_id, Position.OPENED_BY_KOREADER)
        return
    end
    if Position.pendingDue(Session, novel_id) then Position.schedule(Position.CHECK_DELAY, "chapter ready") end
end

--- Leaving the book (the reader closed, another book): nothing is due any more.
function Position.leave()
    Position.clearDue(S())
    Position.unschedule()
end

--- Runs the due check when it can (see dueReady); otherwise it stays due.
function Position.runDue(why)
    local Session = S()
    if not Position.enabled(Session) then
        Position.clearDue(Session) -- switched off in Settings meanwhile
        return
    end
    forgetLostBox()
    local d, not_yet = Position.dueReady(Session)
    if not d then
        if Session.position_due then log("check not run yet (" .. tostring(why) .. "): " .. not_yet) end
        return
    end
    Position.clearDue(Session)
    log("checking (" .. tostring(d.reason) .. ", " .. tostring(why) .. ")")
    Position.check(d.novel_id)
end

--- Asks the server and, when the rule says so, shows the question over the open chapter.
function Position.check(novel_id)
    local Session = S()
    local plugin = Session.reader
    local r = plugin and plugin.reading
    if not r or r.novel_id ~= novel_id or not r.ready then return end
    -- Never turns Wi-Fi on, never prompts: only when already connected.
    if not Session:serverUrl() or not Session:isConnected() then
        log("not connected, not asked")
        return
    end
    local t0 = Session.nowMs()
    local p, e = Session.api:position(novel_id)
    if not p then
        log(string.format("%s (%d ms)", p == false and "none on the server"
            or ("not available (" .. tostring(e and e.kind) .. ")"), Session.nowMs() - t0))
        return
    end
    plugin = Session.reader
    r = plugin and plugin.reading
    if not r or r.novel_id ~= novel_id then return end
    local ok, why = Model.shouldOfferPosition(Position.ruleInputs(Session, novel_id, p, r.chapter_id,
        R().currentPercent(plugin), r.group and r.group.index or nil))
    log(string.format("chapter %s (%s) on %s, %d ms: %s", tostring(p.chapter_id), tostring(p.progress),
        tostring(p.device_name), Session.nowMs() - t0, ok and "asking" or why))
    if ok then Position.ask(novel_id, p) end
end

--[[--
Opening chapter_id from Novarr's screens (Session:openChapter with
opts.check_position, before anything opens): asks the server first when
connected. Returns true when the question is showing (its answer opens the
reader: Go, or the chapter asked for with `opts` minus check_position);
false: open as usual (a check may have become due).
]]
function Position.beforeOpen(novel_id, chapter_id, opts)
    local Session = S()
    if not novel_id or not Position.enabled(Session) or not Session:serverUrl() then return false end
    if not Session:isConnected() then
        log("before opening: not connected, checked once connected")
        Position.due(novel_id, Position.OPENED)
        return false
    end
    if Position.recentFailure(Session) then
        log("before opening: skipped (the server did not answer a moment ago)")
        Position.due(novel_id, Position.OPENED)
        return false
    end
    local t0 = Session.nowMs()
    local p, e = Session.api:position(novel_id)
    local ms = Session.nowMs() - t0
    if p == nil then
        Session.position_failed_at = Session.nowMs()
        log(string.format("before opening: not available (%s), %d ms", tostring(e and e.kind), ms))
        Position.due(novel_id, Position.OPENED)
        return false
    end
    Session.position_failed_at = nil
    if not p then
        log(string.format("before opening: none on the server, %d ms", ms))
        return false
    end
    local _read, progress = Session:chapterState(novel_id, chapter_id)
    local ok, why = Model.shouldOfferPosition(Position.ruleInputs(Session, novel_id, p, chapter_id, progress,
        Session:openGroupSet(novel_id)))
    log(string.format("before opening chapter %s: chapter %s (%s) on %s, %d ms: %s", tostring(chapter_id),
        tostring(p.chapter_id), tostring(p.progress), tostring(p.device_name), ms, ok and "asking" or why))
    if not ok then return false end
    local again = {}
    for k, v in pairs(opts or {}) do again[k] = v end
    again.check_position = nil
    return Position.ask(novel_id, p, function()
        Session:openChapter(novel_id, chapter_id, again)
    end)
end

--[[--
Shows the question. on_stay: runs after "Stay here" (or a tap outside),
on the next tick (before opening: opens the chapter that was tapped).
Returns false when it can't show now (asleep, or one of ours is showing).
]]
function Position.ask(novel_id, p, on_stay)
    local Session = S()
    forgetLostBox()
    if Session.asleep or Session.position_box then
        log("not asked: " .. (Session.asleep and "asleep" or "a question is already showing"))
        return false
    end
    local _ = require("gettext")
    local UI = require("novarr.ui_common")
    local UIManager = require("ui/uimanager")
    Session.position_box = UI.confirm({
        text = Model.positionPromptText(p, _),
        ok_text = _("Go to latest"),
        cancel_text = _("Stay here"),
        ok = function()
            Session.position_box = nil
            Position.go(novel_id, p)
        end,
        -- Also a tap outside the box (ConfirmBox closes through its cancel callback).
        cancel = function()
            Session.position_box = nil
            Position.decline(Session.settings, novel_id, p.at)
            -- Never switch documents from inside a dialog's callback.
            if on_stay then UIManager:nextTick(Session.wrap(on_stay)) end
        end,
    })
    return true
end

--- "Go to latest": that chapter at the other device's place.
function Position.go(novel_id, p)
    local Session = S()
    local chapter_id = tonumber(p.chapter_id)
    local progress = math.max(0, math.min(100, tonumber(p.progress) or 0))
    local plugin = Session.reader
    local r = plugin and plugin.reading
    if r and r.novel_id == novel_id and (r.chapter_id == chapter_id or (r.group and r.group.index[chapter_id])) then
        log("going to chapter " .. chapter_id .. " at " .. progress .. "% in the open document")
        Session:closeAllMenus()
        R().goToProgress(plugin, chapter_id, progress)
        return
    end
    log("opening chapter " .. chapter_id .. " at " .. progress .. "%")
    Session.forced_progress = { novel_id = novel_id, chapter_id = chapter_id, progress = progress, at = Session.nowMs() }
    -- Never switch documents from inside a dialog's callback.
    require("ui/uimanager"):nextTick(Session.wrap(function()
        Session:openChapter(novel_id, chapter_id, { from_reader = r ~= nil })
    end))
end

--[[--
Takes the "Go to latest" place for a chapter being opened: its progress
(0..100) when that chapter is the one Go asked for, else nil.
]]
function Position.takeForced(Session, novel_id, chapter_id)
    local f = Session.forced_progress
    if not f or f.novel_id ~= novel_id or f.chapter_id ~= chapter_id then return nil end
    Session.forced_progress = nil
    if Session.nowMs() - f.at > Position.FORCED_TTL_MS then return nil end
    return f.progress
end

--- The due check and the question, for the autopilot dump.
function Position.dump(Session)
    local d = Session.position_due
    return {
        enabled = Position.enabled(Session),
        due = d and { novel_id = d.novel_id, reason = d.reason, age_ms = Session.nowMs() - d.at } or nil,
        asking = Session.position_box ~= nil,
        failed_at = Session.position_failed_at,
        device_id = Session.device_id,
        device_name = Session.device_name,
    }
end

return Position
