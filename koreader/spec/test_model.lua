local T = ...
local Model = require("novarr.model")

-- ---------------------------------------------------------------- 1.14.0: comics

T.it("media (1.14.0): comic is a known medium; anything else is a novel", function()
    T.eq(Model.MEDIA, { "novel", "book", "manga", "comic" })
    T.eq(Model.medium("comic"), "comic")
    T.eq(Model.medium({ medium = "comic" }), "comic")
    T.eq(Model.medium({ medium = "manga" }), "manga")
    T.eq(Model.medium({ medium = "book" }), "book")
    T.eq(Model.medium({}), "novel", "an older server: no medium")
    T.eq(Model.medium("graphic"), "novel")
    T.eq(Model.medium(nil), "novel")
end)

T.it("isPaged: manga and comics are read as pages, never text", function()
    T.truthy(Model.isPaged("manga"))
    T.truthy(Model.isPaged("comic"))
    T.truthy(Model.isPaged({ medium = "comic" }))
    T.falsy(Model.isPaged("novel"))
    T.falsy(Model.isPaged("book"))
    T.falsy(Model.isPaged({}))
    T.falsy(Model.isPaged(nil))
end)

T.it("mediumLabel: Comics / Comic, and the others", function()
    T.eq(Model.mediumLabel("comic", true), "Comics")
    T.eq(Model.mediumLabel("comic"), "Comic")
    T.eq(Model.mediumLabel("manga", true), "Manga")
    T.eq(Model.mediumLabel("book", true), "Books")
    T.eq(Model.mediumLabel(nil, true), "Novels")
    T.eq(Model.mediumLabel({ medium = "comic" }, false), "Comic")
end)

T.it("readingDirection: a comic reads left to right unless its series says rtl; manga untouched", function()
    T.eq(Model.readingDirection("comic", nil), "ltr", "the default for a comic")
    T.eq(Model.readingDirection("comic", "ltr"), "ltr")
    T.eq(Model.readingDirection("comic", "vertical"), "ltr", "a long strip still never turns right to left")
    T.eq(Model.readingDirection("comic", "rtl"), "rtl", "only when the series says so")
    T.eq(Model.readingDirection({ medium = "comic" }, "rtl"), "rtl")
    T.eq(Model.readingDirection("manga", "rtl"), nil, "a manga keeps KOReader's setting (as 1.13.0)")
    T.eq(Model.readingDirection("manga", nil), nil)
    T.eq(Model.readingDirection("novel", "rtl"), nil)
    T.eq(Model.readingDirection(nil, nil), nil)
end)

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
    -- the resume chapter is read but still being read (web: read on open, 40 %): included
    T.eq(Model.downloadSelection(small, "unread", nil, 4, {}, function(c)
        if c.id == 4 then return true, 40 end
        return false
    end), { 4, 5, 6 })
    -- ... but only as the resume chapter, and not once finished
    T.eq(Model.downloadSelection(small, "unread", nil, 4, {}, function(c)
        if c.id == 4 then return true, 95 end
        if c.id == 5 then return true, 40 end
        return false
    end), { 6 })
end)

T.it("next chapters for prefetch skip ones the server lacks", function()
    T.eq(Model.nextChapters(chapters, 1003, 3), { 1004, 1006, 1007 })
    T.eq(Model.nextChapters(chapters, 4000, 3), {})
    T.eq(Model.nextChapters(chapters, 1000 + 3000, 3), {})
end)

T.it("latest: the last chapter downloaded on the server or stored here", function()
    local l = {
        { id = 100, downloaded = true },
        { id = 101, downloaded = true },
        { id = 102, downloaded = false },
    }
    T.eq(Model.latestIn(l).id, 101, "not on the server yet: not the latest")
    T.eq(Model.latestIn(l, function(id) return id == 102 end).id, 102, "saved on this device: readable")
    T.eq(Model.latestIn({ { id = 1, downloaded = false } }), nil)
    T.eq(Model.latestIn({}), nil)
    T.eq(Model.latestIn(nil), nil)
end)

T.it("neighbour: going forward a missing chapter is reported, never skipped silently", function()
    local l = {
        { id = 100, downloaded = true },
        { id = 101, downloaded = false, label = "Chapter 101" },
        { id = 102, downloaded = false },
        { id = 103, downloaded = true, label = "Chapter 103" },
        { id = 104, downloaded = false },
    }
    local stored = {}
    local function has(id) return stored[id] == true end
    local c, passed = Model.neighbourIn(l, 1, 1, has)
    T.eq(c.id, 103, "the next chapter there is")
    T.eq(passed.id, 101, "the first one passed: the reader asks before going on")
    T.eq(Model.chapterLabel(passed), "Chapter 101")
    -- Saved on this device although the server lost it: readable, nothing passed.
    stored[101] = true
    c, passed = Model.neighbourIn(l, 1, 1, has)
    T.eq(c.id, 101)
    T.eq(passed, nil)
    -- The end of the list with a missing chapter after it: none to go to, but one passed.
    c, passed = Model.neighbourIn(l, 4, 1, has)
    T.eq(c, nil)
    T.eq(passed.id, 104)
    -- Backward keeps today's behaviour: gaps are skipped, nothing reported.
    stored[101] = nil
    c, passed = Model.neighbourIn(l, 4, -1, has)
    T.eq(c.id, 100)
    T.eq(passed, nil)
    T.eq((Model.neighbourIn(l, 1, -1, has)), nil)
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
-- Effective read state and progress (Store.effective): the server row plus the overlay.
local function effective(overlay)
    return function(c)
        local e = overlay[tostring(c.id)]
        local read, progress = c.read, c.read_progress
        if e then
            if e.read ~= nil then read = e.read end
            if e.progress ~= nil then progress = e.progress end
            if e.read == false and e.progress == nil then progress = nil end
        end
        return read, progress
    end
end

-- {name, list, server resume id, overlay, expected id}
local resume_cases = {
    { "server only", readTo(L(20), 4), 105, {}, 105 },
    { "local read further than server", readTo(L(20), 4), 105,
        { ["116"] = { read = true, at = 10 } }, 117 },
    { "a newer read mark behind the server's: next unread after it", readTo(L(20), 9), 110,
        { ["102"] = { read = true, at = 10 } }, 110 },
    { "part-read newer than read marks wins", readTo(L(20), 4), 105,
        { ["116"] = { read = true, at = 10 }, ["117"] = { progress = 40, at = 20 } }, 117 },
    { "re-reading a read chapter part-way resumes it, as the server will", readTo(L(20), 9), 110,
        { ["103"] = { progress = 30, at = 50 } }, 103 },
    { "re-reading a read chapter to its end: next unread after it", readTo(L(20), 9), 110,
        { ["103"] = { progress = 95, at = 50 } }, 110 },
    { "an unread chapter being read, behind the server's, wins when newest", readTo(L(20), 4), 110,
        { ["106"] = { progress = 30, at = 50 } }, 106 },
    { "older part-read loses to a newer read mark further on", readTo(L(20), 4), 105,
        { ["106"] = { progress = 30, at = 5 }, ["111"] = { read = true, at = 9 } }, 112 },
    { "an older part-read chapter never pulls back", readTo(L(20), 4), 105,
        { ["103"] = { progress = 20, at = 1 }, ["105"] = { read = true, progress = 100, at = 9 } }, 106 },
    { "server points at a read chapter (progress < 90), device read past it", readTo(L(20), 5), 105,
        { ["105"] = { read = true, at = 3 }, ["106"] = { read = true, at = 4 } }, 107 },
    { "next after local read skips chapters the server lacks", readTo(L(20, { [8] = true }), 4), 105,
        { ["107"] = { read = true, at = 1 } }, 109 },
    { "earlier unread chapters do not pull back", L(10), 101,
        { ["105"] = { read = true, progress = 100, at = 1 } }, 106 },
    { "unread mark never moves it", readTo(L(20), 9), 110, { ["104"] = { read = false, at = 99 } }, 110 },
    { "opened at 0 % is not reading", readTo(L(20), 4), 105, { ["108"] = { progress = 0, at = 99 } }, 105 },
    { "reading on a chapter the server lacks is ignored", readTo(L(20, { [8] = true }), 4), 105,
        { ["108"] = { progress = 50, at = 99 } }, 105 },
    { "a tie goes to the furthest chapter", readTo(L(20), 2), 103,
        { ["103"] = { read = true, progress = 100, at = 7 }, ["104"] = { read = true, progress = 100, at = 7 },
          ["105"] = { read = true, progress = 100, at = 7 } }, 106 },
    { "no server resume, nothing local: first unread", readTo(L(10), 3), nil, {}, 104 },
    { "caught up locally: the last downloaded chapter, like the server",
        readTo(L(6, { [6] = true }), 4), 105, { ["105"] = { read = true, at = 1 } }, 105 },
    { "read to the end locally: keep server", readTo(L(5), 4), 105, { ["105"] = { read = true, at = 1 } }, 105 },
}
-- Hand marks (Session:markChapter "set", Session:bulkMark: `hand`) and real
-- reading (`reading_at`) since 1.10.2: only reading is an anchor.
local function handMarked(from, to, at, extra)
    local overlay = extra or {}
    for id = from, to do overlay[tostring(id)] = { read = true, progress = 100, at = at, hand = true } end
    return overlay
end
local function withProgress(list, i, p) list[i].read_progress = p return list end
local more_cases = {
    { "a hand mark is not reading: server ch 11, ch 40 marked read", readTo(L(60), 10), 111,
        handMarked(140, 140, 9), 111 },
    { "a bulk mark over the server's chapter: the first unread after it", readTo(L(60), 10), 111,
        handMarked(101, 150, 3), 151 },
    { "a bulk mark over the server's chapter skips chapters the server lacks", readTo(L(60, { [51] = true }), 10), 111,
        handMarked(101, 150, 3), 152 },
    { "everything marked read: the last downloaded chapter", readTo(L(60, { [60] = true }), 10), 111,
        handMarked(101, 160, 3), 159 },
    { "a bulk mark short of the server's chapter keeps it", readTo(L(60), 10), 111,
        handMarked(101, 105, 3), 111 },
    { "a bulk mark over a server chapter already finished keeps it", readTo(L(60), 20), 120,
        handMarked(101, 150, 3), 120 },
    { "a server chapter read but re-read, untouched, is kept", withProgress(readTo(L(60), 20), 12, 30), 112,
        handMarked(140, 140, 3), 112 },
    { "a later hand mark does not take over from reading", readTo(L(60), 9), 110,
        { ["110"] = { progress = 40, at = 1, reading_at = 1 },
          ["130"] = { read = true, progress = 100, at = 2, hand = true } }, 110 },
    { "a hand mark after reading keeps the reading's time", readTo(L(60), 9), 110,
        { ["110"] = { read = true, progress = 100, at = 9, reading_at = 2, hand = true },
          ["112"] = { progress = 40, at = 3, reading_at = 3 } }, 112 },
    { "reading after a hand mark counts (read below 90 % is unfinished)", readTo(L(60), 10), 111,
        { ["140"] = { read = true, progress = 50, at = 2, reading_at = 2, hand = true } }, 140 },
}
for _i, c in ipairs(more_cases) do table.insert(resume_cases, c) end

T.it("readingTime: reading_at, none for a hand mark, `at` for entries before 1.10.2", function()
    T.eq(Model.readingTime({ at = 9, reading_at = 4, hand = true }), 4)
    T.eq(Model.readingTime({ at = 9, hand = true }), nil)
    T.eq(Model.readingTime({ at = 9 }), 9)
    T.eq(Model.readingTime(nil), nil)
end)

for _i, c in ipairs(resume_cases) do
    T.it("resume rule: " .. c[1], function()
        T.eq(Model.resolveResume(c[2], c[3], c[4], effective(c[4])), c[5])
    end)
end

T.it("unfinished: unread 1-99 %, read below 90 %", function()
    T.falsy(Model.unfinished(false, nil)); T.falsy(Model.unfinished(false, 0))
    T.truthy(Model.unfinished(false, 1)); T.truthy(Model.unfinished(false, 99)); T.falsy(Model.unfinished(false, 100))
    T.falsy(Model.unfinished(true, nil)); T.truthy(Model.unfinished(true, 0)); T.truthy(Model.unfinished(true, 89))
    T.falsy(Model.unfinished(true, 90)); T.falsy(Model.unfinished(true, 100))
end)

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

T.it("return screens after leaving a book: where it was opened from, Home underneath", function()
    T.eq(Model.returnScreens("home"), { "home" })
    T.eq(Model.returnScreens(nil), { "home" }, "no origin (KOReader opened it)")
    T.eq(Model.returnScreens("downloads"), { "home" }, "another screen")
    T.eq(Model.returnScreens("library"), { "home", "library" })
    T.eq(Model.returnScreens("novel"), { "home", "novel" })
    T.eq(Model.returnScreens("chapters"), { "home", "chapters" })
    local a = Model.returnScreens("novel")
    a[3] = "x"
    T.eq(Model.returnScreens("novel"), { "home", "novel" }, "a fresh list each time")
end)

-- ---------------------------------------------------------------- books (1.12.0)

local function lib()
    return {
        { id = 1, name = "Web A", medium = "novel", chapters_read = 3 },
        { id = 2, name = "Book Read", medium = "book", chapters_read = 2 },
        { id = 3, name = "Book New", medium = "book", chapters_read = 0 },
        { id = 4, name = "Old Server Novel" }, -- no medium: a novel
        { id = 5, name = "Book Local", medium = "book", chapters_read = 0 },
    }
end

T.it("books: isBook and hasBooks", function()
    T.truthy(Model.isBook({ medium = "book" }))
    T.falsy(Model.isBook({ medium = "novel" }))
    T.falsy(Model.isBook({}), "no medium (older server): a novel")
    T.falsy(Model.isBook(nil))
    T.truthy(Model.hasBooks(lib()))
    T.falsy(Model.hasBooks({ { id = 1, medium = "novel" }, { id = 4 } }))
    T.falsy(Model.hasBooks({}))
    T.falsy(Model.hasBooks(nil))
end)

T.it("books: byMedium splits the library, keeping the API's order", function()
    local ids = function(list) local out = {} for _i, n in ipairs(list) do table.insert(out, n.id) end return out end
    T.eq(ids(Model.byMedium(lib(), "book")), { 2, 3, 5 })
    T.eq(ids(Model.byMedium(lib(), "novel")), { 1, 4 })
    T.eq(ids(Model.byMedium(lib(), nil)), { 1, 4 }, "default: the novels")
    T.eq(Model.byMedium(nil, "book"), {})
end)

T.it("books: Home keeps every novel and only the books being read", function()
    local ids = function(list) local out = {} for _i, n in ipairs(list) do table.insert(out, n.id) end return out end
    T.eq(ids(Model.homeNovels(lib(), { [5] = 1700000000000 })), { 1, 2, 4, 5 }, "read on the server or on this device")
    T.eq(ids(Model.homeNovels(lib(), nil)), { 1, 2, 4 })
    -- The hero can be a book being read; an unopened book never is.
    local hero = Model.shelfOrder(Model.homeNovels({
        { id = 3, medium = "book", chapters_read = 0 },
        { id = 4, chapters_read = 0 },
    }, {}), {})
    T.eq(hero.id, 4)
end)

T.it("books: volume 0 chapters order and label like any other", function()
    local list = {
        { id = 10, book = 0, chapter = 1, label = "Prologue", downloaded = true },
        { id = 11, book = 0, chapter = 2, downloaded = true },
    }
    T.eq(Model.ordinal(list, 11), 2)
    T.eq(Model.chapterLabel(list[1]), "Prologue")
    T.eq(Model.chapterLabel(list[2]), "Chapter 2")
end)
