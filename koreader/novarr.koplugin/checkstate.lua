--[[--
"Check for new chapters": the pure state logic (unit tested) behind
checks.lua, which does the requests and the scheduling.

A check runs on the server (scrape the chapter list, then download the new
chapters). The plugin asks for one, then looks at its state every POLL_S
seconds until it is done, failed, or MAX_S has gone by.

    st = CheckState.new(now)                 phase "starting"
    CheckState.startAction(check)            "join" (one is running) | "post"
    CheckState.onAnswer(st, check, now)      after any answer: phase, and
                                             "poll" (look again) | "stop"
    CheckState.onError(st, e, now)           a request failed: "poll" | "stop"
    CheckState.text(st)                      what the screens show

Phases: starting, running, done, failed, timeout, error.
* idle on a poll (the server forgot the check: a restart, 24 h later): the
  check is over; when it was seen running before it counts as done (the
  chapter list is what tells), otherwise as failed to start.
* A network error while polling (Wi-Fi gone, server restarting) does not end
  the check: it is tried again on the next poll, until MAX_S. Two "not found"
  (404) answers end it (the novel was removed).
* Past MAX_S while the server still says running: phase timeout; the work
  goes on on the server, the plugin just stops looking.
Times are in seconds.
]]

local has_gettext, gettext = pcall(require, "gettext")
local _ = has_gettext and gettext or function(text) return text end

local function template(text, ...)
    local args = { ... }
    return (text:gsub("%%(%d)", function(n) return tostring(args[tonumber(n)]) end))
end

local CheckState = {}

CheckState.POLL_S = 15
CheckState.MAX_S = 10 * 60

function CheckState.new(now)
    return { phase = "starting", started = now, last = nil, seen_running = false, errors = 0, new_chapters = nil,
        message = nil }
end

--- Whether the plugin is still waiting for this check.
function CheckState.active(st)
    return st ~= nil and (st.phase == "starting" or st.phase == "running")
end

--- Given the GET answer before starting: join a running check, else ask for one.
function CheckState.startAction(check)
    if type(check) == "table" and check.status == "running" then return "join" end
    return "post"
end

local function finish(st, phase)
    st.phase = phase
    return "stop"
end

--[[--
Applies a check object from the server. Returns "poll" or "stop".
opts.joined: the answer came from the GET before starting (a running check
is joined).
]]
function CheckState.onAnswer(st, check, now, opts)
    st.last = now
    st.errors = 0
    st.not_found = 0
    if type(check) ~= "table" then
        st.message = nil
        return CheckState.onError(st, { kind = "server" }, now)
    end
    if opts and opts.joined then st.joined = true end
    local status = check.status
    st.chapters_now = tonumber(check.chapters_now)
    if status == "running" then
        st.phase = "running"
        st.seen_running = true
        if now - st.started >= CheckState.MAX_S then return finish(st, "timeout") end
        return "poll"
    elseif status == "done" then
        st.new_chapters = math.max(0, math.floor(tonumber(check.new_chapters) or 0))
        return finish(st, "done")
    elseif status == "failed" then
        st.message = type(check.message) == "string" and check.message ~= "" and check.message or nil
        return finish(st, "failed")
    elseif status == "idle" then
        if st.seen_running then
            -- Finished and already forgotten by the server: the chapter list will tell.
            st.new_chapters = nil
            return finish(st, "done")
        end
        st.message = _("The server didn't start the check.")
        return finish(st, "failed")
    end
    st.message = nil
    return CheckState.onError(st, { kind = "server" }, now)
end

--[[--
A request failed (e: the API error). Returns "poll" (try again on the next
poll) or "stop". Before the check was started (phase starting) every error
stops: the user is told at once.
]]
function CheckState.onError(st, e, now)
    st.errors = (st.errors or 0) + 1
    st.error = e
    if st.phase == "starting" then
        return finish(st, "error")
    end
    if type(e) == "table" and e.kind == "not_found" then
        st.not_found = (st.not_found or 0) + 1
        if st.not_found >= 2 then return finish(st, "error") end
    end
    if now - st.started >= CheckState.MAX_S then return finish(st, "timeout") end
    return "poll"
end

--- Seconds until the next poll (never past the deadline by more than one poll).
function CheckState.nextDelay(st, now)
    local left = st.started + CheckState.MAX_S - now
    if left <= 0 then return 0 end
    return math.min(CheckState.POLL_S, math.max(1, left))
end

--[[--
The status line for screens. Returns text and kind ("busy", "new", "none",
"failed"). nil when there is no check.
]]
function CheckState.text(st)
    if not st then return nil end
    local p = st.phase
    if p == "starting" or p == "running" then
        if st.joined then return _("Checking for new chapters (already running on the server)…"), "busy" end
        return _("Checking for new chapters…"), "busy"
    elseif p == "done" then
        local n = st.new_chapters
        if n == nil then return _("The check is done."), "none" end
        if n == 0 then return _("No new chapters yet."), "none" end
        if n == 1 then return _("1 new chapter."), "new" end
        return template(_("%1 new chapters."), n), "new"
    elseif p == "failed" then
        if st.message then return template(_("The check didn't work: %1"), st.message), "failed" end
        return _("The check didn't work. Try again later."), "failed"
    elseif p == "timeout" then
        return _("The server is still checking. New chapters will show up when it's done."), "none"
    elseif p == "error" then
        return nil, "failed" -- the caller words the network error (session:errorText)
    end
    return nil
end

return CheckState
