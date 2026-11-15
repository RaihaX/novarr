--[[--
Novarr Home: Continue reading, New chapters, then Library, Downloads,
Sync now and Settings. Opens instantly from the cached copy, then refreshes.
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")
local T = require("ffi/util").template

local Model = require("novarr.model")
local ReadingInfo = require("novarr.readinginfo")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local Home = {}
Home.__index = Home

local function shortLabel(ref)
    if ref and ref.chapter then
        return T(_("Ch. %1"), ref.chapter)
    end
    return Model.chapterLabel(ref)
end

function Home:items()
    local s = self.session
    local data, fetched_at = s:cached("home")
    local items = {}
    if s.last_errors.home then
        table.insert(items, UI.offlineRow(fetched_at))
    end
    if data then
        if type(data.continue) == "table" and #data.continue > 0 then
            table.insert(items, UI.headerRow(_("Continue reading")))
            for _i, row in ipairs(data.continue) do
                local novel, ref = row.novel, row.chapter
                if novel and ref then
                    local right = shortLabel(ref)
                    -- Chapters left, when the novel's chapter list is saved.
                    local left = s.store:cacheMeta("chapters_" .. novel.id) and s:novelEstimate(novel.id)
                    if left then right = T(_("%1 · %2 left"), right, ReadingInfo.thousands(left)) end
                    table.insert(items, {
                        text = novel.name,
                        mandatory = right,
                        cover = self.cover_list and { url = novel.cover_url, name = novel.name,
                            author = Model.chapterLabel(ref), info = left and T(_("%1 chapters left"), ReadingInfo.thousands(left)) or nil } or nil,
                        callback = function() s:openChapter(novel.id, ref.id) end,
                        hold_callback = function() s:showNovel(novel.id) end,
                    })
                end
            end
        end
        if type(data.new_chapters) == "table" and #data.new_chapters > 0 then
            table.insert(items, UI.headerRow(_("New chapters")))
            for _i, row in ipairs(data.new_chapters) do
                local novel, ref = row.novel, row.first_chapter
                if novel and ref then
                    table.insert(items, {
                        text = novel.name,
                        mandatory = T(_("+%1 new"), row.count or 0),
                        callback = function() s:openChapter(novel.id, ref.id) end,
                        hold_callback = function() s:showNovel(novel.id) end,
                    })
                end
            end
        end
    elseif not s.last_errors.home then
        table.insert(items, { text = _("Nothing saved yet: connect to load your library."), dim = true, callback = function() end })
    end
    if #items > 0 then
        table.insert(items, UI.headerRow(_("Novarr")))
    end
    table.insert(items, { text = _("Library"), callback = function() require("novarr.ui_library").show(s) end })
    table.insert(items, { text = _("Downloads"), callback = function() require("novarr.ui_downloads").show(s) end })
    local pending = s.queue:count()
    table.insert(items, {
        text = pending > 0 and T(_("Sync now (%1 pending)"), pending) or _("Sync now"),
        callback = function()
            s:syncNow(function() self:refresh(true) end)
        end,
    })
    table.insert(items, { text = _("Settings"), callback = function() require("novarr.ui_settings").show(s) end })
    return items
end

function Home:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

--- Fetches /home (sending pending writes first) and redraws.
function Home:refresh(quiet)
    local s = self.session
    local function run()
        local closer = (not quiet) and UI.loading(_("Loading…"))
        -- Our own pending marks go first so the server's answer includes them.
        if s.queue:count() > 0 then s:flushQueue() end
        local _data, e = s:fetchInto("home", function() return s.api:home() end, false)
        if closer then closer() end
        if e and e.kind ~= "offline" then
            UI.info(s:errorText(e))
        end
        self:rebuild()
    end
    s:whenConnected(run, function()
        s.last_errors.home = s.last_errors.home or { time = Session.nowMs(), kind = "offline" }
        self:rebuild()
    end)
end

function Home.show(session)
    local self = setmetatable({ session = session, kind = "home", cover_list = session:showCovers() }, Home)
    self.menu = UI.showMenu(session, {
        title = _("Novarr"),
        cover_list = self.cover_list,
        items = self:items(),
        screen = self,
    })
    -- Let the cached screen paint first, then refresh.
    UIManager:nextTick(Session.wrap(function() self:refresh() end))
    return self
end

return Home
