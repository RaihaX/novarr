--[[--
Manga on the Kindle (1.13.0): a manga chapter is downloaded as a CBZ (a
zip of its page images; KOReader opens comic archives itself) and stored
like a text chapter, as chapters/<novelId>/<chapterId>_<slug>.cbz, so
opening, next/previous chapter, prefetching, read marks and progress sync
all go the usual way (session.lua, reading.lua).

Server: GET /api/v1/chapters/{id}/pages (the chapter's label, place and
read state) and GET /api/v1/chapters/{id}/cbz?max_width=&gray=1 (meta
manga_cbz = true). The pages are asked scaled to this screen's width and
grey: an e-ink screen shows neither more pixels nor colour, and the file
is several times smaller.

The download blocks KOReader while it runs (LuaSocket), like the EPUB
download: a few seconds per chapter on home Wi-Fi.
]]

local logger = require("logger")

local Api = require("novarr.api")

local Manga = {}

Manga.BLOCK_TIMEOUT = 20
Manga.TOTAL_TIMEOUT = 5 * 60
-- Chapters fetched ahead while reading a manga (each is a few MB, not a few KB).
Manga.DEFAULT_PREFETCH = 3

--[[--
The width pages are asked for: the screen's short side (portrait), at
least 600. nil when the screen is unknown (pages as stored).
]]
function Manga.pageWidth()
    local ok, Device = pcall(require, "device")
    if not ok or not Device or not Device.screen then return nil end
    local okw, w = pcall(Device.screen.getWidth, Device.screen)
    local okh, h = pcall(Device.screen.getHeight, Device.screen)
    if not okw or not okh or not w or not h then return nil end
    return math.max(600, math.min(w, h))
end

--- Whether pages should come grey (every e-ink screen without colour).
function Manga.gray()
    local ok, Device = pcall(require, "device")
    if not ok or not Device then return true end
    local okc, color = pcall(function() return Device:hasColorScreen() end)
    return not (okc and color)
end

local function header(h, name)
    return h and (h[name] or h[name:lower()]) or nil
end

--[[--
Downloads one manga chapter and stores it. Returns path, nil, chapter or
nil, why, chapter: why is "not_downloaded" (no pages on the server yet) or
an Api error table (kind "storage" when it can't be written here).
opts.no_reprobe: as Session:downloadChapter.
]]
function Manga.download(session, chapter_id, opts)
    opts = opts or {}
    local data, e = session.api:chapterPages(chapter_id, { no_reprobe = opts.no_reprobe })
    if not data then return nil, e end
    local c = data.chapter
    if type(c) ~= "table" or not tonumber(c.id) or not tonumber(c.novel_id) then
        return nil, Api.err("server", nil, "bad chapter")
    end
    local summary = session:novelSummary(tonumber(c.novel_id))
    local ch = {
        id = tonumber(c.id),
        novel_id = tonumber(c.novel_id),
        label = c.label,
        number = c.number,
        page_count = c.page_count,
        read = c.read,
        read_progress = c.progress,
        prev = type(data.prev) == "table" and data.prev or nil,
        next = type(data.next) == "table" and data.next or nil,
        novel_name = summary and summary.name or nil,
        downloaded = type(data.pages) == "table" and #data.pages > 0,
        manga = true,
    }
    if not ch.downloaded then return nil, "not_downloaded", ch end
    local free = session:freeBytes()
    if free and free < session.MIN_FREE_BYTES then
        return nil, Api.err("storage", nil, "less than 20 MB free"), ch
    end
    local base = session.api:configuredBase()
    if not base then return nil, Api.err("bad_url") end
    local url = base .. Api.cbzPath(ch.id, Manga.pageWidth(), Manga.gray())
    local dir = session.store:chaptersDir(ch.novel_id)
    session.store:mkdirs(dir)
    local tmp = string.format("%s/%d.cbz.part", dir, ch.id)
    local fh, ferr = io.open(tmp, "wb")
    if not fh then return nil, Api.err("storage", nil, tostring(ferr)), ch end
    local headers = { ["Accept"] = "application/vnd.comicbook+zip", ["User-Agent"] = "NovarrKOReader/" .. Api.VERSION }
    for k, v in pairs(session:authHeadersFor(url)) do headers[k] = v end
    for k, v in pairs(session.api:deviceHeaders()) do headers[k] = v end
    local socketutil = require("socketutil")
    local first = true
    -- (Session:fetchUrl honours the offline test hook itself.)
    local res, terr = session:fetchUrl(url, {
        headers = headers, block_timeout = Manga.BLOCK_TIMEOUT, total_timeout = Manga.TOTAL_TIMEOUT,
    }, function()
        -- A retry on the other server address starts the file again.
        if not first then
            pcall(function() fh:close() end)
            fh = io.open(tmp, "wb")
        end
        first = false
        return fh and socketutil.file_sink(fh) or nil
    end)
    if fh then pcall(function() fh:close() end) end
    local lfs = require("libs/libkoreader-lfs")
    local size = lfs.attributes(tmp, "size") or 0
    local status = res and tonumber(res.status) or nil
    local magic
    if status == 200 and size > 0 then
        local f = io.open(tmp, "rb")
        if f then magic = f:read(2) f:close() end
    end
    if status ~= 200 or size == 0 or magic ~= "PK" then
        os.remove(tmp)
        logger.warn("Novarr: CBZ download of chapter", ch.id, "failed:", status or terr, size)
        if not res then return nil, Api.err("offline", nil, tostring(terr)), ch end
        if status == 422 then return nil, "not_downloaded", ch end
        if status == 404 then return nil, Api.err("not_found", 404), ch end
        if status == 401 then
            local ue = Api.err("unauthenticated", 401)
            session:onUnauthenticated(ue)
            return nil, ue, ch
        end
        if status == 403 then return nil, Api.err("forbidden", 403), ch end
        return nil, Api.err((status or 0) >= 500 and "server" or "invalid", status), ch
    end
    local expected = tonumber(header(res.headers, "content-length"))
    if expected and expected ~= size then
        os.remove(tmp)
        return nil, Api.err("offline", nil, "download cut short"), ch
    end
    local path, serr = session.store:saveChapterFile(ch, tmp, size)
    if not path then
        logger.warn("Novarr: cannot save manga chapter", ch.id, serr)
        return nil, Api.err("storage", nil, tostring(serr)), ch
    end
    -- The server's read flag and position with the stored copy (as for text chapters).
    local entry = session.store:chapterEntry(ch.novel_id, ch.id)
    if entry then
        entry.read = ch.read
        entry.read_progress = ch.read_progress
        session.store:saveIndex(ch.novel_id)
    end
    return path, nil, ch
end

--- Chapters to fetch ahead for a manga: the setting, at most `limit` (text chapters' prefetch).
function Manga.prefetchCount(setting, limit)
    local n = tonumber(setting)
    if not n then n = Manga.DEFAULT_PREFETCH end
    limit = tonumber(limit) or n
    return math.max(0, math.min(n, limit))
end

return Manga
