--[[--
Downloads: novels with chapters saved on this device, counts and sizes;
remove per novel or everything.
]]

local _ = require("gettext")
local T = require("ffi/util").template

local Model = require("novarr.model")
local UI = require("novarr.ui_common")

local Downloads = {}
Downloads.__index = Downloads

function Downloads:items()
    local s = self.session
    local list = s.store:downloads()
    local items = {}
    local total_bytes, total_count = 0, 0
    for _i, d in ipairs(list) do
        total_bytes = total_bytes + d.bytes
        total_count = total_count + d.count
    end
    if #list == 0 then
        table.insert(items, { text = _("No chapters are saved on this device."), dim = true, callback = function() end })
        self:addEpubs(items)
        return items
    end
    table.insert(items, {
        text = _("Remove all downloads"),
        mandatory = T(_("%1 · %2"), total_count, Model.formatBytes(total_bytes)),
        callback = function()
            UI.confirm({
                text = T(_("Remove all %1 saved chapter(s) from this device?"), total_count),
                ok_text = _("Remove all"),
                ok = function()
                    local kept = false
                    for _i, d in ipairs(s.store:downloads()) do
                        kept = s:removeNovelDownloads(d.novel_id) or kept
                    end
                    if kept then UI.info(_("Removed. The chapter you have open was kept.")) end
                    s:refreshScreens()
                end,
            })
        end,
    })
    for _i, d in ipairs(list) do
        local summary = s:novelSummary(d.novel_id)
        local name = (summary and summary.name) or d.name or T(_("Novel %1"), d.novel_id)
        table.insert(items, {
            text = name,
            mandatory = T(_("%1 · %2"), d.count, Model.formatBytes(d.bytes)),
            callback = function()
                UI.choice(name, {
                    { text = _("Open novel"), callback = function() s:showNovel(d.novel_id) end },
                    { text = _("Remove downloads"), callback = function()
                        UI.confirm({
                            text = T(_("Remove the %1 saved chapter(s) of %2 from this device?"), d.count, name),
                            ok_text = _("Remove"),
                            ok = function()
                                local kept = s:removeNovelDownloads(d.novel_id)
                                if kept then UI.info(_("Removed. The chapter you have open was kept.")) end
                                s:refreshScreens()
                            end,
                        })
                    end },
                })
            end,
        })
    end
    self:addEpubs(items)
    return items
end

-- Whole-novel EPUBs downloaded with "Download as EPUB".
function Downloads:addEpubs(items)
    local s = self.session
    local epubs = {}
    for key, e in pairs(s.store:getEpubs()) do
        table.insert(epubs, { novel_id = tonumber(key), entry = e })
    end
    if #epubs == 0 then return end
    table.sort(epubs, function(a, b) return (a.entry.path or "") < (b.entry.path or "") end)
    table.insert(items, UI.headerRow(_("EPUB files")))
    for _i, e in ipairs(epubs) do
        local file = e.entry.path:match("([^/]+)$") or e.entry.path
        table.insert(items, {
            text = file,
            mandatory = Model.formatBytes(e.entry.size),
            callback = function()
                local Epub = require("novarr.epub")
                UI.choice(file, {
                    { text = _("Open"), callback = function() s:openFile(e.entry.path) end },
                    { text = _("Remove"), callback = function()
                        UI.confirm({
                            text = T(_("Remove %1 from this device?"), file),
                            ok_text = _("Remove"),
                            ok = function()
                                if not Epub.remove(s, e.novel_id) then
                                    UI.info(_("This EPUB is open in the reader, so it was kept."))
                                end
                                s:refreshScreens()
                            end,
                        })
                    end },
                })
            end,
        })
    end
end

function Downloads:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

function Downloads.show(session)
    local self = setmetatable({ session = session, kind = "downloads" }, Downloads)
    self.menu = UI.showMenu(session, { title = _("Downloads"), items = self:items(), screen = self })
    return self
end

return Downloads
