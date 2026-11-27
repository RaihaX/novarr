--[[--
Builds the small self-contained HTML document KOReader opens for a chapter,
and maps file paths to and from (novel id, chapter id).

Pure Lua: no KOReader modules, so it can be unit tested on its own.

File layout (relative to the Novarr storage root):
    chapters/<novelId>/<chapterId>_<slug>.html
    chapters/<novelId>/group_<block>_<N>.html   joined chapters (see groups.lua)
The ids come first and only from the path, so a lost index never stops the
plugin from recognising one of its chapters. The slug only makes the file
browser and history readable; it is FAT32-safe and kept short.
]]

local ChapterDoc = {}

ChapterDoc.SLUG_MAX_BYTES = 60

local HTML_ESCAPES = {
    ["&"] = "&amp;",
    ["<"] = "&lt;",
    [">"] = "&gt;",
    ['"'] = "&quot;",
    ["'"] = "&#39;",
}

--- Escapes text for use in HTML element content and attribute values.
function ChapterDoc.escape(text)
    if text == nil then return "" end
    text = tostring(text)
    -- Drop C0 control characters except tab/newline: they are invalid in HTML
    -- and crengine renders them as boxes.
    text = text:gsub("[%z\1-\8\11\12\14-\31\127]", "")
    return (text:gsub("[&<>\"']", HTML_ESCAPES))
end

-- Trims a string to at most max_bytes without cutting a UTF-8 sequence.
local function utf8Truncate(s, max_bytes)
    if #s <= max_bytes then return s end
    local cut = max_bytes
    -- Step back while the byte after the cut is a continuation byte (10xxxxxx).
    while cut > 0 do
        local b = s:byte(cut + 1)
        if not b or b < 0x80 or b >= 0xC0 then break end
        cut = cut - 1
    end
    return s:sub(1, cut)
end
ChapterDoc.utf8Truncate = utf8Truncate

--[[--
Makes a string safe as (part of) a file name on FAT32/exFAT (Kindle's
/mnt/us), Windows and Linux: no \ / : * ? " < > |, no control characters,
no leading/trailing dots or spaces, runs of spaces collapsed, at most
max_bytes bytes (UTF-8 safe). May return "" when nothing usable remains.
]]
function ChapterDoc.fatSafe(name, max_bytes)
    max_bytes = max_bytes or ChapterDoc.SLUG_MAX_BYTES
    if name == nil then return "" end
    local s = tostring(name)
    s = s:gsub("[%z\1-\31\127]", " ")
    s = s:gsub('[\\/:%*%?"<>|]', " ")
    s = s:gsub("%s+", " ")
    s = s:gsub("^[%s%.]+", "")
    s = utf8Truncate(s, max_bytes)
    s = s:gsub("[%s%.]+$", "")
    return s
end

--[[--
File name for a stored chapter: "<chapterId>_<slug>.html" or "<chapterId>.html".
ext: "cbz" for a manga chapter (1.13.0: its pages in a comic archive), else html.
]]
function ChapterDoc.fileName(chapter_id, label, ext)
    local id = tostring(math.floor(tonumber(chapter_id) or 0))
    local suffix = "." .. (ext == "cbz" and "cbz" or "html")
    local slug = ChapterDoc.fatSafe(label or "", ChapterDoc.SLUG_MAX_BYTES)
    if slug == "" then
        return id .. suffix
    end
    return id .. "_" .. slug .. suffix
end

--- Whether a stored chapter file is a manga chapter's comic archive (.cbz).
function ChapterDoc.isCbz(path)
    return type(path) == "string" and path:lower():sub(-4) == ".cbz"
end

--- FAT-safe file name for a whole-novel EPUB: "<name>.epub" (max ~120 bytes).
function ChapterDoc.epubFileName(name)
    name = tostring(name or "")
    name = name:gsub("%.[Ee][Pp][Uu][Bb]%s*$", "")
    name = ChapterDoc.fatSafe(name, 120)
    if name == "" then name = "novel" end
    return name .. ".epub"
end

--- Parses a chapter file name; returns the chapter id (number) or nil.
function ChapterDoc.idFromFileName(name)
    if type(name) ~= "string" then return nil end
    local id = name:match("^(%d+)%.html$") or name:match("^(%d+)_.*%.html$")
        or name:match("^(%d+)%.cbz$") or name:match("^(%d+)_.*%.cbz$")
    return id and tonumber(id) or nil
end

--[[--
Recognises a chapter file path. `root` is the storage root (no trailing
slash). Returns novelId, chapterId (numbers) or nil. Only paths directly in
<root>/chapters/<digits>/ count, so a user's own HTML files never match.
]]
function ChapterDoc.idsFromPath(path, root)
    if type(path) ~= "string" or type(root) ~= "string" or root == "" then
        return nil
    end
    local prefix = root .. "/chapters/"
    if path:sub(1, #prefix) ~= prefix then
        return nil
    end
    local rest = path:sub(#prefix + 1)
    local novel, file = rest:match("^(%d+)/([^/]+)$")
    if not novel then return nil end
    local chapter = ChapterDoc.idFromFileName(file)
    if not chapter then return nil end
    return tonumber(novel), chapter
end

--[[--
Recognises a group file path (<root>/chapters/<novelId>/group_<block>_<N>.html).
Returns novelId, block, N (numbers) or nil.
]]
function ChapterDoc.groupFromPath(path, root)
    if type(path) ~= "string" or type(root) ~= "string" or root == "" then
        return nil
    end
    local prefix = root .. "/chapters/"
    if path:sub(1, #prefix) ~= prefix then return nil end
    local novel, b, n = path:sub(#prefix + 1):match("^(%d+)/group_(%d+)_(%d+)%.html$")
    if not novel then return nil end
    return tonumber(novel), tonumber(b), tonumber(n)
end

--- Whether a path is one of Novarr's documents (a chapter or a group file) under root.
function ChapterDoc.isNovarrPath(path, root)
    return ChapterDoc.idsFromPath(path, root) ~= nil or ChapterDoc.groupFromPath(path, root) ~= nil
end

--- Path of a chapter file (given its file name) under root.
function ChapterDoc.path(root, novel_id, file_name)
    return string.format("%s/chapters/%d/%s", root, novel_id, file_name)
end

--- The document title: "Novel name — chapter label".
function ChapterDoc.title(novel_name, label)
    local parts = {}
    if novel_name and novel_name ~= "" then table.insert(parts, novel_name) end
    if label and label ~= "" then table.insert(parts, label) end
    if #parts == 0 then return "Chapter" end
    return table.concat(parts, " \226\128\148 ") -- U+2014 em dash
end

local CSS = table.concat({
    "p { text-indent: 1.2em; margin: 0 0 0.5em 0; }",
    "h2 { text-align: center; margin: 0.5em 0 1em 0; page-break-before: avoid; }",
    ".novarr-novel { text-align: center; font-size: 0.85em; margin: 0; text-indent: 0; }",
}, "\n")

local function cleanLang(lang)
    lang = ChapterDoc.fatSafe(lang or "en", 16):gsub("[^%w%-]", "")
    if lang == "" then lang = "en" end
    return lang
end

local function chapterLabel(chapter)
    local label = chapter.label
    if not label or label == "" then
        label = chapter.chapter and ("Chapter " .. tostring(chapter.chapter)) or "Chapter"
    end
    return label
end

--[[--
The body of a chapter, as lines of HTML: the novel's name, the chapter
heading and its paragraphs. Shared by single chapter files and group files.
Uses only the plain-text paragraphs (never the server's HTML) and escapes
everything.
]]
function ChapterDoc.bodyLines(chapter)
    local out = {}
    if chapter.novel_name and chapter.novel_name ~= "" then
        table.insert(out, '<p class="novarr-novel">' .. ChapterDoc.escape(chapter.novel_name) .. "</p>")
    end
    table.insert(out, "<h2>" .. ChapterDoc.escape(chapterLabel(chapter)) .. "</h2>")
    local paragraphs = chapter.paragraphs
    if type(paragraphs) == "table" then
        for _i, p in ipairs(paragraphs) do
            if type(p) == "string" and p:find("%S") then
                table.insert(out, "<p>" .. ChapterDoc.escape(p) .. "</p>")
            end
        end
    end
    return out
end

local function head(lang, title, css)
    return {
        "<!DOCTYPE html>",
        '<html lang="' .. lang .. '">',
        "<head>",
        '<meta charset="utf-8"/>',
        "<title>" .. ChapterDoc.escape(title) .. "</title>",
        "<style>" .. css .. "</style>",
        "</head>",
        "<body>",
    }
end

--[[--
Builds the chapter HTML from an API chapter object
({novel_name, label, chapter, paragraphs = {...}}). `lang` defaults to "en".
]]
function ChapterDoc.build(chapter, lang)
    local out = head(cleanLang(lang), ChapterDoc.title(chapter.novel_name, chapterLabel(chapter)), CSS)
    for _i, line in ipairs(ChapterDoc.bodyLines(chapter)) do table.insert(out, line) end
    table.insert(out, "</body>")
    table.insert(out, "</html>")
    table.insert(out, "")
    return table.concat(out, "\n")
end

--[[--
The body lines of a stored chapter file (one built by ChapterDoc.build):
everything between <body> and </body>, or nil when the file does not look
like one (or has no text), so it is left out of group files.
]]
function ChapterDoc.bodyOf(html)
    if type(html) ~= "string" then return nil end
    local body = html:match("\n<body>\n(.-)\n</body>\n</html>")
    if not body or not body:find("<h2>", 1, true) then return nil end
    -- Nothing but the heading and the novel's name: no text to read.
    if not body:find("\n<p>", 1, true) then return nil end
    return body
end

-- Group files: every chapter starts on a new page; the hidden <h1> gives
-- crengine's table of contents a named top level (the chapter <h2>s below).
local GROUP_CSS = CSS .. "\n" .. table.concat({
    ".novarr-chapter { page-break-before: always; }",
    "h1.novarr-group { display: none; }",
}, "\n")

--- Title of a group file: "Novel name · chapters 961–980".
function ChapterDoc.groupTitle(novel_name, first, last)
    local range = first == last and string.format("chapter %d", first)
        or string.format("chapters %d\226\128\147%d", first, last) -- U+2013 en dash
    if novel_name and novel_name ~= "" then
        return novel_name .. " \194\183 " .. range -- U+00B7 middle dot
    end
    return range:sub(1, 1):upper() .. range:sub(2)
end

--[[--
Builds a group document: chapters = list of {id, body} in reading order
(body = ChapterDoc.bodyOf of the stored file). Each chapter is a top-level
<div class="novarr-chapter" id="novarr-ch-<id>"> under <body>. The output is
deterministic: the same chapters give the same bytes, and adding chapters at
the end leaves everything from the first chapter <div> up to the end of the
previous last chapter unchanged.
]]
function ChapterDoc.buildGroup(title, chapters, lang)
    local out = head(cleanLang(lang), title, GROUP_CSS)
    table.insert(out, '<h1 class="novarr-group">' .. ChapterDoc.escape(title) .. "</h1>")
    for _i, c in ipairs(chapters) do
        table.insert(out, string.format('<div class="novarr-chapter" id="novarr-ch-%d">', math.floor(tonumber(c.id) or 0)))
        table.insert(out, c.body)
        table.insert(out, "</div>")
    end
    table.insert(out, "</body>")
    table.insert(out, "</html>")
    table.insert(out, "")
    return table.concat(out, "\n")
end

return ChapterDoc
