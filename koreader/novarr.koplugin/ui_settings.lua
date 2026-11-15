--[[--
Settings screen and the server address dialog (first run and changes).
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")
local T = require("ffi/util").template

local Api = require("novarr.api")
local Model = require("novarr.model")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local Settings = {}
Settings.__index = Settings

local PREFETCH_CHOICES = { 0, 1, 3, 5, 10 }

local function onOff(v)
    return v and _("On") or _("Off")
end

--[[--
Asks for the server address, normalises it like the iOS app, verifies it
with /meta and saves it. on_saved() runs after a successful save.
]]
function Settings.askServer(session, on_saved)
    local function verify(text, dialog)
        local url = Api.normalizeUrl(text)
        if not url then
            UI.info(session:errorText(Api.err("bad_url")))
            return true -- keep the dialog open
        end
        local function check()
            local closer = UI.loading(T(_("Checking %1…"), url))
            local api = Api.new({ base_url = url, log = Session.log })
            local meta, e = api:meta()
            closer()
            if not meta then
                local text_err
                if e.kind == "offline" then
                    text_err = T(_("Couldn't reach a server at %1.\n\nCheck the address (and port), that Novarr is running, and that this device is on the same network."), url)
                elseif e.kind == "not_novarr" then
                    text_err = T(_("%1 answered, but it isn't a Novarr server (or its API isn't available). Check the address and port."), url)
                elseif e.kind == "server" then
                    text_err = T(_("The server at %1 had a problem (error %2). Try again later."), url, e.status or "?")
                else
                    text_err = session:errorText(e)
                end
                UI.info(text_err)
                return
            end
            local function save()
                UIManager:close(dialog)
                session:setServer(url)
                UI.notify(T(_("Connected to Novarr at %1"), url))
                if on_saved then Session.guard(on_saved) end
            end
            local old = session:serverUrl()
            if old and old ~= url and session:hasLocalData() then
                UI.confirm({
                    text = _("This is a different server. Saved chapters and changes not yet sent to the old server will be removed from this device."),
                    ok_text = _("Switch server"),
                    ok = save,
                })
            else
                save()
            end
        end
        session:whenConnected(check, function()
            UI.info(_("No network connection. Connect to Wi-Fi and try again."))
        end)
        return true -- closed by save()
    end
    UI.input({
        title = _("Novarr server address"),
        value = session:serverUrl() or "",
        hint = "192.168.1.34:8080",
        description = _("The address you use to open Novarr in a web browser, for example 192.168.1.34:8080."),
        ok_text = _("Connect"),
        callback = verify,
    })
end

function Settings:items()
    local s = self.session
    local items = {}
    table.insert(items, {
        text = _("Server"),
        mandatory = s:serverUrl() or _("not set"),
        callback = function()
            Settings.askServer(s, function() s:refreshScreens() end)
        end,
    })
    table.insert(items, {
        text = _("Chapters to fetch ahead while reading"),
        mandatory = tostring(s:get("prefetch")),
        callback = function()
            local buttons = {}
            for _i, n in ipairs(PREFETCH_CHOICES) do
                local label = n == 0 and _("None") or tostring(n)
                if n == s:get("prefetch") then label = UI.MARK_READ .. " " .. label end
                table.insert(buttons, { text = label, callback = function()
                    s:set("prefetch", n)
                    self:rebuild()
                end })
            end
            UI.choice(_("Chapters to fetch ahead while reading"), buttons)
        end,
    })
    local function toggle(key, label)
        table.insert(items, {
            text = label,
            mandatory = onOff(s:get(key)),
            callback = function()
                s:set(key, not s:get(key))
                self:rebuild()
            end,
        })
    end
    toggle("remove_after_reading", _("Remove chapters after reading (keeps the last 2)"))
    toggle("mark_read_at_end", _("Mark chapter read at its end"))
    toggle("show_reading_info", _("Show reading info in the status bar"))
    toggle("show_clock", _("Show clock while reading"))
    toggle("show_covers", _("Show covers"))
    toggle("keep_style", _("Keep font and layout from chapter to chapter"))
    toggle("open_on_start", _("Open Novarr when KOReader starts"))
    table.insert(items, {
        text = _("Sleep screen shows the book cover"),
        mandatory = onOff(s:sleepCoverOn()),
        callback = function()
            s:setSleepCover(not s:sleepCoverOn())
            self:rebuild()
        end,
    })
    table.insert(items, {
        text = _("Storage used"),
        mandatory = self.storage_text or _("tap to measure"),
        callback = function()
            self.storage_text = Model.formatBytes(s.store:totalSize())
            self:rebuild()
        end,
    })
    table.insert(items, {
        text = T(_("Novarr plugin %1"), Session.VERSION),
        dim = true,
        callback = function() end,
    })
    return items
end

function Settings:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

function Settings.show(session)
    local self = setmetatable({ session = session, kind = "settings" }, Settings)
    self.storage_text = Model.formatBytes(session.store:totalSize())
    self.menu = UI.showMenu(session, { title = _("Novarr settings"), items = self:items(), screen = self })
    return self
end

return Settings
