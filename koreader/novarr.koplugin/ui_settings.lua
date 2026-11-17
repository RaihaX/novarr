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

local PREFETCH_CHOICES = { 0, 20, 50, 100, 200 }

local function onOff(v)
    return v and _("On") or _("Off")
end

local function failureText(session, url, e)
    if e.kind == "offline" then
        return T(_("Couldn't reach a server at %1.\n\nCheck the address (and port), that Novarr is running, and that this device is on the same network."), url)
    elseif e.kind == "not_novarr" then
        return T(_("%1 answered, but it isn't a Novarr server (or its API isn't available). Check the address and port."), url)
    elseif e.kind == "server" then
        return T(_("The server at %1 had a problem (error %2). Try again later."), url, e.status or "?")
    end
    return session:errorText(e)
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
                UI.info(failureText(session, url, e))
                return
            end
            local function save()
                UIManager:close(dialog)
                session:setServer(url)
                UI.notify(T(_("Connected to Novarr at %1"), url))
                if on_saved then Session.guard(on_saved) end
            end
            local old = session:serverUrl()
            local same_server = url == session:get("server_url_away")
            if old and old ~= url and not same_server and session:hasLocalData() then
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

--[[--
Asks for the second address of the same server (for when away from home,
e.g. a Tailscale name). Checked like the first one; one that can't be
reached right now can still be saved ("Save anyway"). An empty field
removes it.
]]
function Settings.askAwayServer(session, on_saved)
    local function done(dialog, url)
        UIManager:close(dialog)
        session:setAwayServer(url)
        if url then
            UI.notify(T(_("Second address saved: %1"), url))
        else
            UI.notify(_("Second address removed."))
        end
        if on_saved then Session.guard(on_saved) end
    end
    local function verify(text, dialog)
        if not text:find("%S") then
            done(dialog, nil)
            return true
        end
        local url = Api.normalizeUrl(text)
        if not url then
            UI.info(session:errorText(Api.err("bad_url")))
            return true
        end
        if url == session:serverUrl() then
            UI.info(_("That is already the server address. The second address is another way to reach the same server, for example from outside your home network."))
            return true
        end
        local function saveAnyway(message)
            UI.confirm({
                text = message .. "\n\n" .. _("Save it anyway? Novarr will use it when the server address can't be reached."),
                ok_text = _("Save anyway"),
                ok = function() done(dialog, url) end,
            })
        end
        local function check()
            local closer = UI.loading(T(_("Checking %1…"), url))
            local api = Api.new({ base_url = url, log = Session.log })
            local meta, e = api:meta({ block_timeout = 8, total_timeout = 15 })
            closer()
            if meta then
                done(dialog, url)
            elseif e.kind == "offline" then
                saveAnyway(T(_("Couldn't reach a server at %1 right now."), url))
            else
                UI.info(failureText(session, url, e))
            end
        end
        session:whenConnected(check, function()
            saveAnyway(_("No network connection, so the address can't be checked now."))
        end)
        return true
    end
    UI.input({
        title = _("Second address (away from home)"),
        value = session:get("server_url_away") or "",
        hint = "novarr.example.ts.net",
        description = _("Optional: another address of the same Novarr server, for example its Tailscale name. Novarr switches to it by itself when the server address can't be reached. Leave empty to remove it."),
        ok_text = _("Save"),
        callback = verify,
    })
end

function Settings:items()
    local s = self.session
    local items = {}
    table.insert(items, {
        text = _("Server address"),
        mandatory = s:serverUrl() or _("not set"),
        callback = function()
            Settings.askServer(s, function() s:refreshScreens() end)
        end,
    })
    table.insert(items, {
        text = _("Second address (away from home)"),
        mandatory = s:get("server_url_away") or _("not set"),
        callback = function()
            Settings.askAwayServer(s, function() s:refreshScreens() end)
        end,
    })
    if s:get("server_url_away") then
        table.insert(items, {
            text = s.address:which() == "away" and _("Using: second address") or _("Using: home address"),
            dim = true,
            select_enabled = false,
            callback = function() end,
        })
    end
    local prefetch = tonumber(s:get("prefetch")) or 0
    table.insert(items, {
        text = _("Chapters to fetch ahead while reading"),
        mandatory = prefetch <= 0 and _("Off") or tostring(prefetch),
        callback = function()
            local buttons = {}
            for _i, n in ipairs(PREFETCH_CHOICES) do
                local label = n == 0 and _("Off") or tostring(n)
                if n == s:get("prefetch") then label = UI.MARK_READ .. " " .. label end
                table.insert(buttons, { text = label, callback = function()
                    s:set("prefetch", n)
                    s:set("prefetch_user_set", true)
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
    toggle("wifi_auto_off", _("Turn Wi-Fi off while reading when everything is saved"))
    if s:get("wifi_auto_off") then
        local low = require("novarr.wifipolicy").lowWater(prefetch)
        table.insert(items, {
            text = prefetch > 0
                and T(_("Wi-Fi comes back at %1 chapters left or after %2 read"), low, require("novarr.wifipolicy").SYNC_AFTER)
                or _("Not while chapters to fetch ahead is Off"),
            dim = true,
            select_enabled = false,
            callback = function() end,
        })
    end
    -- How much of the reading had Wi-Fi on (wifitime.lua): hidden under 10 min of reading.
    local wifi_line = require("novarr.wifitime").line(s:wifiTime(), os.time())
    if wifi_line then
        table.insert(items, { text = wifi_line, dim = true, select_enabled = false, wifi_time = true, callback = function() end })
    end
    toggle("remove_after_reading", _("Remove chapters after reading (keeps the last 2)"))
    toggle("mark_read_at_end", _("Mark chapter read at its end"))
    -- Takes effect the next time a chapter is opened.
    toggle("join_chapters", _("Join chapters for smooth page turns"))
    toggle("show_reading_info", _("Show reading info in the status bar"))
    toggle("show_clock", _("Show clock while reading"))
    toggle("show_sync_status", _("Show sync status in the status bar"))
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
    toggle("auto_update_check", _("Check for plugin updates automatically"))
    toggle("auto_update_install", _("Install plugin updates automatically"))
    local Update = require("novarr.ui_update")
    local available = Update.available(s)
    local version_text
    if s.update_installed then
        version_text = T(_("Novarr %1 installed — restart KOReader to use it"), s.update_installed)
    elseif available then
        version_text = T(_("Update available (%1) — tap to install"), available)
    else
        version_text = T(_("Novarr plugin %1 — check for update"), Session.VERSION)
    end
    table.insert(items, {
        text = version_text,
        bold = available ~= nil or nil,
        callback = function()
            Update.check(s, function() s:refreshScreens() end)
        end,
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
