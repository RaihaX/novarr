local T = ...
local Covers = require("novarr.covers")
local lfs = require("libkoreader-lfs")

local function hash(s)
    local h = 0
    for i = 1, #s do h = (h * 31 + s:byte(i)) % 4294967296 end
    return string.format("%08x", h)
end

local function writer(content_type, bytes)
    return function(url, path)
        if content_type == "fail" then return nil, "timeout" end
        local f = io.open(path, "wb"); f:write(bytes or "IMAGEDATA"); f:close()
        return content_type
    end
end

T.it("extensions from URL and content type", function()
    T.eq(Covers.extFromUrl("http://h/storage/covers/md/a.JPEG?x=1"), "jpg")
    T.eq(Covers.extFromUrl("http://h/c.webp"), "webp")
    T.eq(Covers.extFromUrl("http://h/c"), nil)
    T.eq(Covers.extFromType("image/png; charset=binary"), "png")
    T.eq(Covers.extFromType("text/html"), nil)
end)

T.it("download caches by URL hash, with the right extension", function()
    local dir = T.tmpdir() .. "/covers"
    local c = Covers.new({ dir = dir, lfs = lfs, hash = hash, fetch = writer("image/png") })
    local url = "http://h/cover"
    T.truthy(c:wants(url))
    local p = c:download(url)
    T.eq(p, dir .. "/" .. hash(url) .. ".png")
    T.eq(c:cached(url), p)
    T.falsy(c:wants(url))
    T.eq(lfs.attributes(p .. ".part", "mode"), nil)
    -- a different URL is a different file
    T.truthy(c:wants("http://h/cover2"))
end)

T.it("a failing URL is not retried in the same session", function()
    local dir = T.tmpdir() .. "/covers"
    local calls = 0
    local c = Covers.new({ dir = dir, lfs = lfs, hash = hash, fetch = function(u, p) calls = calls + 1 return writer("fail")(u, p) end })
    T.eq(c:download("http://h/x.jpg"), nil)
    T.falsy(c:wants("http://h/x.jpg"))
    T.eq(c:download("http://h/x.jpg"), nil)
    T.eq(calls, 1)
    -- a non-image answer (captive portal) also fails
    local c2 = Covers.new({ dir = dir, lfs = lfs, hash = hash, fetch = writer("text/html") })
    T.eq(c2:download("http://h/y"), nil)
end)

T.it("cap evicts the oldest files first", function()
    local dir = T.tmpdir() .. "/covers"
    local c = Covers.new({ dir = dir, lfs = lfs, hash = hash, fetch = writer("image/jpeg", string.rep("x", 400)), max_bytes = 1000 })
    local a = c:download("http://h/a.jpg")
    lfs.touch(a, 100, 100)
    local b = c:download("http://h/b.jpg")
    lfs.touch(b, 200, 200)
    local d = c:download("http://h/d.jpg") -- 1200 bytes > 1000: oldest (a) goes
    T.eq(lfs.attributes(a, "mode"), nil)
    T.truthy(lfs.attributes(b, "mode"))
    T.truthy(lfs.attributes(d, "mode"))
end)

T.it("placeholder initials", function()
    T.eq(Covers.initials("Ascending the Nine Heavens"), "AH")
    T.eq(Covers.initials("The Untouched Archive"), "UA")
    T.eq(Covers.initials("Reborn"), "R")
    T.eq(Covers.initials(""), "?")
    T.eq(Covers.initials("\229\164\169\229\174\152 b"), "\229\164\169B")
end)

T.it("a key function makes one file for the same cover on either server address", function()
    local dir = T.tmpdir() .. "/covers"
    local key = function(url) return (url:gsub("^https://nas%.ts%.net", "http://192.168.1.34:8080")) end
    local fetched = {}
    local c = Covers.new({ dir = dir, lfs = lfs, hash = hash, key = key, fetch = function(url, path)
        table.insert(fetched, url)
        return writer("image/jpeg")(url, path)
    end })
    local p = c:download("https://nas.ts.net/covers/2-md.jpg")
    T.eq(p, dir .. "/" .. hash("http://192.168.1.34:8080/covers/2-md.jpg") .. ".jpg")
    T.eq(c:cached("http://192.168.1.34:8080/covers/2-md.jpg"), p, "found through the home address")
    T.falsy(c:wants("http://192.168.1.34:8080/covers/2-md.jpg"))
    T.eq(#fetched, 1)
end)
