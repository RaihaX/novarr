--[[--
Everything Novarr keeps on the device, under one root folder
(default <KOReader data dir>/novarr):

    cache/<key>.json        last server response body (home, novels, novel_<id>, chapters_<id>)
    cache/<key>.meta.json   {etag, fetched_at}
    chapters/<novelId>/<chapterId>_<slug>.html   stored chapters
    chapters/<novelId>/index.json                what is stored (rebuilt from file names if lost)
    chapters/<novelId>/group_<b>_<N>.html/.json  joined chapters, built from the stored ones (disposable)
    overlay/<novelId>.json  local read marks / progress not yet confirmed by the server
    queue.json              pending server writes
    highlights.json         server ids of highlights sent from this device (bounded)
    wifitime.json           reading time and the part with Wi-Fi on, per day (7 days)

Files are written to a temp file and renamed into place (a Kindle can lose
power at any moment) and every reader tolerates missing or corrupt files.
Dependencies (lfs, json, now, log) are injected so this is unit testable.

Overlay rule: a local mark (read flag and/or progress) wins over cached
server data. It is forgotten once a chapter list is fetched from the server
after the write carrying it was delivered (or rejected for good), because
from then on the server's answer already reflects it.
]]

local ChapterDoc = require("novarr.chapterdoc")
local Groups = require("novarr.groups")

local Store = {}
Store.__index = Store

function Store.new(opts)
    local self = setmetatable({
        root = assert(opts.root, "root required"):gsub("/+$", ""),
        lfs = assert(opts.lfs, "lfs required"),
        json = assert(opts.json, "json required"),
        now = opts.now or function() return os.time() * 1000 end,
        log = opts.log or function() end,
        _index = {},   -- novel_id -> index table (memory cache)
        _overlay = {}, -- novel_id -> overlay table (memory cache)
    }, Store)
    return self
end

-- ---------------------------------------------------------------- files

function Store:mkdirs(path)
    local lfs = self.lfs
    if lfs.attributes(path, "mode") == "directory" then return true end
    local acc = path:sub(1, 1) == "/" and "" or "."
    for part in path:gmatch("[^/]+") do
        acc = acc .. "/" .. part
        if lfs.attributes(acc, "mode") ~= "directory" then
            local ok, err = lfs.mkdir(acc)
            if not ok and lfs.attributes(acc, "mode") ~= "directory" then
                return nil, err
            end
        end
    end
    return true
end

local function dirname(path)
    return path:match("^(.*)/[^/]*$") or "."
end

function Store:exists(path)
    return self.lfs.attributes(path, "mode") ~= nil
end

function Store:readFile(path)
    local f = io.open(path, "rb")
    if not f then return nil end
    local data = f:read("*a")
    f:close()
    return data
end

--- Writes data atomically (temp file + rename). Returns true or nil, err.
function Store:writeFile(path, data)
    local ok, err = self:mkdirs(dirname(path))
    if not ok then return nil, err end
    local tmp = path .. ".tmp"
    local f
    f, err = io.open(tmp, "wb")
    if not f then return nil, err end
    local wrote
    wrote, err = f:write(data)
    if not wrote then
        f:close()
        os.remove(tmp)
        return nil, err
    end
    f:flush()
    f:close()
    local renamed
    renamed, err = os.rename(tmp, path)
    if not renamed then
        -- Some filesystems refuse to rename over an existing file.
        os.remove(path)
        renamed, err = os.rename(tmp, path)
        if not renamed then
            os.remove(tmp)
            return nil, err
        end
    end
    return true
end

function Store:readJson(path)
    local text = self:readFile(path)
    if not text then return nil end
    local value, err = self.json.decode(text)
    if value == nil then
        self.log("Novarr store: ignoring unreadable file " .. path .. ": " .. tostring(err))
    end
    return value
end

function Store:writeJson(path, value)
    local text, err = self.json.encode(value)
    if not text then
        self.log("Novarr store: cannot encode " .. path .. ": " .. tostring(err))
        return nil, err
    end
    local ok, werr = self:writeFile(path, text)
    if not ok then
        self.log("Novarr store: cannot write " .. path .. ": " .. tostring(werr))
    end
    return ok, werr
end

-- Removes a file or a directory tree. Returns bytes freed.
function Store:removeTree(path)
    local lfs = self.lfs
    local mode = lfs.attributes(path, "mode")
    if not mode then return 0 end
    if mode ~= "directory" then
        local size = lfs.attributes(path, "size") or 0
        os.remove(path)
        return size
    end
    local freed = 0
    for entry in lfs.dir(path) do
        if entry ~= "." and entry ~= ".." then
            freed = freed + self:removeTree(path .. "/" .. entry)
        end
    end
    lfs.rmdir(path)
    return freed
end

function Store:treeSize(path)
    local lfs = self.lfs
    local mode = lfs.attributes(path, "mode")
    if not mode then return 0 end
    if mode ~= "directory" then return lfs.attributes(path, "size") or 0 end
    local total = 0
    for entry in lfs.dir(path) do
        if entry ~= "." and entry ~= ".." then
            total = total + self:treeSize(path .. "/" .. entry)
        end
    end
    return total
end

function Store:totalSize()
    return self:treeSize(self.root)
end

-- ---------------------------------------------------------------- cache

local function cacheKeyOk(key)
    return type(key) == "string" and key:match("^[%w_]+$") ~= nil
end

function Store:cachePath(key)
    assert(cacheKeyOk(key), "bad cache key")
    return self.root .. "/cache/" .. key .. ".json"
end

--[[--
Returns the cached response for key: { data = decoded, etag, fetched_at }
or nil. A corrupt body is treated as missing.
]]
function Store:getCache(key)
    local path = self:cachePath(key)
    local data = self:readJson(path)
    if data == nil then return nil end
    local meta = self:readJson(self.root .. "/cache/" .. key .. ".meta.json") or {}
    return { data = data, etag = meta.etag, fetched_at = meta.fetched_at }
end

--- Only the {etag, fetched_at} of a cached entry (cheap: no body decode).
function Store:cacheMeta(key)
    return self:readJson(self.root .. "/cache/" .. key .. ".meta.json")
end

--- Stores a raw response body (already validated JSON text) with its ETag.
function Store:putCache(key, body, etag, fetched_at)
    local ok = self:writeFile(self:cachePath(key), body)
    if ok then
        self:writeJson(self.root .. "/cache/" .. key .. ".meta.json",
            { etag = etag, fetched_at = fetched_at or self.now() })
    end
    return ok
end

--- Refreshes the fetch time of a cached entry (after a 304).
function Store:touchCache(key, fetched_at)
    local metapath = self.root .. "/cache/" .. key .. ".meta.json"
    local meta = self:readJson(metapath) or {}
    meta.fetched_at = fetched_at or self.now()
    self:writeJson(metapath, meta)
end

function Store:clearCache()
    self:removeTree(self.root .. "/cache")
end

-- ---------------------------------------------------------------- chapters

function Store:chaptersDir(novel_id)
    return string.format("%s/chapters/%d", self.root, novel_id)
end

function Store:indexPath(novel_id)
    return self:chaptersDir(novel_id) .. "/index.json"
end

-- Rebuilds an index from the file names in a novel's folder.
function Store:scanIndex(novel_id)
    local dir = self:chaptersDir(novel_id)
    local index = { novel_id = novel_id, chapters = {} }
    if self.lfs.attributes(dir, "mode") ~= "directory" then return index end
    for entry in self.lfs.dir(dir) do
        local cid = ChapterDoc.idFromFileName(entry)
        if cid then
            index.chapters[tostring(cid)] = {
                id = cid,
                file = entry,
                saved_at = (self.lfs.attributes(dir .. "/" .. entry, "modification") or 0) * 1000,
            }
        end
    end
    return index
end

--[[--
Index of stored chapters for a novel:
{ novel_id, novel_name, chapters = { ["<id>"] = {id, file, label, chapter, book,
prev, next, saved_at, size} } }. Entries whose file vanished are dropped and
files missing from the index are added back (labels then unknown).
]]
function Store:getIndex(novel_id)
    local cached = self._index[novel_id]
    if cached then return cached end
    local index = self:readJson(self:indexPath(novel_id))
    if type(index) ~= "table" or type(index.chapters) ~= "table" then
        index = self:scanIndex(novel_id)
    else
        -- Reconcile with the files actually present.
        local scanned = self:scanIndex(novel_id)
        for key, entry in pairs(index.chapters) do
            local found = scanned.chapters[key]
            if not found then
                index.chapters[key] = nil
            elseif type(entry) ~= "table" or entry.file ~= found.file then
                index.chapters[key] = found
            end
        end
        for key, entry in pairs(scanned.chapters) do
            if not index.chapters[key] then index.chapters[key] = entry end
        end
    end
    index.novel_id = novel_id
    self._index[novel_id] = index
    return index
end

function Store:saveIndex(novel_id)
    local index = self._index[novel_id]
    if not index then return end
    if next(index.chapters) == nil then
        os.remove(self:indexPath(novel_id))
        return
    end
    self:writeJson(self:indexPath(novel_id), index)
end

--- Returns the full path of a stored chapter, or nil.
function Store:chapterPath(novel_id, chapter_id)
    local index = self:getIndex(novel_id)
    local entry = index.chapters[tostring(chapter_id)]
    if not entry then return nil end
    return self:chaptersDir(novel_id) .. "/" .. entry.file
end

function Store:hasChapter(novel_id, chapter_id)
    return self:chapterPath(novel_id, chapter_id) ~= nil
end

--- Set of stored chapter ids for a novel ({[id]=true}) and its count.
function Store:storedSet(novel_id)
    local set, n = {}, 0
    for _i, entry in pairs(self:getIndex(novel_id).chapters) do
        set[entry.id] = true
        n = n + 1
    end
    return set, n
end

--[[--
Stores a chapter. `chapter` is the API chapter object, `html` the built
document. Returns the file path or nil, err.
]]
function Store:saveChapter(chapter, html)
    local novel_id, chapter_id = chapter.novel_id, chapter.id
    local index = self:getIndex(novel_id)
    local key = tostring(chapter_id)
    local file = ChapterDoc.fileName(chapter_id, chapter.label)
    local path = self:chaptersDir(novel_id) .. "/" .. file
    local ok, err = self:writeFile(path, html)
    if not ok then return nil, err end
    local old = index.chapters[key]
    if old and old.file ~= file then
        os.remove(self:chaptersDir(novel_id) .. "/" .. old.file)
    end
    index.novel_name = chapter.novel_name or index.novel_name
    index.chapters[key] = {
        id = chapter_id,
        file = file,
        label = chapter.label,
        chapter = chapter.chapter,
        book = chapter.book,
        prev = chapter.prev and chapter.prev.id or nil,
        next = chapter.next and chapter.next.id or nil,
        next_downloaded = chapter.next and chapter.next.downloaded,
        saved_at = self.now(),
        size = #html,
        words = Store.countWords(chapter.paragraphs),
    }
    self:saveIndex(novel_id)
    return path
end

--[[--
Stores a manga chapter (1.13.0): the CBZ downloaded to `tmp` is moved into
the novel's folder as <chapterId>_<slug>.cbz. `chapter` is the chapter
object of GET chapters/{id}/pages (id, novel_id, label, number,
page_count) plus prev/next ({id} tables) and novel_name. Returns the path
or nil, err (tmp is removed on failure).
]]
function Store:saveChapterFile(chapter, tmp, size)
    local novel_id, chapter_id = chapter.novel_id, chapter.id
    local index = self:getIndex(novel_id)
    local key = tostring(chapter_id)
    local file = ChapterDoc.fileName(chapter_id, chapter.label, "cbz")
    local dir = self:chaptersDir(novel_id)
    local ok, merr = self:mkdirs(dir)
    if not ok then
        os.remove(tmp)
        return nil, merr
    end
    local path = dir .. "/" .. file
    local renamed, rerr = os.rename(tmp, path)
    if not renamed then
        os.remove(path)
        renamed, rerr = os.rename(tmp, path)
    end
    if not renamed then
        os.remove(tmp)
        return nil, rerr
    end
    local old = index.chapters[key]
    if old and old.file ~= file then
        os.remove(dir .. "/" .. old.file)
    end
    index.novel_name = chapter.novel_name or index.novel_name
    index.chapters[key] = {
        id = chapter_id,
        file = file,
        label = chapter.label,
        chapter = chapter.number,
        prev = chapter.prev and chapter.prev.id or nil,
        next = chapter.next and chapter.next.id or nil,
        next_downloaded = chapter.next and chapter.next.downloaded,
        saved_at = self.now(),
        size = size,
        pages = tonumber(chapter.page_count),
        manga = true,
        -- 1.14.0: "manga" | "comic", and the series' page direction (Model.readingDirection).
        medium = chapter.medium,
        reading_mode = chapter.reading_mode,
    }
    self:saveIndex(novel_id)
    return path
end

--- Number of words in a list of paragraphs (nil when there are none).
function Store.countWords(paragraphs)
    if type(paragraphs) ~= "table" then return nil end
    local n = 0
    for _i, p in ipairs(paragraphs) do
        if type(p) == "string" then
            for _w in p:gmatch("%S+") do n = n + 1 end
        end
    end
    return n > 0 and n or nil
end

function Store:chapterEntry(novel_id, chapter_id)
    return self:getIndex(novel_id).chapters[tostring(chapter_id)]
end

--- Removes one stored chapter. Returns its path (for sidecar cleanup) or nil.
function Store:removeChapter(novel_id, chapter_id)
    local index = self:getIndex(novel_id)
    local key = tostring(chapter_id)
    local entry = index.chapters[key]
    if not entry then return nil end
    local path = self:chaptersDir(novel_id) .. "/" .. entry.file
    os.remove(path)
    index.chapters[key] = nil
    self:saveIndex(novel_id)
    return path
end

--- Lists the paths of every stored chapter of a novel.
function Store:chapterPaths(novel_id)
    local out = {}
    local dir = self:chaptersDir(novel_id)
    for _i, entry in pairs(self:getIndex(novel_id).chapters) do
        table.insert(out, dir .. "/" .. entry.file)
    end
    return out
end

--- Removes all stored chapters of a novel. Returns the removed chapter paths.
function Store:removeNovel(novel_id)
    local paths = self:chapterPaths(novel_id)
    self:removeTree(self:chaptersDir(novel_id))
    self._index[novel_id] = nil
    return paths
end

-- ---------------------------------------------------------------- group files
-- Joined chapters (see groups.lua): group_<block>_<N>.html plus a manifest
-- group_<block>_<N>.json ({version, block, n, ids, parts}) next to it.

function Store:groupPath(novel_id, block, n)
    return self:chaptersDir(novel_id) .. "/" .. Groups.fileName(block, n)
end

function Store:groupManifestPath(novel_id, block, n)
    return self:chaptersDir(novel_id) .. "/" .. Groups.manifestName(block, n)
end

--- The manifest of a group file, or nil (missing or unreadable).
function Store:readGroupManifest(novel_id, block, n)
    local m = self:readJson(self:groupManifestPath(novel_id, block, n))
    if type(m) ~= "table" then return nil end
    return m
end

--[[--
Writes a group file and its manifest. The manifest is removed first and
written last, so an interrupted write is never taken for a complete file.
Returns the path or nil, err.
]]
function Store:writeGroup(novel_id, block, n, html, manifest)
    local mpath = self:groupManifestPath(novel_id, block, n)
    os.remove(mpath)
    local path = self:groupPath(novel_id, block, n)
    local ok, err = self:writeFile(path, html)
    if not ok then return nil, err end
    ok, err = self:writeJson(mpath, manifest)
    if not ok then return nil, err end
    return path
end

--- Group files of a novel: list of {block, n, path, size}.
function Store:groupFiles(novel_id)
    local out = {}
    local dir = self:chaptersDir(novel_id)
    if self.lfs.attributes(dir, "mode") ~= "directory" then return out end
    for entry in self.lfs.dir(dir) do
        local b, n = Groups.parseFileName(entry)
        if b then
            local path = dir .. "/" .. entry
            table.insert(out, { block = b, n = n, path = path, size = self.lfs.attributes(path, "size") or 0 })
        end
    end
    table.sort(out, function(a, b) return a.block < b.block or (a.block == b.block and a.n < b.n) end)
    return out
end

--- Removes a group file and its manifest. Returns bytes freed.
function Store:removeGroup(novel_id, block, n)
    local freed = self:removeTree(self:groupPath(novel_id, block, n))
    return freed + self:removeTree(self:groupManifestPath(novel_id, block, n))
end

--- Novel ids that have a chapters folder.
function Store:novelsWithDownloads()
    local out = {}
    local dir = self.root .. "/chapters"
    if self.lfs.attributes(dir, "mode") ~= "directory" then return out end
    for entry in self.lfs.dir(dir) do
        local id = entry:match("^(%d+)$")
        if id then table.insert(out, tonumber(id)) end
    end
    table.sort(out)
    return out
end

--[[--
Summary of what is stored: list of {novel_id, name, count, bytes}, novels
with no chapters left are skipped (and their empty folder removed).
]]
function Store:downloads()
    local out = {}
    for _i, novel_id in ipairs(self:novelsWithDownloads()) do
        local index = self:getIndex(novel_id)
        local count, bytes = 0, 0
        local dir = self:chaptersDir(novel_id)
        for _i, entry in pairs(index.chapters) do
            count = count + 1
            bytes = bytes + (self.lfs.attributes(dir .. "/" .. entry.file, "size") or entry.size or 0)
        end
        -- Joined-chapter files take space too (they are counted, not listed).
        for _i, g in ipairs(self:groupFiles(novel_id)) do bytes = bytes + g.size end
        if count > 0 then
            table.insert(out, { novel_id = novel_id, name = index.novel_name, count = count, bytes = bytes })
        else
            self:removeTree(dir)
            self._index[novel_id] = nil
        end
    end
    return out
end

-- ---------------------------------------------------------------- overlay

function Store:overlayPath(novel_id)
    return string.format("%s/overlay/%d.json", self.root, novel_id)
end

--- Overlay table for a novel: { ["<chapterId>"] = {read, progress, at, sent_at} }.
function Store:getOverlay(novel_id)
    local cached = self._overlay[novel_id]
    if cached then return cached end
    local overlay = self:readJson(self:overlayPath(novel_id))
    if type(overlay) ~= "table" then overlay = {} end
    self._overlay[novel_id] = overlay
    return overlay
end

function Store:saveOverlay(novel_id)
    local overlay = self._overlay[novel_id]
    if not overlay then return end
    if next(overlay) == nil then
        os.remove(self:overlayPath(novel_id))
    else
        self:writeJson(self:overlayPath(novel_id), overlay)
    end
end

--[[--
Records a local change for a chapter. fields: read (bool, optional),
progress (0..100, optional), reading (true: real reading, recorded as
reading_at, the resume rule's activity time) or hand (true: a hand mark,
which changes the state only and keeps an earlier reading_at). A new change
resets sent_at (it has not been delivered yet). `save` defaults to true.
]]
function Store:setOverlay(novel_id, chapter_id, fields, save)
    local overlay = self:getOverlay(novel_id)
    local key = tostring(chapter_id)
    local entry = overlay[key] or {}
    if fields.read ~= nil then
        entry.read = fields.read
        if fields.read == false then entry.progress = nil end
    end
    if fields.progress ~= nil then entry.progress = fields.progress end
    if fields.clear_progress then entry.progress = nil end
    entry.at = self.now()
    if fields.reading then entry.reading_at = entry.at end
    if fields.hand then entry.hand = true end
    entry.sent_at = nil
    overlay[key] = entry
    if save ~= false then self:saveOverlay(novel_id) end
    return entry
end

--[[--
Notes that the write recorded at `recorded_at` for a chapter reached the
server (or was rejected for good). A local change made after that write
was recorded stays pending.
]]
function Store:markOverlaySent(novel_id, chapter_id, recorded_at, sent_at)
    local overlay = self:getOverlay(novel_id)
    local entry = overlay[tostring(chapter_id)]
    if entry and not entry.sent_at and (entry.at or 0) <= (recorded_at or math.huge) then
        entry.sent_at = sent_at or self.now()
        self:saveOverlay(novel_id)
    end
end

--- Marks every overlay entry of a novel recorded up to `at` as sent (after a bulk write).
function Store:markNovelOverlaySent(novel_id, at, sent_at)
    local overlay = self:getOverlay(novel_id)
    local changed = false
    for _i, entry in pairs(overlay) do
        if (entry.at or 0) <= at and not entry.sent_at then
            entry.sent_at = sent_at or self.now()
            changed = true
        end
    end
    if changed then self:saveOverlay(novel_id) end
end

--[[--
Called after a chapter list for the novel arrived from the server; the
request started at `fetched_from` (ms). Forgets entries delivered before
that moment. Returns how many were cleared.
]]
function Store:settleOverlay(novel_id, fetched_from)
    local overlay = self:getOverlay(novel_id)
    local cleared = 0
    for key, entry in pairs(overlay) do
        if entry.sent_at and entry.sent_at <= fetched_from then
            overlay[key] = nil
            cleared = cleared + 1
        end
    end
    if cleared > 0 then self:saveOverlay(novel_id) end
    return cleared
end

--[[--
Effective state of a chapter row: server row (from the chapter list, may be
nil) plus overlay. Returns read (bool), progress (int or nil).
]]
function Store.effective(row, entry)
    local read = row and row.read or false
    local progress = row and row.read_progress or nil
    if entry then
        if entry.read ~= nil then read = entry.read end
        if entry.progress ~= nil then progress = entry.progress end
        if entry.read == false and entry.progress == nil then progress = nil end
    end
    return read, progress
end

function Store:clearOverlays()
    self._overlay = {}
    self:removeTree(self.root .. "/overlay")
end

-- ---------------------------------------------------------------- timing

--- Measured active seconds of finished chapters, per novel: list (oldest first).
function Store:getTiming(novel_id)
    if not self._timing then
        local t = self:readJson(self.root .. "/timing.json")
        self._timing = type(t) == "table" and t or {}
    end
    local list = self._timing[tostring(novel_id)]
    if type(list) ~= "table" then
        list = {}
        self._timing[tostring(novel_id)] = list
    end
    return list
end

function Store:saveTiming()
    if self._timing then self:writeJson(self.root .. "/timing.json", self._timing) end
end

-- ---------------------------------------------------------------- reading stats

--- Finished-chapter events of a novel (see stats.lua): list {t, s}, oldest first.
function Store:getStats(novel_id)
    if not self._stats then
        local t = self:readJson(self.root .. "/stats.json")
        self._stats = type(t) == "table" and t or {}
    end
    local list = self._stats[tostring(novel_id)]
    if type(list) ~= "table" then
        list = {}
        self._stats[tostring(novel_id)] = list
    end
    return list
end

function Store:saveStats()
    if not self._stats then return end
    -- Novels with no events left are not written.
    local out = {}
    for k, list in pairs(self._stats) do
        if type(list) == "table" and #list > 0 then out[k] = self.json.array(list) end
    end
    self:writeJson(self.root .. "/stats.json", out)
end

function Store:clearStats()
    self._stats = {}
    os.remove(self.root .. "/stats.json")
end

-- ---------------------------------------------------------------- EPUBs

--- Downloaded EPUBs: { ["<novelId>"] = {path, etag, size, name, saved_at} }.
function Store:getEpubs()
    if not self._epubs then
        local t = self:readJson(self.root .. "/epubs.json")
        self._epubs = type(t) == "table" and t or {}
    end
    -- Forget entries whose file was deleted outside the plugin.
    for key, e in pairs(self._epubs) do
        if type(e) ~= "table" or not e.path or self.lfs.attributes(e.path, "mode") ~= "file" then
            self._epubs[key] = nil
        end
    end
    return self._epubs
end

function Store:saveEpubs()
    if self._epubs then self:writeJson(self.root .. "/epubs.json", self._epubs) end
end

-- ---------------------------------------------------------------- small JSON tables

-- A JSON object kept in memory once read: highlights.json (highlight ids,
-- see highlights.lua), wifitime.json (reading and Wi-Fi time, wifitime.lua).
local function smallTable(name)
    return function(self)
        self._small = self._small or {}
        if not self._small[name] then
            local t = self:readJson(self.root .. "/" .. name .. ".json")
            self._small[name] = type(t) == "table" and t or {}
        end
        return self._small[name]
    end, function(self)
        if self._small and self._small[name] then
            self:writeJson(self.root .. "/" .. name .. ".json", self._small[name])
        end
    end, function(self)
        if self._small then self._small[name] = nil end
        os.remove(self.root .. "/" .. name .. ".json")
    end
end

--- chapter_id:hash -> {id, at}: server ids of highlights sent from this device.
Store.getHighlightMap, Store.saveHighlightMap, Store.clearHighlightMap = smallTable("highlights")
--- {days = {["YYYY-MM-DD"] = {read, wifi}}}: reading time with Wi-Fi on or off.
Store.getWifiTime, Store.saveWifiTime, Store.clearWifiTime = smallTable("wifitime")

-- ---------------------------------------------------------------- queue file

function Store:queuePath()
    return self.root .. "/queue.json"
end

function Store:loadQueue()
    return self:readJson(self:queuePath())
end

function Store:saveQueue(items)
    return self:writeJson(self:queuePath(), self.json.array(items))
end

--- Forgets in-memory copies (after external changes, e.g. in tests).
function Store:forget()
    self._index = {}
    self._overlay = {}
    self._timing = nil
    self._stats = nil
    self._epubs = nil
    self._small = nil
end

return Store
