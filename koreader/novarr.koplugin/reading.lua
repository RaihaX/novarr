--[[--
Reader side: what happens while a Novarr chapter is open in KOReader's
reader. Only ever attached when the open document's path is a Novarr
chapter path; for any other document the plugin does nothing.

* First open of a part-read chapter with no KOReader position yet: jump to
  the server's (or local) progress.
* Progress is tracked in memory on page turns, saved locally and queued at
  most every 30 s, and on close / suspend.
* It reaches the server soon, not only at the next online window: going to
  sleep sends it (wifi.lua's sleepSync, best effort), waking up asks for a
  sync, and so does a pause: 3 minutes without a page turn (IDLE_SYNC_MS;
  one timer per pause, started by a page turn, never a recurring poll)
  saves the position and sends the queue when connected, or with Wi-Fi
  switched off by Novarr asks for it to come on (Wifi.requestSync).
* The status line ends with the sync state ("not synced", "3 ch not
  synced", "synced 5 min ago"; setting show_sync_status), redrawn after a
  flush that sent something.
* A few seconds after opening, if (and only if) the device is already
  connected: quietly send pending writes, then prefetch the next chapters
  (up to the "chapters to fetch ahead" setting, 100 by default), one per
  scheduled step (0.5 s apart while more than 10 are missing, else 3 s),
  never within 1.5 s of a page turn, giving up silently on any error.
* With "Turn Wi-Fi off while reading when everything is saved" on, see
  wifi.lua: each online window also refreshes the chapter list and runs the
  daily update check when due, and Wi-Fi is switched off and on around it.
* End of chapter: mark read (setting) and open the next chapter straight
  away, or say clearly why it cannot be opened. KOReader's own end-of-book dialog is suppressed for
  Novarr chapters only. A next chapter the server doesn't have yet (and that
  isn't saved here) is never jumped past: "<chapter> isn't on the server
  yet." offers Check for new chapters, Skip to <the next one there is>, Stay
  here (Reading.showMissing). Going back still skips such gaps.
* On close: final progress, remove-after-reading clean-up.

Joined chapters (a group file holds a run of chapters, see groups.lua): the
chapter being read is worked out from the position in the document, so
moving from one chapter to the next is an ordinary page turn. Progress,
read marks, reading time and the status line stay per chapter. KOReader's
end of the document (and paging back on its first page) opens the group
file with the next (previous) chapter.
]]

local Event = require("ui/event")
local UIManager = require("ui/uimanager")
local logger = require("logger")
local _ = require("gettext")
local T = require("ffi/util").template

local Groups = require("novarr.groups")
local Model = require("novarr.model")
local Position = require("novarr.position")
local ReadingInfo = require("novarr.readinginfo")
local Session = require("novarr.session")

local Reading = {}

Reading.SAVE_INTERVAL_MS = 30 * 1000
Reading.LIVE_SYNC_DELAY_S = 2            -- Wi-Fi stays on: send this long after a save
Reading.LIVE_RETRY_MS = 5 * 60 * 1000    -- ... but after a send that reached nothing, not for this long
Reading.BACKGROUND_DELAY = 3     -- seconds after the chapter opens (send pending writes)
Reading.PREFETCH_START_MS = 6000 -- first prefetch not before this, after the chapter is ready
Reading.PREFETCH_STEP = 3        -- seconds between two prefetched chapters (topping up a few)
Reading.PREFETCH_FAST_STEP = 0.5 -- seconds between two when many are missing
Reading.PREFETCH_FAST_ABOVE = 10 -- "many": more than this many chapters missing
Reading.PREFETCH_QUIET_MS = 1500 -- never fetch this soon after a page turn
Reading.PREFETCH_LOG_EVERY = 10  -- one log line per this many prefetched chapters
Reading.IDLE_SYNC_MS = 3 * 60 * 1000 -- no page turn for this long: save and send the position

local function UI() return require("novarr.ui_common") end
local footerOf -- (status bar section)

--- Same file paths, also when one of them went through realpath (History).
local function samePath(a, b)
    if not a or not b then return false end
    if a == b then return true end
    local tail = "chapters/[^/]+/[^/]+$"
    return a:match(tail) ~= nil and a:match(tail) == b:match(tail)
end

-- ---------------------------------------------------------------- attach

function Reading.attach(plugin, novel_id, chapter_id, group)
    local ui = plugin.ui
    local grp
    if group then
        -- The chapters in this file, from the file itself (what is on screen).
        local ids = Groups.idsInHtml(Session.store:readFile(ui.document.file))
        if #ids == 0 then
            logger.warn("Novarr: group file without chapters, leaving it alone:", ui.document.file)
            return
        end
        local index = {}
        for i, id in ipairs(ids) do index[id] = i end
        grp = { block = group.block, n = group.n, ids = ids, index = index }
        chapter_id = ids[1] -- until the position is known (onReady)
    end
    local r = {
        group = grp,
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

    -- Paging back on the first page opens the previous chapter at its end.
    -- KOReader has no "start of book" event, so, like EndOfBook for the
    -- forward direction, a backward GotoViewRel that does not move is caught
    -- on this ReaderUI's own rolling/paging module instance.
    for _i, mod in ipairs({ ui.rolling, ui.paging }) do
        if mod and mod.onGotoViewRel then
            local original = mod.onGotoViewRel
            mod.onGotoViewRel = function(m, diff, ...)
                if not plugin.reading or not diff or diff >= 0 then
                    return original(m, diff, ...)
                end
                local before = Reading.position(plugin)
                local res = original(m, diff, ...)
                if before and before == Reading.position(plugin) then
                    Session.guard(Reading.onStart, plugin)
                end
                return res
            end
        end
    end

    -- Highlights go to the server (highlights.lua). Every add, change and
    -- removal of an annotation reaches ReaderAnnotation:onAnnotationsModified,
    -- through the AnnotationsModified event or called directly (a note edited
    -- in place): it is wrapped on this ReaderUI's own instance only, so other
    -- books are never touched, and KOReader's handling always runs first.
    local annotation = ui.annotation
    if annotation and annotation.onAnnotationsModified then
        local original = annotation.onAnnotationsModified
        annotation.onAnnotationsModified = function(a, items, ...)
            local res = original(a, items, ...)
            if plugin.reading then Session.guard(Reading.onAnnotation, plugin, items) end
            return res
        end
    end

    plugin.background_task = function()
        Session.guard(Reading.background, plugin)
    end
    plugin.prefetch_task = function()
        Session.guard(Reading.prefetchStep, plugin)
    end
    plugin.idle_task = function()
        Session.guard(Reading.idleSync, plugin)
    end
    plugin.idle_scheduled = false
end

-- ---------------------------------------------------------------- progress

function Reading.currentPercent(plugin)
    local ui = plugin.ui
    if plugin.reading and plugin.reading.group then
        local here = Reading.where(plugin)
        return here and here.percent or nil
    end
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

--- Where the reader is (page in page mode, y position in scroll mode), for "did it move?".
function Reading.position(plugin)
    local ui = plugin.ui
    local doc = ui and ui.document
    if not doc then return nil end
    if ui.rolling and ui.view and ui.view.view_mode == "scroll" and doc.getCurrentPos then
        local ok, pos = pcall(doc.getCurrentPos, doc)
        if ok then return "pos:" .. tostring(pos) end
    end
    local ok, page = pcall(ui.getCurrentPage, ui)
    if ok and page then return "page:" .. tostring(page) end
    return nil
end

--- Local/server progress known for the chapter before opening it.
function Reading.knownProgress(r)
    local read, progress = Session:chapterState(r.novel_id, r.chapter_id)
    return read, progress
end

function Reading.onReady(plugin)
    local r = plugin.reading
    if not r then return end
    -- Opened by KOReader itself (start-up, History, the file browser): not
    -- through Session:openFile, and not this file reopened by a re-render.
    local by_koreader = not samePath(Session.opening_path, r.path) and not samePath(Session.reloading_path, r.path)
    Session.reloading_path = nil
    Session.switching = false
    Session.return_to = nil
    Session.opening_path = nil
    local _read, progress = Reading.knownProgress(r)
    -- "Go to latest" (position.lua) opened this chapter: its place wins over KOReader's saved one.
    local forced = not r.group and Position.takeForced(Session, r.novel_id, r.chapter_id) or nil
    if r.group then
        Reading.placeInGroup(plugin)
    elseif forced then
        logger.info("Novarr: chapter", r.chapter_id, "at the latest position,", forced, "%")
        plugin.ui:handleEvent(Event:new("GotoPercent", forced))
    elseif Session.open_at_end and Session.open_at_end == r.path then
        -- Opened by paging back from the next chapter: show the last page,
        -- whatever position KOReader had saved. Nothing is marked: progress
        -- is reported again only once the user turns a page.
        Session.open_at_end = nil
        r.at_end_open = true
        local doc = plugin.ui.document
        local count = doc and doc.getPageCount and doc:getPageCount()
        if plugin.ui.view and plugin.ui.view.view_mode == "page" and count then
            plugin.ui:handleEvent(Event:new("GotoPage", count))
        else
            plugin.ui:handleEvent(Event:new("GotoPercent", 100))
        end
    elseif not r.had_position and progress and progress > 0 and progress < 100 then
        -- A chapter is "part-read" while its progress is between 0 and 100 (the
        -- server keeps a re-opened read chapter's position too).
        logger.info("Novarr: resuming chapter", r.chapter_id, "at", progress, "%")
        plugin.ui:handleEvent(Event:new("GotoPercent", progress))
    end
    Session.open_at_end = nil
    Session.leaving_path = nil
    -- Keep KOReader's History to this chapter only.
    Session.guard(function() Session:pruneHistory(r.path) end)
    r.percent = Reading.currentPercent(plugin)
    r.dirty = false
    r.ready = true
    r.last_turn = nil
    r.ready_at = Session.nowMs()
    r.timer = ReadingInfo.newTimer(os.time())
    if r.group then r.last_where = Reading.where(plugin) end
    Reading.timeStart(plugin)
    Session.guard(Reading.addFooter, plugin)
    Session.guard(Reading.applyCover, plugin, false)
    Session.guard(Reading.offerSleepCover, plugin)
    if r.group then
        -- A block was opened: tidy up earlier ones (see Session:cleanupJoined).
        Session.guard(function() Session:cleanupJoined(r.novel_id, r.group.block, r.group.n) end)
    end
    UIManager:unschedule(plugin.background_task)
    UIManager:scheduleIn(Reading.BACKGROUND_DELAY, plugin.background_task)
    -- Was it read further on another device? (A check due, or KOReader opened it; position.lua.)
    Session.guard(Position.chapterReady, r.novel_id, by_koreader)
    Reading.logTiming(r)
end

-- How long opening took (from the request), and from the end of the previous chapter.
function Reading.logTiming(r)
    local now = Session.nowMs()
    local kind = r.group and string.format("group of %d chapters", #r.group.ids) or "single chapter"
    if Session.open_t0 then
        logger.info(string.format("Novarr: TIMING open %s: ready %d ms after the request", kind, now - Session.open_t0))
    end
    if Session.switch_t0 and now - Session.switch_t0 > 30000 then Session.switch_t0 = nil end
    if Session.switch_t0 then
        logger.info(string.format("Novarr: TIMING chapter change by document switch (%s): ready %d ms after the end of the previous one", kind, now - Session.switch_t0))
    end
    local t0, s0 = Session.open_t0, Session.switch_t0
    Session.open_t0, Session.switch_t0 = nil, nil
    -- The first paint happens before the next tick.
    UIManager:nextTick(function()
        local t = Session.nowMs()
        if t0 then logger.info(string.format("Novarr: TIMING open %s: painted %d ms after the request", kind, t - t0)) end
        if s0 then logger.info(string.format("Novarr: TIMING chapter change by document switch (%s): painted %d ms after the end of the previous one", kind, t - s0)) end
    end)
end

function Reading.onMove(plugin)
    local r = plugin.reading
    if not r or not r.ready then return end
    if r.group then Reading.trackGroup(plugin) end
    Reading.timeTick(plugin)
    local p = Reading.currentPercent(plugin)
    r.last_turn = Session.nowMs()
    -- One idle timer per pause: started here, it re-arms itself while pages turn.
    if not plugin.idle_scheduled and plugin.idle_task then
        plugin.idle_scheduled = true
        UIManager:scheduleIn(Reading.IDLE_SYNC_MS / 1000, plugin.idle_task)
    end
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
    Reading.liveSync()
end

--[[--
Wi-Fi stays on (the "turn Wi-Fi off while reading" setting is off): what was
just queued is sent a moment later, so another device resumes here. With the
setting on, wifi.lua's windows decide when to send. A send that reached
nothing (away from the server) is not retried from here for LIVE_RETRY_MS:
each try blocks reading for up to the request timeout.
]]
local live_task
function Reading.liveSync()
    if require("novarr.wifi").enabled() then return end
    if not Session:serverUrl() or Session.queue:count() == 0 or not Session:isConnected() then return end
    if Session:needsLink() then return end -- not linked: the writes wait (auth.lua)
    if Session.nowMs() < (Reading.live_retry_at or 0) then return end
    live_task = live_task or function()
        Session.guard(function()
            local res = Session:quietFlush(50)
            if res and (res.remaining or 0) > 0 and (res.sent or 0) + (res.dropped or 0) == 0 then
                Reading.live_retry_at = Session.nowMs() + Reading.LIVE_RETRY_MS
            else
                Reading.live_retry_at = nil
            end
        end)
    end
    UIManager:unschedule(live_task)
    UIManager:scheduleIn(Reading.LIVE_SYNC_DELAY_S, live_task)
end

-- ---------------------------------------------------------------- joined chapters

local function isScroll(plugin)
    local view = plugin.ui and plugin.ui.view
    return view ~= nil and view.view_mode == "scroll"
end

-- What changes when the document is laid out again (font, margins, size, view mode).
local function layoutKey(plugin)
    local doc = plugin.ui.document
    local okh, hash = pcall(doc.getDocumentRenderingHash, doc, true)
    local okc, count = pcall(doc.getPageCount, doc)
    return table.concat({ okh and tostring(hash) or "", tostring(doc.info and doc.info.doc_height),
        okc and tostring(count) or "", isScroll(plugin) and "scroll" or "page" }, ":")
end

--[[--
Where each chapter of the open group file starts: r.layout = {key, pages,
ys, count, height}, pages[i]/ys[i] the page and y position of chapter i's
<div> (crengine resolves "#novarr-ch-<id>" like a link target). Worked out
once per layout; returns layout, fresh (true when just recomputed).
]]
function Reading.layout(plugin)
    local r = plugin.reading
    local key = layoutKey(plugin)
    if r.layout and r.layout.key == key then return r.layout, false end
    local doc = plugin.ui.document
    local pages, ys = {}, {}
    for i, id in ipairs(r.group.ids) do
        local xp = "#novarr-ch-" .. id
        local okp, page = pcall(doc.getPageFromXPointer, doc, xp)
        local oky, y = pcall(doc.getPosFromXPointer, doc, xp)
        pages[i] = okp and tonumber(page) or (pages[i - 1] or 1)
        ys[i] = oky and tonumber(y) or (ys[i - 1] or 0)
    end
    local okc, count = pcall(doc.getPageCount, doc)
    r.layout = { key = key, pages = pages, ys = ys, count = okc and count or pages[#pages],
        height = doc.info and doc.info.doc_height or ys[#ys] }
    return r.layout, true
end

--- Last page and end y of each chapter of the layout.
local function chapterEnds(L)
    local pages, ys = {}, {}
    for i = 1, #L.pages do
        pages[i] = L.pages[i + 1] and (L.pages[i + 1] - 1) or L.count
        ys[i] = L.ys[i + 1] or L.height
    end
    return pages, ys
end

--[[--
The reader's place in the group file: {key, scroll, page, y, index (chapter
index in the file), percent (within that chapter)}. Page mode goes by pages
(each chapter starts on a new page), scroll mode by the y position of the
top of the screen.
]]
function Reading.where(plugin)
    local r = plugin.reading
    if not r or not r.group then return nil end
    local ui = plugin.ui
    local doc = ui.document
    local L = Reading.layout(plugin)
    local scroll = isScroll(plugin)
    local oky, y = pcall(doc.getCurrentPos, doc)
    y = oky and tonumber(y) or 0
    local okp, page = pcall(doc.getCurrentPage, doc)
    page = okp and tonumber(page) or 1
    local index = scroll and Groups.chapterAt(L.ys, y) or Groups.chapterAt(L.pages, page)
    local _pages, ends = chapterEnds(L)
    return { key = L.key, scroll = scroll, page = page, y = y, index = index,
        percent = Groups.percentIn(y, L.ys[index], ends[index]) }
end

--[[--
Moves to a chapter of the open group file. opts.at_end: its last page;
opts.percent: that far into it; opts.resume: its known progress when it is
part-read (as a chapter opened on its own resumes); else its start.
]]
function Reading.goInside(plugin, chapter_id, opts)
    opts = opts or {}
    local r = plugin.reading
    local i = r.group.index[chapter_id]
    if not i then return false end
    local ui = plugin.ui
    local L = Reading.layout(plugin)
    local pct = opts.percent
    if pct == nil and opts.resume and not opts.at_end then
        local _read, progress = Session:chapterState(r.novel_id, chapter_id)
        if progress and progress > 0 and progress < 100 then pct = progress end
    end
    local end_pages, end_ys = chapterEnds(L)
    if isScroll(plugin) and ui.rolling and ui.rolling._gotoPos then
        local s, e = L.ys[i], end_ys[i]
        local y
        if opts.at_end then
            y = math.max(s, e - (ui.dimen and ui.dimen.h or 0))
        else
            y = s + math.floor((pct or 0) * (e - s) / 100)
        end
        ui.rolling:_gotoPos(y)
        ui.rolling.xpointer = ui.document:getXPointer()
    else
        local page = opts.at_end and end_pages[i] or Reading.pageForPercent(plugin, i, pct or 0)
        ui:handleEvent(Event:new("GotoPage", page))
    end
    return true
end

--- Page of chapter i (of the open group file) that shows `percent` of it (page mode).
function Reading.pageForPercent(plugin, i, percent)
    local L = Reading.layout(plugin)
    local end_pages, end_ys = chapterEnds(L)
    local doc = plugin.ui.document
    local tops = {}
    if (percent or 0) > 0 then
        for q = L.pages[i], end_pages[i] do
            local ok, y = pcall(function() return doc:getPosFromXPointer(doc:getPageXPointer(q)) end)
            table.insert(tops, ok and tonumber(y) or (tops[#tops] or L.ys[i]))
        end
    end
    return Groups.pageForPercent(L.pages[i], tops, L.ys[i], end_ys[i], percent)
end

--[[--
On opening a group file: go to the chapter the plugin opened it for
(Session.group_target). KOReader's own saved position wins when the file is
the one it was (unchanged, or only grown at the end) and that position lies
inside the expected chapter: it is more precise than a percent. Opened by
paging back: the chapter's last page. Opened by KOReader itself (History,
last document): its own position, whatever chapter that is.
]]
function Reading.placeInGroup(plugin)
    local r = plugin.reading
    local target = Session.group_target
    Session.group_target = nil
    if target and not samePath(target.path, r.path) then target = nil end
    Reading.layout(plugin)
    local here = Reading.where(plugin)
    local forced = target and Position.takeForced(Session, r.novel_id, target.chapter_id) or nil
    if target and r.group.index[target.chapter_id] and forced then
        -- "Go to latest" (position.lua): the other device's place, whatever KOReader had saved.
        Reading.goToProgress(plugin, target.chapter_id, forced, true)
        logger.info("Novarr: chapter", target.chapter_id, "at the latest position,", forced, "%")
        here = Reading.where(plugin)
    elseif target and r.group.index[target.chapter_id] then
        local keep = not target.at_end and r.had_position and target.decision ~= "rebuild"
            and here and r.group.ids[here.index] == target.chapter_id
        if keep then
            logger.info("Novarr: chapter", target.chapter_id, "at KOReader's saved position")
        else
            Reading.goInside(plugin, target.chapter_id, { at_end = target.at_end, resume = true })
            logger.info("Novarr: chapter", target.chapter_id, target.at_end and "at its last page" or "placed in the group file")
        end
        r.at_end_open = target.at_end or nil
        here = Reading.where(plugin)
    elseif target then
        logger.warn("Novarr: chapter", target.chapter_id, "is not in", r.path)
    end
    r.chapter_id = r.group.ids[here and here.index or 1]
    r.left, r.ordinal, r.ordinal_of = nil, nil, nil
end

--[[--
Moves to `progress` (0..100) of a chapter in the open document: the open
chapter file, or a chapter of the open group file ("Go to latest"). In a
group file 100 is the chapter's last page (not the start of the next one).
placing: called while the file is being placed (onReady), before tracking starts.
]]
function Reading.goToProgress(plugin, chapter_id, progress, placing)
    local r = plugin.reading
    if not r then return false end
    progress = math.max(0, math.min(100, tonumber(progress) or 0))
    if r.group then
        if not r.group.index[chapter_id] then return false end
        if progress >= 100 then
            Reading.goInside(plugin, chapter_id, { at_end = true })
        else
            Reading.goInside(plugin, chapter_id, { percent = progress })
        end
        if not placing and r.ready then Reading.trackGroup(plugin) end
        return true
    end
    if r.chapter_id ~= chapter_id then return false end
    plugin.ui:handleEvent(Event:new("GotoPercent", progress))
    return true
end

--- Jumps to a chapter of the open group file (menu, chapter list). Returns true when it is there.
function Reading.jumpTo(plugin, chapter_id, opts)
    local r = plugin.reading
    if not r or not r.group or not r.group.index[chapter_id] then return false end
    logger.info("Novarr: going to chapter", chapter_id, "inside the group file")
    Reading.goInside(plugin, chapter_id, opts)
    -- The move above was seen by onMove (events are synchronous); make sure.
    if r.ready then Reading.trackGroup(plugin) end
    return true
end

--[[--
The end of a chapter, reached by paging through its last page (or, for the
last chapter of a file, KOReader's end of the document): reading time
recorded, marked read (setting) or progress 100, as one chapter file did.
]]
function Reading.finishChapter(plugin)
    local r = plugin.reading
    if not r or r.ended then return end
    r.ended = true
    r.percent = 100
    local seconds
    if r.timer then
        ReadingInfo.tick(r.timer, os.time())
        seconds = r.timer.seconds
        local kept = Session:recordChapterTime(r.novel_id, r.timer.seconds)
        logger.info("Novarr: chapter", r.chapter_id, "read in", r.timer.seconds, "s of active reading", kept and "(recorded)" or "(too short, ignored)")
    end
    -- Reading stats (Novel screen): one finished chapter, now.
    Session.guard(Session.recordChapterRead, Session, r.novel_id, seconds)
    if Session:get("mark_read_at_end") then
        Session:markChapter(r.novel_id, r.chapter_id, true, "end")
    else
        Session:recordProgress(r.novel_id, r.chapter_id, 100)
    end
    r.dirty = false
    Reading.liveSync()
end

--- Chapters left, time per chapter and "ch N of M" for the current chapter; refreshes the status bar.
function Reading.chapterInfo(plugin)
    local r = plugin.reading
    r.left = Session:novelEstimate(r.novel_id, r.chapter_id, 0)
    r.per_chapter = Session:secondsPerChapter(r.novel_id)
    local cl = Session:chapterList(r.novel_id)
    if cl then r.ordinal, r.ordinal_of = Model.ordinal(cl.list, r.chapter_id) end
    Reading.refreshFooter(plugin)
end

--[[--
After every move in a group file: has the reader gone into another chapter?
Forward by one screen through the end of a chapter finishes it (see
Groups.finished); any other change (paging back, a jump through go-to, skim,
the table of contents, bookmarks or the menu, a re-layout) only saves the
left chapter's position, as closing it did.
]]
function Reading.trackGroup(plugin)
    local r = plugin.reading
    local _L, fresh = Reading.layout(plugin)
    local here = Reading.where(plugin)
    local prev = r.last_where
    r.last_where = here
    local from = r.group.index[r.chapter_id]
    local to = here.index
    if not from or to == from then return end
    local finished = {}
    if not fresh and prev and prev.key == here.key and prev.scroll == here.scroll then
        local L = r.layout
        local end_pages, end_ys = chapterEnds(L)
        local doc = plugin.ui.document
        local okv, visible = pcall(doc.getVisiblePageCount, doc)
        finished = Groups.finished({
            from = from, to = to, scroll = here.scroll,
            prev = here.scroll and prev.y or prev.page,
            cur = here.scroll and here.y or here.page,
            ends = here.scroll and end_ys or end_pages,
            view = here.scroll and (plugin.ui.dimen and plugin.ui.dimen.h or 0) or (okv and tonumber(visible) or 1),
        })
    end
    local left_id = r.chapter_id
    local done = {}
    for _i, idx in ipairs(finished) do
        done[idx] = true
        if idx == from then
            Reading.finishChapter(plugin)
        else
            -- Paged through in the same move (a very short chapter): finished, not timed.
            Session.guard(Session.recordChapterRead, Session, r.novel_id, nil)
            if Session:get("mark_read_at_end") then
                Session:markChapter(r.novel_id, r.group.ids[idx], true, "end")
            end
        end
    end
    if not done[from] then Reading.persist(plugin) end
    r.chapter_id = r.group.ids[to]
    r.ended = false
    r.percent = here.percent
    r.dirty = false
    r.at_end_open = nil
    r.timer = ReadingInfo.newTimer(os.time())
    logger.info(string.format("Novarr: chapter %s -> %s inside the group file (%s)", tostring(left_id), tostring(r.chapter_id),
        done[from] and "finished by paging" or (to > from and "jumped forward" or "back")))
    Session.guard(Reading.chapterInfo, plugin)
    -- As opening a chapter did: a few seconds later, quietly send what is
    -- pending (when connected) and fetch ahead from this chapter on.
    if plugin.background_task then
        r.prefetch = nil
        if plugin.prefetch_task then UIManager:unschedule(plugin.prefetch_task) end
        UIManager:unschedule(plugin.background_task)
        UIManager:scheduleIn(Reading.BACKGROUND_DELAY, plugin.background_task)
    end
end

--- The document was laid out again: chapter starts are worked out afresh.
function Reading.onRerendered(plugin)
    local r = plugin.reading
    if not r or not r.group or not r.ready then return end
    r.layout = nil
    Reading.trackGroup(plugin)
    r.last_where = Reading.where(plugin)
    r.percent = r.last_where and r.last_where.percent or r.percent
    Session.guard(Reading.chapterInfo, plugin)
end

-- ---------------------------------------------------------------- highlights

-- What was last sent for each highlight item of the open document (weak: items go with the document).
local sent_items = setmetatable({}, { __mode = "k" })

--[[--
The chapter a highlight belongs to: the open chapter, or in a group file
the chapter whose start comes last at or before the highlight's start
(by its y position in the document, else by its page).
]]
function Reading.chapterOfItem(plugin, item)
    local r = plugin.reading
    if not r.group then return r.chapter_id end
    local Highlights = require("novarr.highlights")
    local doc = plugin.ui.document
    local L = Reading.layout(plugin)
    local xp = type(item.pos0) == "string" and item.pos0 or (type(item.page) == "string" and item.page) or nil
    if xp then
        local ok, y = pcall(doc.getPosFromXPointer, doc, xp)
        if ok and tonumber(y) then return Highlights.chapterAt(L.ys, tonumber(y), r.group.ids) end
        local okp, page = pcall(doc.getPageFromXPointer, doc, xp)
        if okp and tonumber(page) then return Highlights.chapterAt(L.pages, tonumber(page), r.group.ids) end
    end
    if tonumber(item.pageno) then return Highlights.chapterAt(L.pages, tonumber(item.pageno), r.group.ids) end
    return nil
end

--- An annotation was added, changed or removed in a Novarr chapter (see Reading.attach).
function Reading.onAnnotation(plugin, items)
    local r = plugin.reading
    if not r then return end
    local Highlights = require("novarr.highlights")
    local kind, item, before = Highlights.change(items)
    if not kind then return end
    local chapter = Reading.chapterOfItem(plugin, item)
    if not chapter then
        logger.info("Novarr: highlight not sent: its chapter could not be worked out")
        return
    end
    local last = sent_items[item]
    if kind == "removed" then
        sent_items[item] = nil
        Session:unqueueHighlight(chapter, item.text)
        return
    end
    if kind == "moved" then
        Session:unqueueHighlight(Reading.chapterOfItem(plugin, before) or chapter, before.text)
        last = nil
    elseif kind == "changed" and last then
        if last.text == item.text and last.note == item.note and last.chapter == chapter then return end -- style, colour
        -- The highlighted text itself was edited: it is another highlight for the server.
        if last.text ~= item.text then Session:unqueueHighlight(last.chapter, last.text) end
    end
    -- A change without a note (note deleted, or not known to have none): the server's note is cleared.
    if Session:queueHighlight(r.novel_id, chapter, item.text, item.note, kind ~= "added") then
        sent_items[item] = { text = item.text, note = item.note, chapter = chapter }
    end
end

-- ---------------------------------------------------------------- sleep screen cover

--[[--
Gives the open chapter the novel's cover as its KOReader "custom cover" (a
cover.* file in the chapter's sidecar folder). KOReader's "book cover" sleep
screen, its history and its file browser then show the novel's cover for
what is otherwise a cover-less HTML file. Uses a cover already in the cache;
with `fetch` it may download one (callers pass true only when connected).
The file goes away with the chapter's sidecar when the chapter is removed.
]]
function Reading.applyCover(plugin, fetch)
    local r = plugin.reading
    if not r or r.cover_done then return end
    local doc = plugin.ui and plugin.ui.document
    local path = doc and doc.file
    if not path then return end
    local DocSettings = require("docsettings")
    if not DocSettings.flushCustomCover or not DocSettings.findCustomCoverFile then
        r.cover_done = true -- KOReader too old for custom covers
        return
    end
    if DocSettings:findCustomCoverFile(path) then
        r.cover_done = true
        r.has_cover = true
        return
    end
    local novel = Session:novelSummary(r.novel_id)
    if not novel then return end
    local urls = {}
    if novel.cover_large_url then table.insert(urls, novel.cover_large_url) end
    if novel.cover_url then table.insert(urls, novel.cover_url) end
    if #urls == 0 then r.cover_done = true return end
    local covers = Session:covers()
    local image
    for _i, url in ipairs(urls) do
        image = covers:cached(url)
        if image then break end
    end
    if not image and fetch then
        for _i, url in ipairs(urls) do
            image = covers:download(url)
            if image then break end
        end
        r.cover_done = true -- one attempt per chapter
    end
    if not image then return end
    DocSettings:flushCustomCover(path, image)
    if plugin.ui.doc_settings and plugin.ui.doc_settings.getCustomCoverFile then
        plugin.ui.doc_settings:getCustomCoverFile(true) -- reset its cache
    end
    r.cover_done = true
    r.has_cover = true
end

--[[--
The first time a Novarr chapter has a cover, switches KOReader's sleep
screen to the book cover, as a Kindle does with its own books. Done once
only, without asking (the user's request): after that the switch in
Novarr ▸ Settings is the user's to leave on or turn off.
]]
function Reading.offerSleepCover(plugin)
    local r = plugin.reading
    if not r or not r.has_cover or Session:get("sleep_cover_asked") then return end
    Session:set("sleep_cover_asked", true)
    if not Session:sleepCoverOn() then
        Session:setSleepCover(true)
        logger.info("Novarr: sleep screen set to the book cover")
    end
end

-- ---------------------------------------------------------------- status bar

function footerOf(plugin)
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
    local cl = Session:chapterList(r.novel_id)
    if cl then r.ordinal, r.ordinal_of = Model.ordinal(cl.list, r.chapter_id) end
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

--- Redraws the status bar now (chapter info changed, a flush sent something). Not while asleep.
function Reading.refreshFooter(plugin)
    if Session.asleep then return end
    local footer = footerOf(plugin)
    if plugin.footer_func and footer and footer.onUpdateFooter then
        pcall(footer.onUpdateFooter, footer, true)
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
    local sync_part
    if Session:get("show_sync_status") then
        sync_part = ReadingInfo.syncPart(Session:syncState(), os.time())
    end
    local parts = ReadingInfo.statusParts(clock, r.ordinal, r.ordinal_of, r.left, secs, sync_part)
    local measure, budget = Reading.footerMeasure(plugin)
    return ReadingInfo.fitStatus(parts, measure, budget)
end

--[[--
How wide the status line may be: the width ReaderFooter gives its text
(screen minus the progress bar's minimum share when the bar is alongside),
halved in "all items at once" mode where KOReader's own items share it.
Returns measure(text) -> px and the budget, or nil when unknown.
]]
function Reading.footerMeasure(plugin)
    local footer = footerOf(plugin)
    if not footer or not footer.footer_text_face then return nil end
    local Screen = require("device").screen
    local width = footer._saved_screen_width or Screen:getWidth()
    local st = footer.settings or {}
    local budget = width - 2 * (footer.horizontal_margin or 0)
    if not st.disable_progress_bar and st.progress_bar_position == "alongside" then
        budget = width * (100 - (st.progress_bar_min_width_pct or 20)) / 100 - 2 * (footer.horizontal_margin or 0)
    end
    if st.all_at_once then budget = budget / 2 end
    local RenderText = require("ui/rendertext")
    local face = footer.footer_text_face
    return function(text)
        local ok, size = pcall(RenderText.sizeUtf8Text, RenderText, 0, Screen:getWidth(), face, text, true, false)
        return ok and size and size.x or 0
    end, budget
end

function Reading.pauseTimer(plugin)
    local r = plugin.reading
    if r and r.timer then ReadingInfo.pause(r.timer) end
    Reading.timeTick(plugin, true)
end

function Reading.resumeTimer(plugin)
    local r = plugin.reading
    if r and r.timer then r.timer.last = os.time() end
    Reading.timeStart(plugin)
end

-- ---------------------------------------------------------------- reading time with Wi-Fi on

local function wifiIsOn()
    local ok, on = pcall(function() return require("novarr.wifi").backend.isOn() end)
    return ok and on and true or false
end

--- A reading segment starts (chapter ready, woken up): see wifitime.lua.
function Reading.timeStart(plugin)
    local r = plugin.reading
    if not r or r.wt then return end
    r.wt = require("novarr.wifitime").start(os.time(), wifiIsOn())
end

--[[--
Ends the current segment (a page turn, Wi-Fi going on or off) and starts
the next one; with stop (sleep, closing) no next one. Written at most once
a minute, and when stopping.
]]
function Reading.timeTick(plugin, stop)
    local r = plugin.reading
    if not r or not r.wt then return end
    local WifiTime = require("novarr.wifitime")
    local now = os.time()
    local data = Session:wifiTime()
    WifiTime.add(data, r.wt, now, wifiIsOn())
    if stop then r.wt = nil end
    if stop or WifiTime.saveDue(Session.wifitime_saved, now) then
        Session.wifitime_saved = now
        Session.store:saveWifiTime()
    end
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

--[[--
Chapters stored ahead of the open one: {has_list, ahead (stored among the
next `n` chapters the server has text for), missing (of those, not stored
and fetchable)}.
]]
function Reading.aheadInfo(r, n)
    local out = { has_list = false, ahead = 0, missing = 0 }
    local cl = Session:chapterList(r.novel_id)
    if not cl or not cl.pos[r.chapter_id] then return out end
    out.has_list = true
    if (tonumber(n) or 0) <= 0 then return out end
    for _i, id in ipairs(Model.nextChapters(cl.list, r.chapter_id, n)) do
        if Session.store:hasChapter(r.novel_id, id) then
            out.ahead = out.ahead + 1
        elseif not Session.empty_chapters[id] then
            out.missing = out.missing + 1
        end
    end
    return out
end

local function Wifi() return require("novarr.wifi") end

function Reading.background(plugin)
    local r = plugin.reading
    if not r or not Session:serverUrl() then return end
    -- Not linked to the account (auth.lua): nothing is asked of the server
    -- until it is; the online window has nothing to do (so Wi-Fi may go off).
    if Session:needsLink() then
        local window = Wifi().managing() and Wifi().window or nil
        if window and not window.background_done then
            window.background_done = true
            Wifi().evaluate("not linked")
        end
        return
    end
    -- Never prompt; Wi-Fi only comes on when Novarr had switched it off (wifi.lua).
    if not Session:isConnected() then
        if Wifi().managing() then Wifi().evaluate("chapter opened, offline") end
        return
    end
    local managing = Wifi().managing()
    local window = managing and Wifi().window or nil
    local res = Session:quietFlush(managing and 200 or 20)
    if res and (res.sent or 0) + (res.dropped or 0) > 0 then Wifi().progress() end
    if res and res.stalled then
        if managing then Wifi().evaluate("sync failed") end
        return
    end
    if window and not window.list_refreshed then
        -- Once per online window: new chapters on the server?
        local _cl, e = Session:refreshChapterList(r.novel_id)
        if not e then window.list_refreshed = true end
    end
    if window and not window.update_ran then
        window.update_ran = true
        Session.guard(function() require("novarr.ui_update").autoCheck(Session) end)
    end
    -- The novel's cover for the sleep screen, if it was not cached yet.
    Session.guard(Reading.applyCover, plugin, true)
    Session.guard(Reading.offerSleepCover, plugin)
    r.prefetch = Reading.prefetchIds(r)
    r.prefetch_total = #r.prefetch
    r.prefetch_done = 0
    r.prefetch_t0 = Session.nowMs()
    -- The pace is chosen for the whole run: a big fill stays fast to its end.
    r.prefetch_fast = #r.prefetch > Reading.PREFETCH_FAST_ABOVE
    if #r.prefetch > 0 then
        logger.info(string.format("Novarr: prefetch: %d chapter(s) to fetch ahead (%s)", #r.prefetch,
            #r.prefetch > Reading.PREFETCH_FAST_ABOVE and "fast" or "topping up"))
        UIManager:scheduleIn(Reading.prefetchGap(r), plugin.prefetch_task)
    end
    if window then window.background_done = true end
    if managing then Wifi().evaluate("background done") end
end

--[[--
The idle timer fired: pages turned since -> wait for the rest of the
period; otherwise (a pause of IDLE_SYNC_MS) save the position and send the
queue when connected, or ask for Wi-Fi when Novarr switched it off. The
next page turn starts a new timer.
]]
function Reading.idleSync(plugin)
    local r = plugin.reading
    if not r then
        plugin.idle_scheduled = false
        return
    end
    local since = r.last_turn and Session.nowMs() - r.last_turn or Reading.IDLE_SYNC_MS
    if since < Reading.IDLE_SYNC_MS then
        UIManager:scheduleIn((Reading.IDLE_SYNC_MS - since) / 1000 + 0.1, plugin.idle_task)
        return
    end
    plugin.idle_scheduled = false
    Reading.persist(plugin)
    if not Session:serverUrl() or Session.queue:count() == 0 then return end
    local managing = Wifi().managing()
    if Session:isConnected() then
        local res = Session:quietFlush(50)
        if managing then
            if res and (res.sent or 0) + (res.dropped or 0) > 0 then Wifi().progress() end
            Wifi().evaluate("idle sync")
        end
    elseif managing then
        Wifi().requestSync("idle")
    end
end

--- Stops the idle timer (sleep, close); the next page turn starts a new one.
function Reading.unscheduleIdle(plugin)
    if plugin.idle_task then UIManager:unschedule(plugin.idle_task) end
    plugin.idle_scheduled = false
end

--- Seconds between two prefetched chapters: short when the run started with many missing.
function Reading.prefetchGap(r)
    if r.prefetch_fast then return Reading.PREFETCH_FAST_STEP end
    return Reading.PREFETCH_STEP
end

-- Seconds to wait before the next prefetch (0: go now). See Model.prefetchWait.
function Reading.prefetchWait(r)
    return Model.prefetchWait(Session.nowMs(), r.ready_at, r.last_turn, Reading.PREFETCH_START_MS, Reading.PREFETCH_QUIET_MS)
end

local function prefetchEnded(r, why)
    if (r.prefetch_done or 0) > 0 then
        logger.info(string.format("Novarr: prefetch %s: %d of %d chapter(s) in %.1f s", why, r.prefetch_done,
            r.prefetch_total or 0, (Session.nowMs() - (r.prefetch_t0 or Session.nowMs())) / 1000))
    end
    r.prefetch = nil
    if Wifi().managing() then Wifi().evaluate("prefetch " .. why) end
end

function Reading.prefetchStep(plugin)
    local r = plugin.reading
    if not r or not r.prefetch or #r.prefetch == 0 then return end
    if not Session:isConnected() then prefetchEnded(r, "stopped (offline)") return end
    -- Keep page turns smooth: not right after opening, not right after a turn.
    local wait = Reading.prefetchWait(r)
    if wait > 0 then
        UIManager:scheduleIn(wait + 0.1, plugin.prefetch_task)
        return
    end
    local free = Session:freeBytes()
    if free and free < Session.MIN_FREE_BYTES then prefetchEnded(r, "stopped (less than 20 MB free)") return end
    local id = table.remove(r.prefetch, 1)
    if not Session.store:hasChapter(r.novel_id, id) then
        local t0 = Session.nowMs()
        local path, why = Session:downloadChapter(id)
        r.prefetch_last_ms = Session.nowMs() - t0
        if not path then
            logger.info("Novarr: prefetch stopped at chapter", id, type(why) == "table" and why.kind or why)
            prefetchEnded(r, "stopped")
            return
        end
        r.prefetch_done = (r.prefetch_done or 0) + 1
        Wifi().progress()
        if r.prefetch_done % Reading.PREFETCH_LOG_EVERY == 0 or (r.prefetch_total or 0) <= Reading.PREFETCH_LOG_EVERY then
            logger.info(string.format("Novarr: prefetched %d of %d (chapter %d, %d ms)", r.prefetch_done,
                r.prefetch_total or 0, id, r.prefetch_last_ms))
        end
    end
    if #r.prefetch > 0 then
        UIManager:scheduleIn(Reading.prefetchGap(r), plugin.prefetch_task)
    else
        prefetchEnded(r, "done")
    end
end

-- ---------------------------------------------------------------- navigation

--[[--
The chapter after (dir = 1) or before (dir = -1) the open one that the
server has (or this device stored). Returns id, known_downloaded
(true/false/nil), label, missing: going forward, the chapter right after
the open one when it is neither on the server nor on this device
({id, label}; see Model.neighbourIn). The caller must not jump past it
without saying so (Reading.goNext).
]]
function Reading.neighbour(r, dir)
    local cl = Session:chapterList(r.novel_id)
    if cl and cl.pos[r.chapter_id] then
        local c, passed = Model.neighbourIn(cl.list, cl.pos[r.chapter_id], dir, function(id)
            return Session.store:hasChapter(r.novel_id, id)
        end)
        local missing = passed and { id = passed.id, label = Model.chapterLabel(passed) } or nil
        if c then return c.id, true, Model.chapterLabel(c), missing end
        return nil, nil, nil, missing
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

--[[--
"Check for new chapters" for the novel being read: asks the server to check
its source (checks.lua); the caught-up page shows how it goes.
]]
function Reading.checkNew(plugin)
    local r = plugin.reading
    if not r then return end
    require("novarr.checks").ask(r.novel_id)
end

--[[--
The chapter after the open one is not on the server yet (and not on this
device): "<label> isn't on the server yet." with Check for new chapters
(Reading.checkNew), Skip to <the next chapter there is> (only when there is
one) and Stay here. Nothing is opened or marked until a button is tapped.
]]
function Reading.showMissing(plugin, missing, next_id, next_downloaded, next_label)
    local r = plugin.reading
    if not r then return end
    local novel_id = r.novel_id
    logger.info("Novarr: chapter", missing.id, "after chapter", r.chapter_id, "is not on the server yet: asking")
    local buttons = {
        { text = _("Check for new chapters"), callback = function() Reading.checkNew(plugin) end },
    }
    if next_id then
        table.insert(buttons, { text = T(_("Skip to %1"), next_label or _("the next chapter")), callback = function()
            local rr = plugin.reading
            if not rr or rr.novel_id ~= novel_id then return end
            rr.next_at = nil
            Reading.openNext(plugin, next_id, next_downloaded)
        end })
    end
    UI().choice(T(_("%1 isn't on the server yet."), missing.label), buttons,
        { cancel_text = _("Stay here") })
end

--[[--
Goes to the next chapter, without asking: the stored copy right away, or
fetched when the device is already connected (brief "Loading" notice). A
message appears only when it cannot be opened. Never prompts to turn Wi-Fi
on (also used at the end of a chapter). A next chapter the server does not
have yet is never jumped past: Reading.showMissing asks first.
]]
function Reading.goNext(plugin)
    local r = plugin.reading
    if not r then return end
    -- A second request right behind the first (double page turn) is dropped.
    local now = Session.nowMs()
    if r.next_at and now - r.next_at < 1500 then return end
    r.next_at = now
    local id, downloaded, label, missing = Reading.neighbour(r, 1)
    if missing then
        -- Nothing is opened from here: no chapter change to time.
        Session.switch_t0 = nil
        Reading.showMissing(plugin, missing, id, downloaded, label)
        return
    end
    Reading.openNext(plugin, id, downloaded)
end

--[[--
Opens chapter `id` after the open one (goNext, or "Skip to" past a missing
chapter): in this group file, the stored copy, fetched when connected, Wi-Fi
switched back on when Novarr had switched it off, or the caught-up page
when there is no next chapter (id nil).
]]
function Reading.openNext(plugin, id, downloaded)
    local r = plugin.reading
    if not r then return end
    -- Joined chapters: the next one is in this file, a page turn away.
    if id and Reading.jumpTo(plugin, id, { resume = true }) then return end
    local stored = id and Session.store:hasChapter(r.novel_id, id)
    local connected = Session:isConnected()
    if id and (stored or (connected and downloaded ~= false)) then
        openLater(r.novel_id, id)
        return
    end
    -- Novarr switched Wi-Fi off to save battery: switch it back on and carry on.
    if id and not stored and not connected and downloaded ~= false then
        local novel_id = r.novel_id
        local restoring = Wifi().restore("the next chapter is not saved", function()
            openLater(novel_id, id)
        end, function()
            Session.switch_t0 = nil
            UI().info(_("The next chapter isn't saved on this device, and there's no network connection."))
        end, { notice = _("Getting the next chapter…") })
        if restoring then return end
    end
    -- Nothing is opened from here on: no chapter change to time.
    Session.switch_t0 = nil
    if id and not stored and not connected then
        UI().info(_("The next chapter isn't saved on this device, and there's no network connection."))
        return
    end
    -- Caught up with the newest downloaded chapter: a calm full-screen page.
    -- When Novarr had switched Wi-Fi off, it comes back (new chapters, sync).
    if not connected then Wifi().restore("caught up with the saved chapters") end
    local ok = pcall(function()
        local novel_id, chapter_id = r.novel_id, r.chapter_id
        require("novarr.ui_caughtup").show(Session, {
            novel_id = novel_id,
            chapter_id = chapter_id,
            -- After a check: the next chapter, once the refreshed list has one.
            has_next = function()
                local rr = plugin.reading
                return rr ~= nil and rr.novel_id == novel_id and Reading.neighbour(rr, 1) ~= nil
            end,
            on_continue = function()
                local rr = plugin.reading
                if not rr or rr.novel_id ~= novel_id then return end
                local id, dl, label, missing = Reading.neighbour(rr, 1)
                if missing then
                    -- A check brought later chapters but not the next one: never skip it silently.
                    Reading.showMissing(plugin, missing, id, dl, label)
                    return
                end
                if id then
                    rr.next_at = nil
                    if not Reading.jumpTo(plugin, id, { resume = true }) then openLater(novel_id, id) end
                end
            end,
        })
    end)
    if not ok then
        UI().info(_("You've reached the last downloaded chapter of this novel."))
    end
end

function Reading.goPrev(plugin, opts)
    opts = opts or {}
    local r = plugin.reading
    if not r then return end
    local id = Reading.neighbour(r, -1)
    if not id then
        if not opts.quiet then UI().info(_("This is the first chapter."), 2) end
        return
    end
    if Reading.jumpTo(plugin, id, { at_end = opts.at_end, resume = true }) then return end
    local stored = Session.store:hasChapter(r.novel_id, id)
    if not stored and not Session:isConnected() then
        UI().info(_("The previous chapter isn't saved on this device, and there's no network connection."))
        return
    end
    UIManager:nextTick(Session.wrap(function()
        Session:openChapter(r.novel_id, id, { from_reader = true, at_end = opts.at_end })
    end))
end

--[[--
Paging back on the first page of a chapter: open the previous chapter at
its last page. Guarded like the forward direction: not in the first 1.2 s
after the chapter became ready, and a second request within 1.5 s is
dropped. The first chapter does nothing.
]]
function Reading.onStart(plugin)
    local r = plugin.reading
    if not r or not r.ready then return end
    local now = Session.nowMs()
    if r.ready_at and now - r.ready_at < 1200 then return end
    if r.prev_at and now - r.prev_at < 1500 then return end
    r.prev_at = now
    Reading.goPrev(plugin, { at_end = true, quiet = true })
end

-- ---------------------------------------------------------------- end / close

function Reading.onEnd(plugin)
    local r = plugin.reading
    if not r then return end
    -- A page turn that was meant for the previous chapter can arrive just
    -- after this one opened; never let it skip (and mark read) a chapter
    -- that has been on screen for a moment only.
    if not r.ready or (r.ready_at and Session.nowMs() - r.ready_at < 1200) then return end
    -- A group file: the end of the document is the end of its last chapter.
    if r.group then Reading.trackGroup(plugin) end
    if not r.ended then Reading.finishChapter(plugin) end
    Session.switch_t0 = Session.nowMs()
    Reading.goNext(plugin)
end

function Reading.unschedule(plugin)
    Position.unschedule(plugin)
    if plugin.background_task then UIManager:unschedule(plugin.background_task) end
    if plugin.prefetch_task then UIManager:unschedule(plugin.prefetch_task) end
    Reading.unscheduleIdle(plugin)
end

function Reading.onClose(plugin)
    local r = plugin.reading
    if not r then return end
    Reading.unschedule(plugin)
    Session.guard(Reading.timeTick, plugin, true)
    Session.guard(Reading.removeFooter, plugin)
    Session.guard(function() Session:captureStyle() end)
    Reading.persist(plugin)
    Session.guard(function()
        if r.group then
            -- Switching to another group file: its onReady tidies up, around
            -- the block being opened (never this one's view of "earlier").
            if not Session.switching then
                Session:cleanupJoined(r.novel_id, r.group.block, r.group.n)
            end
        else
            Session:cleanupRead(r.novel_id, r.chapter_id)
        end
    end)
    if not Session.switching and Session.opened_from_novarr then
        Session.return_to = { novel_id = r.novel_id }
    end
    -- Leaving the book (not the next Novarr file, not a reload of this one):
    -- Wi-Fi that Novarr switched off comes back on (wifi.lua).
    if not Session.switching and not r.reloading then
        Session.guard(Position.leave)
        Session.guard(function()
            Wifi().stop()
            Wifi().restoreOnLeave("left the book")
        end)
    end
    -- A re-render reopens this file: that is not KOReader opening a book (Reading.onReady).
    Session.reloading_path = r.reloading and r.path or nil
    if Session.reader == plugin then Session.reader = nil end
    plugin.reading = nil
end

return Reading
