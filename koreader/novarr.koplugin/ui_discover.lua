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
* Every request blocks KOReader while it runs, as everywhere in the plugin,
  behind a notice painted first. A search through a slow source can take up
  to two minutes; LuaSocket can't be interrupted mid-request, so the notice
  only says how long it may take.
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")
local T = require("ffi/util").template

local Checks = require("novarr.checks")
local Discover = require("novarr.discover")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local Screen = {}
Screen.__index = Screen

-- Remembered for the session (not persisted): where the user was.
local last = { source = nil, list = nil }

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
    if view.error then
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
        if not data then
            self.sources_error = true
            self:rebuild()
            UI.info(s:errorText(e))
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

--- Fetches the next page of the current view and shows it (on the page of its first new item).
function Screen:fetchPage()
    local s = self.session
    local view = self.view
    if not view then return end
    s:whenConnected(function()
        if self.view ~= view then return end
        local closer = UI.loading(Discover.loadingText(view, self:source()))
        local data, e = s.api:browse(Discover.nextRequest(view))
        closer()
        if self.view ~= view or self.closed then return end
        if not data then
            view.error = true
            self:rebuild()
            UI.info(Discover.browseErrorText(e) or s:errorText(e))
            return
        end
        view.error = nil
        local _added, first = Discover.addPage(view, data)
        last.source, last.list = view.source, view.list ~= "search" and view.list or last.list
        self:rebuild({ result = first })
    end, function()
        UI.info(_("No network connection."))
    end, { reason = "Novarr Discover" })
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
    else
        UI.confirm({ text = text, ok_text = _("Add to library"), cancel_text = _("Close"),
            ok = function() self:add(it) end })
    end
end

function Screen:add(it)
    local s = self.session
    s:whenConnected(function()
        local closer = UI.loading(_("Adding to your library…"))
        local data, e = s.api:addNovel(it.title, it.url)
        local kind, text = Discover.addOutcome(data, e)
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

function Screen:onClose()
    self.closed = true
end

function Screen.show(session)
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

return Screen
