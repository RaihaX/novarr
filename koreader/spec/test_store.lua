local T = ...
local Store = require("novarr.store")
local Json = require("novarr.json")
local Doc = require("novarr.chapterdoc")
local lfs = require("libkoreader-lfs")

local function newStore(root, clock)
    clock = clock or { t = 1000 }
    return Store.new({ root = root, lfs = lfs, json = Json, now = function() return clock.t end }), clock
end

local function chapter(novel_id, id, label)
    return { id = id, novel_id = novel_id, novel_name = "Novel " .. novel_id, label = label or ("Chapter " .. id),
        chapter = id, book = 0, paragraphs = { "p" }, prev = { id = id - 1 }, next = { id = id + 1, downloaded = true } }
end

T.it("atomic write leaves no temp file and overwrites", function()
    local root = T.tmpdir()
    local s = newStore(root)
    T.truthy(s:writeFile(root .. "/a/b/c.txt", "one"))
    T.truthy(s:writeFile(root .. "/a/b/c.txt", "two"))
    T.eq(s:readFile(root .. "/a/b/c.txt"), "two")
    T.eq(lfs.attributes(root .. "/a/b/c.txt.tmp", "mode"), nil)
end)

T.it("cache round trip with ETag, corrupt cache treated as missing", function()
    local root = T.tmpdir()
    local s = newStore(root)
    T.eq(s:getCache("novels"), nil)
    s:putCache("novels", '{"novels":[{"id":1}]}', '"e1"', 5)
    local c = s:getCache("novels")
    T.eq(c.data.novels[1].id, 1)
    T.eq(c.etag, '"e1"')
    T.eq(c.fetched_at, 5)
    s:touchCache("novels", 9)
    T.eq(s:getCache("novels").fetched_at, 9)
    s:writeFile(s:cachePath("home"), "{truncated")
    T.eq(s:getCache("home"), nil)
end)

T.it("stores chapters and maps paths back to ids", function()
    local root = T.tmpdir()
    local s = newStore(root)
    local path = s:saveChapter(chapter(7, 70, "Chapter 1: Start?"), "<html>x</html>")
    T.eq(path, root .. "/chapters/7/70_Chapter 1 Start.html")
    T.eq(s:chapterPath(7, 70), path)
    T.truthy(s:hasChapter(7, 70))
    local n, c = Doc.idsFromPath(path, root)
    T.eq({ n, c }, { 7, 70 })
    local e = s:chapterEntry(7, 70)
    T.eq(e.next, 71)
    T.eq(e.prev, 69)
    T.eq(e.label, "Chapter 1: Start?")
end)

T.it("a renamed label replaces the old file", function()
    local root = T.tmpdir()
    local s = newStore(root)
    local p1 = s:saveChapter(chapter(1, 10, "Old"), "a")
    local p2 = s:saveChapter(chapter(1, 10, "New"), "b")
    T.eq(lfs.attributes(p1, "mode"), nil)
    T.eq(s:readFile(p2), "b")
end)

T.it("a lost or corrupt index is rebuilt from file names", function()
    local root = T.tmpdir()
    local s = newStore(root)
    s:saveChapter(chapter(3, 30), "a")
    s:saveChapter(chapter(3, 31), "b")
    os.remove(s:indexPath(3))
    local s2 = newStore(root)
    local set, count = s2:storedSet(3)
    T.eq(count, 2)
    T.truthy(set[30] and set[31])
    s2:writeFile(s2:indexPath(3), "not json at all")
    local s3 = newStore(root)
    T.eq(select(2, s3:storedSet(3)), 2)
end)

T.it("the index drops entries whose file vanished and adopts stray files", function()
    local root = T.tmpdir()
    local s = newStore(root)
    local p = s:saveChapter(chapter(4, 40), "a")
    s:saveChapter(chapter(4, 41), "b")
    os.remove(p)
    s:writeFile(root .. "/chapters/4/42_Extra.html", "c")
    local s2 = newStore(root)
    local set, count = s2:storedSet(4)
    T.eq(count, 2)
    T.truthy(set[41] and set[42])
    T.falsy(set[40])
end)

T.it("downloads summary and removal", function()
    local root = T.tmpdir()
    local s = newStore(root)
    s:saveChapter(chapter(5, 50), "12345")
    s:saveChapter(chapter(5, 51), "123")
    s:saveChapter(chapter(6, 60), "1")
    local d = s:downloads()
    T.eq(#d, 2)
    T.eq(d[1].novel_id, 5)
    T.eq(d[1].count, 2)
    T.eq(d[1].bytes, 8)
    T.eq(d[1].name, "Novel 5")
    T.truthy(s:removeChapter(5, 51))
    T.eq(select(2, s:storedSet(5)), 1)
    local removed = s:removeNovel(5)
    T.eq(#removed, 1)
    T.eq(lfs.attributes(root .. "/chapters/5", "mode"), nil)
    T.eq(#s:downloads(), 1)
end)

T.it("overlay: local marks win until a later server snapshot", function()
    local root = T.tmpdir()
    local s, clock = newStore(root)
    local row = { id = 1, read = false, read_progress = nil }
    clock.t = 100
    local entry = s:setOverlay(9, 1, { progress = 40 })
    T.eq({ Store.effective(row, entry) }, { false, 40 })
    clock.t = 110
    s:setOverlay(9, 1, { read = true, progress = 100 })
    entry = s:getOverlay(9)["1"]
    T.eq({ Store.effective(row, entry) }, { true, 100 })
    -- a snapshot fetched before delivery does not clear it
    T.eq(s:settleOverlay(9, 200), 0)
    -- delivered at 300 (the queued write was recorded at 110)
    s:markOverlaySent(9, 1, 110, 300)
    T.eq(s:settleOverlay(9, 250), 0) -- snapshot started before delivery
    T.eq(s:settleOverlay(9, 301), 1)
    T.eq(s:getOverlay(9)["1"], nil)
    -- persisted (file removed when empty)
    T.eq(lfs.attributes(s:overlayPath(9), "mode"), nil)
end)

T.it("overlay: a change made after the write was recorded stays pending", function()
    local root = T.tmpdir()
    local s, clock = newStore(root)
    clock.t = 100
    s:setOverlay(2, 5, { progress = 10 })  -- queued write recorded at 100
    clock.t = 150
    s:setOverlay(2, 5, { progress = 60 })  -- newer local change
    s:markOverlaySent(2, 5, 100, 200)      -- the old write is delivered
    T.eq(s:settleOverlay(2, 500), 0)
    T.eq(s:getOverlay(2)["5"].progress, 60)
end)

T.it("overlay: unread clears progress; bulk delivery marks the novel", function()
    local root = T.tmpdir()
    local s, clock = newStore(root)
    clock.t = 10
    s:setOverlay(3, 1, { progress = 50 })
    s:setOverlay(3, 1, { read = false })
    T.eq({ Store.effective({ read = true, read_progress = 80 }, s:getOverlay(3)["1"]) }, { false, nil })
    s:setOverlay(3, 2, { read = true })
    s:markNovelOverlaySent(3, 10, 20)
    T.eq(s:settleOverlay(3, 21), 2)
end)

T.it("overlay: reading sets reading_at, a hand mark keeps it (the resume rule's activity)", function()
    local Model = require("novarr.model")
    local s, clock = newStore(T.tmpdir())
    clock.t = 100
    local e = s:setOverlay(4, 10, { progress = 40, reading = true })
    T.eq(e.reading_at, 100); T.eq(e.hand, nil)
    clock.t = 200
    e = s:setOverlay(4, 10, { read = true, progress = 100, hand = true })
    T.eq(e.reading_at, 100); T.eq(e.hand, true); T.eq(e.at, 200)
    clock.t = 300
    e = s:setOverlay(4, 11, { read = true, progress = 100, hand = true })
    T.eq(e.reading_at, nil)
    -- Ch 11 (hand mark only) is no anchor; ch 10 (reading at 100) is: the next unread after it.
    local list = {}
    for i = 1, 14 do list[i] = { id = i, downloaded = true, read = i < 10, read_progress = i < 10 and 100 or nil } end
    local overlay = s:getOverlay(4)
    local state = function(c) return Store.effective(c, overlay[tostring(c.id)]) end
    T.eq(Model.resolveResume(list, 10, overlay, state), 12)
    clock.t = 400
    e = s:setOverlay(4, 11, { progress = 50, reading = true })
    T.eq(e.reading_at, 400)
    T.eq(Model.resolveResume(list, 10, overlay, state), 11)
end)

T.it("overlay survives a reload and tolerates corruption", function()
    local root = T.tmpdir()
    local s = newStore(root)
    s:setOverlay(1, 2, { read = true })
    local s2 = newStore(root)
    T.eq(s2:getOverlay(1)["2"].read, true)
    s2:writeFile(s2:overlayPath(1), "[[[")
    local s3 = newStore(root)
    T.eq(next(s3:getOverlay(1)), nil)
end)

T.it("queue file round trip (empty queue encodes as [])", function()
    local root = T.tmpdir()
    local s = newStore(root)
    s:saveQueue({})
    T.eq(s:readFile(s:queuePath()), "[]")
    s:saveQueue({ { kind = "set_read", chapter_id = 1, read = true, at = 1, seq = 1 } })
    T.eq(s:loadQueue()[1].chapter_id, 1)
end)

T.it("total size counts every file", function()
    local root = T.tmpdir()
    local s = newStore(root)
    s:writeFile(root .. "/x/a", "1234")
    s:writeFile(root .. "/b", "12")
    T.eq(s:totalSize(), 6)
end)

T.it("store: highlight map and Wi-Fi time are small JSON files, kept over a reload, cleared", function()
    local root = T.tmpdir()
    local store = Store.new({ root = root, lfs = lfs, json = Json })
    store:mkdirs(root)
    store:getHighlightMap()["5:x"] = { id = 3, at = 1 }
    store:saveHighlightMap()
    store:getWifiTime().days = { ["2026-11-16"] = { read = 60, wifi = 20 } }
    store:saveWifiTime()
    store:forget()
    T.eq(store:getHighlightMap()["5:x"].id, 3)
    T.eq(store:getWifiTime().days["2026-11-16"].wifi, 20)
    store:clearHighlightMap()
    store:forget()
    T.eq(next(store:getHighlightMap()), nil)
    T.eq(store:getWifiTime().days["2026-11-16"].read, 60)
end)

T.it("manga (1.13.0): a downloaded CBZ is moved in, indexed, found again by a rescan", function()
    local root = T.tmpdir()
    local s = newStore(root)
    local tmp = root .. "/dl.cbz.part"
    s:writeFile(tmp, "PK fake zip")
    local path = s:saveChapterFile({ id = 30, novel_id = 9, novel_name = "A Manga", label = "Chapter 1", number = 1,
        page_count = 18, prev = nil, next = { id = 31, downloaded = true } }, tmp, 11)
    T.eq(path, root .. "/chapters/9/30_Chapter 1.cbz")
    T.eq(s:readFile(path), "PK fake zip")
    T.eq(lfs.attributes(tmp, "mode"), nil, "the temp file is gone")
    local e = s:chapterEntry(9, 30)
    T.eq({ e.file, e.size, e.pages, e.next, e.manga }, { "30_Chapter 1.cbz", 11, 18, 31, true })
    T.eq(s:chapterPath(9, 30), path)
    -- Index lost: the file is found again by name.
    os.remove(s:indexPath(9))
    local fresh = newStore(root)
    T.eq(fresh:chapterPath(9, 30), path)
    -- A missing temp file: nothing indexed.
    local none = s:saveChapterFile({ id = 32, novel_id = 9, label = "x" }, root .. "/missing.part", 1)
    T.eq(none, nil)
    T.eq(s:chapterEntry(9, 32), nil)
end)
