--[[--
Shared Novarr state and actions for every plugin instance (file manager and
reader each get their own plugin instance; this module outlives them).

Holds the settings, the store, the write queue and the API client, the stack
of open Novarr screens, and the actions screens and the reader call:
opening chapters, downloading, syncing, marking read.
]]

local DataStorage = require("datastorage")
local LuaSettings = require("luasettings")
local NetworkMgr = require("ui/network/manager")
local UIManager = require("ui/uimanager")
local lfs = require("libs/libkoreader-lfs")
local logger = require("logger")
local _ = require("gettext")
local T = require("ffi/util").template

local Api = require("novarr.api")
local ChapterDoc = require("novarr.chapterdoc")
local Json = require("novarr.json")
local Model = require("novarr.model")
local Queue = require("novarr.queue")
local ReadingInfo = require("novarr.readinginfo")
local Store = require("novarr.store")
local Sync = require("novarr.sync")

local Session = {
    menus = {},          -- open Novarr screens, bottom to top ({menu=, screen=})
    reader = nil,        -- reader-side plugin instance showing a Novarr chapter
    plugin = nil,        -- most recent plugin instance
    return_to = nil,     -- {novel_id} to show again when the reader closes to the file manager
    opened_from_novarr = false,
    switching = false,   -- a Novarr chapter is replacing another one in the reader
    chapter_lists = {},  -- novel_id -> {list=, by_id=, fetched_at=} memory cache
    last_errors = {},    -- screen key -> {time, kind} of the last failed refresh
    empty_chapters = {}, -- chapter ids the server had no text for (this session)
}

Session.VERSION = Api.VERSION

local DEFAULTS = {
    prefetch = 3,
    remove_after_reading = true,
    keep_read = 2,
    mark_read_at_end = true,
    show_reading_info = true,
    show_clock = true,
    show_covers = true,
}

local function nowMs()
    local ok, socket = pcall(require, "socket")
    if ok and socket.gettime then
        return math.floor(socket.gettime() * 1000)
    end
    return os.time() * 1000
end
Session.nowMs = nowMs

local function log(msg)
    logger.info(msg)
end
Session.log = log

-- ---------------------------------------------------------------- setup

function Session:init()
    if self.initialized then return end
    self.initialized = true
    self.settings = LuaSettings:open(DataStorage:getSettingsDir() .. "/novarr.lua")
    self.root = DataStorage:getFullDataDir() .. "/novarr"
    self.store = Store.new({ root = self.root, lfs = lfs, json = Json, now = nowMs, log = log })
    self.store:mkdirs(self.root)
    self.queue = Queue.new({
        load = function() return self.store:loadQueue() end,
        save = function(items) self.store:saveQueue(items) end,
        now = nowMs,
        log = log,
    })
    self:rebuildApi()
end

function Session:rebuildApi()
    self.api = Api.new({ base_url = self:get("server_url"), log = log })
end

function Session:get(key)
    local v = self.settings:readSetting(key)
    if v == nil then return DEFAULTS[key] end
    return v
end

function Session:set(key, value)
    self.settings:saveSetting(key, value)
    self.settings:flush()
end

function Session:serverUrl()
    return self:get("server_url")
end

-- ---------------------------------------------------------------- guards

--[[--
Runs fn in pcall; on error logs it and shows a brief message instead of
letting it reach KOReader. Returns fn's first result.
]]
function Session.guard(fn, ...)
    local ok, res = pcall(fn, ...)
    if not ok then
        logger.err("Novarr: unexpected error:", res, debug.traceback())
        pcall(function()
            local InfoMessage = require("ui/widget/infomessage")
            UIManager:show(InfoMessage:new{ text = _("Novarr ran into a problem. Details are in KOReader's log."), timeout = 3 })
        end)
        return nil
    end
    return res
end

--- Wraps fn so every call is guarded.
function Session.wrap(fn)
    return function(...) return Session.guard(fn, ...) end
end

-- ---------------------------------------------------------------- network

--- Cheap check: is there a network connection (never turns Wi-Fi on).
function Session:isConnected()
    if Api.simulate_offline then return false end
    local ok, res = pcall(NetworkMgr.isConnected, NetworkMgr)
    return ok and res and true or false
end

--[[--
Runs fn when connected, offering to turn Wi-Fi on first (KOReader's own
prompt). Returns true when fn ran right away. With the offline test hook it
never runs fn and calls on_unavailable instead.
]]
function Session:whenConnected(fn, on_unavailable)
    if Api.simulate_offline then
        if on_unavailable then on_unavailable() end
        return false
    end
    if self:isConnected() then
        fn()
        return true
    end
    NetworkMgr:runWhenConnected(Session.wrap(fn))
    return false
end

-- ---------------------------------------------------------------- messages

function Session:errorText(e)
    local kind = e and e.kind or "offline"
    local server = self:serverUrl() or ""
    if kind == "bad_url" then
        return _("That doesn't look like a server address. Try something like 192.168.1.34:8080.")
    elseif kind == "offline" then
        return T(_("Couldn't reach the Novarr server at %1.\n\nCheck that the server is running and that this device is on the same network."), server)
    elseif kind == "not_novarr" then
        return T(_("%1 answered, but it isn't a Novarr server (or its API isn't available)."), server)
    elseif kind == "not_found" then
        return _("The server doesn't know this item any more (it may have been removed).")
    elseif kind == "invalid" then
        if e.message then
            return T(_("The server refused the request: %1"), e.message)
        end
        return _("The server refused the request.")
    end
    if e.status then
        return T(_("The Novarr server had a problem (error %1). Try again later."), e.status)
    end
    return _("The Novarr server sent an answer that doesn't make sense. Is something else (a login page or a proxy) in the way?")
end

-- ---------------------------------------------------------------- cache

--[[--
Fetches a list endpoint into the cache. key: cache key, call(etag) performs
the request. Returns data (from server or cache), err (nil on success).
On 304 the cached copy is kept.
]]
function Session:fetchInto(key, call, use_etag)
    local cached = self.store:getCache(key)
    local started = nowMs()
    local data, e, info = call(use_etag and cached and cached.etag or nil)
    if info and info.not_modified and cached then
        self.store:touchCache(key, started)
        self.last_errors[key] = nil
        return cached.data, nil, started
    end
    if not data then
        if info and info.not_modified then
            -- 304 without a cached body: fetch again without the ETag.
            data, e, info = call(nil)
        end
        if not data then
            self.last_errors[key] = { time = nowMs(), kind = e and e.kind }
            return cached and cached.data or nil, e or Api.err("server")
        end
    end
    self.store:putCache(key, info.body, info.etag, started)
    self.last_errors[key] = nil
    local novel_id = key:match("^chapters_(%d+)$")
    if novel_id then self.chapter_lists[tonumber(novel_id)] = nil end
    return data, nil, started
end

function Session:cached(key)
    local c = self.store:getCache(key)
    return c and c.data or nil, c and c.fetched_at or nil
end

--- Chapter list for a novel from memory/disk cache: {list, by_id, pos} or nil.
function Session:chapterList(novel_id)
    local mem = self.chapter_lists[novel_id]
    if mem then return mem end
    local t0 = nowMs()
    local cache = self.store:getCache("chapters_" .. novel_id)
    if not cache or type(cache.data) ~= "table" or type(cache.data.chapters) ~= "table" then
        return nil
    end
    local list = cache.data.chapters
    local by_id, pos = {}, {}
    for i, c in ipairs(list) do
        by_id[c.id] = c
        pos[c.id] = i
    end
    mem = { list = list, by_id = by_id, pos = pos, fetched_at = cache.fetched_at }
    self.chapter_lists[novel_id] = mem
    log(string.format("Novarr: loaded saved chapter list of novel %d (%d chapters) in %d ms", novel_id, #list, nowMs() - t0))
    return mem
end

--- Fetches a novel's chapter list (ETag) and settles the overlay. Returns list entry, err.
function Session:refreshChapterList(novel_id)
    local _data, e, started = self:fetchInto("chapters_" .. novel_id, function(etag)
        return self.api:chapters(novel_id, etag)
    end, true)
    if not e and started then
        self.store:settleOverlay(novel_id, started)
    end
    return self:chapterList(novel_id), e
end

--- Novel summary from the cached detail, library or home.
function Session:novelSummary(novel_id)
    local detail = self:cached("novel_" .. novel_id)
    if detail and detail.novel then return detail.novel end
    local novels = self:cached("novels")
    if novels and novels.novels then
        for _i, n in ipairs(novels.novels) do
            if n.id == novel_id then return n end
        end
    end
    local home = self:cached("home")
    if home then
        for _i, list in ipairs({ home.continue or {}, home.new_chapters or {} }) do
            for _i, row in ipairs(list) do
                if row.novel and row.novel.id == novel_id then return row.novel end
            end
        end
    end
    local index = self.store:getIndex(novel_id)
    if index.novel_name then
        return { id = novel_id, name = index.novel_name }
    end
    return nil
end

-- ---------------------------------------------------------------- read state

--- Effective read state of a chapter: read (bool), progress (int|nil).
function Session:chapterState(novel_id, chapter_id)
    local cl = self:chapterList(novel_id)
    local row = cl and cl.by_id[chapter_id]
    if not row then
        local entry = self.store:chapterEntry(novel_id, chapter_id)
        if entry then row = { read = entry.read, read_progress = entry.read_progress } end
    end
    return Store.effective(row, self.store:getOverlay(novel_id)[tostring(chapter_id)])
end

--[[--
Local adjustment of a novel's read count from the overlay (marks not yet in
the server's numbers). Only computed for novels with pending marks.
]]
function Session:readDelta(novel_id)
    local overlay = self.store:getOverlay(novel_id)
    if next(overlay) == nil then return 0 end
    local cl = self:chapterList(novel_id)
    if not cl then return 0 end
    local delta = 0
    for key, entry in pairs(overlay) do
        if entry.read ~= nil then
            local row = cl.by_id[tonumber(key)]
            if row and row.read ~= entry.read then
                delta = delta + (entry.read and 1 or -1)
            end
        end
    end
    return delta
end

--[[--
Resume chapter for a novel, with the server's definition ("the next unread
downloaded chapter after the last read one") applied to local reading the
cached server data may not include yet: the further of the server's
resume_chapter_id and the local candidate (the chapter after the furthest
chapter this device marked read, or the furthest part-read one) wins.
Marking a chapter unread never moves it.
]]
function Session:resumeChapter(novel_id, summary)
    summary = summary or self:novelSummary(novel_id) or {}
    local resume = summary.resume_chapter_id
    local cl = self:chapterList(novel_id)
    if not cl then return resume end
    local read_pos, part_pos = 0, 0
    for key, entry in pairs(self.store:getOverlay(novel_id)) do
        local pos = cl.pos[tonumber(key)]
        if pos then
            if entry.read == true then
                if pos > read_pos then read_pos = pos end
            elseif entry.read == nil and entry.progress and entry.progress > 0 then
                if pos > part_pos then part_pos = pos end
            end
        end
    end
    local local_pos = part_pos
    if read_pos > 0 then
        for i = read_pos + 1, #cl.list do
            local c = cl.list[i]
            if c.downloaded and not self:chapterState(novel_id, c.id) then
                if i > local_pos then local_pos = i end
                break
            end
        end
    end
    local server_pos = resume and cl.pos[resume] or 0
    if local_pos > server_pos then return cl.list[local_pos].id end
    if server_pos > 0 then return resume end
    local idx = Model.resumeIndex(cl.list, nil, function(c) return (self:chapterState(novel_id, c.id)) end)
    return cl.list[idx] and cl.list[idx].id or resume
end

-- ---------------------------------------------------------------- estimates

--- Average word count per chapter: the API's word_count when present, else stored chapters.
function Session:averageWords(novel_id)
    local cl = self:chapterList(novel_id)
    local avg = cl and ReadingInfo.averageWords(cl.list)
    if avg then return avg end
    local rows = {}
    for _i, entry in pairs(self.store:getIndex(novel_id).chapters) do table.insert(rows, entry) end
    return ReadingInfo.averageWords(rows)
end

--- Seconds per chapter for a novel (measured or estimated) and the source.
function Session:secondsPerChapter(novel_id)
    return ReadingInfo.secondsPerChapter(self.store:getTiming(novel_id), self:averageWords(novel_id))
end

--- Records the active reading time of a finished chapter. Returns true if kept.
function Session:recordChapterTime(novel_id, seconds)
    local _list, kept = ReadingInfo.addSample(self.store:getTiming(novel_id), seconds)
    if kept then self.store:saveTiming() end
    return kept
end

--[[--
Chapters left and seconds left for a novel. With `current_id`: chapters
after the open one (time includes the rest of it, at `percent`). Without:
from the resume chapter on (inclusive). Returns left (nil when the chapter
list is not cached), seconds (nil when unknown).
]]
function Session:novelEstimate(novel_id, current_id, percent)
    local cl = self:chapterList(novel_id)
    if not cl then return nil end
    local is_read = function(c) return (self:chapterState(novel_id, c.id)) end
    local per = self:secondsPerChapter(novel_id)
    if current_id then
        local pos = cl.pos[current_id]
        if not pos then return nil end
        local left = ReadingInfo.chaptersLeft(cl.list, pos, is_read)
        return left, ReadingInfo.secondsLeft(per, left, percent)
    end
    local resume = self:resumeChapter(novel_id)
    local pos = resume and cl.pos[resume] or 1
    local left = ReadingInfo.chaptersLeft(cl.list, pos - 1, is_read)
    return left, per and per * left or nil
end

--[[--
Records a read mark locally and queues it. kind: "end" (read at the end of
the chapter, via the progress call), "set" (explicit mark read/unread).
]]
function Session:markChapter(novel_id, chapter_id, read, kind)
    if kind == "end" then
        self.store:setOverlay(novel_id, chapter_id, { read = true, progress = 100 })
        self.queue:enqueue({ kind = "progress", chapter_id = chapter_id, novel_id = novel_id, progress = 100, read = true })
    else
        self.store:setOverlay(novel_id, chapter_id, { read = read, progress = read and 100 or nil })
        self.queue:enqueue({ kind = "set_read", chapter_id = chapter_id, novel_id = novel_id, read = read })
    end
end

--- Records reading progress locally and queues it (coalesced).
function Session:recordProgress(novel_id, chapter_id, percent)
    percent = math.max(0, math.min(100, math.floor(percent + 0.5)))
    self.store:setOverlay(novel_id, chapter_id, { progress = percent })
    self.queue:enqueue({ kind = "progress", chapter_id = chapter_id, novel_id = novel_id, progress = percent })
end

--[[--
Bulk mark (scope "all" or "up_to"): one queued call, overlay updated for
every chapter of the cached list in range.
]]
function Session:bulkMark(novel_id, scope, anchor_id, read)
    local cl = self:chapterList(novel_id)
    if cl then
        local last = #cl.list
        if scope == "up_to" and anchor_id then last = cl.pos[anchor_id] or 0 end
        for i = 1, last do
            local c = cl.list[i]
            self.store:setOverlay(novel_id, c.id, { read = read, progress = read and 100 or nil }, false)
        end
        self.store:saveOverlay(novel_id)
    end
    local bulk = { read = read, scope = scope, novel_id = novel_id }
    if scope == "up_to" then bulk.anchor_id = anchor_id end
    self.queue:enqueue({ kind = "bulk_read", novel_id = novel_id, bulk = bulk })
end

-- ---------------------------------------------------------------- sync

--- Sends pending writes now (no UI). Returns the summary.
function Session:flushQueue(max_entries)
    if self.queue:count() == 0 then
        return { sent = 0, dropped = 0, remaining = 0 }
    end
    return Sync.flush({ api = self.api, queue = self.queue, store = self.store, now = nowMs, log = log,
        max_entries = max_entries })
end

--- "Sync now": user action with Wi-Fi prompt and feedback.
function Session:syncNow(done)
    local UI = require("novarr.ui_common")
    if not self:serverUrl() then return end
    local function run()
        local closer = UI.loading(_("Syncing with Novarr…"))
        local r = self:flushQueue()
        -- Pull fresh lists so the screens show the server's view.
        self:fetchInto("home", function() return self.api:home() end, false)
        closer()
        if r.remaining > 0 then
            UI.info(T(_("Couldn't send everything: %1 change(s) are still waiting.\n\n%2"), r.remaining, self:errorText(r.error)))
        elseif (r.sent or 0) + (r.dropped or 0) > 0 then
            UI.notify(T(_("Synced %1 change(s) with Novarr."), r.sent + r.dropped))
        else
            UI.notify(_("Everything is in sync."))
        end
        self:refreshScreens()
        if done then done() end
    end
    self:whenConnected(run, function()
        UI.info(_("No network connection. Your changes are kept and will be sent later."))
    end)
end

--- Quiet background flush: only when already connected, never prompts.
function Session:quietFlush(max_entries)
    if self.queue:count() == 0 or not self:serverUrl() or not self:isConnected() then return end
    local r = self:flushQueue(max_entries or 50)
    log(string.format("Novarr: background sync sent %d, dropped %d, %d left", r.sent or 0, r.dropped or 0, r.remaining or 0))
    return r
end

-- ---------------------------------------------------------------- screens

function Session:pushMenu(menu, screen)
    table.insert(self.menus, { menu = menu, screen = screen })
end

function Session:menuClosed(menu)
    for i = #self.menus, 1, -1 do
        if self.menus[i].menu == menu then
            table.remove(self.menus, i)
        end
    end
end

function Session:topMenu()
    local top = self.menus[#self.menus]
    return top and top.menu, top and top.screen
end

function Session:closeAllMenus()
    while #self.menus > 0 do
        local entry = table.remove(self.menus)
        UIManager:close(entry.menu)
    end
end

--- Rebuilds every open screen from cached data (after local changes).
function Session:refreshScreens()
    for _i, entry in ipairs(self.menus) do
        if entry.screen and entry.screen.rebuild then
            Session.guard(entry.screen.rebuild, entry.screen)
        end
    end
end

function Session:showHome()
    if not self:serverUrl() then
        require("novarr.ui_settings").askServer(self, function()
            require("novarr.ui_home").show(self)
        end)
        return
    end
    -- One Home at a time: reuse an open one.
    for _i, entry in ipairs(self.menus) do
        if entry.screen and entry.screen.kind == "home" then
            while #self.menus > 0 and self.menus[#self.menus].menu ~= entry.menu do
                UIManager:close(table.remove(self.menus).menu)
            end
            entry.screen:refresh()
            return
        end
    end
    require("novarr.ui_home").show(self)
end

function Session:showChapters(novel_id)
    require("novarr.ui_chapters").show(self, novel_id)
end

function Session:showNovel(novel_id)
    require("novarr.ui_novel").show(self, novel_id)
end

-- ---------------------------------------------------------------- chapters

--[[--
Fetches a chapter from the server and stores it. Returns path or nil plus
a reason ("not_downloaded" or an API error table).
]]
function Session:downloadChapter(chapter_id)
    local data, e = self.api:chapter(chapter_id)
    if not data then return nil, e end
    local ch = data.chapter
    if type(ch) ~= "table" or not ch.id or not ch.novel_id then
        return nil, Api.err("server", nil, "bad chapter")
    end
    if not ch.downloaded then
        return nil, "not_downloaded", ch
    end
    if type(ch.paragraphs) ~= "table" or #ch.paragraphs == 0 then
        self.empty_chapters[ch.id] = true
        return nil, "empty", ch
    end
    local html = ChapterDoc.build(ch, "en")
    local path, werr = self.store:saveChapter(ch, html)
    if not path then
        logger.warn("Novarr: cannot save chapter", chapter_id, werr)
        return nil, Api.err("storage", nil, werr)
    end
    -- Remember the server's position/read flag with the stored copy.
    local entry = self.store:chapterEntry(ch.novel_id, ch.id)
    if entry then
        entry.read = ch.read
        entry.read_progress = ch.read_progress
        self.store:saveIndex(ch.novel_id)
    end
    return path, nil, ch
end

function Session:openFile(path)
    self:closeAllMenus()
    -- Never let the clean-up of the closing chapter remove the one being opened.
    self.opening_path = path
    local ReaderUI = require("apps/reader/readerui")
    if ReaderUI.instance and ReaderUI.instance.document then
        self.switching = true
        ReaderUI.instance:switchDocument(path)
    else
        ReaderUI:showReader(path)
    end
end

--[[--
Opens a chapter: the stored copy right away, else fetched (with the Wi-Fi
prompt), else a clear message. opts.from_reader: called from the reader.
]]
function Session:openChapter(novel_id, chapter_id, opts)
    local UI = require("novarr.ui_common")
    opts = opts or {}
    local path = novel_id and self.store:chapterPath(novel_id, chapter_id)
    if path and lfs.attributes(path, "mode") == "file" then
        self.opened_from_novarr = true
        self:openFile(path)
        return true
    end
    local cl = novel_id and self:chapterList(novel_id)
    local row = cl and cl.by_id[chapter_id]
    if row and row.downloaded == false then
        UI.info(self:notDownloadedText(row))
        return false
    end
    local function fetch()
        local closer = UI.loading(_("Fetching chapter…"))
        local fetched, why, ch = self:downloadChapter(chapter_id)
        closer()
        if fetched then
            self.opened_from_novarr = true
            self:openFile(fetched)
        elseif why == "not_downloaded" then
            UI.info(self:notDownloadedText(row or ch))
        elseif why == "empty" then
            UI.info(_("This chapter has no text on the Novarr server (it may have failed to download from its source)."))
        elseif type(why) == "table" and why.kind == "offline" then
            UI.info(_("This chapter isn't saved on this device, and the Novarr server can't be reached right now."))
        else
            UI.info(self:errorText(why))
        end
    end
    self:whenConnected(fetch, function()
        UI.info(_("This chapter isn't saved on this device, and there's no network connection."))
    end)
    return false
end

function Session:notDownloadedText(row)
    local text = _("Novarr hasn't downloaded this chapter from its source yet, so there is nothing to read.")
    if row and row.reason and row.reason ~= "" then
        text = text .. "\n\n" .. row.reason
    end
    return text
end

-- ---------------------------------------------------------------- removal

local function purgeSidecar(path)
    pcall(function()
        local DocSettings = require("docsettings")
        DocSettings:open(path):purge()
    end)
    pcall(function()
        require("readhistory"):removeItemByPath(path)
    end)
end
Session.purgeSidecar = purgeSidecar

function Session:currentPath()
    local ReaderUI = require("apps/reader/readerui")
    local inst = ReaderUI.instance
    return inst and inst.document and inst.document.file or nil
end

--- Removes one stored chapter with its KOReader data. Returns true if removed.
function Session:removeChapter(novel_id, chapter_id)
    local path = self.store:chapterPath(novel_id, chapter_id)
    if not path or path == self:currentPath() or path == self.opening_path then return false end
    purgeSidecar(path)
    self.store:removeChapter(novel_id, chapter_id)
    return true
end

--- Removes all stored chapters of a novel (except the one open in the reader).
function Session:removeNovelDownloads(novel_id)
    local current = self:currentPath()
    local kept = false
    for _i, path in ipairs(self.store:chapterPaths(novel_id)) do
        if path == current then
            kept = true
        else
            purgeSidecar(path)
        end
    end
    if kept then
        for _i, entry in pairs(self.store:getIndex(novel_id).chapters) do
            local p = self.store:chaptersDir(novel_id) .. "/" .. entry.file
            if p ~= current then self.store:removeChapter(novel_id, entry.id) end
        end
    else
        self.store:removeNovel(novel_id)
    end
    return kept
end

--[[--
Remove-after-reading rule: delete stored chapters marked read except the 2
most recent (in reading order) and except `current_id`. Unread and part-read
chapters are never touched.
]]
function Session:cleanupRead(novel_id, current_id)
    if not self:get("remove_after_reading") then return 0 end
    local index = self.store:getIndex(novel_id)
    local cl = self:chapterList(novel_id)
    local stored = {}
    for _i, entry in pairs(index.chapters) do
        local order = cl and cl.pos[entry.id]
        if not order then
            order = (tonumber(entry.book) or 0) * 1000000 + (tonumber(entry.chapter) or 0)
        end
        table.insert(stored, { id = entry.id, order = order })
    end
    local current_order
    for _i, st in ipairs(stored) do
        if st.id == current_id then current_order = st.order end
    end
    local ids = Model.removalCandidates(stored, function(id) return self:chapterState(novel_id, id) end,
        self:get("keep_read"), current_id, current_order)
    local n = 0
    for _i, id in ipairs(ids) do
        if self:removeChapter(novel_id, id) then n = n + 1 end
    end
    if n > 0 then log("Novarr: removed " .. n .. " read chapter(s) of novel " .. novel_id) end
    return n
end

-- ---------------------------------------------------------------- downloads

--- Free bytes on the storage volume, or nil when unknown.
function Session:freeBytes()
    local ok, usage = pcall(function() return require("util").diskUsage(self.root) end)
    if ok and type(usage) == "table" and usage.available then
        return usage.available
    end
    return nil
end

Session.MIN_FREE_BYTES = 20 * 1024 * 1024

--[[--
Downloads chapters one per UI tick with a cancellable progress dialog.
ids: chapter ids in order. done(summary) is called at the end.
]]
function Session:downloadMany(novel_id, ids, done)
    local UI = require("novarr.ui_common")
    if #ids == 0 then
        UI.info(_("Everything you chose is already on this device."))
        return
    end
    local job = { total = #ids, done = 0, skipped = 0, failed = 0, cancelled = false, i = 0 }
    self.download_job = job
    local dialog = require("novarr.ui_progress"):new{
        title = _("Downloading chapters"),
        text = T(_("0 of %1 saved"), job.total),
        cancel_callback = function() job.cancelled = true end,
    }
    UIManager:show(dialog)
    local function finish(message)
        self.download_job = nil
        UIManager:close(dialog)
        if message then UI.info(message) end
        self:refreshScreens()
        if done then done(job) end
    end
    local step
    step = Session.wrap(function()
        if job.cancelled then
            finish(T(_("Download cancelled. %1 chapter(s) saved."), job.done))
            return
        end
        if job.i >= #ids then
            local msg = T(_("Saved %1 chapter(s) for offline reading."), job.done)
            if job.skipped > 0 then
                msg = msg .. "\n" .. T(_("%1 chapter(s) skipped: the server has no text for them yet."), job.skipped)
            end
            if job.failed > 0 then
                msg = msg .. "\n" .. T(_("%1 chapter(s) couldn't be fetched."), job.failed)
            end
            finish(msg)
            return
        end
        local free = self:freeBytes()
        if free and free < Session.MIN_FREE_BYTES then
            finish(T(_("Stopped: less than 20 MB of free space left on this device. %1 chapter(s) saved."), job.done))
            return
        end
        if not self:isConnected() then
            finish(T(_("Stopped: the network connection was lost. %1 chapter(s) saved."), job.done))
            return
        end
        job.i = job.i + 1
        local path, why = self:downloadChapter(ids[job.i])
        if path then
            job.done = job.done + 1
        elseif why == "not_downloaded" or why == "empty" then
            job.skipped = job.skipped + 1
        elseif type(why) == "table" and why.kind == "offline" then
            finish(T(_("Stopped: the Novarr server can't be reached any more. %1 chapter(s) saved."), job.done))
            return
        elseif type(why) == "table" and why.kind == "storage" then
            finish(T(_("Stopped: couldn't write to this device's storage. %1 chapter(s) saved."), job.done))
            return
        else
            job.failed = job.failed + 1
        end
        dialog:setText(T(_("%1 of %2 saved"), job.done, job.total)
            .. (job.skipped > 0 and ("\n" .. T(_("%1 skipped (no text on the server yet)"), job.skipped)) or ""))
        UIManager:scheduleIn(0.05, step)
    end)
    self:whenConnected(function()
        UIManager:scheduleIn(0.2, step)
    end, function()
        finish(_("No network connection: chapters can't be downloaded now."))
    end)
end

-- ---------------------------------------------------------------- server

--[[--
Switching to a different server: cached lists, local marks, the queue and
stored chapters belong to the old one (ids differ), so they are cleared.
]]
function Session:setServer(url)
    local old = self:serverUrl()
    if old and old ~= url then
        self.store:clearCache()
        self.store:clearOverlays()
        self.queue:clear()
        for _i, novel_id in ipairs(self.store:novelsWithDownloads()) do
            self:removeNovelDownloads(novel_id)
        end
        self.chapter_lists = {}
    end
    self:set("server_url", url)
    self:rebuildApi()
end

--- Whether switching to url would discard local data.
function Session:hasLocalData()
    return self.queue:count() > 0 or #self.store:novelsWithDownloads() > 0
end

-- ---------------------------------------------------------------- covers

local function fetchCover(url, path)
    if Api.simulate_offline then return nil, "offline" end
    local socketutil = require("socketutil")
    local socket = require("socket")
    local requester = url:sub(1, 8) == "https://" and require("ssl.https") or require("socket.http")
    local fh = io.open(path, "wb")
    if not fh then return nil, "cannot write" end
    socketutil:set_timeout(5, 15)
    local ok, code, headers = pcall(function()
        return socket.skip(1, requester.request{
            url = url,
            headers = { ["User-Agent"] = "NovarrKOReader/" .. Api.VERSION, ["Accept"] = "image/jpeg, image/png, image/webp, image/gif" },
            sink = socketutil.file_sink(fh),
        })
    end)
    socketutil:reset_timeout()
    pcall(function() fh:close() end)
    if not ok or code ~= 200 or type(headers) ~= "table" then return nil, tostring(code) end
    return headers["content-type"] or "image/jpeg"
end

--- The cover cache (created on first use).
function Session:covers()
    if not self.cover_cache then
        local Covers = require("novarr.covers")
        self.cover_cache = Covers.new({
            dir = self.root .. "/covers",
            lfs = lfs,
            hash = function(s) return require("ffi/sha2").md5(s) end,
            fetch = fetchCover,
            log = log,
        })
    end
    return self.cover_cache
end

function Session:showCovers()
    return self:get("show_covers") ~= false
end

-- ---------------------------------------------------------------- debug

--- Plugin state for the autopilot "dump" step.
function Session:dumpState()
    local menus = {}
    for _i, entry in ipairs(self.menus) do
        local m = entry.menu
        local items = {}
        local indexes = {}
        if m.pages then
            indexes = m.pages[m.page] or {}
        else
            local perpage = m.perpage or 14
            local first = ((m.page or 1) - 1) * perpage + 1
            for i = first, math.min(#(m.item_table or {}), first + perpage - 1) do table.insert(indexes, i) end
        end
        for _i, i in ipairs(indexes) do
            local it = m.item_table[i]
            local c = it.cover or it.hero
            table.insert(items, { text = it.text, mandatory = it.mandatory, dim = it.dim or nil,
                cover = c and { url = c.url, cached = self:covers():cached(c.url) ~= nil, info = c.info } or nil })
        end
        table.insert(menus, {
            kind = entry.screen and entry.screen.kind,
            title = m.title,
            page = m.page,
            page_num = m.page_num,
            item_count = #(m.item_table or {}),
            visible = items,
        })
    end
    local downloads = {}
    for _i, d in ipairs(self.store:downloads()) do
        local ids = {}
        for _i, entry in pairs(self.store:getIndex(d.novel_id).chapters) do table.insert(ids, entry.id) end
        table.sort(ids)
        d.chapter_ids = ids
        table.insert(downloads, d)
    end
    local overlays = {}
    for _i, novel_id in ipairs(self.store:novelsWithDownloads()) do
        overlays[tostring(novel_id)] = self.store:getOverlay(novel_id)
    end
    -- Also overlays of novels without downloads.
    local odir = self.root .. "/overlay"
    if lfs.attributes(odir, "mode") == "directory" then
        for f in lfs.dir(odir) do
            local id = f:match("^(%d+)%.json$")
            if id then overlays[id] = self.store:getOverlay(tonumber(id)) end
        end
    end
    local reading
    if self.reader and self.reader.reading then
        local r = self.reader.reading
        local okf, footer = pcall(function() return require("novarr.reading").footerText(self.reader) end)
        reading = { novel_id = r.novel_id, chapter_id = r.chapter_id, percent = r.percent, ended = r.ended,
            footer_text = okf and footer or nil, chapters_left = r.left, seconds_per_chapter = r.per_chapter,
            active_seconds = r.timer and r.timer.seconds or nil,
            timing_samples = Json.array(self.store:getTiming(r.novel_id)) }
    end
    return {
        time = nowMs(),
        server_url = self:serverUrl(),
        simulate_offline = Api.simulate_offline,
        settings = {
            prefetch = self:get("prefetch"),
            remove_after_reading = self:get("remove_after_reading"),
            mark_read_at_end = self:get("mark_read_at_end"),
        },
        queue = Json.array(self.queue:list()),
        downloads = Json.array(downloads),
        overlays = overlays,
        menus = Json.array(menus),
        reading = reading,
        current_document = self:currentPath(),
        mem_log = self.mem_log and Json.array(self.mem_log) or nil,
        current_title = (function()
            local ReaderUI = require("apps/reader/readerui")
            local inst = ReaderUI.instance
            return inst and inst.doc_props and inst.doc_props.display_title or nil
        end)(),
        storage_root = self.root,
    }
end

return Session
