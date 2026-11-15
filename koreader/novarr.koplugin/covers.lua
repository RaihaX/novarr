--[[--
Cover image cache: <data folder>/novarr/covers/<hash of URL>.<ext>.

* Files are named by a hash of the URL, so a changed URL is simply fetched
  again (and the old file ages out).
* Written to a temp file and renamed into place.
* Capped (default 60 MB): the oldest files by modification time go first.
* A URL that failed is not tried again in the same session.
* Fetching is done by the caller one cover per scheduled step, only when
  already connected (see ui_coverlist.lua); this module never touches Wi-Fi.

Dependencies (lfs, hash, key, fetch, now) are injected so the logic is testable.
key(url) gives the string hashed for the file name: the session passes one
that maps either server address to the home one, so a cover saved through
one address is found through the other.
fetch(url, path) -> content_type | nil, err  writes the body to `path`.
]]

local Covers = {}
Covers.__index = Covers

Covers.MAX_BYTES = 60 * 1024 * 1024
Covers.EXTENSIONS = { "jpg", "png", "webp", "gif" }

local TYPE_EXT = {
    ["image/jpeg"] = "jpg", ["image/jpg"] = "jpg", ["image/pjpeg"] = "jpg",
    ["image/png"] = "png", ["image/webp"] = "webp", ["image/gif"] = "gif",
}

function Covers.new(opts)
    return setmetatable({
        dir = assert(opts.dir),
        lfs = assert(opts.lfs),
        hash = assert(opts.hash),
        -- url -> the string the file name is a hash of (default: the url itself).
        key = opts.key or function(url) return url end,
        fetch = opts.fetch,
        max_bytes = opts.max_bytes or Covers.MAX_BYTES,
        failed = {},       -- url -> true (this session)
        log = opts.log or function() end,
    }, Covers)
end

--- Extension for a URL's path ("jpg", "png", ...) or nil.
function Covers.extFromUrl(url)
    if type(url) ~= "string" then return nil end
    local ext = url:gsub("[?#].*$", ""):match("%.(%w+)$")
    ext = ext and ext:lower()
    if ext == "jpeg" then ext = "jpg" end
    for _i, e in ipairs(Covers.EXTENSIONS) do
        if e == ext then return ext end
    end
    return nil
end

--- Extension for a Content-Type header value, or nil when it is not an image we keep.
function Covers.extFromType(content_type)
    if type(content_type) ~= "string" then return nil end
    return TYPE_EXT[content_type:lower():match("^%s*([^;%s]+)")]
end

function Covers:base(url)
    return self.dir .. "/" .. self.hash(self.key(url))
end

--- Path of the cached cover for url, or nil.
function Covers:cached(url)
    if type(url) ~= "string" or url == "" then return nil end
    local base = self:base(url)
    for _i, ext in ipairs(Covers.EXTENSIONS) do
        local p = base .. "." .. ext
        if self.lfs.attributes(p, "mode") == "file" then return p end
    end
    return nil
end

--- Whether url should be fetched now (not cached, not failed this session).
function Covers:wants(url)
    return type(url) == "string" and url ~= "" and not self.failed[url] and not self:cached(url)
end

local function mkdirs(lfs, path)
    local acc = path:sub(1, 1) == "/" and "" or "."
    for part in path:gmatch("[^/]+") do
        acc = acc .. "/" .. part
        if lfs.attributes(acc, "mode") ~= "directory" then lfs.mkdir(acc) end
    end
end

--[[--
Fetches one cover into the cache. Returns the path, or nil (and remembers
the failure for this session).
]]
function Covers:download(url)
    if not self:wants(url) then return self:cached(url) end
    mkdirs(self.lfs, self.dir)
    local base = self:base(url)
    local tmp = base .. ".part"
    local content_type, err = self.fetch(url, tmp)
    local size = self.lfs.attributes(tmp, "size") or 0
    local ext = content_type and (Covers.extFromType(content_type) or Covers.extFromUrl(url))
    if not content_type or size == 0 or not ext then
        os.remove(tmp)
        self.failed[url] = true
        self.log("Novarr covers: giving up on " .. tostring(url) .. ": " .. tostring(err or content_type))
        return nil
    end
    local path = base .. "." .. ext
    if not os.rename(tmp, path) then
        os.remove(path)
        if not os.rename(tmp, path) then
            os.remove(tmp)
            self.failed[url] = true
            return nil
        end
    end
    self:trim()
    return path
end

--- Removes the oldest covers (by modification time) until under the cap.
function Covers:trim()
    local lfs = self.lfs
    if lfs.attributes(self.dir, "mode") ~= "directory" then return 0 end
    local files, total = {}, 0
    for name in lfs.dir(self.dir) do
        if name ~= "." and name ~= ".." then
            local p = self.dir .. "/" .. name
            local attr = lfs.attributes(p)
            if attr and attr.mode == "file" then
                table.insert(files, { path = p, size = attr.size or 0, mtime = attr.modification or 0 })
                total = total + (attr.size or 0)
            end
        end
    end
    if total <= self.max_bytes then return 0 end
    table.sort(files, function(a, b)
        if a.mtime == b.mtime then return a.path < b.path end
        return a.mtime < b.mtime
    end)
    local removed = 0
    for _i, f in ipairs(files) do
        if total <= self.max_bytes then break end
        os.remove(f.path)
        total = total - f.size
        removed = removed + 1
    end
    return removed
end

--- Up to two initials for a placeholder ("Ascending the Nine Heavens" -> "AN").
function Covers.initials(name)
    local words = {}
    for w in tostring(name or ""):gmatch("[%w\128-\255][%w\128-\255']*") do
        local lw = w:lower()
        if lw ~= "the" and lw ~= "a" and lw ~= "an" and lw ~= "of" and lw ~= "in" then
            table.insert(words, w)
        end
    end
    if #words == 0 then return "?" end
    local function first(w)
        local c = w:match("^[%z\1-\127\194-\244][\128-\191]*") or w:sub(1, 1)
        return c:upper()
    end
    local out = first(words[1])
    if #words > 1 then out = out .. first(words[#words]) end
    return out
end

return Covers
