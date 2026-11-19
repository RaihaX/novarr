--[[--
"Link this Kindle": the KOReader side of auth.lua (which holds the rules,
the pairing state machine and their tests).

Linking: the server gives a short code; a dialog shows it large

    Link this Kindle
         K7M 4QX
    On your phone or computer, open Novarr, go to Account → Link a device
    and enter this code.
    This code works until 14:35.
    [Cancel]

while the plugin asks the server every few seconds (UIManager:scheduleIn,
never blocking) whether the code was entered. The dialog is drawn once and
not redrawn on each poll (e-ink). Linked: the token and the account are
kept (Session:linked) and "Linked to <name>" shows. The code expired:
"Get a new code". Cancel, Back or closing the dialog stops the polling.

Unlinking while offline (the server could not be told) says where to
remove the Kindle's access by hand: the website's Account → Devices.

The network: the whole thing starts through Session:whenConnected (the
Wi-Fi prompt, or Wi-Fi that Novarr switched off coming back on), and the
Wi-Fi management (wifi.lua) counts a pairing under way as a Novarr action
using the network, so Wi-Fi is not switched off while it runs.
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
local T = require("ffi/util").template
local Screen = Device.screen

local Auth = require("novarr.auth")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local UIAuth = {
    current = nil, -- the pairing under way (Auth.Pairing), for wifi.lua and dumps
}

-- ---------------------------------------------------------------- the code dialog

local PairingDialog = InputContainer:extend{
    name = "novarr_pairing",
    code = "",
    expires_at = nil, -- os.time() when the code stops working (from the server's expires_in)
    cancel_callback = nil,
}

function PairingDialog:init()
    local width = math.floor(math.min(Screen:getWidth(), Screen:getHeight()) * 0.85)
    local pad = Size.padding.large
    local text_width = width - 2 * pad
    if Device:hasKeys() then
        self.key_events = { Close = { { Device.input.group.Back } } }
    end
    local group = VerticalGroup:new{ align = "center" }
    table.insert(group, TextWidget:new{ text = _("Link this Kindle"), face = Font:getFace("tfont") })
    table.insert(group, VerticalSpan:new{ width = pad * 2 })
    table.insert(group, TextWidget:new{ text = self.code, face = Font:getFace("tfont", 48), bold = true })
    table.insert(group, VerticalSpan:new{ width = pad * 2 })
    table.insert(group, TextBoxWidget:new{
        text = _("On your phone or computer, open Novarr, go to Account → Link a device and enter this code."),
        face = Font:getFace("infofont"),
        width = text_width,
        alignment = "center",
    })
    table.insert(group, VerticalSpan:new{ width = pad })
    table.insert(group, TextBoxWidget:new{
        text = _("This Kindle links by itself once the code is entered."),
        face = Font:getFace("cfont", 16),
        width = text_width,
        alignment = "center",
    })
    if self.expires_at then
        -- Drawn once (e-ink): a time, not a countdown.
        table.insert(group, VerticalSpan:new{ width = math.floor(pad / 2) })
        table.insert(group, TextBoxWidget:new{
            text = T(_("This code works until %1."), Auth.clockTime(self.expires_at, os.date)),
            face = Font:getFace("cfont", 16),
            width = text_width,
            alignment = "center",
        })
    end
    table.insert(group, VerticalSpan:new{ width = pad * 2 })
    table.insert(group, Button:new{
        text = _("Cancel"),
        width = math.floor(width * 0.5),
        callback = function() UIManager:close(self) end,
        show_parent = self,
    })
    self.frame = FrameContainer:new{
        background = Blitbuffer.COLOR_WHITE,
        radius = Size.radius.window,
        padding = pad,
        group,
    }
    self[1] = CenterContainer:new{
        dimen = Geom:new{ x = 0, y = 0, w = Screen:getWidth(), h = Screen:getHeight() },
        self.frame,
    }
end

-- Back key: same as Cancel.
function PairingDialog:onClose()
    UIManager:close(self)
    return true
end

-- However it closes (Cancel, Back, closed by the flow): polling stops.
function PairingDialog:onCloseWidget()
    if self.closed then return end
    self.closed = true
    if self.cancel_callback then Session.guard(self.cancel_callback) end
end

-- ---------------------------------------------------------------- linking

local function failureText(session, e)
    if type(e) == "table" and e.kind == "not_found" then
        return _("This Novarr server doesn't offer linking devices (it may need updating).")
    end
    if type(e) == "table" and e.kind == "server" and (e.message == "bad pairing" or e.message == "bad token"
            or (e.message or ""):match("^unknown status")) then
        return _("The Novarr server's answer about linking doesn't make sense. Try again later.")
    end
    return session:errorText(e)
end

--[[--
Runs the pairing (already connected): a code, the dialog, polling until
linked, expired, cancelled or failed. done(linked) runs at the end.
]]
function UIAuth.pair(session, done)
    if UIAuth.current then return end -- one at a time
    local dialog
    local closer = UI.loading(_("Getting a code from Novarr…"))
    local function closeLoading()
        if closer then closer() closer = nil end
    end
    local flow
    flow = Auth.Pairing.new({
        start = function() return session.api:pairingStart() end,
        poll = function(secret) return session.api:pairingStatus(secret) end,
        schedule = function(secs, fn) UIManager:scheduleIn(secs, fn) end,
        unschedule = function(fn) UIManager:unschedule(fn) end,
        now = os.time,
        on_code = function(code)
            closeLoading()
            dialog = PairingDialog:new{ code = code, expires_at = flow.expires_at,
                cancel_callback = function() flow:cancel() end }
            UIManager:show(dialog)
        end,
        on_linked = function(token, user) session:linked(token, user) end,
        on_finish = function(kind, payload)
            closeLoading()
            UIAuth.current = nil
            if dialog and not dialog.closed then
                dialog.closed = true -- closed by the flow: no cancel
                UIManager:close(dialog)
            end
            dialog = nil
            Session.log("Novarr: linking ended: " .. tostring(kind))
            if kind == "linked" then
                local name = payload and payload.name
                UI.info(name and T(_("Linked to %1"), name) or _("Linked to your Novarr account"))
                if done then Session.guard(done, true) end
            elseif kind == "expired" then
                UI.confirm({
                    text = _("The code expired before it was entered."),
                    ok_text = _("Get a new code"),
                    cancel_text = _("Close"),
                    ok = function() UIAuth.link(session, done) end,
                })
            elseif kind == "error" then
                UI.info(failureText(session, payload))
            end
        end,
    })
    flow.poll = Session.wrap(flow.poll)
    UIAuth.current = flow
    flow:start()
    closeLoading()
end

--[[--
"Link this Kindle": connects (Wi-Fi prompt) and asks the server's meta
first; a server that does not need sign-in (any more) says so and nothing
is blocked; else the pairing runs.
]]
function UIAuth.link(session, done)
    if not session:serverUrl() then return end
    session:whenConnected(function()
        local closer = UI.loading(_("Checking the Novarr server…"))
        local meta = session:refreshAuthState(true)
        closer()
        if meta and not Auth.requiredFromMeta(meta) then
            session:refreshScreens()
            UI.info(_("This Novarr server doesn't need sign-in: there is nothing to link."))
            return
        end
        UIAuth.pair(session, done)
    end, function()
        UI.info(_("No network connection. Connect to Wi-Fi and try again."))
    end, { reason = "linking this Kindle" })
end

--- "This Kindle isn't linked … Link it now?" (Sync tapped while not linked).
function UIAuth.offerLink(session, done)
    session.auth_told = true
    UI.confirm({
        text = _("This Kindle isn't linked to your Novarr account, so nothing can be synced.\n\nYour reading on this Kindle is kept and sent once it is linked."),
        ok_text = _("Link this Kindle"),
        cancel_text = _("Later"),
        ok = function() UIAuth.link(session, done) end,
    })
end

--- Told once when the server stopped accepting this Kindle (a 401).
function UIAuth.notifyUnlinked(session)
    UI.confirm({
        text = _("Novarr no longer accepts this Kindle (the link was removed or has expired).\n\nYour reading on this Kindle is kept and sent once it is linked again."),
        ok_text = _("Link this Kindle"),
        cancel_text = _("Later"),
        ok = function() UIAuth.link(session) end,
    })
end

--- "Unlink this Kindle" (Settings): asks first.
function UIAuth.unlink(session, done)
    UI.confirm({
        text = _("Unlink this Kindle from your Novarr account?\n\nNothing is synced until it is linked again. Chapters saved here stay."),
        ok_text = _("Unlink"),
        ok = function()
            if session:unlink() then
                UI.notify(_("This Kindle is unlinked."))
            else
                -- The server could not be told: its access is still listed there.
                UI.info(_("This Kindle is unlinked. Its access is still listed in your account on the website; remove it there under Account → Devices."))
            end
            if done then Session.guard(done) end
        end,
    })
end

--- "Account: <name>" (Settings): who this Kindle is linked to.
function UIAuth.showAccount(session)
    local u = session:authUser() or {}
    local lines = { T(_("This Kindle is linked to %1."), u.name or u.username or _("your Novarr account")) }
    if u.username and u.username ~= u.name then table.insert(lines, T(_("User name: %1"), u.username)) end
    if session:role() == "reader" then
        table.insert(lines, _("This account can read and sync, but not add novels."))
    end
    table.insert(lines, T(_("Server: %1"), session:serverUrl() or ""))
    UI.info(table.concat(lines, "\n"))
end

--- Whether a pairing is under way (wifi.lua keeps Wi-Fi on meanwhile).
function UIAuth.active()
    return UIAuth.current ~= nil and UIAuth.current.state ~= "done"
end

return UIAuth
