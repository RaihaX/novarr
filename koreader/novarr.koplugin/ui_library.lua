--[[--
Library: every novel in the API's order, with read/total and a marker for
new chapters, plus search (name/author) and a filter.
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")
local T = require("ffi/util").template

local Model = require("novarr.model")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local Library = {}
Library.__index = Library

local FILTER_NAMES = {
    all = _("All"),
    reading = _("Reading"),
    unread = _("Unread"),
    new = _("New chapters"),
    completed = _("Completed"),
    downloaded = _("Downloaded on this device"),
}

-- Remembered for the session (not persisted).
local last_filter, last_query = "all", ""

function Library:items()
    local s = self.session
    local data, fetched_at = s:cached("novels")
    local items = {}
    if s.last_errors.novels then
        table.insert(items, UI.offlineRow(fetched_at))
    end
    local covers = self.cover_list
    table.insert(items, {
        text = self.query ~= "" and T(_("Search: %1"), self.query) or _("Search…"),
        toolbar = covers or nil,
        callback = function() self:askSearch() end,
    })
    if self.query ~= "" then
        table.insert(items, {
            toolbar = covers or nil,
            text = _("Clear search"),
            callback = function()
                self.query = ""
                last_query = ""
                self:rebuild()
            end,
        })
    end
    table.insert(items, {
        toolbar = covers or nil,
        text = T(_("Show: %1"), FILTER_NAMES[self.filter] or FILTER_NAMES.all),
        callback = function() self:askFilter() end,
    })
    local novels = data and data.novels or {}
    local downloaded = {}
    for _i, id in ipairs(s.store:novelsWithDownloads()) do downloaded[id] = true end
    local shown = Model.filterNovels(novels, self.filter, self.query, downloaded)
    for _i, novel in ipairs(shown) do
        local count = Model.readCountText(novel, s:readDelta(novel.id))
        local right = count
        if (tonumber(novel.chapters_new) or 0) > 0 then
            right = UI.MARK_NEW .. " " .. right
        end
        if downloaded[novel.id] then
            right = UI.MARK_SAVED .. " " .. right
            count = UI.MARK_SAVED .. " " .. count
        end
        table.insert(items, {
            text = novel.name,
            mandatory = right,
            cover = covers and { url = novel.cover_url, name = novel.name, author = novel.author, info = count, -- the badge shows new chapters
                badge = tonumber(novel.chapters_new) or 0 } or nil,
            -- One tap reads (the resume chapter); hold opens the Novel screen.
            callback = function() s:openNovel(novel.id) end,
            hold_callback = function() s:showNovel(novel.id) end,
        })
    end
    if #shown == 0 then
        local text
        if not data then
            text = _("The library hasn't been loaded yet.")
        else
            text = _("No novels match.")
        end
        table.insert(items, { text = text, dim = true, callback = function() end })
    end
    return items
end

function Library:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

function Library:askSearch()
    UI.input({
        title = _("Search the library"),
        value = self.query,
        hint = _("Name or author"),
        ok_text = _("Search"),
        callback = function(text)
            self.query = (text or ""):gsub("^%s+", ""):gsub("%s+$", "")
            last_query = self.query
            self:rebuild()
        end,
    })
end

function Library:askFilter()
    local buttons = {}
    for _i, key in ipairs(Model.FILTERS) do
        local label = FILTER_NAMES[key]
        if key == self.filter then label = UI.MARK_READ .. " " .. label end
        table.insert(buttons, { text = label, callback = function()
            self.filter = key
            last_filter = key
            self:rebuild()
        end })
    end
    UI.choice(_("Show"), buttons)
end

function Library:refresh()
    local s = self.session
    s:whenConnected(function()
        local closer = UI.loading(_("Loading…"))
        local _data, e = s:fetchInto("novels", function(etag) return s.api:novels(etag) end, true)
        closer()
        if e and e.kind ~= "offline" then UI.info(s:errorText(e)) end
        self:rebuild()
    end, function()
        s.last_errors.novels = s.last_errors.novels or { time = Session.nowMs(), kind = "offline" }
        self:rebuild()
    end)
end

function Library.show(session)
    local self = setmetatable({ session = session, kind = "library", filter = last_filter, query = last_query,
        cover_list = session:showCovers() }, Library)
    self.menu = UI.showMenu(session, {
        title = _("Library"),
        cover_list = self.cover_list,
        items = self:items(),
        screen = self,
    })
    UIManager:nextTick(Session.wrap(function() self:refresh() end))
    return self
end

return Library
