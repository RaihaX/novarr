--[[--
"My lists" (1.13.0): Favourites, Want to read and the reader's own
reading lists, each opening a list of its series that reads like the
Library (tap reads, hold opens the series). Also the Novel screen's
actions: Want to read / Favourite on and off, and "Add to list…".

Opening a screen never asks for Wi-Fi: the saved lists show at once and
are refreshed when already connected (cache keys "lists", "list_wtr",
"list_fav"). Changing a list is an explicit action: it asks for the network
like Sync does, and nothing is queued offline.
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")
local T = require("ffi/util").template

local Collections = require("novarr.collections")
local Model = require("novarr.model")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local Lists = {}

local function builtinName(kind)
    if kind == "favourites" then return _("Favourites") end
    if kind == "want_to_read" then return _("Want to read") end
    return nil
end
Lists.builtinName = builtinName

local function rowName(row)
    return builtinName(row.kind) or row.name or _("List")
end

-- ---------------------------------------------------------------- My lists

local ListsScreen = {}
ListsScreen.__index = ListsScreen

function ListsScreen:items()
    local s = self.session
    local data, fetched_at = s:cached("lists")
    local items = {}
    if s.last_errors.lists then table.insert(items, UI.offlineRow(fetched_at)) end
    local rows = Collections.listRows(data and data.lists or {}, {
        favourites = s:feature("favourites"), want_to_read = s:feature("want_to_read"),
    })
    for _i, row in ipairs(rows) do
        table.insert(items, {
            text = rowName(row),
            bold = row.kind ~= nil,
            mandatory = tostring(row.count or 0),
            callback = function() Lists.showList(s, row) end,
        })
    end
    if not data and #rows == 0 then
        table.insert(items, { text = _("Your lists haven't been loaded yet: connect to load them."), dim = true,
            select_enabled = false, callback = function() end })
    elseif #rows <= 2 then
        table.insert(items, { text = _("Make your own lists on the website or in the Novarr app; they show here."),
            dim = true, select_enabled = false, callback = function() end })
    end
    return items
end

function ListsScreen:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

function ListsScreen:refresh()
    local s = self.session
    s:whenOnline(function()
        local closer = UI.loading(_("Loading…"))
        local _d, e = s:fetchInto("lists", function() return s.api:lists() end, false)
        closer()
        if e and e.kind ~= "offline" then UI.info(s:errorText(e)) end
        self:rebuild()
    end, function()
        s.last_errors.lists = s.last_errors.lists or { time = Session.nowMs(), kind = "offline" }
        self:rebuild()
    end)
end

function Lists.show(session)
    local self = setmetatable({ session = session, kind = "lists" }, ListsScreen)
    self.menu = UI.showMenu(session, { title = _("My lists"), items = self:items(), screen = self })
    UIManager:nextTick(Session.wrap(function() self:refresh() end))
    return self
end

-- ---------------------------------------------------------------- one list

local SeriesScreen = {}
SeriesScreen.__index = SeriesScreen
Lists.SeriesScreen = SeriesScreen

-- Cache key and call of a built-in list (its own endpoint: summaries of every medium).
local BUILTIN = {
    favourites = { key = "list_fav", call = function(api) return api:favourites() end },
    want_to_read = { key = "list_wtr", call = function(api) return api:wantToRead() end },
}

function SeriesScreen:novels()
    local s = self.session
    local library = s:cached("novels")
    local lib = library and library.novels or {}
    local b = BUILTIN[self.row.kind]
    if b then
        local data, fetched_at = s:cached(b.key)
        if data then
            -- The library's summaries are fresher (this device's reading); the list's for the rest.
            local by_id = {}
            for _i, n in ipairs(lib) do by_id[n.id] = n end
            local out = {}
            for _i, n in ipairs(Collections.itemNovels(data)) do table.insert(out, by_id[n.id] or n) end
            -- Taken off on this device since (Session:novelFlag): not shown.
            local field = self.row.kind == "favourites" and "favourite" or "want_to_read"
            local kept = {}
            for _i, n in ipairs(out) do
                if s:novelFlag(n.id, field, n) ~= false then table.insert(kept, n) end
            end
            return kept, 0, fetched_at, s.last_errors[b.key]
        end
    end
    -- An own list: its series ids, resolved through the library.
    local lists = s:cached("lists")
    local ids = self.row.novel_ids or {}
    for _i, l in ipairs(lists and lists.lists or {}) do
        if tonumber(l.id) == self.row.id then ids = l.novel_ids or ids end
    end
    local novels, missing = Collections.resolve(ids, lib)
    return novels, missing, nil, s.last_errors.lists
end

function SeriesScreen:items()
    local s = self.session
    local novels, missing, fetched_at, failed = self:novels()
    local items = {}
    if failed then table.insert(items, UI.offlineRow(fetched_at)) end
    local covers = self.cover_list
    for _i, novel in ipairs(novels) do
        local count = Model.readCountText(novel, s:readDelta(novel.id))
        table.insert(items, {
            text = novel.name,
            mandatory = count,
            cover = covers and { url = novel.cover_url, name = novel.name, author = novel.author, info = count,
                badge = tonumber(novel.chapters_new) or 0 } or nil,
            callback = function() s:openNovel(novel.id) end,
            hold_callback = function() s:showNovel(novel.id) end,
        })
    end
    if #novels == 0 then
        table.insert(items, { text = _("Nothing in this list yet."), dim = true, callback = function() end })
    end
    if missing > 0 then
        table.insert(items, { text = T(_("%1 more not shown here (not in the library on this device)."), missing),
            dim = true, select_enabled = false, callback = function() end })
    end
    return items
end

function SeriesScreen:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

function SeriesScreen:refresh()
    local s = self.session
    local b = BUILTIN[self.row.kind]
    s:whenOnline(function()
        local closer = UI.loading(_("Loading…"))
        local _d, e
        if b then
            _d, e = s:fetchInto(b.key, function() return b.call(s.api) end, false)
        else
            _d, e = s:fetchInto("lists", function() return s.api:lists() end, false)
        end
        closer()
        if e and e.kind ~= "offline" then UI.info(s:errorText(e)) end
        self:rebuild()
    end, function() self:rebuild() end)
end

--- Opens one list's series. row: a Collections.listRows row.
function Lists.showList(session, row)
    local self = setmetatable({ session = session, kind = "list", row = row,
        cover_list = session:showCovers() }, SeriesScreen)
    self.menu = UI.showMenu(session, { title = rowName(row), cover_list = self.cover_list,
        items = self:items(), screen = self })
    UIManager:nextTick(Session.wrap(function() self:refresh() end))
    return self
end

--[[--
A screen of series given directly (a book series from the Library).
title, novels: in order; opts.lines(novel) -> right-hand text.
]]
function Lists.showNovels(session, title, novels, opts)
    opts = opts or {}
    local screen = setmetatable({ session = session, kind = "series", cover_list = session:showCovers() }, SeriesScreen)
    screen.items = function(self)
        local items = {}
        for _i, novel in ipairs(novels) do
            local right = opts.right and opts.right(novel) or Model.readCountText(novel, session:readDelta(novel.id))
            table.insert(items, {
                text = novel.name,
                mandatory = right,
                cover = self.cover_list and { url = novel.cover_url, name = novel.name, author = novel.author,
                    info = right, badge = 0 } or nil,
                callback = function() session:openNovel(novel.id) end,
                hold_callback = function() session:showNovel(novel.id) end,
            })
        end
        return items
    end
    screen.refresh = function() end
    screen.menu = UI.showMenu(session, { title = title, cover_list = screen.cover_list,
        items = screen:items(), screen = screen })
    return screen
end

-- ---------------------------------------------------------------- toggles (Novel screen)

--[[--
Want to read (field "want_to_read") or Favourite ("favourite") on or off for
a series, asking for the network if needed. done() after a change.
]]
function Lists.toggle(session, novel_id, field, done)
    local summary = session:novelSummary(novel_id) or {}
    local on = not session:novelFlag(novel_id, field, summary)
    session:whenConnected(function()
        local closer = UI.loading(_("Saving…"))
        local data, e
        if field == "favourite" then
            data, e = session.api:setFavourite(novel_id, on)
        else
            data, e = session.api:setWantToRead(novel_id, on)
        end
        closer()
        if not data then
            UI.info(session:errorText(e))
            return
        end
        session:setNovelFlag(novel_id, field, on)
        if field == "favourite" then
            UI.notify(on and _("Added to Favourites") or _("Removed from Favourites"))
        else
            UI.notify(on and _("Added to Want to read") or _("Removed from Want to read"))
        end
        if done then done() end
        session:refreshScreens()
    end, function()
        UI.info(_("No network connection: lists can't be changed now."))
    end)
end

--[[--
"Add to list…": the reader's own lists, each with a mark when it holds the
series; a tap adds or removes it. "New list…" makes one (POST lists).
]]
function Lists.addToList(session, novel_id, novel_name)
    session:whenConnected(function()
        local closer = UI.loading(_("Loading your lists…"))
        local data, e = session:fetchInto("lists", function() return session.api:lists() end, false)
        closer()
        if e then
            UI.info(session:errorText(e))
            return
        end
        Lists.chooseList(session, novel_id, novel_name, data and data.lists or {})
    end, function()
        UI.info(_("No network connection: lists can't be changed now."))
    end)
end

function Lists.chooseList(session, novel_id, novel_name, lists)
    local buttons = {}
    for _i, c in ipairs(Collections.listChoices(lists, novel_id)) do
        table.insert(buttons, {
            text = (c.has and (UI.MARK_READ .. " ") or "") .. (c.name or _("List")),
            callback = function() Lists.setInList(session, c.id, novel_id, not c.has, c.name) end,
        })
    end
    table.insert(buttons, { text = _("New list…"), callback = function() Lists.newList(session, novel_id) end })
    UI.choice(novel_name and T(_("Lists for %1"), novel_name) or _("Add to list"), buttons)
end

function Lists.setInList(session, list_id, novel_id, on, name)
    local closer = UI.loading(_("Saving…"))
    local data, e = session.api:setInList(list_id, novel_id, on)
    closer()
    if not data then
        UI.info(session:errorText(e))
        return
    end
    local cached = session:cached("lists")
    if cached and Collections.replaceList(cached, data.list) then
        session.store:putCache("lists", require("novarr.json").encode(cached), nil)
    end
    UI.notify(on and T(_("Added to %1"), name or _("the list")) or T(_("Removed from %1"), name or _("the list")))
    session:refreshScreens()
end

function Lists.newList(session, novel_id)
    UI.input({
        title = _("New list"),
        hint = _("Name (up to 40 characters)"),
        ok_text = _("Make it"),
        callback = function(text)
            local name = (text or ""):gsub("^%s+", ""):gsub("%s+$", "")
            if name == "" then return true end
            UIManager:nextTick(Session.wrap(function()
                local closer = UI.loading(_("Saving…"))
                local data, e = session.api:createList(name)
                closer()
                if not data or type(data.list) ~= "table" then
                    UI.info(session:errorText(e))
                    return
                end
                Lists.setInList(session, tonumber(data.list.id), novel_id, true, data.list.name)
            end))
        end,
    })
end

return Lists
