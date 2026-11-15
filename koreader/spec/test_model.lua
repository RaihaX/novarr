local T = ...
local Model = require("novarr.model")

local novels = {
    { id = 1, name = "Ascending the Nine Heavens", author = "Feng Qingyang", chapters_read = 18, chapters_total = 300, chapters_new = 2, finished = false, completed = false },
    { id = 2, name = "Finished Tale", author = "Moon", chapters_read = 3, chapters_total = 3, chapters_new = 0, finished = true, completed = true },
    { id = 3, name = "Untouched", author = nil, chapters_read = 0, chapters_total = 4, chapters_new = 0, finished = false, completed = false },
    { id = 4, name = "Reborn as the ARCHIVIST", author = "Liu Chen", chapters_read = 0, chapters_total = 450, chapters_new = 5, finished = false, completed = true },
}

local function ids(list)
    local out = {}
    for _, n in ipairs(list) do table.insert(out, n.id) end
    return out
end

T.it("filters", function()
    T.eq(ids(Model.filterNovels(novels, "all")), { 1, 2, 3, 4 })
    T.eq(ids(Model.filterNovels(novels, "reading")), { 1 })
    T.eq(ids(Model.filterNovels(novels, "unread")), { 3, 4 })
    T.eq(ids(Model.filterNovels(novels, "new")), { 1, 4 })
    T.eq(ids(Model.filterNovels(novels, "completed")), { 2, 4 })
    T.eq(ids(Model.filterNovels(novels, "downloaded", nil, { [3] = true, [2] = true })), { 2, 3 })
    T.eq(ids(Model.filterNovels(novels, "downloaded")), {})
end)

T.it("search by name or author, case-insensitive, plain text", function()
    T.eq(ids(Model.filterNovels(novels, "all", "archivist")), { 4 })
    T.eq(ids(Model.filterNovels(novels, "all", "FENG")), { 1 })
    T.eq(ids(Model.filterNovels(novels, "all", "the")), { 1, 4 })
    T.eq(ids(Model.filterNovels(novels, "all", "%")), {})
    T.eq(ids(Model.filterNovels(novels, "unread", "liu")), { 4 })
    T.eq(ids(Model.filterNovels(novels, "all", "")), { 1, 2, 3, 4 })
end)

T.it("skips malformed rows", function()
    T.eq(ids(Model.filterNovels({ "x", { name = "no id" }, novels[1] }, "all")), { 1 })
end)

-- A 3,000-chapter list.
local chapters = {}
for i = 1, 3000 do
    chapters[i] = { id = 1000 + i, chapter = i, downloaded = i ~= 5, read = i <= 1234 }
end

T.it("page for the resume chapter", function()
    T.eq(Model.pageFor(1, 14), 1)
    T.eq(Model.pageFor(14, 14), 1)
    T.eq(Model.pageFor(15, 14), 2)
    T.eq(Model.pageFor(3000, 14), 215)
    T.eq(Model.pageFor(nil, 14), 1)
    local idx = Model.resumeIndex(chapters, 1000 + 1235)
    T.eq(idx, 1235)
    T.eq(Model.pageFor(idx, 14), 89)
end)

T.it("resume index falls back to the first unread downloaded chapter", function()
    T.eq(Model.resumeIndex(chapters, nil), 1235)
    T.eq(Model.resumeIndex(chapters, 999999), 1235)
    T.eq(Model.resumeIndex({}, nil), 1)
    local with_overlay = Model.resumeIndex(chapters, nil, function(c) return c.chapter <= 2000 end)
    T.eq(with_overlay, 2001)
end)

T.it("go to chapter number", function()
    T.eq(Model.findChapterNumber(chapters, 77), 77)
    T.eq(Model.findChapterNumber(chapters, "2999"), 2999)
    T.eq(Model.findChapterNumber(chapters, 0), nil)
    T.eq(Model.findChapterNumber(chapters, "abc"), nil)
    T.eq(Model.findChapterNumber({ { chapter = 10 }, { chapter = 11 } }, 2), 2)
end)

T.it("download selections", function()
    local small = {
        { id = 1, downloaded = true, read = true },
        { id = 2, downloaded = true, read = false },
        { id = 3, downloaded = false, read = false },
        { id = 4, downloaded = true, read = false },
        { id = 5, downloaded = true, read = false },
        { id = 6, downloaded = true, read = true },
    }
    T.eq(Model.downloadSelection(small, "unread", 2, 2, {}), { 2, 4 })
    T.eq(Model.downloadSelection(small, "unread", nil, 2, { [4] = true }), { 2, 5 })
    T.eq(Model.downloadSelection(small, "unread", nil, nil, {}), { 2, 4, 5 })
    T.eq(Model.downloadSelection(small, "all", nil, 2, {}), { 1, 2, 4, 5, 6 })
    T.eq(Model.downloadSelection(small, "unread", nil, 4, {}, function(c) return c.id == 4 end), { 5, 6 })
end)

T.it("next chapters for prefetch skip ones the server lacks", function()
    T.eq(Model.nextChapters(chapters, 1003, 3), { 1004, 1006, 1007 })
    T.eq(Model.nextChapters(chapters, 4000, 3), {})
    T.eq(Model.nextChapters(chapters, 1000 + 3000, 3), {})
end)

T.it("removal keeps the 2 most recent read chapters and never unread/part-read", function()
    local stored = {
        { id = 11, order = 1 }, { id = 12, order = 2 }, { id = 13, order = 3 },
        { id = 14, order = 4 }, { id = 15, order = 5 }, { id = 16, order = 6 },
    }
    local state = {
        [11] = { true }, [12] = { true }, [13] = { false, 40 }, [14] = { true }, [15] = { true }, [16] = { false },
    }
    local fn = function(id) return state[id][1], state[id][2] end
    T.eq(Model.removalCandidates(stored, fn, 2, nil), { 11, 12 })
    -- the open chapter is never removed and does not count
    T.eq(Model.removalCandidates(stored, fn, 2, 15), { 11 })
    T.eq(Model.removalCandidates(stored, fn, 0, nil), { 11, 12, 14, 15 })
    T.eq(Model.removalCandidates({}, fn, 2), {})
    -- reading chapter 13 (order 3): read chapters after it (14, 15) are kept
    T.eq(Model.removalCandidates(stored, fn, 0, 13, 3), { 11, 12 })
    T.eq(Model.removalCandidates(stored, fn, 1, 13, 3), { 11 })
end)

T.it("ISO dates parse as UTC", function()
    T.eq(Model.parseIso("1970-01-01T00:00:00Z"), 0)
    T.eq(Model.parseIso("2026-11-15T03:20:11Z"), 1794712811)
    T.eq(Model.parseIso("2000-02-29T12:00:00Z"), 951825600)
    T.eq(Model.parseIso(nil), nil)
    T.eq(Model.parseIso("yesterday"), nil)
end)

T.it("formatting helpers", function()
    T.eq(Model.formatBytes(512), "512 B")
    T.eq(Model.formatBytes(2048), "2 KB")
    T.eq(Model.formatBytes(5 * 1024 * 1024), "5.0 MB")
    T.eq(Model.readCountText({ chapters_read = 3, chapters_total = 10 }, 2), "5/10")
    T.eq(Model.readCountText({ chapters_read = 9, chapters_total = 10 }, 5), "10/10")
    T.eq(Model.chapterLabel({ chapter = 4 }), "Chapter 4")
    T.eq(Model.chapterLabel({ label = "Prologue" }), "Prologue")
end)

T.it("building 3,000 rows is fast", function()
    local t0 = os.clock()
    local rows = {}
    for i, c in ipairs(chapters) do
        rows[i] = { text = "Chapter " .. c.chapter .. " - The Long Road", mandatory = c.read and "\226\156\147" or "" }
    end
    local idx = Model.resumeIndex(chapters, 2500)
    local dt = os.clock() - t0
    T.truthy(#rows == 3000 and idx)
    T.truthy(dt < 0.5, "took " .. dt .. " s")
end)

-- ---------------------------------------------------------------- UX round

local function L(n, missing)
    local list = {}
    for i = 1, n do list[i] = { id = 100 + i, downloaded = not (missing and missing[i]), read = false } end
    return list
end
local function readTo(list, k) for i = 1, k do list[i].read = true end return list end
local function effective(overlay)
    return function(c)
        local e = overlay[tostring(c.id)]
        if e and e.read ~= nil then return e.read end
        return c.read
    end
end

-- {name, list, server resume id, overlay, expected id}
local resume_cases = {
    { "server only", readTo(L(20), 4), 105, {}, 105 },
    { "local read further than server", readTo(L(20), 4), 105,
        { ["116"] = { read = true, at = 10 } }, 117 },
    { "local read behind server (catch-up mark) keeps server", readTo(L(20), 9), 110,
        { ["102"] = { read = true, at = 10 } }, 110 },
    { "part-read newer than read marks wins", readTo(L(20), 4), 105,
        { ["116"] = { read = true, at = 10 }, ["117"] = { progress = 40, at = 20 } }, 117 },
    { "re-opening an already read chapter does not move it back", readTo(L(20), 9), 110,
        { ["103"] = { progress = 30, at = 50 } }, 110 },
    { "an unread chapter being read, behind the server's, wins when newest", readTo(L(20), 4), 110,
        { ["106"] = { progress = 30, at = 50 } }, 106 },
    { "older part-read loses to a newer read mark further on", readTo(L(20), 4), 105,
        { ["106"] = { progress = 30, at = 5 }, ["111"] = { read = true, at = 9 } }, 112 },
    { "server points at a read chapter (progress < 90), device read past it", readTo(L(20), 5), 105,
        { ["105"] = { read = true, at = 3 }, ["106"] = { read = true, at = 4 } }, 107 },
    { "next after local read skips chapters the server lacks", readTo(L(20, { [8] = true }), 4), 105,
        { ["107"] = { read = true, at = 1 } }, 109 },
    { "unread mark never moves it", readTo(L(20), 9), 110, { ["104"] = { read = false, at = 99 } }, 110 },
    { "no server resume, nothing local: first unread", readTo(L(10), 3), nil, {}, 104 },
    { "read to the end locally: keep server", readTo(L(5), 4), 105, { ["105"] = { read = true, at = 1 } }, 105 },
}
for _i, c in ipairs(resume_cases) do
    T.it("resume rule: " .. c[1], function()
        T.eq(Model.resolveResume(c[2], c[3], c[4], effective(c[4])), c[5])
    end)
end

T.it("ordinal among downloaded chapters", function()
    local list = L(10, { [3] = true, [7] = true })
    T.eq({ Model.ordinal(list, 104) }, { 3, 8 })
    T.eq({ Model.ordinal(list, 103) }, { nil, 8 })
    T.eq({ Model.ordinal({}, 1) }, { nil, 0 })
end)

T.it("home shelf order: hero, in progress by recency, new chapters, rest", function()
    local novels = {
        { id = 1, chapters_read = 5, last_read_at = "2026-11-10T00:00:00Z" },
        { id = 2, chapters_read = 0, chapters_new = 3 },
        { id = 3, chapters_read = 9, last_read_at = "2026-11-14T00:00:00Z" },
        { id = 4, chapters_read = 0 },
        { id = 5, chapters_read = 7, finished = true },
        { id = 6, chapters_read = 0 },
    }
    local hero, started, rest = Model.shelfOrder(novels, {})
    T.eq(hero.id, 3); T.truthy(started)
    T.eq(ids(rest), { 1, 2, 4, 5, 6 })
    -- reading on this device more recently than the server knows
    local h2, _s, r2 = Model.shelfOrder(novels, { [1] = Model.parseIso("2026-11-15T00:00:00Z") * 1000, [6] = 1 })
    T.eq(h2.id, 1)
    T.eq(ids(r2), { 3, 6, 2, 4, 5 })
    -- nothing in progress: first library novel, "Start reading"
    local h3, s3, r3 = Model.shelfOrder({ { id = 9 }, { id = 8, chapters_new = 1 } }, {})
    T.eq(h3.id, 8); T.falsy(s3); T.eq(ids(r3), { 9 })
    local h4 = Model.shelfOrder({}, {})
    T.eq(h4, nil)
end)

T.it("home shelf pages", function()
    T.eq(Model.shelfPages(10, 6, 9), { { first = 1, last = 6 }, { first = 7, last = 10 } })
    T.eq(Model.shelfPages(3, 6, 9), { { first = 1, last = 3 } })
    T.eq(Model.shelfPages(0, 6, 9), { { first = 1, last = 0 } })
    T.eq(#Model.shelfPages(150, 6, 9), 17)
end)

T.it("one tap: resume chapter, or the Novel screen when nothing to resume", function()
    T.eq({ Model.tapTarget({ chapters_downloaded = 10 }, 55) }, { "open", 55 })
    T.eq({ Model.tapTarget({ chapters_downloaded = 0 }, 55) }, { "novel" })
    T.eq({ Model.tapTarget({ chapters_downloaded = 10 }, nil) }, { "novel" })
    T.eq({ Model.tapTarget(nil, 1) }, { "novel" })
end)

T.it("prefetch pacing", function()
    T.eq(Model.prefetchWait(10000, 9000, nil, 5000, 1500), 4)       -- too soon after opening
    T.eq(Model.prefetchWait(20000, 9000, 19500, 5000, 1500), 1)     -- page turn half a second ago
    T.eq(Model.prefetchWait(20000, 9000, 15000, 5000, 1500), 0)     -- quiet: go
    T.eq(Model.prefetchWait(20000, nil, nil), 0)
end)

T.it("history pruning keeps only the chapter being read", function()
    local is_ch = function(p) return p:find("/novarr/chapters/", 1, true) ~= nil end
    local paths = { "/b/book.epub", "/k/novarr/chapters/2/11_a.html", "/k/novarr/chapters/2/12_b.html", "/k/novarr/chapters/3/9.html" }
    T.eq(Model.historyToPrune(paths, "/k/novarr/chapters/2/12_b.html", is_ch),
        { "/k/novarr/chapters/2/11_a.html", "/k/novarr/chapters/3/9.html" })
    T.eq(Model.historyToPrune(paths, nil, is_ch), { paths[2], paths[3], paths[4] })
end)
