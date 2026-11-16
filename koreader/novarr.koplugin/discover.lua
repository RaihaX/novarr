--[[--
Discover (finding novels on the sources Novarr scrapes, and adding them):
the pure logic behind ui_discover.lua, unit tested.

* sources(data): the server's GET /discover answer, checked and normalised;
  nothing about the sources is hard-coded here: their names, lists and the
  default come from the server.
* A "view" is what the screen shows: a source, a list (popular, new, ...,
  or search with a query), the items loaded so far, the last page number and
  has_more. addPage() appends the next page; items already shown (same URL)
  are not repeated (a "random" list or a source changing between pages).
* Texts for an item (its info line, details) and for each outcome of
  browsing and adding.
]]

-- gettext inside KOReader; identity in the unit tests.
local has_gettext, gettext = pcall(require, "gettext")
local _ = has_gettext and gettext or function(text) return text end

local function template(text, ...)
    local args = { ... }
    return (text:gsub("%%(%d)", function(n) return tostring(args[tonumber(n)]) end))
end

local Discover = {}

Discover.PER_PAGE = 20
Discover.CHECK_MARK = "\226\156\147" -- ✓

--[[--
The sources from GET /discover: a list of {id, name, host, default, lists =
{{id, name}...}} in the server's order, entries without an id or lists left
out. Returns the list (possibly empty) and the default source (the one
marked default, else the first).
]]
function Discover.sources(data)
    local out, default = {}, nil
    for _i, s in ipairs(type(data) == "table" and type(data.sources) == "table" and data.sources or {}) do
        if type(s) == "table" and type(s.id) == "string" and s.id ~= "" and type(s.lists) == "table" then
            local lists = {}
            for _j, l in ipairs(s.lists) do
                if type(l) == "table" and type(l.id) == "string" and l.id ~= "" then
                    table.insert(lists, { id = l.id, name = type(l.name) == "string" and l.name or l.id })
                end
            end
            if #lists > 0 then
                local src = { id = s.id, name = type(s.name) == "string" and s.name ~= "" and s.name or s.id,
                    host = s.host, default = s.default == true, lists = lists }
                table.insert(out, src)
                if src.default and not default then default = src end
            end
        end
    end
    return out, default or out[1]
end

--- The source with this id in a list from sources(), or nil.
function Discover.find(sources, id)
    for _i, s in ipairs(sources or {}) do
        if s.id == id then return s end
    end
    return nil
end

--- Whether the source offers search.
function Discover.hasSearch(source)
    for _i, l in ipairs(source and source.lists or {}) do
        if l.id == "search" then return true end
    end
    return false
end

--- The lists one can browse (everything but search), in the server's order.
function Discover.browseLists(source)
    local out = {}
    for _i, l in ipairs(source and source.lists or {}) do
        if l.id ~= "search" then table.insert(out, l) end
    end
    return out
end

--- The display name of a list id on a source ("Popular"), else the id.
function Discover.listName(source, list_id)
    for _i, l in ipairs(source and source.lists or {}) do
        if l.id == list_id then return l.name end
    end
    return list_id
end

--[[--
The list to show first on a source: `wanted` when the source offers it and it
is not search, else its first browse list, else nil (a search-only source:
the screen asks for a search).
]]
function Discover.initialList(source, wanted)
    local lists = Discover.browseLists(source)
    for _i, l in ipairs(lists) do
        if l.id == wanted then return l.id end
    end
    return lists[1] and lists[1].id or nil
end

-- ---------------------------------------------------------------- views

--[[--
A new, empty view: source id, list id ("search" with a query), page 0.
]]
function Discover.newView(source_id, list_id, query)
    return { source = source_id, list = list_id, query = query, items = {}, page = 0, has_more = false,
        loaded = false, seen = {} }
end

--- The browse request parameters for the next page of a view.
function Discover.nextRequest(view, per_page)
    return {
        type = view.list,
        source = view.source,
        q = view.list == "search" and view.query or nil,
        page = (view.page or 0) + 1,
        per_page = per_page or Discover.PER_PAGE,
    }
end

-- An item as the plugin keeps it (only the fields it uses, types checked).
local function cleanItem(it)
    if type(it) ~= "table" then return nil end
    local title = type(it.title) == "string" and it.title:gsub("^%s+", ""):gsub("%s+$", "") or ""
    local url = type(it.url) == "string" and it.url or ""
    if title == "" or url == "" then return nil end
    local function str(v) return type(v) == "string" and v ~= "" and v or nil end
    return {
        title = title,
        author = str(it.author),
        cover_url = str(it.cover_url),
        description = str(it.description),
        status = str(it.status),
        chapters = tonumber(it.chapters),
        url = url,
        source = str(it.source),
        in_library = it.in_library == true,
        novel_id = tonumber(it.novel_id),
    }
end

--[[--
Appends one browse answer {items, page, has_more} to a view. Items without
a title or URL, and URLs already in the view, are skipped. Returns the
number of items added and the index (in view.items) of the first one.
]]
function Discover.addPage(view, data)
    local added, first = 0, nil
    for _i, raw in ipairs(type(data) == "table" and type(data.items) == "table" and data.items or {}) do
        local it = cleanItem(raw)
        if it and not view.seen[it.url] then
            view.seen[it.url] = true
            table.insert(view.items, it)
            added = added + 1
            first = first or #view.items
        end
    end
    view.page = tonumber(data and data.page) or ((view.page or 0) + 1)
    -- A page that brought nothing new ends the list, whatever has_more says (no endless "More").
    view.has_more = type(data) == "table" and data.has_more == true and added > 0
    view.loaded = true
    return added, first
end

--- Marks the item(s) with this URL as in the library (after adding). Returns how many.
function Discover.markInLibrary(view, url, novel_id)
    local n = 0
    for _i, it in ipairs(view and view.items or {}) do
        if it.url == url then
            it.in_library = true
            it.novel_id = tonumber(novel_id) or it.novel_id
            n = n + 1
        end
    end
    return n
end

-- ---------------------------------------------------------------- texts

--- 3207 -> "3,207".
local function thousands(n)
    local s = tostring(math.floor(tonumber(n) or 0))
    local out = s:reverse():gsub("(%d%d%d)", "%1,"):reverse():gsub("^,", "")
    return out
end
Discover.thousands = thousands

--[[--
The short info line under a result: "Ongoing · 3,207 chapters" and the
in-library mark ("✓ In library") first when it is in the library. nil when
there is nothing to say.
]]
function Discover.infoLine(it)
    local parts = {}
    if it.in_library then table.insert(parts, Discover.CHECK_MARK .. " " .. _("In library")) end
    if it.status then table.insert(parts, it.status) end
    if it.chapters and it.chapters > 0 then
        table.insert(parts, it.chapters == 1 and _("1 chapter") or template(_("%1 chapters"), thousands(it.chapters)))
    end
    if #parts == 0 then return nil end
    return table.concat(parts, " \194\183 ")
end

--- The right-hand text of a result in the plain (no covers) list: "✓" or the chapter count.
function Discover.mandatory(it)
    if it.in_library then return Discover.CHECK_MARK end
    if it.chapters and it.chapters > 0 then return thousands(it.chapters) end
    return nil
end

--- The details shown when a result is tapped (title, author, status, chapters, where, synopsis).
function Discover.detailsText(it, source_name)
    local lines = { it.title }
    if it.author then table.insert(lines, template(_("By %1"), it.author)) end
    local facts = {}
    if it.status then table.insert(facts, it.status) end
    if it.chapters and it.chapters > 0 then
        table.insert(facts, it.chapters == 1 and _("1 chapter") or template(_("%1 chapters"), thousands(it.chapters)))
    end
    if source_name then table.insert(facts, template(_("on %1"), source_name)) end
    if #facts > 0 then table.insert(lines, table.concat(facts, " \194\183 ")) end
    if it.in_library then table.insert(lines, _("Already in your library.")) end
    table.insert(lines, "")
    table.insert(lines, it.description or _("No synopsis available."))
    return table.concat(lines, "\n")
end

--[[--
What a failed browse/search means for the user. e: the API error. Returns
the text, or nil for "offline" (no answer at all: the caller uses the
session's usual message).
]]
function Discover.browseErrorText(e)
    if type(e) ~= "table" then return nil end
    if e.kind == "server" and e.status == 502 then
        return _("That source didn't answer. Try again or pick another source.")
    end
    if e.kind == "offline" and type(e.message) == "string" and e.message:lower():find("timeout", 1, true) then
        return _("The search took too long and was given up. Try again or pick another source.")
    end
    return nil
end

--[[--
What adding a novel led to. data/e: Api:addNovel's answer. Returns kind
("added" | "already" | "refused" | "busy" | "error"), text (nil for
"error": the caller uses the session's usual message).
]]
function Discover.addOutcome(data, e)
    if data then
        if data.already_in_library then
            return "already", _("This novel is already in your library.")
        end
        return "added", _("Added to your library. Novarr is fetching its chapter list from the source now; the chapters show up as they arrive.")
    end
    if type(e) == "table" then
        if e.kind == "invalid" then
            return "refused", e.message and template(_("The server didn't accept this novel: %1"), e.message)
                or _("The server didn't accept this novel.")
        end
        if e.kind == "server" and e.status == 503 then
            return "busy", e.server_message or _("Another novel is being added right now. Try again in a moment.")
        end
    end
    return "error", nil
end

--- One line saying what is being fetched: "Searching NovelPing for "sword"…" / "Loading Popular from NovelPing…".
function Discover.statusText(view, source)
    local name = source and source.name or view.source
    if view.list == "search" then
        return template(_("Searching %1 for \"%2\"…"), name, view.query or "")
    end
    return template(_("Loading %1 from %2…"), Discover.listName(source, view.list), name)
end

--- The text of the blocking "Searching…" notice for a request (older servers).
function Discover.loadingText(view, source)
    if view.list == "search" then
        return Discover.statusText(view, source) .. "\n" .. _("This can take up to two minutes.")
    end
    return Discover.statusText(view, source)
end

--- A search query from what was typed: trimmed, at most 100 characters (the server's limit), nil when empty.
function Discover.cleanQuery(text)
    if type(text) ~= "string" then return nil end
    local q = text:gsub("^%s+", ""):gsub("%s+$", ""):gsub("%s+", " ")
    if q == "" then return nil end
    if #q > 100 then
        q = q:sub(1, 100)
        -- Never cut a UTF-8 character in half.
        q = q:gsub("[\192-\255][\128-\191]*$", "")
    end
    return q
end

return Discover
