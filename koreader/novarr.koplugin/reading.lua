--[[--
Reader side: what happens while a Novarr chapter is open in KOReader's
reader. Only ever attached when the open document's path is a Novarr
chapter path; for any other document the plugin does nothing.

* First open of a part-read chapter with no KOReader position yet: jump to
  the server's (or local) progress.
* Progress is tracked in memory on page turns, saved locally and queued at
  most every 30 s, and on close / suspend.
* A few seconds after opening, if (and only if) the device is already
  connected: quietly send pending writes, then prefetch the next chapters,
  one per scheduled step, giving up silently on any error.
* End of chapter: mark read (setting) and open the next chapter straight
  away, or say clearly why it cannot be opened. KOReader's own end-of-book dialog is suppressed for
  Novarr chapters only.
* On close: final progress, remove-after-reading clean-up.
]]

local Event = require("ui/event")
local UIManager = require("ui/uimanager")
local logger = require("logger")
local _ = require("gettext")
local T = require("ffi/util").template

local Model = require("novarr.model")
local ReadingInfo = require("novarr.readinginfo")
local Session = require("novarr.session")

local Reading = {}

Reading.SAVE_INTERVAL_MS = 30 * 1000
Reading.BACKGROUND_DELAY = 3     -- seconds after the chapter opens
Reading.PREFETCH_STEP = 1.5      -- seconds between two prefetched chapters

local function UI() return require("novarr.ui_common") end

-- ---------------------------------------------------------------- attach

function Reading.attach(plugin, novel_id, chapter_id)
    local ui = plugin.ui
    local r = {
        novel_id = novel_id,
        chapter_id = chapter_id,
        path = ui.document.file,
        percent = nil,
        last_saved = Session.nowMs(),
        dirty = false,
        ended = false,
        ready = false,
    }
    plugin.reading = r
    Session.reader = plugin
    -- Did KOReader already have a position for this file (sidecar)?
    r.had_position = ui.doc_settings ~= nil and ui.doc_settings:readSetting("last_xpointer") ~= nil

    -- Suppress KOReader's end-of-book dialog for this document only: the
    -- ReaderStatus module of *this* ReaderUI handles EndOfBook before any
    -- plugin, so its handler is overridden on the instance.
    local status = ui.status
    if status and status.onEndOfBook then
        local original = status.onEndOfBook
        status.onEndOfBook = function(st, ...)
            if plugin.reading then
                Session.guard(Reading.onEnd, plugin)
                return true
            end
            return original(st, ...)
        end
    end

    plugin.background_task = function()
        Session.guard(Reading.background, plugin)
    end
    plugin.prefetch_task = function()
        Session.guard(Reading.prefetchStep, plugin)
    end
end

-- ---------------------------------------------------------------- progress

function Reading.currentPercent(plugin)
    local ui = plugin.ui
    local frac
    -- Position of the top of the screen through the chapter: the same
    -- measure GotoPercent (used to resume) and the web reader use.
    local doc = ui.document
    if ui.rolling and doc and doc.getCurrentPos and doc.info and (doc.info.doc_height or 0) > 0 then
        local ok, pos = pcall(doc.getCurrentPos, doc)
        if ok and type(pos) == "number" then frac = pos / doc.info.doc_height end
    end
    if not frac and ui.rolling and ui.rolling.getLastPercent then
        local ok, v = pcall(ui.rolling.getLastPercent, ui.rolling)
        if ok and type(v) == "number" then frac = v end
    end
    if not frac and ui.paging and ui.paging.getLastPercent then
        local ok, v = pcall(ui.paging.getLastPercent, ui.paging)
        if ok and type(v) == "number" then frac = v end
    end
    if not frac and ui.document then
        local ok, page = pcall(ui.getCurrentPage, ui)
        local count = ui.document.getPageCount and ui.document:getPageCount()
        if ok and page and count and count > 0 then frac = page / count end
    end
    if not frac or frac ~= frac then return nil end
    return math.max(0, math.min(100, math.floor(frac * 100)))
end

--- Local/server progress known for the chapter before opening it.
function Reading.knownProgress(r)
    local read, progress = Session:chapterState(r.novel_id, r.chapter_id)
    return read, progress
end

function Reading.onReady(plugin)
    local r = plugin.reading
    if not r then return end
    Session.switching = false
    Session.return_to = nil
    Session.opening_path = nil
    local _read, progress = Reading.knownProgress(r)
    -- A chapter is "part-read" while its progress is between 0 and 100 (the
    -- server keeps a re-opened read chapter's position too).
    if not r.had_position and progress and progress > 0 and progress < 100 then
        logger.info("Novarr: resuming chapter", r.chapter_id, "at", progress, "%")
        plugin.ui:handleEvent(Event:new("GotoPercent", progress))
    end
    r.percent = Reading.currentPercent(plugin)
    r.dirty = false
    r.ready = true
    r.timer = ReadingInfo.newTimer(os.time())
    Session.guard(Reading.addFooter, plugin)
    UIManager:unschedule(plugin.background_task)
    UIManager:scheduleIn(Reading.BACKGROUND_DELAY, plugin.background_task)
end

function Reading.onMove(plugin)
    local r = plugin.reading
    if not r or not r.ready then return end
    local p = Reading.currentPercent(plugin)
    if not p or p == r.percent then return end
    if r.timer then ReadingInfo.tick(r.timer, os.time()) end
    r.percent = p
    r.dirty = true
    if Session.nowMs() - r.last_saved >= Reading.SAVE_INTERVAL_MS then
        Reading.persist(plugin)
    end
end

--- Saves the position locally and queues it (no network).
function Reading.persist(plugin)
    local r = plugin.reading
    if not r or not r.dirty or r.ended or not r.percent then return end
    Session:recordProgress(r.novel_id, r.chapter_id, r.percent)
    r.dirty = false
    r.last_saved = Session.nowMs()
end

-- ---------------------------------------------------------------- status bar

local function footerOf(plugin)
    local view = plugin.ui and plugin.ui.view
    return view and view.footer, view
end

--[[--
Adds "14:12 · 2,385 ch left · 61 h left" to KOReader's status bar through
ReaderFooter's plugin hook (KOReader 2024.11 and later; skipped silently
on older versions). Chapters left and the time per chapter are computed
once here; the footer redraws on page turns as usual (no timers).
]]
function Reading.addFooter(plugin)
    local r = plugin.reading
    if not r or not Session:get("show_reading_info") then return end
    local footer, view = footerOf(plugin)
    if not footer or not footer.addAdditionalFooterContent then return end
    r.left = Session:novelEstimate(r.novel_id, r.chapter_id, 0)
    r.per_chapter = Session:secondsPerChapter(r.novel_id)
    plugin.footer_func = function()
        local ok, text = pcall(Reading.footerText, plugin)
        return ok and text or nil
    end
    footer:addAdditionalFooterContent(plugin.footer_func)
    if footer.onUpdateFooter then pcall(footer.onUpdateFooter, footer, true) end
    -- KOReader's default status bar shows one item at a time. In Novarr
    -- chapters make the Novarr line that item, for this reader instance
    -- only: nothing is saved, so other books keep the user's own choice and
    -- a tap on the status bar still cycles through the other items.
    local visible = view.footer_visible ~= false
    if visible and footer.settings and not footer.settings.all_at_once
            and footer.mode_list and footer.mode_list.additional_content
            and footer.applyFooterMode then
        plugin.footer_prev_mode = footer.mode
        pcall(footer.applyFooterMode, footer, footer.mode_list.additional_content)
        if footer.onUpdateFooter then pcall(footer.onUpdateFooter, footer, true) end
    elseif not visible and not Session:get("footer_hint_shown") then
        -- The status bar is switched off: never turn it on for the user,
        -- say once where the setting is.
        Session:set("footer_hint_shown", true)
        UI().info(_("Novarr adds the time, chapters left and reading time left to KOReader's status bar at the bottom of the page, but the status bar is switched off.\n\nTurn it on in the menu: Settings ▸ Status bar."))
    end
end

function Reading.removeFooter(plugin)
    local footer = footerOf(plugin)
    if plugin.footer_func and footer and footer.removeAdditionalFooterContent then
        footer:removeAdditionalFooterContent(plugin.footer_func)
        -- Hand the status bar back as it was (see addFooter).
        if plugin.footer_prev_mode and footer.applyFooterMode and footer.mode_list
                and footer.mode == footer.mode_list.additional_content then
            pcall(footer.applyFooterMode, footer, plugin.footer_prev_mode)
        end
    end
    plugin.footer_prev_mode = nil
    plugin.footer_func = nil
end

function Reading.footerText(plugin)
    local r = plugin.reading
    if not r then return nil end
    local footer = footerOf(plugin)
    local clock
    -- No clock when KOReader's own time item is already shown.
    -- KOReader's own clock only shows next to this item in all-at-once mode.
    local ko_clock = footer and footer.settings and footer.settings.time and footer.settings.all_at_once
    if Session:get("show_clock") and not ko_clock then
        local ok, datetime = pcall(require, "datetime")
        if ok and datetime.secondsToHour then
            clock = datetime.secondsToHour(os.time(), G_reader_settings:isTrue("twelve_hour_clock"))
        else
            clock = os.date("%H:%M")
        end
    end
    local secs = ReadingInfo.secondsLeft(r.per_chapter, r.left, r.percent)
    return ReadingInfo.footerText(clock, r.left, secs)
end

function Reading.pauseTimer(plugin)
    local r = plugin.reading
    if r and r.timer then ReadingInfo.pause(r.timer) end
end

function Reading.resumeTimer(plugin)
    local r = plugin.reading
    if r and r.timer then r.timer.last = os.time() end
end

-- ---------------------------------------------------------------- background

--- Next chapters to prefetch (not stored yet), in reading order.
function Reading.prefetchIds(r)
    local n = tonumber(Session:get("prefetch")) or 0
    if n <= 0 then return {} end
    local store = Session.store
    local out = {}
    local cl = Session:chapterList(r.novel_id)
    if cl then
        for _i, id in ipairs(Model.nextChapters(cl.list, r.chapter_id, n)) do
            if not store:hasChapter(r.novel_id, id) and not Session.empty_chapters[id] then
                table.insert(out, id)
            end
        end
        return out
    end
    -- No chapter list cached: follow the stored chapters' "next" links.
    local entry = store:chapterEntry(r.novel_id, r.chapter_id)
    local seen = 0
    while entry and entry.next and seen < n do
        seen = seen + 1
        local nxt = store:chapterEntry(r.novel_id, entry.next)
        if not nxt then
            if entry.next_downloaded ~= false then table.insert(out, entry.next) end
            break
        end
        entry = nxt
    end
    return out
end

function Reading.background(plugin)
    local r = plugin.reading
    if not r or not Session:serverUrl() then return end
    -- Never turn Wi-Fi on, never prompt: skip entirely when not connected.
    if not Session:isConnected() then return end
    local res = Session:quietFlush(20)
    if res and res.stalled then return end
    r.prefetch = Reading.prefetchIds(r)
    if #r.prefetch > 0 then
        UIManager:scheduleIn(Reading.PREFETCH_STEP, plugin.prefetch_task)
    end
end

function Reading.prefetchStep(plugin)
    local r = plugin.reading
    if not r or not r.prefetch or #r.prefetch == 0 then return end
    if not Session:isConnected() then r.prefetch = nil return end
    local free = Session:freeBytes()
    if free and free < Session.MIN_FREE_BYTES then r.prefetch = nil return end
    local id = table.remove(r.prefetch, 1)
    if not Session.store:hasChapter(r.novel_id, id) then
        local path, why = Session:downloadChapter(id)
        if not path then
            logger.info("Novarr: prefetch stopped at chapter", id, type(why) == "table" and why.kind or why)
            r.prefetch = nil
            return
        end
        logger.info("Novarr: prefetched chapter", id)
    end
    if #r.prefetch > 0 then
        UIManager:scheduleIn(Reading.PREFETCH_STEP, plugin.prefetch_task)
    end
end

-- ---------------------------------------------------------------- navigation

--[[--
The chapter after (dir = 1) or before (dir = -1) the open one that the
server has. Returns id, known_downloaded (true/false/nil), label.
]]
function Reading.neighbour(r, dir)
    local cl = Session:chapterList(r.novel_id)
    if cl and cl.pos[r.chapter_id] then
        local i = cl.pos[r.chapter_id] + dir
        while cl.list[i] do
            local c = cl.list[i]
            if c.downloaded or Session.store:hasChapter(r.novel_id, c.id) then
                return c.id, true, c.label
            end
            i = i + dir
        end
        return nil
    end
    local entry = Session.store:chapterEntry(r.novel_id, r.chapter_id)
    if not entry then return nil end
    local id = dir > 0 and entry.next or entry.prev
    if not id then return nil end
    local other = Session.store:chapterEntry(r.novel_id, id)
    if other then return id, true, other.label end
    if dir > 0 then return id, entry.next_downloaded, nil end
    return id, nil, nil
end

local function openLater(novel_id, chapter_id)
    -- Never switch documents from inside the closing document's event handler.
    UIManager:nextTick(Session.wrap(function()
        Session:openChapter(novel_id, chapter_id, { from_reader = true })
    end))
end

function Reading.checkNew(plugin)
    local r = plugin.reading
    if not r then return end
    Session:whenConnected(function()
        local closer = UI().loading(_("Checking for new chapters…"))
        local _cl, e = Session:refreshChapterList(r.novel_id)
        closer()
        if e then
            UI().info(Session:errorText(e))
            return
        end
        local id = Reading.neighbour(r, 1)
        if id then
            openLater(r.novel_id, id)
        else
            UI().info(_("No new chapters yet."))
        end
    end, function()
        UI().info(_("No network connection."))
    end)
end

--[[--
Goes to the next chapter, without asking: the stored copy right away, or
fetched when the device is already connected (brief "Loading" notice). A
message appears only when it cannot be opened. Never prompts to turn Wi-Fi
on (also used at the end of a chapter).
]]
function Reading.goNext(plugin)
    local r = plugin.reading
    if not r then return end
    local id, downloaded = Reading.neighbour(r, 1)
    local stored = id and Session.store:hasChapter(r.novel_id, id)
    local connected = Session:isConnected()
    if id and (stored or (connected and downloaded ~= false)) then
        openLater(r.novel_id, id)
        return
    end
    if id and not stored and not connected then
        UI().info(_("The next chapter isn't saved on this device, and there's no network connection."))
        return
    end
    local msg = _("You've reached the last downloaded chapter of this novel.")
    if connected then
        UI().confirm({
            text = msg,
            ok_text = _("Check for new chapters"),
            cancel_text = _("Close"),
            ok = function() Reading.checkNew(plugin) end,
        })
    else
        UI().info(msg)
    end
end

function Reading.goPrev(plugin)
    local r = plugin.reading
    if not r then return end
    local id = Reading.neighbour(r, -1)
    if not id then
        UI().info(_("This is the first chapter."), 2)
        return
    end
    openLater(r.novel_id, id)
end

-- ---------------------------------------------------------------- end / close

function Reading.onEnd(plugin)
    local r = plugin.reading
    if not r then return end
    if not r.ended then
        r.ended = true
        r.percent = 100
        if r.timer then
            ReadingInfo.tick(r.timer, os.time())
            local kept = Session:recordChapterTime(r.novel_id, r.timer.seconds)
            logger.info("Novarr: chapter", r.chapter_id, "read in", r.timer.seconds, "s of active reading", kept and "(recorded)" or "(too short, ignored)")
        end
        if Session:get("mark_read_at_end") then
            Session:markChapter(r.novel_id, r.chapter_id, true, "end")
        else
            Session:recordProgress(r.novel_id, r.chapter_id, 100)
        end
        r.dirty = false
    end
    Reading.goNext(plugin)
end

function Reading.unschedule(plugin)
    if plugin.background_task then UIManager:unschedule(plugin.background_task) end
    if plugin.prefetch_task then UIManager:unschedule(plugin.prefetch_task) end
end

function Reading.onClose(plugin)
    local r = plugin.reading
    if not r then return end
    Reading.unschedule(plugin)
    Session.guard(Reading.removeFooter, plugin)
    Reading.persist(plugin)
    Session.guard(function() Session:cleanupRead(r.novel_id, r.chapter_id) end)
    if not Session.switching and Session.opened_from_novarr then
        Session.return_to = { novel_id = r.novel_id }
    end
    if Session.reader == plugin then Session.reader = nil end
    plugin.reading = nil
end

return Reading
