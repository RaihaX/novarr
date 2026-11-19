--[[--
Discover: find novels on the sources Novarr scrapes and add them to the
library, from the Kindle. Reached from Home (next to Library).

* The sources and their lists come from the server (GET /discover). The
  bar under the title: Source (when there is more than one), List (popular,
  new, completed, random: what the source offers) and Search (KOReader's
  input dialog and keyboard).
* Results are a paged cover list (ui_coverlist.lua; the plain list when
  covers are off): cover, title, author, status and chapter count, and
  "✓ In library". "More…" at the end loads the next page while the server
  says there is one. Covers come from the sources' own image hosts, into
  their own capped folder (session:discoverCovers()).
* A tap shows the details: "Add to library", or "Open" for a novel already
  in it. After adding: the result is marked, the library is fetched again,
  and the server is asked to check the novel (checks.lua) so its chapters
  arrive.
* Lists, searches and "More" pages run on the server in the background
  (POST /discover/searches, searchstate.lua holds the rules): the screen
  shows "Searching…" with a Cancel row and looks at the search every 3 s
  through UIManager scheduling, so KOReader stays usable, for up to 5
  minutes. Cancel only stops following. A result that arrives after the
  user left Discover (or picked another list) is dropped. While a search
  is followed the Wi-Fi management counts Novarr as busy.
* An older server (the POST answers 404/405): the blocking GET
  /discover/browse as before, behind a notice painted first, remembered for
  the rest of the session (Session.discover_blocking).
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")
local T = require("ffi/util").template

local logger = require("logger")

local Checks = require("novarr.checks")
local Discover = require("novarr.discover")
local SearchState = require("novarr.searchstate")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local Screen = {}
Screen.__index = Screen

-- Remembered for the session (not persisted): where the user was.
local last = { source = nil, list = nil }

-- The search being followed: {screen, view, st, task} or nil (one at a time).
local follow = nil
local suspended = false

local function nowS() return math.floor(Session.nowMs() / 1000) end
local function log(text) logger.info("Novarr Discover: " .. text) end

--- Whether a background search is being followed (the Wi-Fi management counts it as busy).
function Screen.following()
    return follow ~= nil and SearchState.active(follow.st)
end

function Screen:source()
    return Discover.find(self.sources, self.source_id)
end

function Screen:items()
    local items = {}
    local tb = self.cover_list or nil
    local src = self:source()
    self.index_of = {}
    if not self.sources then
        table.insert(items, { text = self.sources_error and _("Couldn't load the sources. Tap to try again.") or _("Loading…"),
            dim = true, callback = function() if self.sources_error then self:load() end end })
        return items
    end
    if #self.sources == 0 then
        table.insert(items, { text = _("The server offers no sources to discover novels from."), dim = true,
            select_enabled = false, callback = function() end })
        return items
    end
    if #self.sources > 1 then
        table.insert(items, { toolbar = tb, text = T(_("Source: %1"), src and src.name or "?"),
            callback = function() self:askSource() end })
    end
    local lists = Discover.browseLists(src)
    if #lists > 0 then
        local name = self.view and self.view.list ~= "search" and Discover.listName(src, self.view.list) or nil
        table.insert(items, { toolbar = tb, text = name and T(_("List: %1"), name) or _("Lists…"),
            callback = function() self:askList() end })
    end
    if Discover.hasSearch(src) then
        local q = self.view and self.view.list == "search" and self.view.query
        table.insert(items, { toolbar = tb, text = q and T(_("Search: %1"), q) or _("Search…"),
            callback = function() self:askSearch() end })
    end
    local view = self.view
    if not view then
        table.insert(items, { text = T(_("Search %1 to find novels."), src and src.name or ""), dim = true,
            callback = function() self:askSearch() end })
        return items
    end
    for i, it in ipairs(view.items) do
        table.insert(items, {
            text = it.title,
            mandatory = Discover.mandatory(it),
            cover = self.cover_list and { url = it.cover_url, name = it.title, author = it.author,
                info = Discover.infoLine(it) } or nil,
            discover_url = it.url,
            in_library = it.in_library,
            callback = function() self:showItem(it) end,
        })
        self.index_of[i] = #items
    end
    if view.search and SearchState.active(view.search) then
        table.insert(items, { text = Discover.statusText(view, src), dim = true, select_enabled = false,
            searching = true, callback = function() end })
        table.insert(items, { text = _("Cancel"), callback = function() self:cancelSearch() end })
    elseif view.stopped then
        table.insert(items, { text = _("Stopped. Tap to try again."), dim = true,
            callback = function() self:fetchPage() end })
    elseif view.error then
        table.insert(items, { text = _("Couldn't load this list. Tap to try again."), dim = true,
            callback = function() self:fetchPage() end })
    elseif view.loaded and #view.items == 0 then
        table.insert(items, { text = view.list == "search" and _("Nothing found. Try other words or another source.")
            or _("This list is empty."), dim = true, select_enabled = false, callback = function() end })
    elseif view.has_more then
        table.insert(items, { text = _("More…"), bold = true, callback = function() self:fetchPage() end })
    end
    return items
end

--[[--
Rebuilds the rows. opts.top: show the first page; opts.result: show the page
of the view's result with this index (the first one a "More" brought).
]]
function Screen:rebuild(opts)
    if not self.menu then return end
    local items = self:items()
    local current
    if opts and opts.result then current = self.index_of[opts.result] end
    if opts and opts.top then current = 1 end
    if current then
        -- Straight to that page (UI.setItems would also bold the row).
        self.menu:switchItemTable(nil, items, current)
    else
        UI.setItems(self.menu, items)
    end
end

-- ---------------------------------------------------------------- loading

--- Loads the sources (GET /discover), then the first list of the remembered or default source.
function Screen:load()
    local s = self.session
    s:whenConnected(function()
        local closer = UI.loading(_("Loading Discover…"))
        local data, e = s:fetchInto("discover", function() return s.api:discover() end, false)
        closer()
        -- A failed refresh hands back the saved copy: never shown as if it were live.
        if e or not data then
            self.sources, self.view = nil, nil
            self.sources_error = true
            self:rebuild()
            UI.info(Discover.browseErrorText(e) or s:errorText(e))
            return
        end
        local sources, default = Discover.sources(data)
        self.sources, self.sources_error = sources, nil
        local src = Discover.find(sources, last.source) or default
        self.source_id = src and src.id
        local list = src and Discover.initialList(src, last.list)
        self.view = list and Discover.newView(src.id, list) or nil
        self:rebuild()
        if self.view then self:fetchPage() end
    end, function()
        self.sources_error = true
        self:rebuild()
        UI.info(_("No network connection: Discover needs the Novarr server."))
    end, { reason = "Novarr Discover" })
end

-- A page arrived (blocking or background): shown on the page of its first new item.
function Screen:showPage(view, data)
    view.error, view.stopped = nil, nil
    local _added, first = Discover.addPage(view, data)
    last.source, last.list = view.source, view.list ~= "search" and view.list or last.list
    self:rebuild({ result = first })
end

-- A request failed (or a followed search ended without a result): the retry row and a plain message.
function Screen:showFailure(view, text)
    view.error = true
    self:rebuild()
    UI.info(text)
end

--- Fetches the next page of the current view: in the background, or blocking on older servers.
function Screen:fetchPage()
    local s = self.session
    local view = self.view
    if not view or (view.search and SearchState.active(view.search)) then return end
    s:whenConnected(function()
        if self.view ~= view or self.closed then return end
        if Session.discover_blocking then
            self:fetchBlocking(view)
        else
            self:startSearch(view)
        end
    end, function()
        UI.info(_("No network connection."))
    end, { reason = "Novarr Discover" })
end

--- The blocking GET /discover/browse (servers without background searches).
function Screen:fetchBlocking(view)
    local s = self.session
    local closer = UI.loading(Discover.loadingText(view, self:source()))
    local data, e = s.api:browse(Discover.nextRequest(view))
    closer()
    if self.view ~= view or self.closed then return end
    if not data then
        self:showFailure(view, Discover.browseErrorText(e) or s:errorText(e))
        return
    end
    self:showPage(view, data)
end

local function stopFollowing()
    if follow and follow.task then UIManager:unschedule(follow.task) end
    if follow then SearchState.cancel(follow.st) end
    follow = nil
end

--- POST /discover/searches, then follow it (see handle()).
function Screen:startSearch(view)
    local s = self.session
    stopFollowing()
    local st = SearchState.new(nowS())
    view.search, view.error, view.stopped = st, nil, nil
    follow = { screen = self, view = view, st = st }
    self:rebuild()
    UIManager:forceRePaint() -- "Searching…" shows while the request is sent
    local req = Discover.nextRequest(view)
    local search, e = s.api:searchStart(req)
    if follow == nil or follow.st ~= st then return end -- cancelled meanwhile
    local action = SearchState.onStart(st, search, e, nowS())
    log(string.format("%s %s page %d: %s (%s)", req.type, req.q and ("\"" .. req.q .. "\"") or req.source, req.page,
        st.phase, st.id or (e and e.kind) or "-"))
    self:handle(view, st, action)
end

-- What a search answer leads to.
function Screen:handle(view, st, action)
    local s = self.session
    if action == "poll" then
        if not follow.task then
            follow.task = function() Session.guard(Screen.poll) end
        end
        UIManager:unschedule(follow.task)
        if not suspended then UIManager:scheduleIn(SearchState.nextDelay(st, nowS()), follow.task) end
        return
    end
    follow = nil
    view.search = nil
    if self.view ~= view or self.closed then
        log("result dropped (Discover left or another list chosen)")
        return
    end
    if action == "fallback" then
        log("the server has no background searches: blocking requests for this session")
        Session.discover_blocking = true
        self:fetchBlocking(view)
    elseif action == "done" then
        log(string.format("done after %d poll(s)", st.polls))
        self:showPage(view, st.result)
    elseif st.phase ~= "cancelled" then
        log("ended: " .. st.phase .. (st.message and (" (" .. st.message .. ")") or ""))
        self:showFailure(view, SearchState.failureText(st) or Discover.browseErrorText(st.error) or s:errorText(st.error))
    end
end

--- One look at the followed search (scheduled; never prompts for Wi-Fi).
function Screen.poll()
    local f = follow
    if not f or suspended then return end
    local self, view, st = f.screen, f.view, f.st
    if self.closed or self.view ~= view then
        SearchState.cancel(st)
        follow = nil
        log("result dropped (Discover left or another list chosen)")
        return
    end
    local s = self.session
    local action
    if not s:isConnected() then
        action = SearchState.onOffline(st, nowS())
    else
        local search, e = s.api:searchStatus(st.id)
        if follow ~= f then return end
        action = SearchState.onPoll(st, search, e, nowS())
        if not search then log(string.format("poll failed (%s), %s", tostring(e and e.kind), action)) end
    end
    self:handle(view, st, action)
end

--- The Cancel row: stop following (the server may still finish it; its answer is not used).
function Screen:cancelSearch()
    local view = self.view
    if not view or not view.search then return end
    log("cancelled after " .. view.search.polls .. " poll(s)")
    stopFollowing()
    view.search = nil
    -- Pages already shown stay (with More…); nothing shown yet: a row to try again.
    if not view.loaded then view.stopped = true end
    self:rebuild()
end

--- KOReader went to sleep: no polls meanwhile.
function Screen.onSuspend()
    suspended = true
    if follow and follow.task then UIManager:unschedule(follow.task) end
end

--- Woken up: look again soon.
function Screen.onResume()
    suspended = false
    if follow and follow.task and SearchState.active(follow.st) then
        UIManager:unschedule(follow.task)
        UIManager:scheduleIn(1, follow.task)
    end
end

-- ---------------------------------------------------------------- choices

function Screen:askSource()
    local buttons = {}
    for _i, src in ipairs(self.sources or {}) do
        local label = src.name
        if src.id == self.source_id then label = UI.MARK_READ .. " " .. label end
        table.insert(buttons, { text = label, callback = function()
            if src.id == self.source_id then return end
            local wanted = self.view and self.view.list ~= "search" and self.view.list or last.list
            self.source_id = src.id
            last.source = src.id
            local list = Discover.initialList(src, wanted)
            self.view = list and Discover.newView(src.id, list) or nil
            self:rebuild({ top = true })
            if self.view then self:fetchPage() end
        end })
    end
    UI.choice(_("Source"), buttons)
end

function Screen:askList()
    local src = self:source()
    local buttons = {}
    for _i, l in ipairs(Discover.browseLists(src)) do
        local label = l.name
        if self.view and self.view.list == l.id then label = UI.MARK_READ .. " " .. label end
        table.insert(buttons, { text = label, callback = function()
            -- "Random" draws a new list each time: picking it again is "another draw".
            if self.view and self.view.list == l.id and l.id ~= "random" then return end
            self.view = Discover.newView(src.id, l.id)
            self:rebuild({ top = true })
            self:fetchPage()
        end })
    end
    UI.choice(_("List"), buttons)
end

function Screen:askSearch()
    local src = self:source()
    if not src or not Discover.hasSearch(src) then return end
    UI.input({
        title = T(_("Search %1"), src.name),
        value = self.view and self.view.list == "search" and self.view.query or "",
        hint = _("Title or author"),
        ok_text = _("Search"),
        callback = function(text)
            local q = Discover.cleanQuery(text)
            if not q then return true end -- nothing typed: keep the dialog open
            self.view = Discover.newView(src.id, "search", q)
            self:rebuild({ top = true })
            -- After the dialog and keyboard have closed.
            UIManager:nextTick(Session.wrap(function() self:fetchPage() end))
        end,
    })
end

-- ---------------------------------------------------------------- one result

function Screen:showItem(it)
    local s = self.session
    local src = self:source()
    local text = Discover.detailsText(it, src and src.name)
    if it.in_library and it.novel_id then
        UI.confirm({ text = text, ok_text = _("Open"), cancel_text = _("Close"),
            ok = function() s:showNovel(it.novel_id) end })
    elseif it.in_library then
        UI.info(text)
    elseif not s:allows("add_novel") then
        UI.info(text) -- a reader account can't add novels (auth.lua)
    else
        UI.confirm({ text = text, ok_text = _("Add to library"), cancel_text = _("Close"),
            ok = function() self:add(it) end })
    end
end

--[[--
Adds a result to the library. force: "Add anyway" after the server said a
novel of that title is already there (409): sent with force=1.
]]
function Screen:add(it, force)
    local s = self.session
    s:whenConnected(function()
        local closer = UI.loading(_("Adding to your library…"))
        local data, e = s.api:addNovel(it.title, it.url, force)
        local kind, text = Discover.addOutcome(data, e, it.title)
        if kind == "duplicate" then
            closer()
            self:askDuplicate(it, e.duplicate, text)
            return
        end
        local novel_id = data and data.novel and data.novel.id
        if novel_id then
            Discover.markInLibrary(self.view, it.url, novel_id)
            -- Home and Library show it from now on.
            s:fetchInto("novels", function(etag) return s.api:novels(etag) end, true)
        end
        if kind == "added" and novel_id then
            -- Its chapter list is fetched by a check on the server.
            Checks.start(novel_id)
        end
        closer()
        UI.info(text or s:errorText(e))
        s:refreshScreens()
    end, function()
        UI.info(_("No network connection: the novel can't be added now."))
    end, { reason = "Novarr Discover" })
end

--- "<title> is already in your library.": Open it / Add anyway / Cancel.
function Screen:askDuplicate(it, dup, text)
    local s = self.session
    local buttons = {}
    if dup and dup.id then
        table.insert(buttons, { text = _("Open it"), callback = function()
            -- Home and Library may not have it yet: the Novel screen refreshes itself.
            s:showNovel(dup.id)
        end })
    end
    table.insert(buttons, { text = _("Add anyway"), callback = function() self:add(it, true) end })
    UI.choice(text, buttons)
end

function Screen:onClose()
    self.closed = true
    if follow and follow.screen == self then
        log("Discover closed while following a search: stopped")
        stopFollowing()
    end
end

function Screen.show(session)
    -- Discover is for adding novels: not for a reader account (auth.lua; Home has no button for it then).
    if not session:allows("discover") then
        UI.info(_("Your Novarr account can read and sync, but not add novels."))
        return nil
    end
    local self = setmetatable({ session = session, kind = "discover", cover_list = session:showCovers() }, Screen)
    self.menu = UI.showMenu(session, {
        title = _("Discover"),
        cover_list = self.cover_list,
        covers = self.cover_list and session:discoverCovers() or nil,
        items = self:items(),
        screen = self,
    })
    UIManager:nextTick(Session.wrap(function() self:load() end))
    return self
end

--- The screen's state for the autopilot dump.
function Screen.dumpOf(screen)
    local v = screen.view
    return {
        source = screen.source_id,
        sources = screen.sources and #screen.sources or nil,
        list = v and v.list, query = v and v.query, page = v and v.page, has_more = v and v.has_more,
        loaded = v and v.loaded, error = v and v.error or nil, count = v and #v.items or 0,
    }
end

--- The background search state for the autopilot dump (its own section, round 5).
function Screen.searchDumpOf(screen)
    local v = screen.view
    return {
        following = Screen.following(),
        blocking = Session.discover_blocking or false,
        stopped = v and v.stopped or false,
        search = v and v.search and { phase = v.search.phase, id = v.search.id, polls = v.search.polls } or nil,
    }
end

return Screen
