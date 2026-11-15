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
    "api", "autopilot", "chapterdoc", "covers", "epub", "epubflow", "groups", "json", "model", "queue", "reading", "readinginfo", "session",
    "store", "sync", "ui_caughtup", "ui_chapters", "ui_common", "ui_coverlist", "ui_downloads", "ui_home", "ui_library",
    "ui_novel", "ui_progress", "ui_settings", "ui_shelf", "ui_update", "updater",
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
                Session.opened_from_novarr = false
                Session.switching = false
            end
        elseif Session.return_to then
            -- Back in the file manager after reading a chapter opened from Novarr.
            local target = Session.return_to
            Session.return_to = nil
            UIManager:nextTick(Session.wrap(function()
                Session:showChapters(target.novel_id)
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
end

function Novarr:addToMainMenu(menu_items)
    if self.reading then
        local Reading = require("novarr.reading")
        menu_items.novarr = {
            text = _("Novarr"),
            sorting_hint = "tools",
            sub_item_table = {
                { text = _("Next chapter"), callback = Session.wrap(function() Reading.goNext(self) end) },
                { text = _("Previous chapter"), callback = Session.wrap(function() Reading.goPrev(self) end) },
                { text = _("Chapter list"), callback = Session.wrap(function()
                    if self.reading then Session:showChapters(self.reading.novel_id) end
                end) },
                { text = _("Novarr home"), callback = Session.wrap(function() Session:showHome() end) },
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
    if not self.reading then return end
    Session.guard(function()
        local Reading = require("novarr.reading")
        Reading.pauseTimer(self)
        Reading.persist(self)
    end)
end

function Novarr:onResume()
    if not self.reading then return end
    Session.guard(function() require("novarr.reading").resumeTimer(self) end)
end

function Novarr:onCloseDocument()
    if not self.reading then return end
    Session.guard(function() require("novarr.reading").onClose(self) end)
end

-- A connection appeared (KOReader broadcasts this): send pending writes.
-- Never turns Wi-Fi on; nothing happens when there is nothing to send.
function Novarr:onNetworkConnected()
    if Session.plugin ~= self or not Session.queue or Session.queue:count() == 0 then return end
    if not self.flush_task then
        self.flush_task = function()
            Session.guard(function() Session:quietFlush(50) end)
        end
    end
    UIManager:unschedule(self.flush_task)
    UIManager:scheduleIn(2, self.flush_task)
end

function Novarr:onCloseWidget()
    Session.guard(function()
        if self.reading then
            require("novarr.reading").unschedule(self)
        end
        if self.flush_task then UIManager:unschedule(self.flush_task) end
        if Session.plugin == self then Session.plugin = nil end
        if Session.reader == self then Session.reader = nil end
    end)
end

logger.dbg("Novarr: plugin loaded from", plugin_dir)

return Novarr
