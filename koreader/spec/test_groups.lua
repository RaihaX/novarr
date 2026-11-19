local T = ...
local Groups = require("novarr.groups")
local ChapterDoc = require("novarr.chapterdoc")
local Store = require("novarr.store")
local Json = require("novarr.json")
local lfs = require("libkoreader-lfs")

-- A chapter list: ids 101..(100+count), all downloaded unless listed in `missing`.
local function list(count, missing)
    local out = {}
    missing = missing or {}
    for i = 1, count do
        table.insert(out, { id = 100 + i, downloaded = not missing[100 + i] })
    end
    return out
end

local function avail(set)
    return function(id) return set[id] == true end
end

local function all(ids)
    local set = {}
    for _i, id in ipairs(ids) do set[id] = true end
    return set
end

-- ---------------------------------------------------------------- blocks

T.it("block arithmetic", function()
    T.eq(Groups.block(1, 20), 1)
    T.eq(Groups.block(20, 20), 1)
    T.eq(Groups.block(21, 20), 2)
    T.eq(Groups.block(969, 20), 49)
    T.eq({ Groups.blockRange(49, 20) }, { 961, 980 })
    T.eq({ Groups.blockRange(1, 20) }, { 1, 20 })
end)

T.it("group size from the setting is clamped", function()
    T.eq(Groups.size(nil), 20)
    T.eq(Groups.size("x"), 20)
    T.eq(Groups.size(1), 2)
    T.eq(Groups.size(5000), 200)
    T.eq(Groups.size(30.7), 30)
end)

T.it("ordinals count only chapters the server has downloaded (as 'ch N of M')", function()
    local l = list(5, { [102] = true })
    local ids, ord = Groups.ordinals(l)
    T.eq(ids, { 101, 103, 104, 105 })
    T.eq(ord[103], 2)
    T.eq(ord[102], nil)
    local Model = require("novarr.model")
    T.eq(ord[105], (Model.ordinal(l, 105)))
end)

T.it("run: whole block when everything is stored", function()
    local ids = Groups.ordinals(list(45))
    local run, first = Groups.run(ids, 25, 20, avail(all(ids)))
    T.eq(first, 21)
    T.eq(#run, 20)
    T.eq(run[1], 121)
    T.eq(run[20], 140)
end)

T.it("run: a novel with fewer chapters than the group size", function()
    local ids = Groups.ordinals(list(7))
    local run, first = Groups.run(ids, 3, 20, avail(all(ids)))
    T.eq(run, { 101, 102, 103, 104, 105, 106, 107 })
    T.eq(first, 1)
end)

T.it("run: a hole in the block makes two runs; the target picks its own", function()
    local ids = Groups.ordinals(list(20))
    local set = all(ids)
    set[108] = nil -- not stored (or no text)
    T.eq((Groups.run(ids, 3, 20, avail(set))), { 101, 102, 103, 104, 105, 106, 107 })
    local run, first = Groups.run(ids, 12, 20, avail(set))
    T.eq(first, 9)
    T.eq(run[1], 109)
    T.eq(run[#run], 120)
    T.eq(#run, 12)
end)

T.it("run: nil when the target itself is not available or unknown", function()
    local ids = Groups.ordinals(list(20))
    local set = all(ids)
    set[105] = nil
    T.eq(Groups.run(ids, 5, 20, avail(set)), nil)
    T.eq(Groups.run(ids, nil, 20, avail(set)), nil)
    T.eq(Groups.run(ids, 99, 20, avail(set)), nil)
end)

T.it("ordinals mark gaps: a chapter the server does not have yet sits before ordinal k", function()
    local ids, ord, gaps = Groups.ordinals(list(6, { [101] = true, [103] = true, [104] = true }))
    T.eq(ids, { 102, 105, 106 })
    T.eq(ord[105], 2)
    T.eq(gaps, { [2] = true }, "between 102 and 105; nothing before the first one")
    local _i, _o, none = Groups.ordinals(list(5))
    T.eq(none, {})
end)

T.it("run never spans a missing chapter (the reader stops there and says so)", function()
    local l = list(10, { [105] = true })
    local ids, _ord, gaps = Groups.ordinals(l)
    local set = all(ids)
    local run, first = Groups.run(ids, 2, 20, avail(set), gaps)
    T.eq(run, { 101, 102, 103, 104 })
    T.eq(first, 1)
    run, first = Groups.run(ids, 6, 20, avail(set), gaps)
    T.eq(run, { 106, 107, 108, 109, 110 })
    T.eq(first, 5)
    -- Without the gaps (older callers) the run bridged it.
    T.eq(#Groups.run(ids, 2, 20, avail(set)), 9)
end)

T.it("run never crosses a block boundary", function()
    local ids = Groups.ordinals(list(60))
    local run, first = Groups.run(ids, 40, 20, avail(all(ids)))
    T.eq(first, 21)
    T.eq(run[#run], 140)
    run = Groups.run(ids, 41, 20, avail(all(ids)))
    T.eq(run[1], 141)
end)

T.it("open mode: setting off is the old one-chapter-per-file path", function()
    local l = list(10)
    T.eq({ Groups.openMode(false, l, 103) }, { "single", "setting off" })
    T.eq({ Groups.openMode(nil, l, 103) }, { "joined" })
    T.eq({ Groups.openMode(true, l, 103) }, { "joined" })
    -- Not in the cached list, or no list: that open falls back to a single chapter.
    T.eq({ Groups.openMode(true, l, 999) }, { "single", "no_ordinal" })
    T.eq({ Groups.openMode(true, nil, 103) }, { "single", "no_list" })
    T.eq({ Groups.openMode(true, list(3, { [102] = true }), 102) }, { "single", "no_ordinal" })
end)

-- ---------------------------------------------------------------- files

T.it("group file names", function()
    T.eq(Groups.fileName(49, 20), "group_49_20.html")
    T.eq(Groups.manifestName(49, 20), "group_49_20.json")
    T.eq({ Groups.parseFileName("group_49_20.html") }, { 49, 20 })
    T.eq(Groups.parseFileName("group_49_20.json"), nil)
    T.eq(Groups.parseFileName("12_group.html"), nil)
    T.eq(ChapterDoc.idFromFileName("group_49_20.html"), nil, "never taken for a chapter")
    T.eq({ ChapterDoc.groupFromPath("/r/chapters/7/group_3_20.html", "/r") }, { 7, 3, 20 })
    T.eq(ChapterDoc.groupFromPath("/r/chapters/7/sub/group_3_20.html", "/r"), nil)
    T.eq(ChapterDoc.groupFromPath("/other/chapters/7/group_3_20.html", "/r"), nil)
    T.truthy(ChapterDoc.isNovarrPath("/r/chapters/7/group_3_20.html", "/r"))
    T.truthy(ChapterDoc.isNovarrPath("/r/chapters/7/12_x.html", "/r"))
    T.falsy(ChapterDoc.isNovarrPath("/r/chapters/7/index.json", "/r"))
end)

T.it("rebuild decision: same / append / rebuild", function()
    local m = { version = Groups.VERSION, parts = { "1:a:10", "2:b:20" } }
    T.eq(Groups.decide(m, { "1:a:10", "2:b:20" }, true), "same")
    T.eq(Groups.decide(m, { "1:a:10", "2:b:20", "3:c:30" }, true), "append")
    T.eq(Groups.decide(m, { "1:a:10" }, true), "rebuild", "shrank")
    T.eq(Groups.decide(m, { "0:z:5", "1:a:10", "2:b:20" }, true), "rebuild", "grew at the start")
    T.eq(Groups.decide(m, { "1:a:10", "2:b:21" }, true), "rebuild", "a chapter changed")
    T.eq(Groups.decide(m, { "1:a:10", "2:b:20" }, false), "rebuild", "file missing")
    T.eq(Groups.decide(nil, { "1:a:10" }, true), "rebuild", "no manifest")
    T.eq(Groups.decide({ version = Groups.VERSION - 1, parts = m.parts }, m.parts, true), "rebuild", "older build")
    T.eq(Groups.decide({ version = Groups.VERSION, parts = "junk" }, m.parts, true), "rebuild", "corrupt manifest")
    T.eq(Groups.decide({ version = Groups.VERSION, parts = {} }, m.parts, true), "rebuild")
end)

local function chapter(id, words)
    local paragraphs = {}
    for i = 1, words or 3 do table.insert(paragraphs, "Paragraph " .. i .. " of chapter " .. id .. " & <more>.") end
    return { id = id, novel_id = 7, novel_name = "Nine Heavens", label = "Chapter " .. id, chapter = id, paragraphs = paragraphs }
end

T.it("chapter body is shared by single and group documents", function()
    local html = ChapterDoc.build(chapter(5), "en")
    local body = ChapterDoc.bodyOf(html)
    T.eq(body, table.concat(ChapterDoc.bodyLines(chapter(5)), "\n"))
    T.contains(body, "<h2>Chapter 5</h2>")
    T.contains(body, "&amp; &lt;more&gt;")
    -- Not a Novarr chapter file / no text: left out of group files.
    T.eq(ChapterDoc.bodyOf("<html><body><p>x</p></body></html>"), nil)
    T.eq(ChapterDoc.bodyOf(ChapterDoc.build({ id = 1, label = "Empty", paragraphs = {} })), nil)
    T.eq(ChapterDoc.bodyOf(nil), nil)
end)

T.it("group document: one top-level div per chapter, each on a new page, a title", function()
    local chapters = {}
    for _i, id in ipairs({ 11, 12, 13 }) do
        table.insert(chapters, { id = id, body = ChapterDoc.bodyOf(ChapterDoc.build(chapter(id))) })
    end
    local title = ChapterDoc.groupTitle("Nine Heavens", 961, 963)
    T.eq(title, "Nine Heavens \194\183 chapters 961\226\128\147963")
    T.eq(ChapterDoc.groupTitle("N", 5, 5), "N \194\183 chapter 5")
    local html = ChapterDoc.buildGroup(title, chapters, "en")
    T.contains(html, "<title>Nine Heavens \194\183 chapters 961\226\128\147963</title>")
    T.contains(html, ".novarr-chapter { page-break-before: always; }")
    T.contains(html, '<body>\n<h1 class="novarr-group">')
    T.contains(html, '</h1>\n<div class="novarr-chapter" id="novarr-ch-11">\n<p class="novarr-novel">Nine Heavens</p>\n<h2>Chapter 11</h2>')
    T.contains(html, '</div>\n<div class="novarr-chapter" id="novarr-ch-12">')
    T.eq(Groups.idsInHtml(html), { 11, 12, 13 })
    T.eq(ChapterDoc.buildGroup(title, chapters, "en"), html, "deterministic")
end)

T.it("appending chapters keeps every earlier chapter byte-identical", function()
    local chapters = {}
    for _i, id in ipairs({ 11, 12, 13, 14 }) do
        table.insert(chapters, { id = id, body = ChapterDoc.bodyOf(ChapterDoc.build(chapter(id, 40))) })
    end
    local short = ChapterDoc.buildGroup(ChapterDoc.groupTitle("N", 1, 2), { chapters[1], chapters[2] }, "en")
    local long = ChapterDoc.buildGroup(ChapterDoc.groupTitle("N", 1, 4), chapters, "en")
    local function chaptersPart(html)
        local a = html:find('<div class="novarr-chapter"', 1, true)
        local b = html:find("\n</body>", 1, true)
        return html:sub(a, b - 1)
    end
    local old = chaptersPart(short)
    T.eq(chaptersPart(long):sub(1, #old), old)
    T.eq(chaptersPart(long):sub(#old + 1, #old + 1), "\n")
end)

-- ---------------------------------------------------------------- positions

T.it("chapter from position: last chapter starting at or before it", function()
    local pages = { 1, 15, 29, 43 }
    T.eq(Groups.chapterAt(pages, 1), 1)
    T.eq(Groups.chapterAt(pages, 14), 1)
    T.eq(Groups.chapterAt(pages, 15), 2)
    T.eq(Groups.chapterAt(pages, 42), 3)
    T.eq(Groups.chapterAt(pages, 400), 4)
    T.eq(Groups.chapterAt({ 0, 1588, 12801 }, 12800), 2, "y positions (scroll mode)")
    T.eq(Groups.chapterAt({ 0, 1588, 12801 }, 12801), 3)
end)

T.it("percent within a chapter", function()
    T.eq(Groups.percentIn(0, 0, 1000), 0)
    T.eq(Groups.percentIn(499, 0, 1000), 49)
    T.eq(Groups.percentIn(1588 + 1121, 1588, 12801), 9)
    T.eq(Groups.percentIn(5000, 0, 1000), 100)
    T.eq(Groups.percentIn(-5, 0, 1000), 0)
    T.eq(Groups.percentIn(5, 10, 10), 0, "empty span")
    T.eq(Groups.percentIn(nil, 0, 10), 0)
end)

T.it("page for a saved percent comes back to the page it was saved on", function()
    -- A chapter of 10 pages from y 1000 to 11000; page k's top at 1000 + 1000k (k from 0), first page 21.
    local tops = {}
    for k = 0, 9 do tops[k + 1] = 1000 + 1000 * k end
    for k = 0, 9 do
        local pct = Groups.percentIn(tops[k + 1], 1000, 11000)
        T.eq(Groups.pageForPercent(21, tops, 1000, 11000, pct), 21 + k, "page " .. k)
    end
    T.eq(Groups.pageForPercent(21, tops, 1000, 11000, 0), 21)
    T.eq(Groups.pageForPercent(21, {}, 1000, 11000, 40), 21)
    T.eq(Groups.pageForPercent(21, tops, 1000, 11000, 100), 30)
end)

-- ---------------------------------------------------------------- crossing rules

-- Four chapters on pages 1-14, 15-28, 29-42, 43-56.
local ENDS = { 14, 28, 42, 56 }

T.it("crossing forward by one page turn finishes the chapter left", function()
    T.eq(Groups.finished({ from = 1, to = 2, prev = 14, cur = 15, ends = ENDS, view = 1 }), { 1 })
end)

T.it("crossing backward finishes nothing", function()
    T.eq(Groups.finished({ from = 2, to = 1, prev = 15, cur = 14, ends = ENDS, view = 1 }), {})
end)

T.it("a jump finishes nothing (go-to, skim, TOC, bookmark, menu)", function()
    -- From the middle of chapter 1 to chapter 2's first page: chapter 1's end was never shown.
    T.eq(Groups.finished({ from = 1, to = 2, prev = 7, cur = 15, ends = ENDS, view = 1 }), {})
    -- From chapter 1's last page over chapter 2 and 3 into 4: nothing either (more than one page).
    T.eq(Groups.finished({ from = 1, to = 4, prev = 14, cur = 43, ends = ENDS, view = 1 }), {})
    -- Staying in the chapter.
    T.eq(Groups.finished({ from = 1, to = 1, prev = 13, cur = 14, ends = ENDS, view = 1 }), {})
end)

T.it("two-page mode: a turn of two pages through a chapter's end", function()
    T.eq(Groups.finished({ from = 1, to = 2, prev = 13, cur = 15, ends = ENDS, view = 2 }), { 1 })
    T.eq(Groups.finished({ from = 1, to = 2, prev = 11, cur = 15, ends = ENDS, view = 2 }), {})
end)

T.it("scroll mode: one screen forward through a chapter's end", function()
    local ends = { 1588, 12801, 16337 }
    T.eq(Groups.finished({ scroll = true, from = 1, to = 2, prev = 1000, cur = 1000 + 1500, ends = ends, view = 1648 }), { 1 })
    -- Scrolled a little (pan) across the boundary.
    T.eq(Groups.finished({ scroll = true, from = 1, to = 2, prev = 1500, cur = 1600, ends = ends, view = 1648 }), { 1 })
    -- Moved more than a screen: a jump.
    T.eq(Groups.finished({ scroll = true, from = 1, to = 2, prev = 0, cur = 1700, ends = ends, view = 1648 }), {})
    T.eq(Groups.finished({ scroll = true, from = 2, to = 1, prev = 1600, cur = 100, ends = ends, view = 1648 }), {})
    -- A chapter shorter than a screen passed in one turn: both ends were on screen.
    T.eq(Groups.finished({ scroll = true, from = 1, to = 3, prev = 1000, cur = 2500, ends = { 1588, 2000, 9000 }, view = 1648 }), { 1, 2 })
end)

-- ---------------------------------------------------------------- removal

T.it("removal: read chapters of earlier blocks go, except the 2 latest", function()
    local stored = {}
    local read = {}
    for o = 1, 45 do
        table.insert(stored, { id = 100 + o, ord = o, block = Groups.block(o, 20) })
        read[100 + o] = o <= 30
    end
    local ids = Groups.removalCandidates(stored, function(id) return read[id] end, 2, 2)
    -- Block 1 (101..120) all read: 119 and 120 are the 2 latest before block 2.
    T.eq(#ids, 18)
    T.eq(ids[1], 101)
    T.eq(ids[18], 118)
    -- The open block (2) and later ones are never touched, read or not.
    ids = Groups.removalCandidates(stored, function(id) return read[id] end, 2, 3)
    T.eq(ids[#ids], 128, "block 2's read chapters except 129/130")
end)

T.it("removal: unread, part-read and unknown chapters are kept", function()
    local stored = {
        { id = 1, ord = 1, block = 1 }, { id = 2, ord = 2, block = 1 }, { id = 3, ord = 3, block = 1 },
        { id = 4, ord = 4, block = 1 }, { id = 5, ord = 5, block = 1 }, { id = 99 },
    }
    local read = { [1] = true, [2] = false, [3] = true, [4] = true, [5] = true, [99] = true }
    T.eq(Groups.removalCandidates(stored, function(id) return read[id] end, 2, 2), { 1, 3 })
    T.eq(Groups.removalCandidates(stored, function(id) return read[id] end, 2, 1), {}, "same block")
end)

T.it("group files kept: the open block and its neighbours", function()
    T.truthy(Groups.keepGroupFile(5, 5))
    T.truthy(Groups.keepGroupFile(4, 5))
    T.truthy(Groups.keepGroupFile(6, 5))
    T.falsy(Groups.keepGroupFile(3, 5))
    T.falsy(Groups.keepGroupFile(1, nil))
end)

-- ---------------------------------------------------------------- prepare (with a real store)

local function newStore()
    return Store.new({ root = T.tmpdir(), lfs = lfs, json = Json, now = function() return 1000 end })
end

local function save(store, id)
    local ch = chapter(id, 30)
    return store:saveChapter(ch, ChapterDoc.build(ch, "en"))
end

local function prep(store, l, id, extra)
    local o = { novel_id = 7, chapter_id = id, list = l, n = 4, novel_name = "Nine Heavens", lang = "en", bad = {} }
    for k, v in pairs(extra or {}) do o[k] = v end
    return Groups.prepare(store, o)
end

T.it("prepare: builds the block's run, then leaves an unchanged file alone", function()
    local store = newStore()
    local l = list(10)
    for _i, id in ipairs({ 105, 106, 107 }) do save(store, id) end
    local g = prep(store, l, 106)
    T.eq(g.decision, "rebuild")
    T.eq(g.block, 2)
    T.eq(g.ids, { 105, 106, 107 })
    T.eq(g.first, 5)
    T.contains(g.path, "/chapters/7/group_2_4.html")
    local html = store:readFile(g.path)
    T.eq(Groups.idsInHtml(html), { 105, 106, 107 })
    T.contains(html, "Nine Heavens \194\183 chapters 5\226\128\1477")
    local m = store:readGroupManifest(7, 2, 4)
    T.eq(m.ids, { 105, 106, 107 })
    T.eq(m.version, Groups.VERSION)
    -- Unchanged: not rewritten (same inode contents and modification time kept).
    local mtime = lfs.attributes(g.path, "modification")
    os.execute("sleep 1")
    local g2 = prep(store, l, 107)
    T.eq(g2.decision, "same")
    T.eq(lfs.attributes(g.path, "modification"), mtime)
end)

T.it("prepare: new chapters at the end are appended, earlier content unchanged", function()
    local store = newStore()
    local l = list(10)
    save(store, 105)
    save(store, 106)
    local g = prep(store, l, 105)
    local before = store:readFile(g.path)
    save(store, 107)
    local g2 = prep(store, l, 107)
    T.eq(g2.decision, "append")
    T.eq(g2.ids, { 105, 106, 107 })
    local after = store:readFile(g.path)
    local a = before:find('<div class="novarr-chapter"', 1, true)
    local b = before:find("\n</body>", 1, true)
    local part = before:sub(a, b - 1)
    T.eq(after:sub(a, a + #part - 1), part)
end)

T.it("prepare: a hole splits the block; an earlier chapter appearing rebuilds", function()
    local store = newStore()
    local l = list(8)
    for _i, id in ipairs({ 101, 103, 104 }) do save(store, id) end
    T.eq(prep(store, l, 101).ids, { 101 })
    local g = prep(store, l, 104)
    T.eq(g.ids, { 103, 104 })
    T.eq(g.decision, "rebuild", "a different run of the same block")
    save(store, 102)
    g = prep(store, l, 104)
    T.eq(g.ids, { 101, 102, 103, 104 })
    T.eq(g.decision, "rebuild")
end)

T.it("prepare: a group file ends before a chapter the server does not have yet", function()
    local store = newStore()
    local l = list(8, { [103] = true })
    for _i, id in ipairs({ 101, 102, 104, 105 }) do save(store, id) end
    -- n = 4: ordinals 101 102 104 105 are block 1, but 103 is missing between 102 and 104.
    local g = prep(store, l, 101)
    T.eq(g.ids, { 101, 102 })
    local g2 = prep(store, l, 105)
    T.eq(g2.ids, { 104, 105 })
    T.eq(g2.first, 3)
end)

T.it("prepare: order change on the server moves chapters between blocks", function()
    local store = newStore()
    local l = list(8)
    for i = 101, 108 do save(store, i) end
    T.eq(prep(store, l, 106).ids, { 105, 106, 107, 108 })
    -- Chapter 102 removed from the list (blacklisted): 106 is now ordinal 5 -> block 2 starts at 106.
    table.remove(l, 2)
    local g = prep(store, l, 106)
    T.eq(g.ids, { 106, 107, 108 })
    T.eq(g.decision, "rebuild")
    T.eq(prep(store, l, 105).ids, { 101, 103, 104, 105 })
end)

T.it("prepare: corrupt manifest or a file from an older build is rebuilt", function()
    local store = newStore()
    local l = list(4)
    save(store, 101)
    local g = prep(store, l, 101)
    store:writeFile(store:groupManifestPath(7, 1, 4), "{not json")
    T.eq(prep(store, l, 101).decision, "rebuild")
    local m = store:readGroupManifest(7, 1, 4)
    m.version = 0
    store:writeJson(store:groupManifestPath(7, 1, 4), m)
    T.eq(prep(store, l, 101).decision, "rebuild")
    os.remove(g.path)
    T.eq(prep(store, l, 101).decision, "rebuild", "group file deleted")
    T.eq(prep(store, l, 101).decision, "same")
end)

T.it("prepare: unreadable stored chapters break the run; reasons for no group", function()
    local store = newStore()
    local l = list(6)
    for i = 101, 104 do save(store, i) end
    store:writeFile(store:chapterPath(7, 102), "garbage")
    local bad = {}
    local g = prep(store, l, 103, { bad = bad })
    T.eq(g.ids, { 103, 104 })
    T.truthy(bad[102])
    T.eq({ prep(store, l, 102, { bad = {} }) }, { nil, "not_stored" }, "its own file is unreadable")
    T.eq({ prep(store, l, 105) }, { nil, "not_stored" })
    T.eq({ prep(store, l, 999) }, { nil, "no_ordinal" })
    T.eq({ prep(store, nil, 101) }, { nil, "no_list" })
    T.eq({ prep(store, l, 101, { can_write = function() return false end }) }, { nil, "no_space" })
end)

T.it("store: group files are listed, counted in downloads and removed with their manifest", function()
    local store = newStore()
    local l = list(10)
    for i = 101, 106 do save(store, i) end
    prep(store, l, 101)
    prep(store, l, 105)
    local files = store:groupFiles(7)
    T.eq(#files, 2)
    T.eq(files[1].block, 1)
    T.eq(files[2].block, 2)
    local d = store:downloads()[1]
    local chapters_only = 0
    for _i, p in ipairs(store:chapterPaths(7)) do chapters_only = chapters_only + lfs.attributes(p, "size") end
    T.eq(d.count, 6, "group files are not chapters")
    T.eq(d.bytes, chapters_only + files[1].size + files[2].size)
    T.truthy(store:removeGroup(7, 1, 4) > 0)
    T.eq(#store:groupFiles(7), 1)
    T.eq(store:readGroupManifest(7, 1, 4), nil)
    T.eq(store:getIndex(7).chapters["101"] ~= nil, true, "the index never lists group files")
end)
