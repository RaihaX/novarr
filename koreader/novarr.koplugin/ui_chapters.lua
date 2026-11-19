--[[--
Chapter list of a novel in reading order. Each row: label, and on the right
progress % (part-read), ● (saved on this device), ✓ (read). Opens on the
page with the resume chapter (shown bold). Hold a row for more actions;
the title bar's left button jumps to a chapter number.
]]

local UIManager = require("ui/uimanager")
local logger = require("logger")
local _ = require("gettext")
local T = require("ffi/util").template

local Model = require("novarr.model")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local Chapters = {}
Chapters.__index = Chapters

function Chapters:items()
    local s = self.session
    local cl = s:chapterList(self.novel_id)
    local items = {}
    local offset = 0
    if s.last_errors["chapters_" .. self.novel_id] then
        local meta = s.store:cacheMeta("chapters_" .. self.novel_id)
        table.insert(items, UI.offlineRow(meta and meta.fetched_at))
        offset = 1
    end
    if not cl then
        table.insert(items, { text = _("The chapter list hasn't been loaded yet."), dim = true, callback = function() end })
        return items, nil
    end
    local stored = s.store:storedSet(self.novel_id)
    local overlay = s.store:getOverlay(self.novel_id)
    local Store = require("novarr.store")
    local novel_id = self.novel_id
    local MARK_READ, MARK_SAVED = UI.MARK_READ, UI.MARK_SAVED
    for i, c in ipairs(cl.list) do
        local read, progress = Store.effective(c, overlay[tostring(c.id)])
        local marks = ""
        if not read and progress and progress > 0 and progress < 100 then
            marks = progress .. "%"
        end
        if stored[c.id] then marks = marks == "" and MARK_SAVED or (marks .. " " .. MARK_SAVED) end
        if read then marks = marks == "" and MARK_READ or (marks .. " " .. MARK_READ) end
        local id = c.id
        items[offset + i] = {
            text = c.label or ("Chapter " .. tostring(c.chapter)),
            mandatory = marks ~= "" and marks or nil,
            dim = c.downloaded == false or nil,
            chapter_id = id,
            callback = function() s:openChapter(novel_id, id, { check_position = true }) end,
        }
    end
    local resume = s:resumeChapter(novel_id)
    local current = resume and cl.pos[resume] and (cl.pos[resume] + offset) or nil
    return items, current
end

function Chapters:rebuild()
    if not self.menu then return end
    local items = self:items()
    -- Keep the page the user is on; the bold row follows the resume chapter.
    local s = self.session
    local cl = s:chapterList(self.novel_id)
    local resume = s:resumeChapter(self.novel_id)
    items.current = cl and resume and cl.pos[resume] and (cl.pos[resume] + (#items - #cl.list)) or nil
    UI.setItems(self.menu, items)
end

function Chapters:onHold(item)
    if not item.chapter_id then return end
    local s = self.session
    local novel_id, id = self.novel_id, item.chapter_id
    local read = s:chapterState(novel_id, id)
    local saved = s.store:hasChapter(novel_id, id)
    local buttons = {
        {
            text = read and _("Mark as unread") or _("Mark as read"),
            callback = function()
                s:markChapter(novel_id, id, not read, "set")
                s:quietFlush()
                s:refreshScreens()
            end,
        },
        {
            text = _("Mark all up to here as read"),
            callback = function()
                s:bulkMark(novel_id, "up_to", id, true)
                s:quietFlush()
                s:refreshScreens()
            end,
        },
    }
    if saved then
        table.insert(buttons, { text = _("Remove this download"), callback = function()
            if not s:removeChapter(novel_id, id) then
                UI.info(_("This chapter is open in the reader, so it was kept."))
            end
            s:refreshScreens()
        end })
    else
        table.insert(buttons, { text = _("Download this chapter"), callback = function()
            s:downloadMany(novel_id, { id })
        end })
    end
    UI.choice(item.text, buttons)
end

function Chapters:askGoto()
    local s = self.session
    UI.input({
        title = _("Go to chapter"),
        hint = _("Chapter number"),
        input_type = "number",
        ok_text = _("Go"),
        callback = function(text)
            local cl = s:chapterList(self.novel_id)
            local idx = cl and Model.findChapterNumber(cl.list, text)
            if not idx then
                UI.info(_("There is no chapter with that number."), 2)
                return true
            end
            local offset = #self.menu.item_table - #cl.list
            self.menu:switchItemTable(nil, self.menu.item_table, idx + offset)
        end,
    })
end

--- Refreshes from the server only when already connected (opening a screen never asks for Wi-Fi).
function Chapters:refresh()
    local s = self.session
    s:whenOnline(function()
        local closer = UI.loading(_("Loading…"))
        if s.queue:count() > 0 then s:flushQueue() end
        local _cl, e = s:refreshChapterList(self.novel_id)
        closer()
        if e and e.kind ~= "offline" then UI.info(s:errorText(e)) end
        self:rebuild()
    end, function()
        s.last_errors["chapters_" .. self.novel_id] = { time = Session.nowMs(), kind = "offline" }
        self:rebuild()
    end)
end

function Chapters.show(session, novel_id)
    local self = setmetatable({ session = session, kind = "chapters", novel_id = novel_id }, Chapters)
    local novel = session:novelSummary(novel_id)
    local t0, w0 = os.clock(), Session.nowMs()
    local items, current = self:items()
    self.menu = UI.showMenu(session, {
        title = novel and novel.name or _("Chapters"),
        items = items,
        current = current,
        screen = self,
        left_icon = "appbar.search",
        on_left = function() self:askGoto() end,
        on_hold = function(item) self:onHold(item) end,
    })
    self.build_seconds = os.clock() - t0
    logger.info(string.format("Novarr: chapter list of novel %d (%d rows) ready in %d ms (%.3f s CPU, rows + menu)",
        novel_id, #items, Session.nowMs() - w0, self.build_seconds))
    -- Refresh from the server unless the list was fetched a moment ago
    -- (the Novel screen refreshes it right before).
    local meta = session.store:cacheMeta("chapters_" .. novel_id)
    local fresh = meta and meta.fetched_at and (Session.nowMs() - meta.fetched_at) < 60000
    if not fresh or not session:chapterList(novel_id) then
        UIManager:nextTick(Session.wrap(function() self:refresh() end))
    end
    return self
end

return Chapters
