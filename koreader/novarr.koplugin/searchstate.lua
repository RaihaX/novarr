--[[--
Discover searches in the background: the pure state logic (unit tested)
behind ui_discover.lua, which does the requests and the scheduling.

A list or search page is asked for with POST /discover/searches; the server
runs it as a job and the plugin looks at GET /discover/searches/{id} every
POLL_S seconds until it is done, failed, or MAX_S has gone by. KOReader
stays usable in between; the user can stop following (cancel).

    st = SearchState.new(now)                    phase "starting"
    SearchState.onStart(st, search, e, now)      the POST's answer:
                                                 "poll" | "done" | "fallback" | "stop"
    SearchState.onPoll(st, search, e, now)       a GET's answer: "poll" | "done" | "stop"
    SearchState.onOffline(st, now)               no network for a poll: "poll" | "stop"
    SearchState.cancel(st)                       the user stopped following
    SearchState.active(st)

Phases: starting, running, done, failed, timeout, error, cancelled.
* "fallback": the POST answered 404 or 405, an older server without
  background searches; the caller uses the blocking GET /discover/browse
  (and remembers that for the session).
* While polling, a request that gets no answer (Wi-Fi gone, server
  restarting) does not end the search: it is tried again on the next poll,
  until MAX_S. A 404 on a poll means the server forgot the search (expired,
  restarted): failed.
* Past MAX_S still running: timeout (the server itself reports a search
  still running after 5 minutes as failed).
Times are in seconds.
]]

local has_gettext, gettext = pcall(require, "gettext")
local _ = has_gettext and gettext or function(text) return text end

local SearchState = {}

SearchState.POLL_S = 3
SearchState.MAX_S = 5 * 60

function SearchState.new(now)
    return { phase = "starting", started = now, id = nil, polls = 0, errors = 0, result = nil, message = nil }
end

--- Whether the plugin is still following this search.
function SearchState.active(st)
    return st ~= nil and (st.phase == "starting" or st.phase == "running")
end

--- Whether an API error means "this server has no background searches".
function SearchState.notSupported(e)
    return type(e) == "table" and (e.kind == "not_found" or (e.kind == "invalid" and e.status == 405))
end

local function finish(st, phase)
    st.phase = phase
    return phase == "done" and "done" or "stop"
end

-- Applies a search object {id, status, message, result}. Returns "poll" | "done" | "stop".
local function apply(st, search, now)
    if type(search.id) == "string" and search.id ~= "" then st.id = search.id end
    local status = search.status
    if status == "done" then
        if type(search.result) ~= "table" then
            st.message = nil
            return finish(st, "failed")
        end
        st.result = search.result
        return finish(st, "done")
    elseif status == "failed" then
        st.message = type(search.message) == "string" and search.message ~= "" and search.message or nil
        return finish(st, "failed")
    elseif status == "running" then
        if not st.id then
            st.phase = "error"
            return "stop"
        end
        st.phase = "running"
        if now - st.started >= SearchState.MAX_S then return finish(st, "timeout") end
        return "poll"
    end
    st.message = nil
    return finish(st, "failed")
end

--[[--
The POST's answer (search object or nil, e). Returns "poll", "done" (an
answer straight away, e.g. cached), "fallback" (older server) or "stop"
(st.error holds the API error, st.phase "error").
]]
function SearchState.onStart(st, search, e, now)
    st.last = now
    if not search then
        st.error = e
        if SearchState.notSupported(e) then
            st.phase = "fallback"
            return "fallback"
        end
        st.phase = "error"
        return "stop"
    end
    return apply(st, search, now)
end

--- A poll's answer (search object or nil, e). Returns "poll", "done" or "stop".
function SearchState.onPoll(st, search, e, now)
    if not SearchState.active(st) then return "stop" end
    st.polls = st.polls + 1
    st.last = now
    if not search then
        st.error = e
        if type(e) == "table" and e.kind == "not_found" then
            st.message = _("The server lost track of this search. Try again.")
            return finish(st, "failed")
        end
        st.errors = st.errors + 1
        if now - st.started >= SearchState.MAX_S then return finish(st, "timeout") end
        return "poll"
    end
    st.errors = 0
    return apply(st, search, now)
end

--- No network for this poll (never prompts): look again later, until the limit.
function SearchState.onOffline(st, now)
    if not SearchState.active(st) then return "stop" end
    if now - st.started >= SearchState.MAX_S then return finish(st, "timeout") end
    return "poll"
end

--- The user stopped following (the search may still finish on the server; its answer is not used).
function SearchState.cancel(st)
    if SearchState.active(st) then st.phase = "cancelled" end
end

--- Seconds until the next poll (never past the deadline by more than one poll).
function SearchState.nextDelay(st, now)
    local left = st.started + SearchState.MAX_S - now
    if left <= 0 then return 0 end
    return math.min(SearchState.POLL_S, math.max(1, left))
end

--[[--
What the user is told when a followed search ends without results:
failed (the server's plain message, or a default), timeout. nil for
error (the caller words the network/API error) and cancelled.
]]
function SearchState.failureText(st)
    if st.phase == "failed" then
        return st.message or _("That source didn't answer. Try again or pick another source.")
    elseif st.phase == "timeout" then
        return _("The search took too long and was given up. Try again or pick another source.")
    end
    return nil
end

return SearchState
