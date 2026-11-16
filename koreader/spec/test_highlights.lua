local T = ...
local H = require("novarr.highlights")

local function hash(s) return "h" .. #s .. s:sub(1, 3) end

T.it("clean: trimmed, inside kept, empty -> nil", function()
    T.eq(H.clean("  Hello,\n  world.  "), "Hello,\n  world.")
    T.eq(H.clean("   "), nil)
    T.eq(H.clean(nil), nil)
    T.eq(H.clean(42), nil)
end)

T.it("clean: cut to the limit in characters, never half a UTF-8 character", function()
    T.eq(H.clean(string.rep("a", 2500)), string.rep("a", 2000))
    local e = "\195\169" -- é (2 bytes)
    local long = string.rep(e, 2001)
    local out = H.clean(long)
    T.eq(H.ulen(out), 2000)
    T.eq(#out, 4000)
    T.eq(H.clean("abc def", 4), "abc", "trailing space after the cut is trimmed")
    T.eq(H.clean(string.rep(e, 5), 3), e .. e .. e)
    T.eq(H.ulen("a\226\128\156b"), 3)
end)

T.it("key: chapter id and a hash of the excerpt", function()
    T.eq(H.key(187423, "Some text", hash), "187423:h9Som")
end)

T.it("change: added, removed, changed, moved; bookmarks ignored", function()
    local hl = { drawer = "lighten", text = "x" }
    T.eq({ H.change({ hl, nb_highlights_added = 1, index_modified = 3 }) }, { "added", hl })
    T.eq({ H.change({ hl, nb_highlights_added = -1, index_modified = -3 }) }, { "removed", hl })
    T.eq({ H.change({ hl, nb_notes_added = -1, index_modified = -2 }) }, { "removed", hl }, "a highlight with a note")
    T.eq({ H.change({ hl, nb_highlights_added = -1, nb_notes_added = 1 }) }, { "changed", hl }, "note added")
    T.eq({ H.change({ hl, nb_highlights_added = 1, nb_notes_added = -1 }) }, { "changed", hl }, "note removed")
    T.eq({ H.change({ hl }) }, { "changed", hl }, "note edited, style, colour")
    local before = { drawer = "lighten", text = "older text" }
    T.eq({ H.change({ hl, before }) }, { "moved", hl, before })
    T.eq({ H.change({ hl, { drawer = "lighten", text = "x" } }) }, { "changed", hl }, "same text: nothing moved")
    T.eq(H.change({ { page = "xp", text = "in Chapter 1" }, index_modified = 4 }), nil, "page bookmark")
    T.eq(H.change(nil), nil)
    T.eq(H.change({}), nil)
end)

T.it("id map: remember, lookup, forget by key or id", function()
    local map = {}
    H.remember(map, "1:a", 10, 100)
    H.remember(map, "2:b", "11", 101)
    H.remember(map, "3:c", nil, 102)
    T.eq(H.lookup(map, "1:a"), 10)
    T.eq(H.lookup(map, "2:b"), 11)
    T.eq(H.lookup(map, "3:c"), nil, "no id, nothing remembered")
    T.eq(H.lookup(nil, "1:a"), nil)
    T.eq(H.forget(map, "1:a"), 1)
    T.eq(H.lookup(map, "1:a"), nil)
    H.remember(map, "4:d", 11, 103)
    T.eq(H.forget(map, nil, 11), 2, "every key with that id")
    T.eq(next(map), nil)
end)

T.it("id map: bounded, the oldest go first", function()
    local map = {}
    for i = 1, 8 do H.remember(map, "k" .. i, i, i * 10, 5) end
    local n = 0
    for _k in pairs(map) do n = n + 1 end
    T.eq(n, 5)
    T.eq(H.lookup(map, "k1"), nil)
    T.eq(H.lookup(map, "k3"), nil)
    T.eq(H.lookup(map, "k4"), 4)
    T.eq(H.lookup(map, "k8"), 8)
    -- Remembering a key again refreshes it.
    H.remember(map, "k4", 4, 1000, 5)
    H.remember(map, "k9", 9, 90, 5)
    T.eq(H.lookup(map, "k4"), 4)
    T.eq(H.lookup(map, "k5"), nil)
end)

T.it("chapterAt: the chapter whose start comes last at or before the position", function()
    local ids = { 101, 102, 103 }
    T.eq(H.chapterAt({ 0, 5000, 9000 }, 4999, ids), 101)
    T.eq(H.chapterAt({ 0, 5000, 9000 }, 5000, ids), 102)
    T.eq(H.chapterAt({ 0, 5000, 9000 }, 20000, ids), 103)
    T.eq(H.chapterAt({ 10, 5000, 9000 }, 3, ids), 101, "before the first start")
    T.eq(H.chapterAt({ 1, 1, 4 }, 2, ids), 102, "two chapters starting on the same page: the later one")
end)

T.it("list view: pages appended, duplicates skipped, has_more only with new items", function()
    local v = H.newView(42)
    local n = H.addPage(v, { highlights = {
        { id = 3, novel_id = 42, chapter_id = 9, chapter_label = "Chapter 9: Rain", excerpt = "Rain fell.", note = "nice" },
        { id = 2, chapter_id = 8, excerpt = "Wind.", note = "" },
        { id = "bad" }, { id = 5 },
    }, page = 1, has_more = true })
    T.eq(n, 2)
    T.eq(v.items[1].note, "nice")
    T.eq(v.items[2].note, nil, "an empty note is no note")
    T.truthy(v.has_more)
    T.eq(H.addPage(v, { highlights = { { id = 3, excerpt = "Rain fell." } }, page = 2, has_more = true }), 0)
    T.falsy(v.has_more, "a page with nothing new ends the list")
    T.eq(v.page, 2)
    T.truthy(H.removeFromView(v, 2))
    T.falsy(H.removeFromView(v, 2))
    T.eq(#v.items, 1)
end)

T.it("row and detail texts", function()
    local h = { excerpt = "The rain\nfell  softly.", note = "Lovely line", chapter_label = "Chapter 12: Rain" }
    local text, right = H.rowText(h)
    T.eq(text, "\226\128\156The rain fell softly.\226\128\157 \194\183 Note: Lovely line")
    T.eq(right, "Chapter 12: Rain")
    local long = { excerpt = string.rep("word ", 50) }
    local t2, r2 = H.rowText(long)
    T.eq(r2, nil)
    T.truthy(H.ulen(t2) <= H.ROW_CHARS + 2)
    T.contains(t2, "…")
    local d = H.detailText(h)
    T.contains(d, "Chapter 12: Rain")
    T.contains(d, "The rain\nfell  softly.")
    T.contains(d, "Note: Lovely line")
    T.notContains(H.detailText({ excerpt = "x" }), "Note")
end)
