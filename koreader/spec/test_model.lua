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
