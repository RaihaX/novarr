--[[--
Novarr for KOReader: read novels from a Novarr server chapter by chapter,
offline too, with progress synced back to the server.

Loaded in both the file manager and the reader (is_doc_only = false). In the
reader it only acts when the open document is one of its own chapter files
(recognised from the path); every other document is left alone.
]]

local Dispatcher = require("dispatcher")
local UIManager = require("ui/uimanager")
local WidgetContainer = require("ui/widget/container/widgetcontainer")
local logger = require("logger")
local _ = require("gettext")

-- The plugin's own modules are required as "novarr.<name>" so they can never
-- clash with another plugin's module of the same name (plugin folders share
-- package.path). They load lazily, on first use.
local plugin_dir = (debug.getinfo(1, "S").source or ""):match("^@(.*)/[^/]*$") or "."
local MODULES = {
    "address", "api", "auth", "autopilot", "chapterdoc", "checks", "checkstate", "covers", "discover", "epub", "epubflow",
    "groups", "highlights", "http_client", "json", "model", "queue", "reading", "readinginfo", "searchstate", "session",
    "position", "quickmenu", "stats", "store", "sync", "ui_highlights", "ui_quickmenu", "wifitime",
    "ui_caughtup", "ui_chapters", "ui_common", "ui_coverlist", "ui_discover", "ui_downloads", "ui_home", "ui_library",
    "ui_auth", "ui_novel", "ui_progress", "ui_settings", "ui_shelf", "ui_update", "updater", "wifi", "wifipolicy",
}
for _i, name in ipairs(MODULES) do
    package.preload["novarr." .. name] = function()
        local chunk, err = loadfile(plugin_dir .. "/" .. name .. ".lua")
        if not chunk then error(err) end
        return chunk()
    end
end

-- The version shown and sent to the server comes from _meta.lua alone.
do
    local Updater = require("novarr.updater")
    local version = Updater.parseVersion(Updater.readFile(plugin_dir .. "/_meta.lua"))
    if version then require("novarr.api").VERSION = version end
    require("novarr.api").log = function(text) logger.info(text) end
end

local ChapterDoc = require("novarr.chapterdoc")
local Session = require("novarr.session")
Session.plugin_dir = plugin_dir

local Novarr = WidgetContainer:extend{
    name = "novarr",
    is_doc_only = false,
}

-- Recognises a Novarr chapter path (also through the real path of the root).
-- Returns novel_id, chapter_id for a chapter file; novel_id, nil, {block, n}
-- for a group file (joined chapters).
local function chapterIds(file)
    if not file then return nil end
    local roots = { Session.root }
    local ok, real = pcall(function() return require("ffi/util").realpath(Session.root) end)
    if ok and real and real ~= Session.root then table.insert(roots, real) end
    for _i, root in ipairs(roots) do
        local n, c = ChapterDoc.idsFromPath(file, root)
        if n then return n, c end
        local gn, block, size = ChapterDoc.groupFromPath(file, root)
        if gn then return gn, nil, { block = block, n = size } end
    end
    return nil
end

function Novarr:init()
    Session.guard(function()
        Session:init()
        Session.plugin = self
        -- Wi-Fi stays on (the setting is off) but Novarr had switched it off: on again.
        local Wifi = require("novarr.wifi")
        if not Wifi.enabled() and Wifi.offByNovarr() then
            UIManager:scheduleIn(2, function()
                Session.guard(function() Wifi.releaseIfDisabled("Wi-Fi stays on now") end)
            end)
        end
        if not Session.leftovers_checked then
            -- Once per KOReader start: remove what an interrupted plugin update left behind.
            Session.leftovers_checked = true
            require("novarr.ui_update").cleanupLeftovers(Session)
        end
        self:onDispatcherRegisterActions()
        if self.ui and self.ui.menu then
            self.ui.menu:registerToMainMenu(self)
        end
        local file = self.ui and self.ui.document and self.ui.document.file
        if file then
            local novel_id, chapter_id, group = chapterIds(file)
            if novel_id then
                require("novarr.reading").attach(self, novel_id, chapter_id, group)
            else
                -- Someone else's document: forget any "return to Novarr" intent.
                Session.return_to = nil
                Session.origin_kind = nil
                Session.opened_from_novarr = false
                Session.switching = false
                -- Another book: no latest-position check is due any more.
                require("novarr.position").leave()
            end
        elseif Session.return_to then
            -- Back in the file manager after reading a chapter opened from
            -- Novarr: the screen it was opened from, with Home underneath.
            local target = Session.return_to
            Session.return_to = nil
            Session.origin_kind = nil
            UIManager:nextTick(Session.wrap(function()
                Novarr.showReturnScreens(target)
            end))
        elseif not file and not Session.started_once and Session:get("open_on_start")
                and Session:serverUrl() then
            -- KOReader has just started (or the chapter it resumed was
            -- closed): go to Novarr's Home instead of the file browser.
            UIManager:nextTick(Session.wrap(function() Session:showHome() end))
        end
        if not file then Session.started_once = true end
        local script = os.getenv("NOVARR_AUTOPILOT")
        if script and script ~= "" and not Session.autopilot_started then
            Session.autopilot_started = true
            require("novarr.autopilot").start(Session, script)
        end
    end)
end

function Novarr:onDispatcherRegisterActions()
    Dispatcher:registerAction("novarr_show",
        { category = "none", event = "NovarrShow", title = _("Novarr"), general = true })
    Dispatcher:registerAction("novarr_next_chapter",
        { category = "none", event = "NovarrNextChapter", title = _("Novarr: next chapter"), reader = true })
    Dispatcher:registerAction("novarr_prev_chapter",
        { category = "none", event = "NovarrPrevChapter", title = _("Novarr: previous chapter"), reader = true })
    Dispatcher:registerAction("novarr_chapter_list",
        { category = "none", event = "NovarrChapterList", title = _("Novarr: chapter list"), reader = true })
    Dispatcher:registerAction("novarr_novel_page",
        { category = "none", event = "NovarrNovelPage", title = _("Novarr: novel page"), reader = true })
    Dispatcher:registerAction("novarr_quick_menu",
        { category = "none", event = "NovarrQuickMenu", title = _("Novarr: quick menu"), reader = true })
    Dispatcher:registerAction("novarr_sync",
        { category = "none", event = "NovarrSync", title = _("Novarr: sync now"), general = true })
end

--[[--
After leaving a book opened from Novarr: the screens of Model.returnScreens,
bottom to top. Home underneath another screen opens quietly (no refresh, no
"Loading…"); the screen on top refreshes as usual.
]]
function Novarr.showReturnScreens(target)
    local screens = require("novarr.model").returnScreens(target.origin_kind)
    -- A Novarr screen left open over the book that just closed: started afresh (one Home at a time).
    Session:closeAllMenus()
    for i, kind in ipairs(screens) do
        local top = i == #screens
        if kind == "home" then
            if top then
                Session:showHome()
            elseif Session:serverUrl() then
                require("novarr.ui_home").show(Session, { quiet = true })
            end
        elseif kind == "library" then
            require("novarr.ui_library").show(Session)
        elseif kind == "novel" then
            Session:showNovel(target.novel_id)
        elseif kind == "chapters" then
            Session:showChapters(target.novel_id)
        end
    end
end

function Novarr:addToMainMenu(menu_items)
    if self.reading then
        local Reading = require("novarr.reading")
        menu_items.novarr = {
            text = _("Novarr"),
            sorting_hint = "tools",
            sub_item_table = {
                { text = _("Chapter list"), callback = Session.wrap(function()
                    if self.reading then Session:showChapters(self.reading.novel_id) end
                end) },
                { text = _("Novel page"), callback = Session.wrap(function()
                    if self.reading then Session:showNovel(self.reading.novel_id) end
                end) },
                { text = _("Novarr home"), callback = Session.wrap(function() Session:showHome() end) },
                { text = _("Next chapter"), callback = Session.wrap(function() Reading.goNext(self) end) },
                { text = _("Previous chapter"), callback = Session.wrap(function() Reading.goPrev(self) end) },
                { text = _("Sync now"), callback = Session.wrap(function() Session:syncNow() end) },
            },
        }
    else
        menu_items.novarr = {
            text = _("Novarr"),
            sorting_hint = "tools",
            callback = Session.wrap(function() Session:showHome() end),
        }
    end
end

-- ---------------------------------------------------------------- actions

function Novarr:onNovarrShow()
    Session.guard(function() Session:showHome() end)
    return true
end

function Novarr:onNovarrNextChapter()
    if not self.reading then return end
    Session.guard(function() require("novarr.reading").goNext(self) end)
    return true
end

function Novarr:onNovarrPrevChapter()
    if not self.reading then return end
    Session.guard(function() require("novarr.reading").goPrev(self) end)
    return true
end

function Novarr:onNovarrChapterList()
    if not self.reading then return end
    local novel_id = self.reading.novel_id
    Session.guard(function() Session:showChapters(novel_id) end)
    return true
end

function Novarr:onNovarrNovelPage()
    if not self.reading then return end
    local novel_id = self.reading.novel_id
    Session.guard(function() Session:showNovel(novel_id) end)
    return true
end

function Novarr:onNovarrQuickMenu()
    if not self.reading then return end
    Session.guard(function() require("novarr.reading").showQuickMenu(self) end)
    return true
end

-- Both plugin instances (file manager and reader) may see the event: only the most recent one syncs.
function Novarr:onNovarrSync()
    if Session.plugin ~= self then return end
    Session.guard(function() Session:syncNow() end)
    return true
end

-- ---------------------------------------------------------------- reader events
-- Every handler starts with the "is this a Novarr chapter" check and returns
-- nothing, so other modules and plugins always see the event too.

function Novarr:onReaderReady()
    if not self.reading then return end
    Session.guard(function() require("novarr.reading").onReady(self) end)
end

function Novarr:onPageUpdate()
    if not self.reading then return end
    Session.guard(function() require("novarr.reading").onMove(self) end)
end

function Novarr:onPosUpdate()
    if not self.reading then return end
    Session.guard(function() require("novarr.reading").onMove(self) end)
end

-- Font, margins, rotation...: where the chapters of a group file start changed.
function Novarr:onDocumentRerendered()
    if not self.reading then return end
    Session.guard(function() require("novarr.reading").onRerendered(self) end)
end

function Novarr:onSuspend()
    Session.asleep = true -- no status-bar redraws over the sleep screen
    -- The reading position first: saved locally and queued, so the sleep sync sends it.
    if self.reading then
        Session.guard(function()
            local Reading = require("novarr.reading")
            Reading.pauseTimer(self)
            Reading.persist(self)
            Reading.unscheduleIdle(self)
        end)
    end
    if Session.plugin == self then
        -- When it fell asleep (waking up after a while asks for the latest position).
        Session.suspended_at = os.time()
        Session.guard(function()
            -- Another plugin instance holds the open chapter: its position too.
            local reader = Session.reader
            if reader and reader ~= self and reader.reading then require("novarr.reading").persist(reader) end
        end)
        -- Send it before sleeping (best effort; see wifi.lua), then KOReader's sleep handling as before.
        Session.guard(function() require("novarr.wifi").sleepSync() end)
        Session.guard(function() require("novarr.wifi").onSuspend() end)
        -- Checks for new chapters stop looking while asleep (only when one was started).
        local Checks = package.loaded["novarr.checks"]
        if type(Checks) == "table" then Session.guard(Checks.onSuspend) end
        local Discover = package.loaded["novarr.ui_discover"]
        if type(Discover) == "table" then Session.guard(Discover.onSuspend) end
    end
end

function Novarr:onResume()
    Session.asleep = nil
    if Session.plugin == self then
        -- How long it slept (wall clock: the monotonic clocks may stop while suspended).
        Session.slept_s = Session.suspended_at and os.time() - Session.suspended_at or nil
        Session.suspended_at = nil
        Session.guard(function()
            -- Woken up: the next request checks the home address again.
            Session.address:invalidate("wake-up")
            -- After KOReader's own resume handling (it may restore Wi-Fi).
            if not self.resume_task then
                self.resume_task = Session.wrap(function()
                    require("novarr.wifi").onResume()
                    -- Already connected (Wi-Fi not managed, or left on): send the
                    -- reading position now. A connection that comes later sends
                    -- it in onNetworkConnected.
                    if Session.queue and Session.queue:count() > 0 and Session:isConnected() then
                        Session:flushSoon(0.5)
                    end
                    local Checks = package.loaded["novarr.checks"]
                    if type(Checks) == "table" then Checks.onResume() end
                    local Discover = package.loaded["novarr.ui_discover"]
                    if type(Discover) == "table" then Discover.onResume() end
                    -- Slept a while with a chapter open: was it read further on another device?
                    local Position = require("novarr.position")
                    local r = Session.reader and Session.reader.reading
                    if r and Position.wakeDue(Session.slept_s, Position.enabled(Session)) then
                        Position.due(r.novel_id, Position.WOKE_UP)
                    end
                end)
            end
            UIManager:unschedule(self.resume_task)
            UIManager:scheduleIn(3, self.resume_task)
        end)
    end
    if not self.reading then return end
    Session.guard(function() require("novarr.reading").resumeTimer(self) end)
end

function Novarr:onCloseDocument()
    if not self.reading then return end
    Session.guard(function() require("novarr.reading").onClose(self) end)
end

-- Sent by ReaderUI:reloadDocument() only (a re-render that reopens the same
-- file): the close that follows is not "leaving the book".
function Novarr:onPreserveCurrentSession()
    if not self.reading then return end
    self.reading.reloading = true
end

-- KOReader exiting or restarting (its Exit / Restart menu entries, gestures,
-- Novarr's own "Restart now"): Wi-Fi that Novarr switched off comes back on.
-- Both are broadcast; restoreOnLeave does nothing the second time.
function Novarr:onExit()
    Session.guard(function() require("novarr.wifi").restoreOnLeave("KOReader exiting", { exiting = true }) end)
end

function Novarr:onRestart()
    Session.guard(function() require("novarr.wifi").restoreOnLeave("KOReader restarting", { exiting = true }) end)
end

-- A connection appeared (KOReader broadcasts this): send pending writes.
-- Never turns Wi-Fi on; nothing happens when there is nothing to send.
-- Also: the next request checks the home address again, and the Wi-Fi
-- management (wifi.lua) starts an online window while reading.
function Novarr:onNetworkConnected()
    if Session.plugin ~= self or not Session.queue then return end
    Session.guard(function()
        Session.address:invalidate("network connected")
        -- Reading time with Wi-Fi on: the segment so far was with Wi-Fi off.
        if Session.reader and Session.reader.reading then require("novarr.reading").timeTick(Session.reader) end
        require("novarr.wifi").onNetworkConnected()
    end)
    -- A latest-position check waiting for the connection: after the flush below.
    Session.guard(function()
        local Position = require("novarr.position")
        if Position.pendingDue(Session) then Position.schedule(Position.NETWORK_DELAY, "network connected") end
    end)
    -- Home opened offline (saved data shown): refreshed quietly now that there is a connection.
    Session.guard(function()
        local _menu, screen = Session:topMenu()
        if screen and screen.kind == "home" and Session.last_errors.novels then
            UIManager:scheduleIn(3, function()
                Session.guard(function()
                    if not screen.closed and Session:isConnected() then screen:refresh(true) end
                end)
            end)
        end
    end)
    if Session.queue:count() == 0 then return end
    if not self.flush_task then
        self.flush_task = function()
            Session.guard(function() Session:quietFlush(50) end)
        end
    end
    UIManager:unschedule(self.flush_task)
    UIManager:scheduleIn(2, self.flush_task)
end

function Novarr:onNetworkDisconnected()
    if Session.plugin ~= self then return end
    Session.guard(function()
        if Session.reader and Session.reader.reading then require("novarr.reading").timeTick(Session.reader) end
        require("novarr.wifi").onNetworkDisconnected()
    end)
end

function Novarr:onCloseWidget()
    Session.guard(function()
        if self.reading then
            require("novarr.reading").unschedule(self)
        end
        if self.flush_task then UIManager:unschedule(self.flush_task) end
        if self.resume_task then UIManager:unschedule(self.resume_task) end
        if Session.plugin == self then Session.plugin = nil end
        if Session.reader == self then Session.reader = nil end
    end)
end

logger.dbg("Novarr: plugin loaded from", plugin_dir)

return Novarr
