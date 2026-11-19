local T = ...
local Discover = require("novarr.discover")

local DATA = { sources = {
    { id = "novelarrow", name = "NovelPing (novelarrow)", host = "novelping.com", default = true,
      lists = { { id = "popular", name = "Popular" }, { id = "new", name = "New" }, { id = "random", name = "Random" },
                { id = "completed", name = "Completed" }, { id = "search", name = "Search" } } },
    { id = "empirenovel", name = "empirenovel.com", default = false, lists = { { id = "search", name = "Search" } } },
    { id = "novelfull", name = "novelfull.com", default = false, lists = { { id = "search", name = "Search" } } },
} }

T.it("sources: kept in the server's order, default found", function()
    local list, default = Discover.sources(DATA)
    T.eq(#list, 3)
    T.eq(list[1].id, "novelarrow")
    T.eq(default.id, "novelarrow")
    T.eq(#list[1].lists, 5)
end)

T.it("sources: the default is the one the server marks, else the first", function()
    local d = { sources = { { id = "a", lists = { { id = "search" } } }, { id = "b", default = true, lists = { { id = "new" } } } } }
    local _l, def = Discover.sources(d)
    T.eq(def.id, "b")
    d.sources[2].default = nil
    _l, def = Discover.sources(d)
    T.eq(def.id, "a")
end)

T.it("sources: bad entries skipped, nothing hard-coded", function()
    local list, def = Discover.sources({ sources = { { name = "no id" }, { id = "x", lists = {} }, "junk",
        { id = "y", lists = { { id = "popular" }, { name = "no id" } } } } })
    T.eq(#list, 1)
    T.eq(list[1].id, "y")
    T.eq(list[1].name, "y", "name falls back to the id")
    T.eq(list[1].lists[1].name, "popular")
    T.eq(def.id, "y")
    local none, nodef = Discover.sources(nil)
    T.eq(#none, 0)
    T.eq(nodef, nil)
    T.eq(#Discover.sources({ sources = "x" }), 0)
end)

T.it("lists: browse lists without search, search detected, initial list", function()
    local list = Discover.sources(DATA)
    local np, nf = list[1], list[3]
    local ids = {}
    for _i, l in ipairs(Discover.browseLists(np)) do table.insert(ids, l.id) end
    T.eq(ids, { "popular", "new", "random", "completed" })
    T.truthy(Discover.hasSearch(np))
    T.truthy(Discover.hasSearch(nf))
    T.eq(#Discover.browseLists(nf), 0)
    T.eq(Discover.initialList(np), "popular")
    T.eq(Discover.initialList(np, "completed"), "completed")
    T.eq(Discover.initialList(np, "search"), "popular", "never starts on search")
    T.eq(Discover.initialList(np, "nope"), "popular")
    T.eq(Discover.initialList(nf, "popular"), nil, "search-only source: ask for a search")
    T.eq(Discover.listName(np, "new"), "New")
    T.eq(Discover.listName(np, "zzz"), "zzz")
    T.eq(Discover.find(list, "novelfull").name, "novelfull.com")
    T.eq(Discover.find(list, "nope"), nil)
end)

local function item(n, extra)
    local it = { title = "Novel " .. n, url = "https://src/n" .. n, author = "A" .. n, chapters = n * 100,
        status = "Ongoing", in_library = false }
    for k, v in pairs(extra or {}) do it[k] = v end
    return it
end

T.it("paging: next request, pages appended, has_more", function()
    local v = Discover.newView("novelarrow", "popular")
    T.eq(Discover.nextRequest(v), { type = "popular", source = "novelarrow", page = 1, per_page = 20 })
    local added, first = Discover.addPage(v, { items = { item(1), item(2) }, page = 1, has_more = true })
    T.eq({ added, first }, { 2, 1 })
    T.truthy(v.has_more)
    T.eq(Discover.nextRequest(v).page, 2)
    added, first = Discover.addPage(v, { items = { item(3) }, page = 2, has_more = false })
    T.eq({ added, first }, { 1, 3 })
    T.falsy(v.has_more)
    T.eq(#v.items, 3)
    T.truthy(v.loaded)
end)

T.it("paging: duplicates (same URL) skipped; a page with nothing new ends the list", function()
    local v = Discover.newView("novelarrow", "random")
    Discover.addPage(v, { items = { item(1), item(2) }, page = 1, has_more = true })
    local added = Discover.addPage(v, { items = { item(2), item(1) }, page = 2, has_more = true })
    T.eq(added, 0)
    T.falsy(v.has_more, "no endless More")
    T.eq(#v.items, 2)
end)

T.it("paging: items without title or URL dropped, fields typed", function()
    local v = Discover.newView("s", "search", "x")
    Discover.addPage(v, { items = { { title = "  ", url = "u" }, { title = "T", url = "" }, { title = " Ok ", url = "u1",
        chapters = "12", novel_id = "4", in_library = true, author = "" }, "junk" }, page = 1 })
    T.eq(#v.items, 1)
    T.eq(v.items[1].title, "Ok")
    T.eq(v.items[1].chapters, 12)
    T.eq(v.items[1].novel_id, 4)
    T.eq(v.items[1].author, nil)
    T.truthy(v.items[1].in_library)
    T.eq(Discover.nextRequest(v), { type = "search", source = "s", q = "x", page = 2, per_page = 20 })
end)

T.it("paging: an empty answer (page past the end, list not offered)", function()
    local v = Discover.newView("novelfull", "popular")
    local added = Discover.addPage(v, { items = {}, page = 1, has_more = false })
    T.eq(added, 0)
    T.truthy(v.loaded)
    T.eq(#v.items, 0)
end)

T.it("in library: marked by URL after adding", function()
    local v = Discover.newView("s", "popular")
    Discover.addPage(v, { items = { item(1), item(2) }, page = 1 })
    T.eq(Discover.markInLibrary(v, "https://src/n2", 42), 1)
    T.truthy(v.items[2].in_library)
    T.eq(v.items[2].novel_id, 42)
    T.falsy(v.items[1].in_library)
    T.eq(Discover.markInLibrary(v, "https://other", 1), 0)
end)

T.it("texts: info line, mandatory, details", function()
    T.eq(Discover.infoLine(item(32)), "Ongoing · 3,200 chapters")
    T.eq(Discover.infoLine({ title = "x", in_library = true, chapters = 1 }), "✓ In library · 1 chapter")
    T.eq(Discover.infoLine({ title = "x" }), nil)
    T.eq(Discover.mandatory(item(32)), "3,200")
    T.eq(Discover.mandatory(item(32, { in_library = true })), "✓")
    T.eq(Discover.mandatory({}), nil)
    local d = Discover.detailsText(item(5, { description = "A tale." }), "novelfull.com")
    T.contains(d, "Novel 5\nBy A5\nOngoing · 500 chapters · on novelfull.com")
    T.contains(d, "A tale.")
    T.contains(Discover.detailsText({ title = "x", in_library = true }), "Already in your library.")
    T.contains(Discover.detailsText({ title = "x" }), "No synopsis available.")
end)

T.it("browse errors: 502 and timeouts in plain words; offline left to the caller", function()
    T.eq(Discover.browseErrorText({ kind = "server", status = 502 }),
        "That source didn't answer. Try again or pick another source.")
    T.contains(Discover.browseErrorText({ kind = "offline", message = "timeout" }), "took too long")
    T.eq(Discover.browseErrorText({ kind = "offline", message = "connection refused" }), nil)
    T.eq(Discover.browseErrorText({ kind = "server", status = 500 }), nil)
    T.eq(Discover.browseErrorText(nil), nil)
end)

T.it("add outcomes: added (one sentence about the chapter list), already, 422, 503, other", function()
    local k, text = Discover.addOutcome({ novel = { id = 1 }, already_in_library = false })
    T.eq(k, "added")
    T.contains(text, "chapter list")
    k, text = Discover.addOutcome({ novel = { id = 1 }, already_in_library = true })
    T.eq(k, "already")
    T.eq(text, "This novel is already in your library.")
    k, text = Discover.addOutcome(nil, { kind = "invalid", status = 422, message = "The url field must be a valid URL." })
    T.eq(k, "refused")
    T.contains(text, "The url field must be a valid URL.")
    k, text = Discover.addOutcome(nil, { kind = "server", status = 503, server_message = "Busy, try again." })
    T.eq({ k, text }, { "busy", "Busy, try again." })
    k, text = Discover.addOutcome(nil, { kind = "server", status = 503 })
    T.contains(text, "Try again in a moment")
    k, text = Discover.addOutcome(nil, { kind = "offline" })
    T.eq({ k, text }, { "error", nil })
end)

T.it("Discover refused (403, e.g. on the public address): one plain sentence, for lists and for adding", function()
    local text = "Discover isn't available on this address. Use it on your home network."
    T.eq(Discover.browseErrorText({ kind = "forbidden", status = 403, message = "This page isn't available on the public address." }), text)
    local k, t = Discover.addOutcome(nil, { kind = "forbidden", status = 403, message = "x" })
    T.eq({ k, t }, { "forbidden", text })
end)

T.it("add outcome: a duplicate title (409) names the novel already in the library", function()
    local k, text = Discover.addOutcome(nil, { kind = "duplicate", status = 409,
        duplicate = { id = 42, title = "Shadow Slave", source = "novelfull.com" } }, "Shadow Slave (NP)")
    T.eq(k, "duplicate")
    T.eq(text, "Shadow Slave is already in your library.")
    k, text = Discover.addOutcome(nil, { kind = "duplicate", status = 409, duplicate = {} }, "Lord of the Mysteries")
    T.eq(text, "Lord of the Mysteries is already in your library.")
end)

T.it("queries: trimmed, inner spaces collapsed, empty is nil, at most 100 bytes on a character boundary", function()
    T.eq(Discover.cleanQuery("  shadow   slave "), "shadow slave")
    T.eq(Discover.cleanQuery("   "), nil)
    T.eq(Discover.cleanQuery(nil), nil)
    T.eq(#Discover.cleanQuery(string.rep("a", 150)), 100)
    local q = Discover.cleanQuery(string.rep("a", 99) .. "é")
    T.eq(q, string.rep("a", 99), "the cut é is dropped whole")
end)

T.it("loading text names the source and the query", function()
    local list = Discover.sources(DATA)
    T.contains(Discover.loadingText(Discover.newView("novelfull", "search", "sword"), list[3]), 'Searching novelfull.com for "sword"')
    T.contains(Discover.loadingText(Discover.newView("novelfull", "search", "sword"), list[3]), "two minutes")
    T.eq(Discover.loadingText(Discover.newView("novelarrow", "new"), list[1]), "Loading New from NovelPing (novelarrow)…")
end)

T.it("status text (the Searching… row): one line, no wait warning", function()
    local list = Discover.sources(DATA)
    T.eq(Discover.statusText(Discover.newView("novelfull", "search", "sword"), list[3]), 'Searching novelfull.com for "sword"…')
    T.eq(Discover.statusText(Discover.newView("novelarrow", "new"), list[1]), "Loading New from NovelPing (novelarrow)…")
end)
