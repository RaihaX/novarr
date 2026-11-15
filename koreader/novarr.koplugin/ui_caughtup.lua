--[[--
"You're up to date": a calm full-screen page shown when the reader reaches
the end of the newest downloaded chapter of a novel (instead of a dialog).
Cover, a short text, and three buttons: Check for new chapters (only when
connected), Choose another novel (Home), Close. Stock widgets only.
]]

local Blitbuffer = require("ffi/blitbuffer")
local Button = require("ui/widget/button")
local CenterContainer = require("ui/widget/container/centercontainer")
local Device = require("device")
local Font = require("ui/font")
local FrameContainer = require("ui/widget/container/framecontainer")
local Geom = require("ui/geometry")
local GestureRange = require("ui/gesturerange")
local ImageWidget = require("ui/widget/imagewidget")
local InputContainer = require("ui/widget/container/inputcontainer")
local Size = require("ui/size")
local TextBoxWidget = require("ui/widget/textboxwidget")
local TextWidget = require("ui/widget/textwidget")
local UIManager = require("ui/uimanager")
local VerticalGroup = require("ui/widget/verticalgroup")
local VerticalSpan = require("ui/widget/verticalspan")
local _ = require("gettext")
local T = require("ffi/util").template
local Screen = Device.screen

local Covers = require("novarr.covers")
local Model = require("novarr.model")
local ReadingInfo = require("novarr.readinginfo")

local CaughtUp = InputContainer:extend{
    name = "novarr_caughtup",
    covers_fullscreen = true,
    session = nil,
    info = nil, -- {novel_id, chapter_id, connected, on_check}
}

function CaughtUp:init()
    local s, info = self.session, self.info
    local novel = s:novelSummary(info.novel_id) or { name = "" }
    local detail = s:cached("novel_" .. info.novel_id)
    if detail and detail.novel then novel = detail.novel end
    self.dimen = Geom:new{ x = 0, y = 0, w = Screen:getWidth(), h = Screen:getHeight() }
    local w = self.dimen.w
    local dpi = Screen:getDPI()
    local pad = Size.padding.large
    local cover_h = math.floor(math.min(dpi * 2.2, self.dimen.h * 0.4))
    local cover_w = math.floor(cover_h * 2 / 3)

    local group = VerticalGroup:new{ align = "center" }
    -- Cover (cached large cover, else the medium one, else a placeholder).
    local path
    local covers = s:covers()
    for _i, url in ipairs({ novel.cover_large_url, novel.cover_url }) do
        path = url and covers:cached(url)
        if path then break end
    end
    local inner
    if path then
        local ok, img = pcall(function()
            local widget = ImageWidget:new{ file = path, file_do_cache = false,
                width = cover_w, height = cover_h, scale_factor = 0 }
            widget:_render()
            return widget
        end)
        if ok and img then
            self.image = img
            inner = CenterContainer:new{ dimen = Geom:new{ w = cover_w, h = cover_h }, img }
        end
    end
    if not inner then
        inner = CenterContainer:new{
            dimen = Geom:new{ w = cover_w, h = cover_h },
            TextWidget:new{ text = Covers.initials(novel.name), face = Font:getFace("tfont", 40) },
        }
    end
    table.insert(group, FrameContainer:new{ bordersize = Size.border.thin, padding = 0, margin = 0, inner })
    table.insert(group, VerticalSpan:new{ width = pad * 2 })
    table.insert(group, TextWidget:new{ text = _("You're up to date"), face = Font:getFace("tfont", 26), bold = true })
    table.insert(group, VerticalSpan:new{ width = pad })

    local lines = {}
    local cl = s:chapterList(info.novel_id)
    local n = cl and Model.ordinal(cl.list, info.chapter_id)
    if n then
        table.insert(lines, T(_("Chapter %1 was the latest."), ReadingInfo.thousands(n)))
    end
    local t = Model.parseIso(novel.updated_at)
    if t then
        table.insert(lines, T(_("The server last got a new chapter on %1."), os.date("%d %b %Y", t)))
    end
    if #lines > 0 then
        table.insert(group, TextBoxWidget:new{
            text = table.concat(lines, "\n"),
            face = Font:getFace("cfont", 20),
            width = math.floor(w * 0.85),
            alignment = "center",
        })
    end
    table.insert(group, VerticalSpan:new{ width = pad * 2 })

    local bw = math.floor(w * 0.7)
    local function button(text, cb)
        table.insert(group, Button:new{
            text = text,
            width = bw,
            text_font_size = 20,
            bordersize = Size.border.button,
            radius = Size.radius.button,
            callback = cb,
            show_parent = self,
        })
        table.insert(group, VerticalSpan:new{ width = pad })
    end
    if info.connected then
        button(_("Check for new chapters"), function()
            self:close()
            if info.on_check then info.on_check() end
        end)
    end
    button(_("Choose another novel"), function()
        self:close()
        s:showHome()
    end)
    button(_("Close"), function() self:close() end)

    self[1] = FrameContainer:new{
        background = Blitbuffer.COLOR_WHITE,
        bordersize = 0, padding = 0, margin = 0,
        CenterContainer:new{ dimen = self.dimen:copy(), group },
    }
    if Device:hasKeys() then
        self.key_events = { Close = { { Device.input.group.Back } } }
    end
    -- Swallow taps outside the buttons (they would turn the page underneath).
    self.ges_events = {
        TapAnywhere = { GestureRange:new{ ges = "tap", range = self.dimen } },
    }
end

function CaughtUp:onTapAnywhere() return true end

function CaughtUp:close()
    UIManager:close(self)
end

function CaughtUp:onClose()
    self:close()
    return true
end

function CaughtUp:onCloseWidget()
    if self.image then pcall(self.image.free, self.image) end
    self.image = nil
    UIManager:setDirty(nil, "full")
end

function CaughtUp.show(session, info)
    local page = CaughtUp:new{ session = session, info = info }
    UIManager:show(page, "full")
    return page
end

return CaughtUp
