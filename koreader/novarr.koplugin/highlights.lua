--[[--
Highlights sent to the server (the website's "bookmarks": an excerpt and an
optional note on a chapter): the pure logic, unit tested. reading.lua hooks
KOReader's annotations, session.lua queues the writes (queue.lua), sync.lua
sends them, ui_highlights.lua lists them.

* clean(text, max): trimmed, whitespace runs inside kept, cut to at most
  `max` characters (UTF-8 aware, never half a character): the server's
  limit is 2000 for the excerpt and the note (BookmarkController).
* change(items): what one KOReader AnnotationsModified notification means
  for Novarr: "added", "removed", "changed" (note, text, style) or "moved"
  (the highlight's ends were moved: items[2] is the state before), or nil
  for anything that is not a highlight (page bookmarks).
* The id map: chapter_id + a hash of the excerpt -> the server's highlight
  id, so a highlight deleted in KOReader can be deleted on the server. At
  most MAP_MAX entries; the oldest go first. Unknown id: nothing is sent.
* Rows and texts for the Highlights list (paged like Discover).
]]

local has_gettext, gettext = pcall(require, "gettext")
local _ = has_gettext and gettext or function(text) return text end

local function template(text, ...)
    local args = { ... }
    return (text:gsub("%%(%d)", function(n) return tostring(args[tonumber(n)]) end))
end

local Highlights = {}

Highlights.MAX_EXCERPT = 2000
Highlights.MAX_NOTE = 2000
Highlights.MAP_MAX = 500
Highlights.PER_PAGE = 50
Highlights.ROW_CHARS = 90

--- Number of UTF-8 characters in s.
local function ulen(s)
    local _n, count = s:gsub("[^\128-\191]", "")
    return count
end
Highlights.ulen = ulen

--- The first n UTF-8 characters of s.
local function uhead(s, n)
    if n <= 0 then return "" end
    local count, cut = 0, #s
    for pos in s:gmatch("()[^\128-\191]") do
        count = count + 1
        if count > n then
            cut = pos - 1
            break
        end
    end
    return s:sub(1, cut)
end
Highlights.uhead = uhead

--[[--
Trimmed text cut to at most max characters (default MAX_EXCERPT); nil when
nothing is left. Line breaks and spaces inside are kept as they are.
]]
function Highlights.clean(text, max)
    if type(text) ~= "string" then return nil end
    local t = text:gsub("^%s+", ""):gsub("%s+$", "")
    if t == "" then return nil end
    max = max or Highlights.MAX_EXCERPT
    if ulen(t) > max then
        t = uhead(t, max):gsub("%s+$", "")
    end
    return t
end

--- The map key of a highlight: "<chapter_id>:<hash of the cleaned excerpt>".
function Highlights.key(chapter_id, excerpt, hash)
    return tostring(chapter_id) .. ":" .. hash(excerpt)
end

--[[--
What an AnnotationsModified notification (KOReader's `items` table: the
item first; index_modified > 0 for an added item, < 0 for a removed one;
a second item for a highlight whose ends moved) means. Returns kind, item,
before (the item before a move). Only highlights (items with a drawer)
count; page bookmarks return nil.
]]
function Highlights.change(items)
    if type(items) ~= "table" then return nil end
    local item = items[1]
    if type(item) ~= "table" or not item.drawer then return nil end
    local index = tonumber(items.index_modified)
    if index and index < 0 then return "removed", item end
    if index and index > 0 and (items.nb_highlights_added or 0) > 0 then return "added", item end
    local before = items[2]
    if type(before) == "table" and before.drawer and before.text ~= item.text then
        return "moved", item, before
    end
    return "changed", item
end

-- ---------------------------------------------------------------- the id map

--[[--
Remembers a server id for a key (map: key -> {id, at}). Keeps at most
max (MAP_MAX) entries, dropping the oldest. Returns the map.
]]
function Highlights.remember(map, key, id, now, max)
    id = tonumber(id)
    if not id then return map end
    map[key] = { id = id, at = now or 0 }
    max = max or Highlights.MAP_MAX
    local n = 0
    for _k in pairs(map) do n = n + 1 end
    while n > max do
        local oldest, oldest_at
        for k, v in pairs(map) do
            local at = type(v) == "table" and tonumber(v.at) or -1
            if not oldest_at or at < oldest_at or (at == oldest_at and k < oldest) then
                oldest, oldest_at = k, at
            end
        end
        map[oldest] = nil
        n = n - 1
    end
    return map
end

--- The server id for a key, or nil.
function Highlights.lookup(map, key)
    local v = map and map[key]
    return type(v) == "table" and tonumber(v.id) or nil
end

--- Forgets a key; or with id_only, every key with that id. Returns how many went.
function Highlights.forget(map, key, id)
    local n = 0
    if key and map[key] then
        map[key] = nil
        n = 1
    end
    if id then
        for k, v in pairs(map) do
            if type(v) == "table" and tonumber(v.id) == tonumber(id) then
                map[k] = nil
                n = n + 1
            end
        end
    end
    return n
end

-- ---------------------------------------------------------------- the list

--- A new, empty list view of a novel's highlights.
function Highlights.newView(novel_id)
    return { novel_id = novel_id, items = {}, page = 0, has_more = false, loaded = false, seen = {} }
end

--- Appends one GET /highlights answer {highlights, page, has_more}. Returns how many were added.
function Highlights.addPage(view, data)
    local added = 0
    for _i, h in ipairs(type(data) == "table" and type(data.highlights) == "table" and data.highlights or {}) do
        local id = type(h) == "table" and tonumber(h.id)
        if id and not view.seen[id] and type(h.excerpt) == "string" then
            view.seen[id] = true
            table.insert(view.items, {
                id = id,
                chapter_id = tonumber(h.chapter_id),
                chapter_label = type(h.chapter_label) == "string" and h.chapter_label or nil,
                excerpt = h.excerpt,
                note = type(h.note) == "string" and h.note ~= "" and h.note or nil,
                created_at = h.created_at,
            })
            added = added + 1
        end
    end
    view.page = tonumber(data and data.page) or ((view.page or 0) + 1)
    view.has_more = type(data) == "table" and data.has_more == true and added > 0
    view.loaded = true
    return added
end

--- Removes a highlight from the view (deleted). Returns true when it was there.
function Highlights.removeFromView(view, id)
    for i, h in ipairs(view.items) do
        if h.id == id then
            table.remove(view.items, i)
            return true
        end
    end
    return false
end

-- One line of text: whitespace runs (line breaks too) become one space.
local function oneLine(s)
    return (s:gsub("%s+", " "):gsub("^ ", ""):gsub(" $", ""))
end

local function short(s, n)
    s = oneLine(s)
    if ulen(s) <= n then return s end
    return uhead(s, n - 1):gsub("%s+$", "") .. "…"
end

--- The row: “excerpt…” and, when there is one, " · Note: …"; the chapter label on the right.
function Highlights.rowText(h)
    local text = "\226\128\156" .. short(h.excerpt, Highlights.ROW_CHARS) .. "\226\128\157"
    if h.note then text = text .. " \194\183 " .. template(_("Note: %1"), short(h.note, 40)) end
    return text, h.chapter_label and short(h.chapter_label, 28) or nil
end

--- The full text shown on a tap: chapter, excerpt, note.
function Highlights.detailText(h)
    local lines = {}
    if h.chapter_label then table.insert(lines, h.chapter_label) table.insert(lines, "") end
    table.insert(lines, "\226\128\156" .. h.excerpt .. "\226\128\157")
    if h.note then
        table.insert(lines, "")
        table.insert(lines, template(_("Note: %1"), h.note))
    end
    return table.concat(lines, "\n")
end

--[[--
Which chapter of a group file a position belongs to: starts (chapter start
y positions, or pages), the position (same unit), ids (chapter ids in file
order). The last chapter whose start is at or before the position.
]]
function Highlights.chapterAt(starts, pos, ids)
    local found = 1
    for i, s in ipairs(starts or {}) do
        if s and pos and s <= pos then found = i end
    end
    return ids and ids[found] or nil
end

return Highlights
