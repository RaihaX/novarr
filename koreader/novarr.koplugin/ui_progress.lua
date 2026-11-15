--[[--
A small centred dialog with a line of text and a Cancel button, used while
downloading chapters. setText() updates it in place (one partial refresh).
Built from long-standing widgets so it works on older KOReader releases.
]]

local Blitbuffer = require("ffi/blitbuffer")
local Button = require("ui/widget/button")
local CenterContainer = require("ui/widget/container/centercontainer")
local Device = require("device")
local Font = require("ui/font")
local FrameContainer = require("ui/widget/container/framecontainer")
local Geom = require("ui/geometry")
local InputContainer = require("ui/widget/container/inputcontainer")
local Size = require("ui/size")
local TextBoxWidget = require("ui/widget/textboxwidget")
local TextWidget = require("ui/widget/textwidget")
local UIManager = require("ui/uimanager")
local VerticalGroup = require("ui/widget/verticalgroup")
local VerticalSpan = require("ui/widget/verticalspan")
local _ = require("gettext")
local Screen = Device.screen

local ProgressDialog = InputContainer:extend{
    title = nil,
    text = "",
    cancel_callback = nil,
    cancelling_text = nil,
    name = "novarr_progress",
}

function ProgressDialog:init()
    local width = math.floor(math.min(Screen:getWidth(), Screen:getHeight()) * 0.8)
    local face = Font:getFace("infofont")
    local text_width = width - 2 * Size.padding.large
    -- Reserve room for three lines so updates never change the dialog's
    -- size (the text is replaced in place with a partial refresh).
    local probe = TextBoxWidget:new{ text = "M\nM\nM", face = face, width = text_width }
    local height = probe:getSize().h
    probe:free()
    self.text_widget = TextBoxWidget:new{
        text = self.text,
        face = face,
        width = text_width,
        height = height,
        alignment = "center",
    }
    self.cancel_button = Button:new{
        text = _("Cancel"),
        width = math.floor(width * 0.5),
        callback = function()
            self.cancelled = true
            self.cancel_button:disable()
            self:setText(self.cancelling_text or _("Cancelling after this chapter…"))
            if self.cancel_callback then self.cancel_callback() end
        end,
        show_parent = self,
    }
    local group = VerticalGroup:new{ align = "center" }
    if self.title then
        table.insert(group, TextWidget:new{ text = self.title, face = Font:getFace("tfont") })
        table.insert(group, VerticalSpan:new{ width = Size.padding.large })
    end
    table.insert(group, self.text_widget)
    table.insert(group, VerticalSpan:new{ width = Size.padding.large })
    table.insert(group, self.cancel_button)
    self.frame = FrameContainer:new{
        background = Blitbuffer.COLOR_WHITE,
        radius = Size.radius.window,
        padding = Size.padding.large,
        group,
    }
    self[1] = CenterContainer:new{
        dimen = Geom:new{ x = 0, y = 0, w = Screen:getWidth(), h = Screen:getHeight() },
        self.frame,
    }
end

function ProgressDialog:setText(text)
    if self.cancelled and text ~= (self.cancelling_text or _("Cancelling after this chapter…")) then return end
    self.text_widget:setText(text)
    UIManager:setDirty(self, function()
        return "ui", self.frame.dimen
    end)
end

-- The dialog only closes when the job finishes.
function ProgressDialog:onClose()
    return true
end

return ProgressDialog
