--[[--
Highlights of a novel (from the Novel screen): what the server has, newest
first (GET /highlights?novel_id=), 50 per page with "More…". A row shows
the excerpt, its note and the chapter; a tap shows the whole text with
Delete. Deleting goes through the write queue (sent at once when
connected). Needs the network: nothing is kept on the device.
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")

local Highlights = require("novarr.highlights")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local Screen = {}
Screen.__index = Screen

function Screen:items()
    local items = {}
    local view = self.view
    if not view.loaded and not view.error then
        table.insert(items, { text = _("Loading…"), dim = true, select_enabled = false, callback = function() end })
        return items
    end
    for _i, h in ipairs(view.items) do
        local text, chapter = Highlights.rowText(h)
        table.insert(items, { text = text, mandatory = chapter, highlight_id = h.id,
            callback = function() self:showOne(h) end })
    end
    if view.error then
        table.insert(items, { text = _("Couldn't load the highlights. Tap to try again."), dim = true,
            callback = function() self:fetchPage() end })
    elseif #view.items == 0 then
        table.insert(items, { text = _("No highlights yet. Highlight text while reading a chapter and it shows up here."),
            dim = true, select_enabled = false, callback = function() end })
    elseif view.has_more then
        table.insert(items, { text = _("More…"), bold = true, callback = function() self:fetchPage() end })
    end
    return items
end

function Screen:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

function Screen:fetchPage()
    local s = self.session
    local view = self.view
    s:whenConnected(function()
        if self.closed or self.view ~= view then return end
        -- Our own pending highlights first, so the list includes them.
        if s.queue:count() > 0 then s:quietFlush(50) end
        local closer = UI.loading(_("Loading highlights…"))
        local data, e = s.api:highlights(view.novel_id, view.page + 1, Highlights.PER_PAGE)
        closer()
        if self.closed or self.view ~= view then return end
        if not data then
            view.error = true
            self:rebuild()
            UI.info(e and e.kind == "not_found" and _("This Novarr server doesn't keep highlights yet.") or s:errorText(e))
            return
        end
        view.error = nil
        Highlights.addPage(view, data)
        self:rebuild()
    end, function()
        view.error = true
        self:rebuild()
        UI.info(_("No network connection: the highlights are on the Novarr server."))
    end, { reason = "Novarr highlights" })
end

function Screen:showOne(h)
    local TextViewer = require("ui/widget/textviewer")
    local viewer
    viewer = TextViewer:new{
        title = _("Highlight"),
        text = Highlights.detailText(h),
        buttons_table = { {
            { text = _("Delete"), callback = function()
                UIManager:close(viewer)
                Session.guard(function() self:delete(h) end)
            end },
            { text = _("Close"), callback = function() UIManager:close(viewer) end },
        } },
    }
    UIManager:show(viewer)
end

function Screen:delete(h)
    local s = self.session
    s:deleteHighlightId(h.id)
    Highlights.removeFromView(self.view, h.id)
    self:rebuild()
    UI.notify(_("Highlight deleted."))
end

function Screen:onClose()
    self.closed = true
end

function Screen.show(session, novel_id, title)
    local self = setmetatable({ session = session, kind = "highlights", novel_id = novel_id,
        view = Highlights.newView(novel_id) }, Screen)
    self.menu = UI.showMenu(session, {
        title = title and (_("Highlights") .. " · " .. title) or _("Highlights"),
        items = self:items(),
        screen = self,
    })
    UIManager:nextTick(Session.wrap(function() self:fetchPage() end))
    return self
end

--- The screen's state for the autopilot dump.
function Screen.dumpOf(screen)
    local v = screen.view
    local ids = {}
    for _i, h in ipairs(v.items) do table.insert(ids, h.id) end
    return { novel_id = v.novel_id, page = v.page, has_more = v.has_more, loaded = v.loaded,
        error = v.error or nil, count = #v.items, ids = require("novarr.json").array(ids) }
end

return Screen
