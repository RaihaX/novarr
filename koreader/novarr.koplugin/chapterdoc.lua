--[[--
Builds the small self-contained HTML document KOReader opens for a chapter,
and maps file paths to and from (novel id, chapter id).

Pure Lua: no KOReader modules, so it can be unit tested on its own.

File layout (relative to the Novarr storage root):
    chapters/<novelId>/<chapterId>_<slug>.html
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

--- File name for a stored chapter: "<chapterId>_<slug>.html" or "<chapterId>.html".
function ChapterDoc.fileName(chapter_id, label)
    local id = tostring(math.floor(tonumber(chapter_id) or 0))
    local slug = ChapterDoc.fatSafe(label or "", ChapterDoc.SLUG_MAX_BYTES)
    if slug == "" then
        return id .. ".html"
    end
    return id .. "_" .. slug .. ".html"
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

--[[--
Builds the chapter HTML from an API chapter object
({novel_name, label, chapter, paragraphs = {...}}). Uses only the plain-text
paragraphs (never the server's HTML) and escapes everything.
`lang` defaults to "en".
]]
function ChapterDoc.build(chapter, lang)
    lang = ChapterDoc.fatSafe(lang or "en", 16):gsub("[^%w%-]", "")
    if lang == "" then lang = "en" end
    local label = chapter.label
    if not label or label == "" then
        label = chapter.chapter and ("Chapter " .. tostring(chapter.chapter)) or "Chapter"
    end
    local out = {
        "<!DOCTYPE html>",
        '<html lang="' .. lang .. '">',
        "<head>",
        '<meta charset="utf-8"/>',
        "<title>" .. ChapterDoc.escape(ChapterDoc.title(chapter.novel_name, label)) .. "</title>",
        "<style>" .. CSS .. "</style>",
        "</head>",
        "<body>",
    }
    if chapter.novel_name and chapter.novel_name ~= "" then
        table.insert(out, '<p class="novarr-novel">' .. ChapterDoc.escape(chapter.novel_name) .. "</p>")
    end
    table.insert(out, "<h2>" .. ChapterDoc.escape(label) .. "</h2>")
    local paragraphs = chapter.paragraphs
    if type(paragraphs) == "table" then
        for _i, p in ipairs(paragraphs) do
            if type(p) == "string" and p:find("%S") then
                table.insert(out, "<p>" .. ChapterDoc.escape(p) .. "</p>")
            end
        end
    end
    table.insert(out, "</body>")
    table.insert(out, "</html>")
    table.insert(out, "")
    return table.concat(out, "\n")
end

return ChapterDoc
