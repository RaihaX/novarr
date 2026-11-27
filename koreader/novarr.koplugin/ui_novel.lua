--[[--
Novel screen: Continue/Start, Chapters, Highlights (ui_highlights.lua), reading stats (stats.lua), Check
for new chapters (checks.lua) with its status, Download for offline,
Remove downloads, Mark all as read, Download as EPUB, About (synopsis).
A book (1.12.0: medium "book", complete) has no Check for new chapters;
its EPUB is the original file (1.13.0: never rebuilt).
1.13.0: Want to read, Favourite and "Add to list…" (when the server has
lists); a series with an audiobook says so (it plays in the Novarr app,
not here); a manga (read as CBZ) has no highlights and no EPUB.
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")
local T = require("ffi/util").template

local Checks = require("novarr.checks")
local Model = require("novarr.model")
local Stats = require("novarr.stats")
local ReadingInfo = require("novarr.readinginfo")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local Novel = {}
Novel.__index = Novel

function Novel:summary()
    return self.session:novelSummary(self.novel_id) or { id = self.novel_id, name = _("Novel") }
end

function Novel:items()
    local s = self.session
    local novel = self:summary()
    local items = {}
    if s.last_errors["novel_" .. self.novel_id] or s.last_errors["chapters_" .. self.novel_id] then
        local meta = s.store:cacheMeta("chapters_" .. self.novel_id)
        table.insert(items, UI.offlineRow(meta and meta.fetched_at))
    end
    local cl = s:chapterList(self.novel_id)
    local resume_id = s:resumeChapter(self.novel_id, novel)
    local resume_row = cl and resume_id and cl.by_id[resume_id]
    local started = (tonumber(novel.chapters_read) or 0) + s:readDelta(self.novel_id) > 0
    if resume_id then
        local label
        if resume_row then
            label = Model.chapterLabel(resume_row)
        else
            local entry = s.store:chapterEntry(self.novel_id, resume_id)
            label = entry and entry.label
        end
        table.insert(items, {
            text = started and (label and T(_("Continue: %1"), label) or _("Continue reading"))
                or (label and T(_("Start reading: %1"), label) or _("Start reading")),
            callback = function() s:openChapter(self.novel_id, resume_id, { check_position = true }) end,
        })
    else
        table.insert(items, { text = _("No chapters downloaded on the server yet"), dim = true, callback = function() end })
    end
    local total = tonumber(novel.chapters_total) or (cl and #cl.list) or 0
    local read = (tonumber(novel.chapters_read) or 0) + s:readDelta(self.novel_id)
    table.insert(items, {
        text = _("Chapters"),
        mandatory = string.format("%d/%d", math.max(0, math.min(read, total)), total),
        callback = function() s:showChapters(self.novel_id) end,
    })
    local manga = s:isManga(self.novel_id)
    if not manga then
        table.insert(items, {
            text = _("Highlights"),
            callback = function() require("novarr.ui_highlights").show(s, self.novel_id, novel.name) end,
        })
    end
    local left, secs = s:novelEstimate(self.novel_id)
    local left_text
    if left then
        left_text = left == 0 and _("No chapters left to read")
            or left == 1 and _("1 chapter left") or T(_("%1 chapters left"), ReadingInfo.thousands(left))
        if secs and left > 0 then left_text = left_text .. " · " .. ReadingInfo.formatDurationLong(secs) end
    end
    if self.cover_list then
        -- Large cover beside the summary, actions below.
        local detail = s:cached("novel_" .. self.novel_id)
        local full = detail and detail.novel or novel
        local lines = {}
        if full.author then table.insert(lines, full.author) end
        table.insert(lines, T(_("%1 of %2 chapters read"), math.max(0, math.min(read, total)), total))
        if left_text then table.insert(lines, left_text) end
        table.insert(items, 1, {
            text = novel.name,
            hero = { url = full.cover_large_url or full.cover_url, name = novel.name, lines = lines },
            callback = function() self:showAbout() end,
        })
    elseif left_text then
        table.insert(items, { text = left_text, dim = true, select_enabled = false, callback = function() end })
    end
    -- Reading stats, compact (nothing until something was recorded).
    for _i, line in ipairs(Stats.lines(s:novelStats(self.novel_id))) do
        table.insert(items, { text = line, dim = true, select_enabled = false, stats = true, callback = function() end })
    end
    self:addCollectionRows(items, novel)
    local book = s:isBook(self.novel_id)
    -- A book is complete: it never gets new chapters, so no check.
    if not book then
        local check_text, check_kind = Checks.text(self.novel_id)
        table.insert(items, {
            text = _("Check for new chapters"),
            mandatory = check_kind == "busy" and _("Checking…") or nil,
            dim = check_kind == "busy",
            callback = function()
                if check_kind == "busy" then
                    UI.info(check_text)
                    return
                end
                Checks.ask(self.novel_id)
            end,
        })
        if check_text and check_kind ~= "busy" then
            -- A long reason may not fit on the row: a tap shows it whole.
            table.insert(items, { text = check_text, dim = true, check_status = true,
                callback = function() UI.info(check_text) end })
        end
    end
    local _set, saved = s.store:storedSet(self.novel_id)
    table.insert(items, {
        text = _("Download for offline…"),
        mandatory = saved > 0 and T(_("%1 saved"), saved) or nil,
        callback = function() self:askDownload() end,
    })
    table.insert(items, {
        text = book and _("Remove downloads for this book") or _("Remove downloads for this novel"),
        dim = saved == 0,
        callback = function()
            if saved == 0 then
                UI.info(_("No chapters of this novel are saved on this device."), 2)
                return
            end
            UI.confirm({
                text = T(_("Remove the %1 saved chapter(s) of %2 from this device?"), saved, novel.name),
                ok_text = _("Remove"),
                ok = function()
                    local kept = s:removeNovelDownloads(self.novel_id)
                    if kept then UI.info(_("Removed. The chapter you have open was kept.")) end
                    s:refreshScreens()
                end,
            })
        end,
    })
    table.insert(items, {
        text = _("Mark all as read"),
        callback = function()
            UI.confirm({
                text = T(_("Mark every chapter of %1 as read?"), novel.name),
                ok_text = _("Mark read"),
                ok = function()
                    s:bulkMark(self.novel_id, "all", nil, true)
                    s:quietFlush()
                    s:refreshScreens()
                end,
            })
        end,
    })
    -- A manga has page images, not text: no EPUB (the server refuses one).
    if not manga then
        local epub = s.store:getEpubs()[tostring(self.novel_id)]
        table.insert(items, {
            -- A book's EPUB is the file it came as (1.13.0: never rebuilt from its chapters).
            text = book and _("Download the original ePub") or _("Download as EPUB"),
            mandatory = epub and Model.formatBytes(epub.size) or nil,
            callback = function()
                require("novarr.epub").download(s, self.novel_id, self:summary().name, { original = book })
            end,
        })
    end
    table.insert(items, {
        text = book and _("About this book") or _("About this novel"),
        callback = function() self:showAbout() end,
    })
    return items
end

--[[--
1.13.0: Want to read / Favourite (on and off), "Add to list…", and a line
for a series that has an audiobook. Each only when the server has it.
]]
function Novel:addCollectionRows(items, novel)
    local s = self.session
    local Lists = require("novarr.ui_lists")
    local function toggleRow(field, label)
        local on = s:novelFlag(self.novel_id, field, novel)
        table.insert(items, {
            text = label,
            mandatory = on and UI.MARK_READ or _("Add"),
            callback = function() Lists.toggle(s, self.novel_id, field, function() self:rebuild() end) end,
        })
    end
    if s:feature("want_to_read") then toggleRow("want_to_read", _("Want to read")) end
    if s:feature("favourites") then toggleRow("favourite", _("Favourite")) end
    if s:feature("lists") then
        table.insert(items, {
            text = _("Add to list…"),
            callback = function() Lists.addToList(s, self.novel_id, novel.name) end,
        })
    end
    local detail = s:cached("novel_" .. self.novel_id)
    local full = detail and detail.novel or novel
    if type(full.audiobook) == "table" then
        -- No audio on a Kindle: it plays in the Novarr app.
        table.insert(items, { text = _("Audiobook available: listen in the Novarr app"), dim = true,
            select_enabled = false, callback = function() end })
    end
end

function Novel:showAbout()
    local s = self.session
    local detail = s:cached("novel_" .. self.novel_id)
    local novel = detail and detail.novel or self:summary()
    local parts = {}
    if novel.author then table.insert(parts, T(_("By %1"), novel.author)) end
    if novel.origin then table.insert(parts, novel.origin) end
    if Model.isBook(novel) or self.session:isBook(self.novel_id) then table.insert(parts, _("Book")) end
    if type(novel.tags) == "table" and #novel.tags > 0 then
        local names = {}
        for _i, t in ipairs(novel.tags) do table.insert(names, t.name) end
        table.insert(parts, table.concat(names, ", "))
    end
    if novel.completed then table.insert(parts, _("Completed")) end
    table.insert(parts, "")
    table.insert(parts, novel.description or _("No synopsis available."))
    if type(novel.source_unavailable) == "table" and novel.source_unavailable.message then
        table.insert(parts, "")
        table.insert(parts, novel.source_unavailable.message)
    end
    UI.textViewer(novel.name or _("Novel"), table.concat(parts, "\n"))
end

function Novel:askDownload()
    local s = self.session
    local function start(mode, limit)
        local cl = s:chapterList(self.novel_id)
        if not cl then
            UI.info(_("The chapter list isn't loaded yet. Connect to the network and open this novel again."))
            return
        end
        local stored = s.store:storedSet(self.novel_id)
        local resume = s:resumeChapter(self.novel_id, self:summary())
        local ids = Model.downloadSelection(cl.list, mode, limit, resume, stored,
            function(c) return s:chapterState(self.novel_id, c.id) end)
        s:downloadMany(self.novel_id, ids)
    end
    if s:isManga(self.novel_id) then
        -- A manga chapter is a few MB (its pages): smaller steps.
        UI.choice(_("Download for offline reading"), {
            { text = _("Next 3 unread chapters"), callback = function() start("unread", 3) end },
            { text = _("Next 10 unread chapters"), callback = function() start("unread", 10) end },
            { text = _("Next 25 unread chapters"), callback = function() start("unread", 25) end },
            { text = _("All unread chapters"), callback = function() start("unread", nil) end },
        })
        return
    end
    UI.choice(_("Download for offline reading"), {
        { text = _("Next 10 unread chapters"), callback = function() start("unread", 10) end },
        { text = _("Next 50 unread chapters"), callback = function() start("unread", 50) end },
        { text = _("All unread chapters"), callback = function() start("unread", nil) end },
        { text = _("All chapters"), callback = function() start("all", nil) end },
    })
end

function Novel:rebuild()
    if self.menu then
        self.menu.title = self:summary().name
        UI.setItems(self.menu, self:items())
    end
end

--- Refreshes from the server only when already connected (opening a screen never asks for Wi-Fi).
function Novel:refresh()
    local s = self.session
    s:whenOnline(function()
        local closer = UI.loading(_("Loading…"))
        if s.queue:count() > 0 then s:flushQueue() end
        local _d, e1 = s:fetchInto("novel_" .. self.novel_id, function() return s.api:novel(self.novel_id) end, false)
        local _cl, e2 = s:refreshChapterList(self.novel_id)
        closer()
        local e = e1 or e2
        if e and e.kind ~= "offline" then UI.info(s:errorText(e)) end
        self:rebuild()
    end, function()
        s.last_errors["chapters_" .. self.novel_id] = { time = Session.nowMs(), kind = "offline" }
        self:rebuild()
    end)
end

function Novel.show(session, novel_id)
    local self = setmetatable({ session = session, kind = "novel", novel_id = novel_id,
        cover_list = session:showCovers() }, Novel)
    self.menu = UI.showMenu(session, {
        cover_list = self.cover_list,
        title = self:summary().name,
        items = self:items(),
        screen = self,
    })
    UIManager:nextTick(Session.wrap(function() self:refresh() end))
    return self
end

return Novel
