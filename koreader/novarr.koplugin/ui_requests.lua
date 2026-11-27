--[[--
Requests (1.13.0): "Request a book / web novel / manga" and "My requests".

Request: the medium (Books first), a search box (title, author or ISBN;
a pasted link is no use on a Kindle), then the server searches every
source of that medium in the background while a small dialog waits
(polled every 3 s, cancellable). The results: Request (with a note if
wanted), "Me too" (someone asked already: follow their request), or "In
the library: open it". My requests: each with its status; cancel a
pending one (or leave one joined).

Every step is an explicit action: it asks for the network like Sync.
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")
local T = require("ffi/util").template

local Requests = require("novarr.requests")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local UIRequests = {}

local MEDIUM_NAMES = {
    book = _("Book"),
    novel = _("Web novel"),
    manga = _("Manga"),
}

local MEDIUM_TITLES = {
    book = _("Request a book"),
    novel = _("Request a web novel"),
    manga = _("Request a manga"),
}

-- ---------------------------------------------------------------- start

--- Asks the medium, then what to search for.
function UIRequests.start(session)
    local buttons = {}
    for _i, m in ipairs(Requests.MEDIA) do
        table.insert(buttons, { text = MEDIUM_NAMES[m], callback = function() UIRequests.ask(session, m) end })
    end
    UI.choice(_("What would you like to request?"), buttons)
end

UIRequests.last_query = {}

function UIRequests.ask(session, medium)
    UI.input({
        title = MEDIUM_TITLES[medium],
        value = UIRequests.last_query[medium] or "",
        hint = medium == "book" and _("Title, author or ISBN") or _("Title or author"),
        ok_text = _("Search"),
        callback = function(text)
            local q = Requests.cleanQuery(text)
            if not q then
                UI.notify(_("Type a title or author (up to 100 characters)."))
                return true
            end
            UIRequests.last_query[medium] = q
            UIManager:nextTick(Session.wrap(function() UIRequests.search(session, medium, q) end))
        end,
    })
end

-- ---------------------------------------------------------------- search

function UIRequests.search(session, medium, q)
    session:whenConnected(function()
        local closer = UI.loading(_("Starting the search…"))
        local search, e = session.api:requestSearchStart(q, medium)
        closer()
        if not search then
            if e and e.kind == "not_found" then
                UI.info(_("This Novarr server doesn't take requests (they may be turned off)."))
            else
                UI.info(session:errorText(e))
            end
            return
        end
        if search.status == "done" then return UIRequests.showResults(session, search) end
        if search.status == "failed" then
            UI.info(search.message or _("The search failed. Try again later."))
            return
        end
        UIRequests.wait(session, search.id, medium, q)
    end, function()
        UI.info(_("No network connection: requests can't be searched now."))
    end)
end

function UIRequests.wait(session, id, medium, q)
    local dialog
    local poll
    poll = Requests.Poll.new(id, {
        get = function(sid) return session.api:requestSearchStatus(sid) end,
        schedule = function(secs, fn) UIManager:scheduleIn(secs, fn) end,
        unschedule = function(fn) UIManager:unschedule(fn) end,
        now = os.time,
        on_done = function(search)
            if dialog then UIManager:close(dialog) dialog = nil end
            UIRequests.showResults(session, search)
        end,
        on_finish = function(kind, payload)
            if dialog then UIManager:close(dialog) dialog = nil end
            if kind == "failed" then
                UI.info(payload and payload.message or _("The search failed. Try again later."))
            elseif kind == "timeout" then
                UI.info(_("The search is taking a long time. Try again in a few minutes."))
            elseif kind == "cancelled" then
                UI.notify(_("Search stopped."))
            elseif payload and payload.kind == "not_found" then
                UI.info(payload.message or _("This search has expired. Search again."))
            else
                UI.info(session:errorText(payload))
            end
        end,
    })
    poll.poll = Session.wrap(poll.poll)
    dialog = require("novarr.ui_progress"):new{
        title = MEDIUM_TITLES[medium],
        text = T(_("Searching every source for “%1”…"), q),
        cancelling_text = _("Stopping…"),
        cancel_callback = function() poll:cancel() end,
    }
    UIManager:show(dialog)
    UIRequests.current_poll = poll
    poll:start()
end

-- ---------------------------------------------------------------- results

local ACTION_MARKS = {
    open = _("In library"),
    mine = _("Requested"),
    me_too = _("Me too?"),
    request = _("Request"),
}

local Results = {}
Results.__index = Results

function Results:items()
    local items = {}
    local results = self.search.results or {}
    for _i, r in ipairs(results) do
        local line = Requests.resultLine(r)
        table.insert(items, {
            text = (r.title or "?") .. (line ~= "" and ("  ·  " .. line) or ""),
            mandatory = ACTION_MARKS[Requests.resultAction(r)],
            callback = function() self:choose(r) end,
        })
    end
    if #results == 0 then
        table.insert(items, { text = _("Nothing found. Try other words, or the author's name."), dim = true,
            callback = function() end })
    end
    local skipped = self.search.skipped_sources
    if type(skipped) == "table" and #skipped > 0 then
        table.insert(items, { text = T(_("%1 source(s) couldn't be searched just now."), #skipped), dim = true,
            select_enabled = false, callback = function() end })
    end
    table.insert(items, { text = _("Search again…"), callback = function()
        UIRequests.ask(self.session, self.search.medium or "book")
    end })
    return items
end

function Results:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

function Results:choose(r)
    local s = self.session
    local action = Requests.resultAction(r)
    local buttons = {}
    if action == "open" then
        local id = tonumber(r.novel_id)
        table.insert(buttons, { text = _("In the library: open it"), callback = function() s:showNovel(id) end })
    elseif action == "mine" then
        table.insert(buttons, { text = _("You asked for this already: see My requests"), callback = function()
            UIRequests.showMine(s)
        end })
    elseif action == "me_too" then
        table.insert(buttons, { text = _("Me too (someone asked already)"), callback = function() self:request(r) end })
    else
        table.insert(buttons, { text = _("Request it"), callback = function() self:request(r) end })
        table.insert(buttons, { text = _("Request it with a note…"), callback = function()
            UI.input({
                title = _("A note for the owner"),
                hint = _("Which edition, translation…"),
                ok_text = _("Request"),
                callback = function(text)
                    UIManager:nextTick(Session.wrap(function() self:request(r, text) end))
                end,
            })
        end })
    end
    if type(r.description) == "string" and r.description ~= "" then
        table.insert(buttons, { text = _("About"), callback = function()
            local line = Requests.resultLine(r)
            UI.textViewer(r.title or "", (line ~= "" and (line .. "\n\n") or "") .. r.description)
        end })
    end
    UI.choice(r.title or "", buttons)
end

function Results:request(r, note)
    local s = self.session
    s:whenConnected(function()
        local closer = UI.loading(_("Sending the request…"))
        local data, e = s.api:requestCreate(self.search.id, r.key, note)
        closer()
        if not data then
            if e and e.kind == "not_found" then
                UI.info(e.message or _("This search has expired. Search again."))
            else
                UI.info(s:errorText(e))
            end
            return
        end
        r.requested = { status = data.request.status or "pending", by_me = true }
        self:rebuild()
        if data.joined then
            UI.info(T(_("Someone asked for %1 already. You'll hear when it's ready too."), r.title or ""))
        else
            UI.info(T(_("Requested %1. You'll hear when it's ready to read."), r.title or ""))
        end
    end, function()
        UI.info(_("No network connection: the request can't be sent now."))
    end)
end

function UIRequests.showResults(session, search)
    local self = setmetatable({ session = session, kind = "request_results", search = search }, Results)
    local title = T(_("Results for “%1”"), search.query or "")
    self.menu = UI.showMenu(session, { title = title, items = self:items(), screen = self, multi_line = true })
    return self
end

-- ---------------------------------------------------------------- my requests

local Mine = {}
Mine.__index = Mine

function Mine:items()
    local s = self.session
    local data, fetched_at = s:cached("requests_mine")
    local items = {}
    if s.last_errors.requests_mine then table.insert(items, UI.offlineRow(fetched_at)) end
    table.insert(items, { text = _("Request something new…"), callback = function() UIRequests.start(s) end })
    local list = data and data.requests or {}
    for _i, r in ipairs(list) do
        local medium = MEDIUM_NAMES[r.medium] or ""
        table.insert(items, {
            text = (r.title or "?") .. (medium ~= "" and ("  ·  " .. medium) or ""),
            mandatory = Requests.canOpen(r) and UI.MARK_NEW .. " " .. _("Ready") or nil,
            dim = r.status == "declined",
            callback = function() self:choose(r) end,
        })
        table.insert(items, { text = "    " .. Requests.statusText(r, _), dim = true, select_enabled = false,
            callback = function() end })
    end
    if data and #list == 0 then
        table.insert(items, { text = _("You haven't requested anything yet."), dim = true, callback = function() end })
    elseif not data then
        table.insert(items, { text = _("Your requests haven't been loaded yet: connect to load them."), dim = true,
            callback = function() end })
    end
    return items
end

function Mine:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

function Mine:refresh()
    local s = self.session
    s:whenOnline(function()
        local closer = UI.loading(_("Loading…"))
        local _d, e = s:fetchInto("requests_mine", function() return s.api:requests() end, false)
        closer()
        if e and e.kind ~= "offline" then UI.info(s:errorText(e)) end
        self:rebuild()
    end, function()
        s.last_errors.requests_mine = s.last_errors.requests_mine or { time = Session.nowMs(), kind = "offline" }
        self:rebuild()
    end)
end

function Mine:choose(r)
    local s = self.session
    local buttons = {}
    if Requests.canOpen(r) then
        local id = tonumber(r.novel_id)
        table.insert(buttons, { text = _("Open it"), callback = function() s:showNovel(id) end })
    end
    if r.can_cancel then
        local follower = r.mine == "follower"
        table.insert(buttons, {
            text = follower and _("Leave this request (Me too)") or _("Cancel this request"),
            callback = function()
                UI.confirm({
                    text = T(follower and _("Stop following the request for %1?") or _("Cancel your request for %1?"), r.title or ""),
                    ok_text = follower and _("Leave") or _("Cancel request"),
                    cancel_text = _("Keep it"),
                    ok = function() self:cancel(r) end,
                })
            end,
        })
    end
    local lines = { Requests.statusText(r, _) }
    if type(r.author) == "string" and r.author ~= "" then table.insert(lines, 1, r.author) end
    if type(r.note) == "string" and r.note ~= "" then table.insert(lines, T(_("Your note: %1"), r.note)) end
    if (tonumber(r.also_wanted_by) or 0) > 0 then
        table.insert(lines, T(_("%1 other reader(s) want it too."), r.also_wanted_by))
    end
    table.insert(buttons, { text = _("Details"), callback = function()
        UI.textViewer(r.title or "", table.concat(lines, "\n") .. (r.description and ("\n\n" .. r.description) or ""))
    end })
    UI.choice((r.title or "") .. "\n" .. Requests.statusText(r, _), buttons)
end

function Mine:cancel(r)
    local s = self.session
    s:whenConnected(function()
        local closer = UI.loading(_("Saving…"))
        local data, e = s.api:requestCancel(r.id)
        closer()
        if not data then
            UI.info(s:errorText(e))
            return
        end
        UI.notify(_("Request cancelled."))
        -- Read again (the list's cache is the server's answer).
        local _d, e2 = s:fetchInto("requests_mine", function() return s.api:requests() end, false)
        if e2 and e2.kind ~= "offline" then UI.info(s:errorText(e2)) end
        self:rebuild()
    end, function()
        UI.info(_("No network connection: the request can't be cancelled now."))
    end)
end

function UIRequests.showMine(session)
    local self = setmetatable({ session = session, kind = "requests" }, Mine)
    self.menu = UI.showMenu(session, { title = _("My requests"), items = self:items(), screen = self, multi_line = true })
    UIManager:nextTick(Session.wrap(function() self:refresh() end))
    return self
end

return UIRequests
