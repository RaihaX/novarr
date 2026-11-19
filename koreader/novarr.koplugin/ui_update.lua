--[[--
"Update the plugin over Wi-Fi": the thin UI around updater.lua.

    UIUpdate.check(session, on_change)       Settings: check, then offer to install
    UIUpdate.autoCheck(session, on_change)   after Home has loaded: quiet, at most once a day
    UIUpdate.autoInstall(session, on_change) "Install plugin updates automatically" (Home, idle)
    UIUpdate.cleanupLeftovers(session)       at start-up

The automatic check never turns Wi-Fi on and never shows a popup: it only
remembers the update in the settings (update_available_build/_version),
which makes Home's button read "Settings •" and the Settings row offer it.

Never a downgrade by itself (1.10.0): versions are compared numerically
(Updater.updateKind); a lower version on the server is not flagged and not
installed automatically. "Check for update" says so and offers "Install
version X anyway". The same version with another build is an update.
]]

local Device = require("device")
local Event = require("ui/event")
local UIManager = require("ui/uimanager")
local lfs = require("libs/libkoreader-lfs")
local _ = require("gettext")
local T = require("ffi/util").template

local Api = require("novarr.api")
local Model = require("novarr.model")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")
local Updater = require("novarr.updater")

local UIUpdate = {}

local LINKED = _("This copy of the plugin is linked from a development folder and can't update itself.")
local NOT_OFFERED = _("This Novarr server doesn't offer plugin updates.")

local FAIL = {
    invalid_manifest = _("The update on the server doesn't look right, so it wasn't installed. Nothing was changed."),
    download = _("The update didn't download completely. Nothing was changed."),
    verify = _("The update didn't download completely. Nothing was changed."),
    compile = _("The update on the server is damaged (a file in it doesn't load), so it wasn't installed. Nothing was changed."),
    storage = _("Couldn't write the update to this device's storage. Nothing was changed."),
    swap = _("Couldn't put the new version in place. Nothing was changed."),
}

local function deps(session)
    return {
        lfs = lfs,
        sha256 = function(s) return require("ffi/sha2").sha256(s) end,
        fetch = function(path) return session.api:pluginFile(path) end,
        log = Session.log,
    }
end

local function pluginDir(session)
    return session.plugin_dir
end

--- Whether the plugin folder is a link (a development checkout): never updated.
function UIUpdate.isLinked(session)
    local dir = pluginDir(session)
    return not dir or Updater.mode(lfs, dir) ~= "directory"
end

--- Manifest of the installed folder, computed once per KOReader start.
function UIUpdate.localManifest(session)
    if not session.local_plugin then
        local t0 = Session.nowMs()
        session.local_plugin = Updater.localManifest(deps(session), pluginDir(session))
        Session.log(string.format("Novarr: plugin build %s (%d files) computed in %d ms",
            session.local_plugin.build:sub(1, 12), #session.local_plugin.files, Session.nowMs() - t0))
    end
    return session.local_plugin
end

function UIUpdate.cleanupLeftovers(session)
    local dir = pluginDir(session)
    if not dir then return end
    for _i, removed in ipairs(Updater.cleanupLeftovers(deps(session), dir)) do
        Session.log("Novarr: removed " .. removed .. " left by an interrupted plugin update")
    end
end

-- ---------------------------------------------------------------- remembered update

--- The installed version (its _meta.lua; the running one when unknown).
local function installedVersion(session)
    local m = session.local_plugin
    return m and m.version or Api.VERSION
end

--[[--
"none" | "update" | "older" for the server's manifest m (Updater.updateKind):
an older version is never an update (no dot, no automatic install).
]]
local function kindOf(session, installed, m)
    return Updater.updateKind(installed.build, m.build, installed.version or Api.VERSION, m.version)
end

--- The version of the update the last check found (or "?"), or nil.
function UIUpdate.available(session)
    if session.update_installed then return nil end
    if not session:get("update_available_build") then return nil end
    local version = session:get("update_available_version") or "?"
    -- Flagged by an older plugin that offered any other build: a lower version is no update.
    if Updater.compareVersions(version, installedVersion(session)) == -1 then return nil end
    return version
end

local function remember(session, m)
    session:set("update_available_build", m.build)
    session:set("update_available_version", m.version or "?")
end

local function forget(session)
    if session:get("update_available_build") ~= nil then
        session:unset("update_available_build")
        session:unset("update_available_version")
    end
end

-- ---------------------------------------------------------------- restart

function UIUpdate.canRestart()
    if Session.autopilot_can_restart ~= nil then return Session.autopilot_can_restart end
    local ok, res = pcall(function() return Device:canRestart() end)
    return ok and res and true or false
end

--- Restarts KOReader the way its own Exit ▸ Restart KOReader menu entry does.
function UIUpdate.restart(session)
    if Session.autopilot_started then
        Session.log("NovarrAutopilot: restart requested (not restarting under the autopilot)")
        return
    end
    session:closeAllMenus()
    UIManager:broadcastEvent(Event:new("Restart"))
end

function UIUpdate.offerRestart(session, version)
    local text = T(_("Novarr was updated to %1. KOReader needs to restart to use it."), version or "?")
    if UIUpdate.canRestart() then
        UI.confirm({
            text = text,
            ok_text = _("Restart now"),
            cancel_text = _("Later"),
            ok = function() UIUpdate.restart(session) end,
        })
    else
        UI.info(text .. "\n\n" .. _("Close KOReader and open it again when you are ready."))
    end
end

-- ---------------------------------------------------------------- install

-- Loads every Novarr module the running version has not loaded yet, so
-- nothing of the new version is mixed in before KOReader restarts.
local function preloadModules()
    for name in pairs(package.preload) do
        if name:sub(1, 7) == "novarr." and not package.loaded[name] then
            pcall(require, name)
        end
    end
end

--[[--
Runs the staged install of manifest m one file per scheduled step (the
same download, verification, compile check and swap, with rollback, for
the user's and the automatic install). hooks:
    progress(i, total)   after each file (optional)
    cancelled() -> bool  checked before each file (optional)
    done(ok, code)       at the end: ok, or false and the failure code
]]
local function runJob(session, m, hooks)
    local job = Updater.newJob(deps(session), pluginDir(session), m, UIUpdate.localManifest(session))
    local ok, code = job:prepare()
    if not ok then
        hooks.done(false, code)
        return
    end
    UIUpdate.installing = true
    local ended = false
    local function finish(fok, fcode)
        if ended then return end
        ended = true
        UIUpdate.installing = false
        hooks.done(fok, fcode)
    end
    local step
    local function inner()
        if hooks.cancelled and hooks.cancelled() then
            job:abort()
            Session.log("Novarr: plugin update cancelled after " .. job.i .. " of " .. job.total .. " files")
            finish(false, "cancelled")
            return true
        end
        local state, scode = job:step()
        if not state then
            finish(false, scode)
            return true
        end
        if hooks.progress then hooks.progress(job.i, job.total) end
        if state == "more" then
            UIManager:scheduleIn(0.05, step)
            return true
        end
        preloadModules()
        local fok, ferr = job:finish()
        if not fok then
            finish(false, ferr)
            return true
        end
        Session.log(string.format("Novarr: plugin updated to %s (build %s): %d downloaded, %d unchanged",
            tostring(m.version), m.build:sub(1, 12), job.downloaded, job.reused))
        session.local_plugin = nil
        forget(session)
        session.update_installed = m.version or "?"
        finish(true)
        return true
    end
    step = function()
        if not Session.guard(inner) then
            -- An unexpected error: never leave a half-staged folder.
            job:abort()
            finish(false, "error")
        end
    end
    UIManager:scheduleIn(0.2, step)
end

--- Downloads, checks and installs the manifest m with a cancellable progress window.
function UIUpdate.install(session, m, on_change)
    if UIUpdate.isLinked(session) then
        UI.info(LINKED)
        return
    end
    local free = session:freeBytes()
    if free and free < Session.MIN_FREE_BYTES + (tonumber(m.size) or 0) then
        UI.info(_("There isn't enough free space on this device for the update (it needs 20 MB free). Nothing was changed."))
        return
    end
    local function run()
        local cancelled = false
        local dialog
        runJob(session, m, {
            progress = function(i, total)
                if not dialog then return end
                dialog:setText(T(_("Downloading update… %1 of %2"), i, total))
            end,
            cancelled = function() return cancelled end,
            done = function(ok, code)
                if dialog then UIManager:close(dialog) end
                if ok then
                    if on_change then on_change() end
                    UIUpdate.offerRestart(session, m.version)
                elseif code == "cancelled" then
                    UI.info(_("Update cancelled. Nothing was changed."))
                elseif code ~= "error" then
                    UI.info(FAIL[code] or FAIL.download)
                end
            end,
        })
        if UIUpdate.installing then
            dialog = require("novarr.ui_progress"):new{
                title = _("Updating Novarr"),
                text = T(_("Downloading update… %1 of %2"), 0, #m.files),
                cancelling_text = _("Cancelling…"),
                cancel_callback = function() cancelled = true end,
            }
            UIManager:show(dialog)
        end
    end
    session:whenConnected(run, function()
        UI.info(_("No network connection. Connect to Wi-Fi and try again."))
    end)
end

-- ---------------------------------------------------------------- automatic install

--- What autoInstallDue says now (see updater.lua). home: the Home screen object.
function UIUpdate.autoInstallState(session, home)
    local top = UIManager._window_stack and UIManager._window_stack[#UIManager._window_stack]
    local now = Session.nowMs()
    return Updater.autoInstallDue({
        enabled = session:get("auto_update_install") == true,
        flagged = session:get("update_available_build"),
        installed = session.update_installed ~= nil,
        linked = UIUpdate.isLinked(session),
        attempt = session:get("update_auto_attempt"),
        today = os.date("%Y-%m-%d"),
        home_top = home ~= nil and not home.closed and top ~= nil and top.widget == home.menu,
        reader_open = session:currentPath() ~= nil,
        connected = session:isConnected(),
        idle_s = (now - (Session.last_input or 0)) / 1000,
        idle_needed = UIUpdate.AUTO_IDLE_S,
    })
end

UIUpdate.AUTO_IDLE_S = Updater.AUTO_IDLE_S

--[[--
"Install plugin updates automatically": installs the flagged update now,
quietly (no progress window), with the same verification and rollback as
the user's install. Then one question: restart now or later (never a
restart by itself). A failure is logged, nothing is shown; the update
stays offered in Settings. The attempt is recorded first, so a build is
tried at most once a day. Never turns Wi-Fi on (the caller checked the
device is online).
]]
function UIUpdate.autoInstall(session, on_change)
    local build = session:get("update_available_build")
    session:set("update_auto_attempt", { build = build, day = os.date("%Y-%m-%d") })
    local m, e = session.api:pluginManifest()
    if not m then
        Session.log("Novarr: automatic plugin install: no manifest (" .. tostring(e and e.kind) .. ")")
        return
    end
    local installed = UIUpdate.localManifest(session)
    local kind = kindOf(session, installed, m)
    if kind ~= "update" then
        -- Never a downgrade by itself: an older version is only installed from Settings.
        Session.log("Novarr: automatic plugin install: " .. (kind == "older"
            and ("the server has an older version (" .. tostring(m.version) .. "), not installed") or "already up to date"))
        forget(session)
        if on_change then on_change() end
        return
    end
    local free = session:freeBytes()
    if free and free < Session.MIN_FREE_BYTES + (tonumber(m.size) or 0) then
        Session.log("Novarr: automatic plugin install: not enough free space")
        return
    end
    Session.log(string.format("Novarr: automatic plugin install of %s (build %s) starting", tostring(m.version), m.build:sub(1, 12)))
    runJob(session, m, {
        done = function(ok, code)
            if ok then
                if on_change then on_change() end
                UIUpdate.offerRestart(session, m.version)
            else
                Session.log("Novarr: automatic plugin install failed (" .. tostring(code) .. "); nothing was changed")
            end
        end,
    })
end

--- Asks whether to install the update described by manifest m.
function UIUpdate.offer(session, m, on_change)
    local text = Updater.availableText(Api.VERSION, m.version, T, _)
    if tonumber(m.size) then
        text = text .. "\n\n" .. T(_("Download size: %1."), Model.formatBytes(m.size))
    end
    UI.confirm({
        text = text,
        ok_text = _("Install update"),
        cancel_text = _("Not now"),
        ok = function() UIUpdate.install(session, m, on_change) end,
    })
end

--[[--
The server has a lower version than the one installed: said plainly, with
"Install version X anyway" (the user's choice; never automatic).
]]
function UIUpdate.offerOlder(session, m, on_change)
    local text = Updater.olderText(installedVersion(session), m.version, T, _)
    UI.confirm({
        text = text,
        ok_text = T(_("Install version %1 anyway"), m.version or "?"),
        cancel_text = _("Keep this version"),
        ok = function() UIUpdate.install(session, m, on_change) end,
    })
end

-- ---------------------------------------------------------------- check

--- "Check for update" from Settings: always asks the server, then offers to install.
function UIUpdate.check(session, on_change)
    if session.update_installed then
        UIUpdate.offerRestart(session, session.update_installed)
        return
    end
    if UIUpdate.isLinked(session) then
        UI.info(LINKED)
        return
    end
    if not session:serverUrl() then return end
    local function run()
        local closer = UI.loading(_("Checking for an update…"))
        local m, e = session.api:pluginManifest()
        local installed = UIUpdate.localManifest(session)
        closer()
        if not m then
            if e and e.kind == "not_found" then
                session:set("update_checked_at", Session.nowMs())
                UI.info(NOT_OFFERED)
            else
                UI.info(session:errorText(e))
            end
            return
        end
        session:set("update_checked_at", Session.nowMs())
        local kind = kindOf(session, installed, m)
        if kind == "none" then
            Session.log("Novarr: plugin is up to date (build " .. installed.build:sub(1, 12) .. ")")
            forget(session)
            if on_change then on_change() end
            UI.info(T(_("Novarr is up to date (%1)."), Api.VERSION))
            return
        elseif kind == "older" then
            Session.log(string.format("Novarr: the server has an older plugin version: %s (build %s), installed %s",
                tostring(m.version), m.build:sub(1, 12), tostring(installed.version or Api.VERSION)))
            forget(session)
            if on_change then on_change() end
            UIUpdate.offerOlder(session, m, on_change)
            return
        end
        Session.log(string.format("Novarr: plugin update available: %s (build %s), installed %s (build %s)",
            tostring(m.version), m.build:sub(1, 12), Api.VERSION, installed.build:sub(1, 12)))
        remember(session, m)
        if on_change then on_change() end
        UIUpdate.offer(session, m, on_change)
    end
    session:whenConnected(run, function()
        UI.info(_("No network connection. Connect to Wi-Fi and try again."))
    end)
end

--[[--
The quiet daily check, run after Home has loaded its data. Only when the
setting is on and the device is already online; never prompts.
]]
function UIUpdate.autoCheck(session, on_change)
    if not session:serverUrl() or session.update_installed or UIUpdate.isLinked(session) then return end
    -- A remembered update that is already installed (copied over USB, say).
    local flagged = session:get("update_available_build")
    if flagged and not session.update_flag_checked then
        session.update_flag_checked = true
        if UIUpdate.localManifest(session).build == flagged then
            forget(session)
            if on_change then on_change() end
        end
    end
    local now = Session.nowMs()
    local connected = session:isConnected()
    if not Updater.autoCheckDue({ enabled = session:get("auto_update_check"), connected = connected,
            last_checked = session:get("update_checked_at"), now = now }) then
        Session.log("Novarr: automatic plugin update check skipped (" ..
            (session:get("auto_update_check") == false and "switched off" or (not connected and "offline" or "checked within 24 h")) .. ")")
        return
    end
    local m, e = session.api:pluginManifest()
    if not m then
        Session.log("Novarr: automatic plugin update check: " .. tostring(e and e.kind))
        if e and e.kind ~= "offline" then session:set("update_checked_at", now) end
        return
    end
    session:set("update_checked_at", now)
    local installed = UIUpdate.localManifest(session)
    local kind = kindOf(session, installed, m)
    if kind == "update" then
        Session.log("Novarr: automatic plugin update check: " .. tostring(m.version) .. " available")
        remember(session, m)
    else
        -- An older version on the server is no update: no dot on Home.
        Session.log("Novarr: automatic plugin update check: " .. (kind == "older"
            and ("the server has an older version (" .. tostring(m.version) .. ")") or "up to date"))
        forget(session)
    end
    if on_change then on_change() end
end

return UIUpdate
