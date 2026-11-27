local T = ...
local C = require("novarr.collections")
local Model = require("novarr.model")

local function ids(list)
    local out = {}
    for _i, n in ipairs(list) do table.insert(out, n.id) end
    return out
end

local LIB = {
    { id = 1, name = "Web Novel", medium = "novel" },
    { id = 2, name = "Dune", medium = "book", series = "Dune", series_index = 1 },
    { id = 3, name = "Old", }, -- an older server: no medium
    { id = 4, name = "A Manga", medium = "manga" },
    { id = 5, name = "Children of Dune", medium = "book", series = "Dune", series_index = 3 },
    { id = 6, name = "Dune Messiah", medium = "book", series = " dune ", series_index = 2 },
    { id = 7, name = "Standalone", medium = "book" },
    { id = 8, name = "Lone in Series", medium = "book", series = "Solo", series_index = 1 },
}

T.it("medium: manga, book, else novel", function()
    T.eq(C.medium(LIB[1]), "novel")
    T.eq(C.medium(LIB[3]), "novel")
    T.eq(C.medium(LIB[4]), "manga")
    T.eq(C.medium(LIB[2]), "book")
    T.eq(C.medium(nil), "novel")
    T.eq(C.mediaCounts(LIB), { novel = 2, book = 5, manga = 1, comic = 0 })
end)

-- ---------------------------------------------------------------- 1.14.0: comics

local COMICS_LIB = {
    { id = 1, name = "Web Novel", medium = "novel" },
    { id = 4, name = "A Manga", medium = "manga" },
    { id = 11, name = "Saga", medium = "comic" },
    { id = 12, name = "Watchmen", medium = "comic", chapters_read = 3 },
    { id = 13, name = "Odd", medium = "graphic" }, -- an unknown medium: a novel
}

T.it("comics: medium, counts, byMedium and isPaged", function()
    T.eq(C.medium(COMICS_LIB[3]), "comic")
    T.eq(C.medium(COMICS_LIB[5]), "novel", "an unknown medium is a novel")
    T.eq(C.mediaCounts(COMICS_LIB), { novel = 2, book = 0, manga = 1, comic = 2 })
    T.eq(ids(C.byMedium(COMICS_LIB, "comic")), { 11, 12 })
    T.eq(ids(Model.byMedium(COMICS_LIB, "comic")), { 11, 12 })
    T.eq(ids(Model.byMedium(COMICS_LIB, "novel")), { 1, 13 }, "comics are not among the novels")
    T.eq(ids(Model.byMedium(COMICS_LIB, "manga")), { 4 }, "nor among the manga")
    T.truthy(C.isPaged(COMICS_LIB[3]))
    T.truthy(C.isPaged(COMICS_LIB[2]))
    T.falsy(C.isPaged(COMICS_LIB[1]))
    T.falsy(C.isManga(COMICS_LIB[3]), "a comic is not a manga")
    T.truthy(Model.hasComics(COMICS_LIB))
    T.falsy(Model.hasComics(LIB))
end)

T.it("library media (1.14.0): Comics last, only when held and the server can send them", function()
    T.eq(C.libraryMedia(COMICS_LIB, true), { "novel", "manga", "comic" })
    T.eq(C.libraryMedia(COMICS_LIB, false), { "novel", "manga" }, "server without comics / CBZ")
    T.eq(C.libraryMedia(COMICS_LIB), { "novel", "manga" }, "not said: no Comics")
    T.eq(C.libraryMedia(LIB, true), { "novel", "book", "manga" }, "no comics held: no Comics")
    T.eq(C.libraryMedia({ COMICS_LIB[1], COMICS_LIB[3] }, true), { "novel", "comic" })
end)

T.it("Home's shelf leaves unstarted comics out, like manga", function()
    T.eq(ids(Model.homeNovels(COMICS_LIB, { [11] = 123 })), { 1, 11, 12, 13 })
    T.eq(ids(Model.homeNovels(COMICS_LIB, nil)), { 1, 12, 13 })
end)

T.it("byMedium keeps the API order; Model.byMedium agrees", function()
    T.eq(ids(C.byMedium(LIB, "novel")), { 1, 3 })
    T.eq(ids(C.byMedium(LIB, "manga")), { 4 })
    T.eq(ids(Model.byMedium(LIB, "novel")), { 1, 3 }, "manga are no longer among the novels")
    T.eq(ids(Model.byMedium(LIB, "manga")), { 4 })
    T.eq(ids(Model.byMedium(LIB, "book")), { 2, 5, 6, 7, 8 })
    T.eq(ids(Model.byMedium(LIB, nil)), { 1, 3 })
    T.truthy(Model.hasManga(LIB))
    T.falsy(Model.hasManga({ LIB[1] }))
end)

T.it("Home's shelf leaves unstarted manga out, like books", function()
    local lib = { { id = 1, medium = "novel" }, { id = 4, medium = "manga" },
        { id = 9, medium = "manga", chapters_read = 2 }, { id = 10, medium = "manga" } }
    T.eq(ids(Model.homeNovels(lib, { [10] = 123 })), { 1, 9, 10 })
end)

T.it("library media: novels always, books and manga when held", function()
    T.eq(C.libraryMedia(LIB), { "novel", "book", "manga" })
    T.eq(C.libraryMedia({ LIB[1] }), { "novel" })
    T.eq(C.libraryMedia({ LIB[1], LIB[4] }), { "novel", "manga" })
    T.eq(C.libraryMedia(nil), { "novel" })
end)

T.it("groupSeries: one entry per series (case and spaces ignored), in order; a lone book stays a book", function()
    local books = C.byMedium(LIB, "book")
    local entries = C.groupSeries(books)
    T.eq(#entries, 3)
    T.eq(entries[1].kind, "series")
    T.eq(entries[1].name, "Dune")
    T.eq(entries[1].count, 3)
    T.eq(ids(entries[1].books), { 2, 6, 5 }, "in series order")
    T.eq(entries[2], { kind = "book", novel = LIB[7] })
    T.eq(entries[3], { kind = "book", novel = LIB[8] }, "a series of one")
end)

T.it("seriesOrder: by index, those without one last, then by name", function()
    local out = C.seriesOrder({
        { id = 1, name = "b", series_index = 2 }, { id = 2, name = "z" }, { id = 3, name = "a" },
        { id = 4, name = "c", series_index = 1.5 }, { id = 5, name = "d", series_index = "1" } })
    T.eq(ids(out), { 5, 4, 1, 3, 2 })
    T.eq(C.seriesNumber({ series_index = 2 }), "#2")
    T.eq(C.seriesNumber({ series_index = 1.5 }), "#1.5")
    T.eq(C.seriesNumber({}), nil)
end)

T.it("filterEntries: a series matches by its name or any of its books", function()
    local entries = C.groupSeries(C.byMedium(LIB, "book"))
    local function q(text) return function(n) return Model.matchesQuery(n, text) end end
    local found = C.filterEntries(entries, q("messiah"))
    T.eq(#found, 1)
    T.eq(found[1].name, "Dune")
    T.eq(#C.filterEntries(entries, q("dune")), 1)
    T.eq(C.filterEntries(entries, q("standalone"))[1].novel.id, 7)
    T.eq(#C.filterEntries(entries, q("")), 3)
end)

T.it("listRows: Favourites, Want to read, then own lists by name; built-ins shown before first use", function()
    local lists = {
        { id = 7, name = "Zed", count = 1, novel_ids = { 1 } },
        { id = 2, name = "Want to read", kind = "want_to_read", count = 2, novel_ids = { 1, 2 } },
        { id = 5, name = "beach", count = 0, novel_ids = {} },
    }
    local rows = C.listRows(lists, { favourites = true, want_to_read = true })
    T.eq(#rows, 4)
    T.eq(rows[1].kind, "favourites")
    T.eq(rows[1].count, 0)
    T.eq(rows[2].id, 2)
    T.eq(rows[2].novel_ids, { 1, 2 })
    T.eq(rows[3].name, "beach")
    T.eq(rows[4].name, "Zed")
    -- A server without favourites: none shown.
    rows = C.listRows(lists, { want_to_read = true })
    T.eq(rows[1].kind, "want_to_read")
    T.eq(#rows, 3)
    T.eq(#C.listRows(nil, {}), 0)
end)

T.it("resolve: the list's order from the library, unknown ids counted", function()
    local novels, missing = C.resolve({ 5, 99, 1, "2" }, LIB)
    T.eq(ids(novels), { 5, 1, 2 })
    T.eq(missing, 1)
end)

T.it("itemNovels: the summaries of a want-to-read answer", function()
    T.eq(ids(C.itemNovels({ items = { { novel = { id = 3 } }, { novel = {} }, { nope = 1 }, { novel = { id = 1 } } } })), { 3, 1 })
    T.eq(C.itemNovels(nil), {})
end)

T.it("listChoices: own lists only, by name, with whether each holds the series", function()
    local lists = {
        { id = 1, name = "Want to read", kind = "want_to_read", novel_ids = { 5 } },
        { id = 3, name = "b", novel_ids = { 5, 6 } },
        { id = 2, name = "A", novel_ids = { 6 } },
        { id = 4, name = "Favourites", kind = "favourites", novel_ids = {} },
    }
    T.eq(C.listChoices(lists, 5), { { id = 2, name = "A", has = false }, { id = 3, name = "b", has = true } })
end)

T.it("replaceList / setFlag change cached answers in place", function()
    local cached = { lists = { { id = 1, name = "a", novel_ids = {} } } }
    T.truthy(C.replaceList(cached, { id = 1, name = "a", novel_ids = { 9 } }))
    T.eq(cached.lists[1].novel_ids, { 9 })
    T.truthy(C.replaceList(cached, { id = 2, name = "new" }))
    T.eq(#cached.lists, 2)
    T.falsy(C.replaceList(nil, { id = 1 }))
    local lib = { novels = { { id = 1, want_to_read = false } } }
    T.truthy(C.setFlag(lib, 1, "want_to_read", true))
    T.eq(lib.novels[1].want_to_read, true)
    T.falsy(C.setFlag(lib, 2, "favourite", true))
end)

T.it("moreEntries: each entry only when the server offers it", function()
    local function features(on) return function(name) return on[name] == true end end
    T.eq(C.moreEntries(features({}), false), { "new_chapters" })
    T.eq(C.moreEntries(features({ lists = true, favourites = true, requests = true, stats = true }), true),
        { "lists", "new_chapters", "request", "my_requests", "notifications", "stats", "manga" })
    T.eq(C.moreEntries(features({ favourites = true }), false), { "lists", "new_chapters" })
end)

T.it("moreEntries (1.14.0): Comics after Manga, only when the library holds readable comics", function()
    local function features(on) return function(name) return on[name] == true end end
    T.eq(C.moreEntries(features({}), true, true), { "new_chapters", "manga", "comic" })
    T.eq(C.moreEntries(features({}), false, true), { "new_chapters", "comic" })
    T.eq(C.moreEntries(features({}), true, false), { "new_chapters", "manga" })
    T.eq(C.moreEntries(features({}), true), { "new_chapters", "manga" }, "1.13.0 callers: no comics")
end)
