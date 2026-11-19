--[[--
Joined chapters: pure logic (no KOReader modules, unit tested).

The reader opens chapters joined in groups ("blocks") of the novel's reading
order, so going from one chapter to the next inside a block is an ordinary
page turn. Chapters stay stored one per file; a group file is a derived,
disposable document built from them.

    ordinal   position among the chapters the server has downloaded, in
              reading order (Model.ordinal: "ch 969 of 1,147")
    block     ordinals 1..N are block 1, N+1..2N block 2, ...
    run       the contiguous stored chapters of a block that include the
              chapter to open (a chapter that is not stored, or has no
              readable text, breaks a run; so does a chapter the server
              does not have yet, between two it has: the reader then stops
              there and says so instead of paging past it)
    layout    where each chapter of the open group file starts (page in page
              mode, y position in both modes), read from the rendered document
]]

local Groups = {}

Groups.DEFAULT_SIZE = 20
Groups.MIN_SIZE = 2
Groups.MAX_SIZE = 200
-- Bumped whenever the group file's markup changes: files built by another
-- version are rebuilt.
Groups.VERSION = 1

--- A sane group size from a setting value.
function Groups.size(value)
    local n = math.floor(tonumber(value) or Groups.DEFAULT_SIZE)
    if n < Groups.MIN_SIZE then n = Groups.MIN_SIZE end
    if n > Groups.MAX_SIZE then n = Groups.MAX_SIZE end
    return n
end

-- ---------------------------------------------------------------- blocks

--[[--
Ordinals of a chapter list: ids (list of chapter ids the server has
downloaded, in reading order), ord (id -> ordinal) and gaps (set of
ordinals that come right after a chapter the server does not have yet:
gaps[k] when a missing chapter lies between ids[k - 1] and ids[k]). A run
never spans a gap (Groups.run), so the reader reaches the missing chapter
and says so instead of paging past it.
]]
function Groups.ordinals(list)
    local ids, ord, gaps = {}, {}, {}
    local missing = false
    for _i, c in ipairs(list or {}) do
        if type(c) == "table" and c.downloaded and c.id ~= nil and not ord[c.id] then
            table.insert(ids, c.id)
            ord[c.id] = #ids
            if missing and #ids > 1 then gaps[#ids] = true end
            missing = false
        elseif type(c) == "table" and not c.downloaded then
            missing = true
        end
    end
    return ids, ord, gaps
end

--- Block number of an ordinal (1-based).
function Groups.block(ordinal, n)
    return math.floor((ordinal - 1) / n) + 1
end

--- First and last ordinal of a block.
function Groups.blockRange(block, n)
    return (block - 1) * n + 1, block * n
end

--[[--
The run to open for the chapter at ordinal `target`: the contiguous chapters
around it, inside its block, for which available(id) is true. ids is the
ordinal list (Groups.ordinals); gaps (optional, from Groups.ordinals): a
run never spans a chapter the server does not have yet. Returns a list of
ids in reading order and the ordinal of its first chapter, or nil when the
target itself is not available.
]]
function Groups.run(ids, target, n, available, gaps)
    if not target or not ids[target] or not available(ids[target]) then return nil end
    gaps = gaps or {}
    local first, last = Groups.blockRange(Groups.block(target, n), n)
    if last > #ids then last = #ids end
    local lo, hi = target, target
    while lo - 1 >= first and not gaps[lo] and available(ids[lo - 1]) do lo = lo - 1 end
    while hi + 1 <= last and not gaps[hi + 1] and available(ids[hi + 1]) do hi = hi + 1 end
    local out = {}
    for i = lo, hi do table.insert(out, ids[i]) end
    return out, lo
end

--[[--
How a stored chapter opens: "joined" (in its group file) or "single" (its
own file, as before joined chapters existed) plus the reason. setting: the
join_chapters setting (nil = default on); list: the cached chapter list.
]]
function Groups.openMode(setting, list, chapter_id)
    if setting == false then return "single", "setting off" end
    if type(list) ~= "table" then return "single", "no_list" end
    local _ids, ord = Groups.ordinals(list)
    if not ord[chapter_id] then return "single", "no_ordinal" end
    return "joined"
end

-- ---------------------------------------------------------------- files

--- "group_<block>_<N>.html": the group file of a block (FAT-safe).
function Groups.fileName(block, n)
    return string.format("group_%d_%d.html", block, n)
end

--- Its manifest: "group_<block>_<N>.json".
function Groups.manifestName(block, n)
    return string.format("group_%d_%d.json", block, n)
end

--- Parses a group file name; returns block, n (numbers) or nil.
function Groups.parseFileName(name)
    if type(name) ~= "string" then return nil end
    local b, n = name:match("^group_(%d+)_(%d+)%.html$")
    if not b then return nil end
    return tonumber(b), tonumber(n)
end

--[[--
Rebuild decision for a group file. manifest: what the file holds ({version,
parts}) or nil; parts: what it should hold now (one token per chapter, in
order, see Session); file_ok: the HTML file exists. Returns:
    "same"     nothing to do (the file must not be rewritten)
    "append"   the file holds a strict prefix: rewrite, earlier chapters keep
               their exact content, so positions in them stay valid
    "rebuild"  anything else (missing, corrupt, older build, different run)
]]
function Groups.decide(manifest, parts, file_ok)
    if not file_ok or type(manifest) ~= "table" or manifest.version ~= Groups.VERSION
            or type(manifest.parts) ~= "table" or #manifest.parts == 0 then
        return "rebuild"
    end
    local old = manifest.parts
    if #old > #parts then return "rebuild" end
    for i = 1, #old do
        if old[i] ~= parts[i] then return "rebuild" end
    end
    if #old == #parts then return "same" end
    return "append"
end

--- Chapter ids found in a group file's HTML, in document order.
function Groups.idsInHtml(html)
    local out = {}
    if type(html) ~= "string" then return out end
    for id in html:gmatch('<div class="novarr%-chapter" id="novarr%-ch%-(%d+)">') do
        table.insert(out, tonumber(id))
    end
    return out
end

-- ---------------------------------------------------------------- positions

--[[--
Index of the chapter shown at `cur` (a page number, or a y position): the
last chapter whose start is <= cur. starts: list of numbers in document
order (nil entries are treated as unknown and skipped). Returns 1 when cur
is before every start.
]]
function Groups.chapterAt(starts, cur)
    local found = 1
    for i, s in ipairs(starts) do
        if s and cur and s <= cur then found = i end
    end
    return found
end

--- Whole percent (0..100) of `pos` through a chapter spanning [start_y, end_y).
function Groups.percentIn(pos, start_y, end_y)
    if not pos or not start_y or not end_y or end_y <= start_y then return 0 end
    local frac = (pos - start_y) / (end_y - start_y)
    if frac ~= frac then return 0 end
    return math.max(0, math.min(100, math.floor(frac * 100)))
end

--[[--
Page (page mode) whose screen shows `percent` through a chapter at its top.
tops: tops[k] = y of the top of page first + k - 1, for the chapter's pages;
start_y/end_y: the chapter's span. The percent was floored when it was
measured (from the top of a page), so the page holding the point just under
the next whole percent is the page it was measured on.
]]
function Groups.pageForPercent(first, tops, start_y, end_y, percent)
    percent = tonumber(percent) or 0
    if percent <= 0 or #tops == 0 then return first end
    local y = start_y + math.min(100, percent + 0.99) * (end_y - start_y) / 100
    local k = 1
    for j, top in ipairs(tops) do
        if top <= y then k = j end
    end
    return first + k - 1
end

--[[--
Chapters finished by a move from one position to the next (what "paging
through the last page of a chapter" means here). Only a forward move of at
most one screen counts; for it, every chapter from the old one up to the one
before the new one whose end was on the screen before the move is finished.
Any other move (backward, a jump through go-to/skim/TOC/bookmarks/menu, a
re-layout) finishes nothing.

    m = {
      from, to       chapter index before and after the move
      prev, cur      position before and after (page in page mode, y in scroll mode)
      ends           ends[i] = last page (page mode) / end y (scroll mode) of chapter i
      view           pages per screen (page mode) / screen height in px (scroll mode)
      scroll         true in scroll mode
    }
Returns a list of chapter indexes (possibly empty).
]]
function Groups.finished(m)
    local out = {}
    if not m.from or not m.to or m.to <= m.from or not m.prev or not m.cur then return out end
    local delta = m.cur - m.prev
    if delta <= 0 then return out end
    local view = m.view or 1
    if m.scroll then
        -- A screenful (plus a little slack for rounding); the previous screen
        -- covered [prev, prev + view].
        if delta > view + 2 then return out end
        for i = m.from, m.to - 1 do
            local e = m.ends[i]
            if e and e <= m.prev + view + 1 then table.insert(out, i) end
        end
    else
        -- One page turn: 1 page (2 in two-page mode); the previous screen
        -- showed pages prev .. prev + view - 1.
        if delta > view then return out end
        for i = m.from, m.to - 1 do
            local e = m.ends[i]
            if e and e <= m.prev + view - 1 then table.insert(out, i) end
        end
    end
    return out
end

-- ---------------------------------------------------------------- build

--[[--
Finds (and builds or refreshes when needed) the group file for a chapter.

    store        a Store
    o = {
      novel_id, chapter_id, list (cached chapter list, reading order), n,
      novel_name, lang,
      can_write(bytes) -> bool    free-space check before writing (optional)
      bad          set {[id]=true} of stored chapters without readable text
                   (filled in here; kept by the caller between calls)
      now()        ms (optional)
    }

Returns g = {path, block, n, ids, first, decision, bytes} or nil, reason:
"no_list", "no_ordinal" (the chapter is not in the list: open it on its
own), "not_stored" (the chapter itself is not on the device), "no_space",
"write_failed".
]]
function Groups.prepare(store, o)
    local ChapterDoc = require("novarr.chapterdoc")
    if type(o.list) ~= "table" then return nil, "no_list" end
    local ids, ord, gaps = Groups.ordinals(o.list)
    local target = ord[o.chapter_id]
    if not target then return nil, "no_ordinal" end
    local n = Groups.size(o.n)
    local bad = o.bad or {}
    local function available(id)
        return not bad[id] and store:hasChapter(o.novel_id, id)
    end
    local block = Groups.block(target, n)
    local path = store:groupPath(o.novel_id, block, n)
    -- At most one retry per chapter whose stored file turns out unreadable.
    for _attempt = 1, n + 1 do
        local run, first = Groups.run(ids, target, n, available, gaps)
        if not run then return nil, "not_stored" end
        local parts = {}
        for _i, id in ipairs(run) do
            local entry = store:chapterEntry(o.novel_id, id)
            local cpath = store:chapterPath(o.novel_id, id)
            local size = store.lfs.attributes(cpath, "size") or 0
            table.insert(parts, string.format("%d:%s:%d", id, entry.file, size))
        end
        local manifest = store:readGroupManifest(o.novel_id, block, n)
        local decision = Groups.decide(manifest, parts, store.lfs.attributes(path, "mode") == "file")
        local g = { path = path, block = block, n = n, ids = run, first = first, decision = decision }
        if decision == "same" then
            g.bytes = store.lfs.attributes(path, "size") or 0
            return g
        end
        local chapters, broken = {}, nil
        for _i, id in ipairs(run) do
            local body = ChapterDoc.bodyOf(store:readFile(store:chapterPath(o.novel_id, id)))
            if not body then broken = id break end
            table.insert(chapters, { id = id, body = body })
        end
        if broken then
            bad[broken] = true
            if broken == o.chapter_id then return nil, "not_stored" end
        else
            local title = ChapterDoc.groupTitle(o.novel_name, first, first + #run - 1)
            local html = ChapterDoc.buildGroup(title, chapters, o.lang)
            if o.can_write and not o.can_write(#html) then return nil, "no_space" end
            local ok = store:writeGroup(o.novel_id, block, n, html, {
                version = Groups.VERSION, novel_id = o.novel_id, block = block, n = n,
                ids = run, parts = parts, first = first, title = title,
                built_at = o.now and o.now() or nil,
            })
            if not ok then return nil, "write_failed" end
            g.bytes = #html
            return g
        end
    end
    return nil, "not_stored"
end

-- ---------------------------------------------------------------- removal

--[[--
Remove-after-reading in joined mode. stored: list of {id, block} (block nil
when the chapter's place in the reading order is unknown: never removed);
state(id) -> read (bool); keep: how many read chapters before the open block
stay (so paging back still works); current_block: the block being opened.
Removes read chapters of earlier blocks except the `keep` latest of them;
never touches the open block, later blocks, or unread/part-read chapters.
Each stored row may carry `ord` (ordinal) used for "latest". Returns ids.
]]
function Groups.removalCandidates(stored, state, keep, current_block)
    keep = keep or 2
    local before = {}
    for _i, s in ipairs(stored or {}) do
        if s.block and s.block < current_block and state(s.id) then
            table.insert(before, s)
        end
    end
    table.sort(before, function(a, b)
        if (a.ord or 0) == (b.ord or 0) then return a.id > b.id end
        return (a.ord or 0) > (b.ord or 0)
    end)
    local out = {}
    for i = keep + 1, #before do table.insert(out, before[i].id) end
    table.sort(out)
    return out
end

--- Whether a group file of `block` is kept while `current_block` is open (itself and both neighbours).
function Groups.keepGroupFile(block, current_block)
    return current_block ~= nil and math.abs(block - current_block) <= 1
end

return Groups
