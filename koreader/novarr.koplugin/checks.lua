--[[--
"Check for new chapters" (the Novel screen and the caught-up page): asks the
server to check a novel's source (POST /novels/{id}/check, or joins the
check already running there), then looks at it every 15 s through
UIManager:scheduleIn, so KOReader stays usable in between and the user can
leave the screen. The rules (what each answer means, when to stop) are in
checkstate.lua.

* One check per novel at a time: asking again while one runs joins it.
* Polls only happen while connected and never ask to turn Wi-Fi on; a
  running check counts as busy for the Wi-Fi management (wifi.lua), so
  Novarr does not switch Wi-Fi off in the middle of one.
* KOReader going to sleep stops the polls; waking up looks again at once.
* When a check ends: the novel's chapter list and the library are fetched
  again (so the new chapters appear and the reader's prefetch picks them
  up), the open screens are rebuilt, and listeners (the caught-up page) are
  told; without a listener for that novel the result is a brief notice.
]]

local UIManager = require("ui/uimanager")
local logger = require("logger")
local _ = require("gettext")

local CheckState = require("novarr.checkstate")

local Checks = {
    states = {},     -- novel_id -> CheckState state (the last one, also when over)
    tasks = {},      -- novel_id -> scheduled poll function
    listeners = {},  -- list of {novel_id, fn(st)}
    suspended = false,
}

local function S() return require("novarr.session") end
local function nowS() return math.floor(S().nowMs() / 1000) end
local function log(text) logger.info("Novarr check: " .. text) end

--- The state of a novel's last check (nil when none in this session).
function Checks.state(novel_id)
    return Checks.states[novel_id]
end

--- Whether any check is being followed (the Wi-Fi management counts it as busy).
function Checks.anyActive()
    for _id, st in pairs(Checks.states) do
        if CheckState.active(st) then return true end
    end
    return false
end

function Checks.addListener(novel_id, fn)
    local entry = { novel_id = novel_id, fn = fn }
    table.insert(Checks.listeners, entry)
    return entry
end

function Checks.removeListener(entry)
    for i = #Checks.listeners, 1, -1 do
        if Checks.listeners[i] == entry then table.remove(Checks.listeners, i) end
    end
end

local function hasListener(novel_id)
    for _i, l in ipairs(Checks.listeners) do
        if l.novel_id == novel_id then return true end
    end
    return false
end

local function tell(novel_id)
    local st = Checks.states[novel_id]
    for _i, l in ipairs(Checks.listeners) do
        if l.novel_id == novel_id then S().guard(l.fn, st) end
    end
    S():refreshScreens()
end

--- The status text for a novel's check (what the screens show), or nil.
function Checks.text(novel_id)
    local st = Checks.states[novel_id]
    if not st then return nil end
    local text, kind = CheckState.text(st)
    if not text and st.phase == "error" then
        text = S():errorText(st.error)
    end
    return text, kind
end

-- ---------------------------------------------------------------- the end of a check

local function finished(novel_id)
    local Session = S()
    local st = Checks.states[novel_id]
    Checks.tasks[novel_id] = nil
    log(string.format("novel %d: %s%s", novel_id, st.phase,
        st.new_chapters and (", " .. st.new_chapters .. " new") or "") .. (st.message and (" (" .. st.message .. ")") or ""))
    if st.phase == "done" or st.phase == "timeout" then
        -- Fetch what the server has now (also after a timeout: the list may have grown already).
        if Session:isConnected() then
            local _cl, e = Session:refreshChapterList(novel_id)
            if e then log("novel " .. novel_id .. ": chapter list not refreshed: " .. tostring(e.kind)) end
            Session:fetchInto("novels", function(etag) return Session.api:novels(etag) end, true)
            Session:fetchInto("novel_" .. novel_id, function() return Session.api:novel(novel_id) end, false)
        end
        -- Reading this novel: the background run fetches the new chapters ahead.
        local plugin = Session.reader
        local r = plugin and plugin.reading
        if r and r.novel_id == novel_id and plugin.background_task then
            Session.guard(require("novarr.reading").chapterInfo, plugin)
            UIManager:unschedule(plugin.background_task)
            UIManager:scheduleIn(1, plugin.background_task)
        end
    end
    local text = Checks.text(novel_id)
    if not hasListener(novel_id) and text then
        local summary = Session:novelSummary(novel_id)
        require("novarr.ui_common").notify(summary and summary.name and (summary.name .. ": " .. text) or text)
    end
    tell(novel_id)
    local W = package.loaded["novarr.wifi"]
    if type(W) == "table" then Session.guard(W.evaluate, "check finished") end
end

-- ---------------------------------------------------------------- polling

local schedulePoll

local function poll(novel_id)
    local Session = S()
    local st = Checks.states[novel_id]
    if not CheckState.active(st) or Checks.suspended then return end
    if not Session:isConnected() then
        -- No network now (never prompts): look again later, until the deadline.
        if nowS() - st.started >= CheckState.MAX_S then
            st.phase = "timeout"
            finished(novel_id)
            return
        end
        schedulePoll(novel_id)
        return
    end
    local before = st.phase
    local check, e = Session.api:checkStatus(novel_id)
    local action
    if check then
        action = CheckState.onAnswer(st, check, nowS())
    else
        action = CheckState.onError(st, e, nowS())
        log(string.format("novel %d: poll failed (%s), %s", novel_id, tostring(e and e.kind), action))
    end
    if action == "poll" then
        schedulePoll(novel_id)
        -- Screens are redrawn only when what they show changes (e-ink).
        if st.phase ~= before then tell(novel_id) end
    else
        finished(novel_id)
    end
end

function schedulePoll(novel_id, delay)
    local task = Checks.tasks[novel_id]
    if not task then
        task = function() S().guard(poll, novel_id) end
        Checks.tasks[novel_id] = task
    end
    UIManager:unschedule(task)
    local st = Checks.states[novel_id]
    UIManager:scheduleIn(delay or CheckState.nextDelay(st, nowS()), task)
end

-- ---------------------------------------------------------------- start

--[[--
Starts (or joins) a check for a novel; must be called when connected (the
screens go through session:whenConnected). A check of this novel already
followed is joined: nothing is sent. Returns the state.
]]
function Checks.start(novel_id)
    local Session = S()
    local st = Checks.states[novel_id]
    if CheckState.active(st) then
        log("novel " .. novel_id .. ": already checking, joined")
        tell(novel_id)
        return st
    end
    st = CheckState.new(nowS())
    Checks.states[novel_id] = st
    -- One already running on the server (from the web page, another device): join it.
    local current, e = Session.api:checkStatus(novel_id)
    local action
    if current and CheckState.startAction(current) == "join" then
        log("novel " .. novel_id .. ": a check is already running on the server, joined")
        action = CheckState.onAnswer(st, current, nowS(), { joined = true })
    elseif not current and e and e.kind ~= "server" then
        -- Not found, offline...: no point asking to start one.
        action = CheckState.onError(st, e, nowS())
    else
        local check, perr = Session.api:checkStart(novel_id)
        if check then
            log("novel " .. novel_id .. ": check asked for, server says " .. tostring(check.status))
            action = CheckState.onAnswer(st, check, nowS())
        else
            action = CheckState.onError(st, perr, nowS())
        end
    end
    if action == "poll" then
        schedulePoll(novel_id)
        tell(novel_id)
    else
        finished(novel_id)
    end
    return st
end

--[[--
The user tapped "Check for new chapters": with the Wi-Fi prompt (or Novarr
switching Wi-Fi back on) when not connected. on_started(st) runs after the
first answer.
]]
function Checks.ask(novel_id, on_started)
    local Session = S()
    local UI = require("novarr.ui_common")
    Session:whenConnected(function()
        local closer = UI.loading(_("Asking the server to check for new chapters…"))
        local st = Checks.start(novel_id)
        closer()
        if on_started then on_started(st) end
    end, function()
        UI.info(_("No network connection: the server can't be asked to check now."))
    end, { reason = "check for new chapters" })
end

-- ---------------------------------------------------------------- sleep

function Checks.onSuspend()
    Checks.suspended = true
    for _id, task in pairs(Checks.tasks) do UIManager:unschedule(task) end
end

--- Woken up: look at every followed check again now (a long sleep may have ended them).
function Checks.onResume()
    Checks.suspended = false
    for novel_id, st in pairs(Checks.states) do
        if CheckState.active(st) then
            log("novel " .. novel_id .. ": woken up, looking again")
            schedulePoll(novel_id, 1)
        end
    end
end

-- ---------------------------------------------------------------- debug

function Checks.dump()
    local out = {}
    for novel_id, st in pairs(Checks.states) do
        local text, kind = Checks.text(novel_id)
        out[tostring(novel_id)] = { phase = st.phase, new_chapters = st.new_chapters, message = st.message,
            joined = st.joined or false, text = text, kind = kind, started = st.started, last = st.last }
    end
    return out
end

--- Forgets every check (autopilot, between scenarios).
function Checks.reset()
    for _id, task in pairs(Checks.tasks) do UIManager:unschedule(task) end
    Checks.states, Checks.tasks, Checks.suspended = {}, {}, false
end

return Checks
