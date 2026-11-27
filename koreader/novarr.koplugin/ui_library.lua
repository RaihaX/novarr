--[[--
Library: every novel in the API's order, with read/total and a marker for
new chapters, plus search (name/author) and a filter.
Books (1.12.0): the Library shows either the web novels or the books
(self.medium "novel" | "book"); a "Novels / Books" row switches, shown only
while the library holds at least one book (Home's Books button opens it on
the books).
1.13.0: manga too (self.medium "manga", when the server sends them as CBZ
and the library holds one); the switch row then offers each medium. A book
series is one row (its books in order on a screen of their own).
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")
local T = require("ffi/util").template

local Collections = require("novarr.collections")
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
-- last_medium: also where leaving a book comes back to (main.lua).
local last_filter, last_query, last_medium = "all", "", "novel"

local MEDIUM_NAMES = { novel = _("Novels"), book = _("Books"), manga = _("Manga") }

function Library:items()
    local s = self.session
    local data, fetched_at = s:cached("novels")
    local items = {}
    if s.last_errors.novels then
        table.insert(items, UI.offlineRow(fetched_at))
    end
    local covers = self.cover_list
    local all = data and data.novels or {}
    -- The media this library holds (novels always; books, manga when there are some).
    -- Only novels (or an older server): no switch row, as before 1.12.0.
    local media = Collections.libraryMedia(all)
    local medium = "novel"
    for _i, m in ipairs(media) do
        if m == self.medium then medium = m end
    end
    if #media == 2 then
        local other = medium == media[1] and media[2] or media[1]
        table.insert(items, {
            toolbar = covers or nil,
            -- The cover toolbar's buttons share one line: the short form there.
            text = covers and T(_("Switch to %1"), MEDIUM_NAMES[other])
                or T(_("Showing: %1 (tap for %2)"), MEDIUM_NAMES[medium], MEDIUM_NAMES[other]),
            callback = function()
                self.medium = other
                last_medium = other
                self:rebuild()
            end,
        })
    elseif #media > 2 then
        table.insert(items, {
            toolbar = covers or nil,
            text = covers and T(_("Show: %1"), MEDIUM_NAMES[medium]) or T(_("Showing: %1 (tap to change)"), MEDIUM_NAMES[medium]),
            callback = function()
                local buttons = {}
                for _i, m in ipairs(media) do
                    table.insert(buttons, { text = (m == medium and (UI.MARK_READ .. " ") or "") .. MEDIUM_NAMES[m],
                        callback = function()
                            self.medium = m
                            last_medium = m
                            self:rebuild()
                        end })
                end
                UI.choice(_("Show"), buttons)
            end,
        })
    end
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
    local novels = Model.byMedium(all, medium)
    local downloaded = {}
    for _i, id in ipairs(s.store:novelsWithDownloads()) do downloaded[id] = true end
    local filtered = Model.filterNovels(novels, self.filter, nil, downloaded)
    -- Books: a series is one row (1.13.0); the search matches its name or any of its books.
    local shown
    if medium == "book" then
        shown = Collections.groupSeries(filtered)
    else
        shown = {}
        for _i, n in ipairs(filtered) do table.insert(shown, { kind = "book", novel = n }) end
    end
    shown = Collections.filterEntries(shown, function(n) return Model.matchesQuery(n, self.query) end)
    for _i, entry in ipairs(shown) do
        if entry.kind == "series" then
            local first = entry.books[1]
            local read_books = 0
            for _j, b in ipairs(entry.books) do
                if b.finished then read_books = read_books + 1 end
            end
            table.insert(items, {
                text = entry.name,
                mandatory = T(_("%1 books"), entry.count),
                cover = covers and { url = first.cover_url, name = entry.name, author = first.author,
                    info = T(_("%1 of %2 read"), read_books, entry.count), badge = 0 } or nil,
                callback = function() Library.showSeries(s, entry) end,
                hold_callback = function() Library.showSeries(s, entry) end,
            })
        else
            local novel = entry.novel
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
    end
    if #shown == 0 then
        local text
        if not data then
            text = _("The library hasn't been loaded yet.")
        else
            text = medium == "book" and _("No books match.") or medium == "manga" and _("No manga match.")
                or _("No novels match.")
        end
        table.insert(items, { text = text, dim = true, callback = function() end })
    end
    return items
end

--- A book series (1.13.0): its books in series order (#1, #2…); tap reads, hold opens the book.
function Library.showSeries(session, entry)
    require("novarr.ui_lists").showNovels(session, entry.name, entry.books, {
        right = function(book)
            local count = Model.readCountText(book, session:readDelta(book.id))
            local n = Collections.seriesNumber(book)
            return n and (n .. "  " .. count) or count
        end,
    })
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

--- Refreshes from the server only when already connected (opening a screen never asks for Wi-Fi).
function Library:refresh()
    local s = self.session
    s:whenOnline(function()
        local closer = UI.loading(_("Loading…"))
        local _data, e = s:fetchLibrary()
        closer()
        if e and e.kind ~= "offline" then UI.info(s:errorText(e)) end
        self:rebuild()
    end, function()
        s.last_errors.novels = s.last_errors.novels or { time = Session.nowMs(), kind = "offline" }
        self:rebuild()
    end)
end

--[[--
Opens the Library. medium: "book" (Home's Books button), "novel" (Home's
Library button), "manga" (More > Manga) or nil (the last one shown in this session).
]]
function Library.show(session, medium)
    if medium == "book" or medium == "novel" or medium == "manga" then last_medium = medium end
    local self = setmetatable({ session = session, kind = "library", filter = last_filter, query = last_query,
        medium = last_medium, cover_list = session:showCovers() }, Library)
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
