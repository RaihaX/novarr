--[[--
Pure helpers (1.13.0) for the reader's collections: "My lists" (Favourites,
Want to read and the reader's own reading lists), a book series shown as
one entry, and the media the Library shows (novels, books, manga, comics).
No KOReader dependencies (unit tested: spec/test_collections.lua).

Server shapes (API v1):
    GET lists          {lists = [{id, name, count, novel_ids, kind}]}
                       kind: nil (own list) | "want_to_read" | "favourites"
    GET want-to-read   {list, items = [{novel = summary, added_at, started}]}
    GET favourites     the same
]]

local Model = require("novarr.model")

local Collections = {}

-- ---------------------------------------------------------------- media

--- "comic", "manga", "book" or "novel" (a summary without a medium: an older server, a novel).
function Collections.medium(novel)
    if type(novel) ~= "table" then return "novel" end
    return Model.medium(novel)
end

function Collections.isManga(novel)
    return Collections.medium(novel) == "manga"
end

--- Whether a series is read as pages (manga or comic, 1.14.0).
function Collections.isPaged(novel)
    return Model.isPaged(Collections.medium(novel))
end

--- Which media the library holds: {novel = n, book = n, manga = n, comic = n}.
function Collections.mediaCounts(novels)
    local out = { novel = 0, book = 0, manga = 0, comic = 0 }
    for _i, n in ipairs(novels or {}) do
        if type(n) == "table" then
            local m = Collections.medium(n)
            out[m] = out[m] + 1
        end
    end
    return out
end

--- The library's novels of one medium ("novel" | "book" | "manga" | "comic"), in the API's order.
function Collections.byMedium(novels, medium)
    medium = medium or "novel"
    local out = {}
    for _i, n in ipairs(novels or {}) do
        if type(n) == "table" and Collections.medium(n) == medium then table.insert(out, n) end
    end
    return out
end

--[[--
The media the Library can switch between, in order: novels always, then
books and manga when the library holds some, then comics (1.14.0) when
the library holds some and comics_ok (the server has comics and sends
them as CBZ: Session:comicsReadable).
]]
function Collections.libraryMedia(novels, comics_ok)
    local counts = Collections.mediaCounts(novels)
    local out = { "novel" }
    if counts.book > 0 then table.insert(out, "book") end
    if counts.manga > 0 then table.insert(out, "manga") end
    if comics_ok == true and counts.comic > 0 then table.insert(out, "comic") end
    return out
end

-- ---------------------------------------------------------------- series (books)

local function seriesKey(name)
    if type(name) ~= "string" then return nil end
    local k = name:gsub("^%s+", ""):gsub("%s+$", ""):lower()
    if k == "" then return nil end
    return k
end

--[[--
Books grouped by series, keeping the order of the first book of each
series: a list of entries {kind = "book", novel} or {kind = "series", name,
books (in series order), count}. A series of one book stays a plain book.
]]
function Collections.groupSeries(books)
    local groups, order = {}, {}
    for _i, b in ipairs(books or {}) do
        if type(b) == "table" then
            local key = seriesKey(b.series)
            if key then
                if not groups[key] then
                    groups[key] = { name = b.series:gsub("^%s+", ""):gsub("%s+$", ""), books = {} }
                    table.insert(order, { series = key })
                end
                table.insert(groups[key].books, b)
            else
                table.insert(order, { book = b })
            end
        end
    end
    local out = {}
    for _i, o in ipairs(order) do
        if o.book then
            table.insert(out, { kind = "book", novel = o.book })
        else
            local g = groups[o.series]
            if #g.books == 1 then
                table.insert(out, { kind = "book", novel = g.books[1] })
            else
                table.insert(out, { kind = "series", name = g.name, books = Collections.seriesOrder(g.books), count = #g.books })
            end
        end
    end
    return out
end

--[[--
A series' books in reading order: by series_index (books without one last),
then by name. Returns a new list.
]]
function Collections.seriesOrder(books)
    local out = {}
    for i, b in ipairs(books or {}) do out[i] = b end
    table.sort(out, function(a, b)
        local ia, ib = tonumber(a.series_index), tonumber(b.series_index)
        if ia and ib and ia ~= ib then return ia < ib end
        if ia and not ib then return true end
        if ib and not ia then return false end
        return (a.name or ""):lower() < (b.name or ""):lower()
    end)
    return out
end

--- "#2" / "#1.5" for a book's place in its series (nil without one).
function Collections.seriesNumber(book)
    local i = type(book) == "table" and tonumber(book.series_index) or nil
    if not i then return nil end
    if i == math.floor(i) then return "#" .. tostring(math.floor(i)) end
    return "#" .. tostring(i)
end

--- Series entries whose name, or any of whose books, match a query (Model.matchesQuery).
function Collections.filterEntries(entries, matches)
    local out = {}
    for _i, e in ipairs(entries or {}) do
        if e.kind == "book" then
            if matches(e.novel) then table.insert(out, e) end
        else
            local hit = matches({ name = e.name })
            if not hit then
                for _j, b in ipairs(e.books) do
                    if matches(b) then hit = true break end
                end
            end
            if hit then table.insert(out, e) end
        end
    end
    return out
end

-- ---------------------------------------------------------------- lists

Collections.KIND_ORDER = { favourites = 1, want_to_read = 2 }

--[[--
The rows of "My lists": Favourites and Want to read first (when the
server has them), then the reader's own lists by name. features: Api.features.
Each row {id, name, kind, count, novel_ids}.
]]
function Collections.listRows(lists, features)
    features = features or {}
    local builtin, own = {}, {}
    local seen = {}
    for _i, l in ipairs(lists or {}) do
        if type(l) == "table" and l.id then
            local row = { id = tonumber(l.id), name = l.name, kind = l.kind, count = tonumber(l.count) or 0,
                novel_ids = type(l.novel_ids) == "table" and l.novel_ids or {} }
            if l.kind == "favourites" or l.kind == "want_to_read" then
                builtin[l.kind] = row
                seen[l.kind] = true
            else
                table.insert(own, row)
            end
        end
    end
    local out = {}
    -- Built-in lists are made on first use: shown even before the server has one.
    if features.favourites then table.insert(out, builtin.favourites or { kind = "favourites", count = 0, novel_ids = {} }) end
    if features.want_to_read then table.insert(out, builtin.want_to_read or { kind = "want_to_read", count = 0, novel_ids = {} }) end
    table.sort(own, function(a, b) return (a.name or ""):lower() < (b.name or ""):lower() end)
    for _i, l in ipairs(own) do table.insert(out, l) end
    return out
end

--[[--
The series of a list, in its order, from the library: ids not in the
library (removed, or a medium this plugin is not shown) are left out.
Returns novels, the number left out.
]]
function Collections.resolve(novel_ids, library)
    local by_id = {}
    for _i, n in ipairs(library or {}) do
        if type(n) == "table" and n.id then by_id[n.id] = n end
    end
    local out, missing = {}, 0
    for _i, id in ipairs(novel_ids or {}) do
        local n = by_id[tonumber(id)]
        if n then table.insert(out, n) else missing = missing + 1 end
    end
    return out, missing
end

--- The summaries of a want-to-read / favourites answer ({items = [{novel}]}), in order.
function Collections.itemNovels(data)
    local out = {}
    for _i, item in ipairs(type(data) == "table" and data.items or {}) do
        if type(item) == "table" and type(item.novel) == "table" and item.novel.id then
            table.insert(out, item.novel)
        end
    end
    return out
end

--- The reader's own lists with whether each holds novel_id: [{id, name, has}] (built-in lists left out).
function Collections.listChoices(lists, novel_id)
    local out = {}
    for _i, l in ipairs(lists or {}) do
        if type(l) == "table" and l.id and l.kind ~= "favourites" and l.kind ~= "want_to_read" then
            local has = false
            for _j, id in ipairs(l.novel_ids or {}) do
                if tonumber(id) == novel_id then has = true break end
            end
            table.insert(out, { id = tonumber(l.id), name = l.name, has = has })
        end
    end
    table.sort(out, function(a, b) return (a.name or ""):lower() < (b.name or ""):lower() end)
    return out
end

--[[--
A list answer from a write (PUT/DELETE lists/{id}/novels/{n}: {list}) put
into the cached lists (GET lists answer, changed in place). Returns true
when a list was replaced.
]]
function Collections.replaceList(cached, list)
    if type(cached) ~= "table" or type(cached.lists) ~= "table" or type(list) ~= "table" or not list.id then
        return false
    end
    for i, l in ipairs(cached.lists) do
        if tonumber(l.id) == tonumber(list.id) then
            cached.lists[i] = list
            return true
        end
    end
    table.insert(cached.lists, list)
    return true
end

--[[--
A library summary changed after a toggle (want_to_read / favourite), in
the cached library (changed in place). Returns true when found.
]]
function Collections.setFlag(library, novel_id, field, value)
    for _i, n in ipairs(type(library) == "table" and library.novels or {}) do
        if n.id == novel_id then
            n[field] = value and true or false
            return true
        end
    end
    return false
end

-- ---------------------------------------------------------------- Home's More

--[[--
Home's More menu (ui_more.lua), as ids in order: features(name) -> bool
(Session:feature); has_manga: the library holds manga; has_comics
(1.14.0): the library holds comics the server can send (Library > Comics).
]]
function Collections.moreEntries(features, has_manga, has_comics)
    local out = {}
    if features("lists") or features("favourites") then table.insert(out, "lists") end
    table.insert(out, "new_chapters")
    if features("requests") then
        table.insert(out, "request")
        table.insert(out, "my_requests")
        table.insert(out, "notifications")
    end
    if features("stats") then table.insert(out, "stats") end
    if has_manga then table.insert(out, "manga") end
    if has_comics then table.insert(out, "comic") end
    return out
end

return Collections
