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
            local old = session:serverUrl()
            -- The second address, or another address of the same install
            -- (meta's instance id, 1.10.0): nothing on this device is removed.
            local same_server = old ~= nil and session:isSameServer(url, meta)
            if old and not same_server and meta.instance and not session:get("server_instance") then
                -- The old server's id isn't known yet (set up before 1.10.0): ask it once (4 s at most).
                local asking = UI.loading(T(_("Checking %1…"), old))
                session:probeAddress(old)
                asking()
                same_server = session:isSameServer(url, meta)
            end
            local function save()
                UIManager:close(dialog)
                session:setServer(url, { same_server = same_server })
                -- Whether this server needs sign-in (auth.lua): kept for it.
                session:noteMeta(meta)
                UI.notify(T(_("Connected to Novarr at %1"), url))
                if on_saved then Session.guard(on_saved) end
                if session:needsLink() then
                    UI.confirm({
                        text = _("This Novarr server needs sign-in. Link this Kindle to your account now?"),
                        ok_text = _("Link this Kindle"),
                        cancel_text = _("Later"),
                        ok = function() require("novarr.ui_auth").link(session, function() session:refreshScreens() end) end,
                    })
                end
            end
            if old and old ~= url and not same_server and session:hasLocalData() then
                -- A different server (or one that can't tell): first, once, send
                -- what is queued to the old one if it answers (4 s at most to find out).
                if session.queue:count() > 0 then
                    local closing = UI.loading(_("Sending your changes to the old server…"))
                    session:flushBeforeSwitch()
                    closing()
                end
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
                -- The same server: what it says about sign-in holds (its id never replaces a known one).
                session:noteMeta(meta, { keep_instance = true })
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

--[[--
Asks for this device's name (what other devices show: "… was read on
Kindle"). Printable ASCII, at most 40 characters; empty: the model name.
]]
function Settings.askDeviceName(session, on_saved)
    local Position = require("novarr.position")
    UI.input({
        title = _("Name of this device"),
        value = session.settings:readSetting("device_name") or "",
        hint = Position.deviceName(Session.deviceModel()),
        description = _("Your other devices show this name when they offer to go to the place you read here. Leave empty to use the model name."),
        ok_text = _("Save"),
        callback = function(text)
            local name = session:setDeviceName(text)
            UI.notify(T(_("This device is now called %1"), name))
            if on_saved then Session.guard(on_saved) end
        end,
    })
end

--[[--
The rows, in four sections under header rows: Server and account, Reading,
Wi-Fi and storage, About and updates. Labels are kept short; a long one
wraps onto a second line (the menu is not single-line, see Settings.show).
]]
function Settings:items()
    local s = self.session
    local items = {}
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
    local function note(text, extra)
        local row = { text = text, dim = true, select_enabled = false, callback = function() end }
        for k, v in pairs(extra or {}) do row[k] = v end
        table.insert(items, row)
    end

    -- ------------------------------------------------ Server and account
    table.insert(items, UI.headerRow(_("Server and account")))
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
        note(s.address:which() == "away" and _("Using: second address") or _("Using: home address"))
    end
    -- The account (only when the server requires sign-in: auth.lua's accountRows).
    local UIAuth = require("novarr.ui_auth")
    for _i, row in ipairs(require("novarr.auth").accountRows(s.settings, s:serverUrl())) do
        if row.id == "link" then
            table.insert(items, {
                text = _("Link this Kindle"),
                mandatory = _("not linked"),
                bold = true,
                callback = function() UIAuth.link(s, function() self:rebuild() end) end,
            })
        elseif row.id == "account" then
            table.insert(items, {
                text = T(_("Account: %1"), row.name or _("linked")),
                mandatory = row.role == "reader" and _("reader") or nil,
                callback = function() UIAuth.showAccount(s) end,
            })
        elseif row.id == "unlink" then
            table.insert(items, {
                text = _("Unlink this Kindle"),
                callback = function() UIAuth.unlink(s, function() self:rebuild() end) end,
            })
        end
    end
    table.insert(items, {
        text = _("Name of this device"),
        mandatory = s.device_name,
        callback = function()
            Settings.askDeviceName(s, function() self:rebuild() end)
        end,
    })

    -- ------------------------------------------------ Reading
    table.insert(items, UI.headerRow(_("Reading")))
    toggle("mark_read_at_end", _("Mark chapter read at its end"))
    -- Takes effect the next time a chapter is opened.
    toggle("join_chapters", _("Join chapters for smooth page turns"))
    toggle("keep_style", _("Keep font and layout between chapters"))
    -- A Novarr chapter open in the reader shows the change at once (Reading.applyInfoPosition).
    local function applyToReader()
        local reader = s.reader
        if reader and reader.reading then
            Session.guard(require("novarr.reading").applyInfoPosition, reader)
        end
    end
    table.insert(items, {
        text = _("Reading info in the status bar"),
        mandatory = onOff(s:get("show_reading_info")),
        callback = function()
            s:set("show_reading_info", not s:get("show_reading_info"))
            applyToReader()
            self:rebuild()
        end,
    })
    local POSITION_NAMES = { bottom = _("Bottom"), top = _("Top"), both = _("Top and bottom") }
    local position = s:get("info_position")
    if not POSITION_NAMES[position] then position = "bottom" end
    table.insert(items, {
        text = _("Reading info position"),
        mandatory = POSITION_NAMES[position],
        callback = function()
            local buttons = {}
            for _i, key in ipairs(require("novarr.readinginfo").POSITIONS) do
                local label = POSITION_NAMES[key]
                if key == position then label = UI.MARK_READ .. " " .. label end
                table.insert(buttons, { text = label, callback = function()
                    s:set("info_position", key)
                    applyToReader()
                    self:rebuild()
                end })
            end
            UI.choice(_("Reading info position"), buttons)
        end,
    })
    if position ~= "bottom" then
        note(_("Top uses KOReader's Alt status bar, switched on in Novarr chapters only"))
    end
    toggle("show_clock", _("Show clock while reading"))
    toggle("show_sync_status", _("Sync status in the status bar"))
    toggle("hold_menu", _("Hold the status bar for the Novarr menu"))
    toggle("tap_menu", _("Tap the top of the page for the Novarr menu"))
    toggle("tap_gestures", _("Corner tap: chapter list · two-finger tap: home"))
    -- Off: no question and no request for it (position.lua); the device is still named with every write.
    toggle("position_prompt", _("Offer the place read on another device"))
    toggle("show_covers", _("Show covers"))
    toggle("open_on_start", _("Open Novarr when KOReader starts"))
    table.insert(items, {
        text = _("Novarr first in KOReader's menu"),
        mandatory = onOff(s:get("menu_first")),
        callback = function()
            s:set("menu_first", not s:get("menu_first"))
            require("novarr.menuorder").apply(s:get("menu_first"))
            self:rebuild()
        end,
    })
    table.insert(items, {
        text = _("Book cover as sleep screen"),
        mandatory = onOff(s:sleepCoverOn()),
        callback = function()
            s:setSleepCover(not s:sleepCoverOn())
            self:rebuild()
        end,
    })

    -- ------------------------------------------------ Wi-Fi and storage
    table.insert(items, UI.headerRow(_("Wi-Fi and storage")))
    local prefetch = tonumber(s:get("prefetch")) or 0
    table.insert(items, {
        text = _("Chapters to fetch ahead"),
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
    table.insert(items, {
        text = _("Turn Wi-Fi off while reading"),
        mandatory = onOff(s:get("wifi_auto_off")),
        callback = function()
            s:set("wifi_auto_off", not s:get("wifi_auto_off"))
            -- Switched off while Novarr has Wi-Fi off: it comes back on now.
            s.guard(function() require("novarr.wifi").releaseIfDisabled("switched off in Settings") end)
            self:rebuild()
        end,
    })
    if not s:get("wifi_auto_off") then
        note(_("Wi-Fi stays on: your place is sent as you read"))
    else
        local low = require("novarr.wifipolicy").lowWater(prefetch)
        note(prefetch > 0
            and T(_("Off once everything is saved; back at %1 chapters left or after %2 read"), low, require("novarr.wifipolicy").SYNC_AFTER)
            or _("Not while chapters to fetch ahead is Off"))
    end
    -- How much of the reading had Wi-Fi on (wifitime.lua): hidden under 10 min of reading.
    local wifi_line = require("novarr.wifitime").line(s:wifiTime(), os.time())
    if wifi_line then note(wifi_line, { wifi_time = true }) end
    toggle("remove_after_reading", _("Remove read chapters (keeps the last 2)"))
    table.insert(items, {
        text = _("Storage used"),
        mandatory = self.storage_text or _("tap to measure"),
        callback = function()
            self.storage_text = Model.formatBytes(s.store:totalSize())
            self:rebuild()
        end,
    })

    -- ------------------------------------------------ About and updates
    table.insert(items, UI.headerRow(_("About and updates")))
    toggle("auto_update_check", _("Check for updates automatically"))
    toggle("auto_update_install", _("Install updates automatically"))
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
    -- Rows may wrap onto a second line instead of being cut off.
    self.menu = UI.showMenu(session, { title = _("Novarr settings"), items = self:items(), screen = self,
        multi_line = true })
    return self
end

return Settings
