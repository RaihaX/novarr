local T = ...
local Api = require("novarr.api")
local Json = require("novarr.json")

-- URL normalisation table (mirrors the iOS app's ServerURL.normalize).
local cases = {
    { "192.168.1.34", "http://192.168.1.34" },
    { "  192.168.1.34:8080  ", "http://192.168.1.34:8080" },
    { "192.168.1.34:8080/", "http://192.168.1.34:8080" },
    { "http://192.168.1.34:8080/api/v1", "http://192.168.1.34:8080" },
    { "http://192.168.1.34:8080/api/v1/", "http://192.168.1.34:8080" },
    { "http://192.168.1.34:8080/api", "http://192.168.1.34:8080" },
    { "HTTPS://Novarr.Example.TS.net", "https://novarr.example.ts.net" },
    { "https://host/novarr/", "https://host/novarr" },
    { "https://host/novarr/API/V1", "https://host/novarr" },
    { "host///", "http://host" },
    { "http://host/?x=1#frag", "http://host" },
    { "[fd7a::1]:8137", "http://[fd7a::1]:8137" },
    { "localhost:08137", "http://localhost:8137" },
    { "", nil },
    { "   ", nil },
    { "ftp://host", nil },
    { "http://", nil },
    { "http://host:abc", nil },
    { "http://host:70000", nil },
    { "two words", nil },
    { "http://user:pw@host", nil },
    { "http://ho$t", nil },
}
for _, c in ipairs(cases) do
    T.it("normalizeUrl " .. string.format("%q", c[1]), function()
        T.eq(Api.normalizeUrl(c[1]), c[2])
    end)
end

-- A fake transport that records requests and returns canned answers.
local function fake(responses)
    local log = {}
    local transport = function(req)
        table.insert(log, req)
        local r = responses[#log] or responses.default
        if type(r) == "function" then return r(req) end
        if r == "offline" then return nil, "connection refused" end
        return r
    end
    return transport, log
end

local function json(status, value, headers)
    return { status = status, headers = headers or {}, body = Json.encode(value) }
end

T.it("request sends Accept, User-Agent, JSON body and parses JSON", function()
    local transport, log = fake({ json(200, { success = true }) })
    local api = Api.new({ base_url = "http://h", transport = transport })
    local ok = api:sendWrite({ kind = "progress", chapter_id = 5, progress = 40 }, 1234)
    T.truthy(ok)
    T.eq(log[1].method, "POST")
    T.eq(log[1].url, "http://h/api/v1/chapters/5/progress")
    T.eq(log[1].headers["Accept"], "application/json")
    T.eq(log[1].headers["Content-Type"], "application/json")
    T.contains(log[1].headers["User-Agent"], "NovarrKOReader/")
    local body = Json.decode(log[1].body)
    T.eq(body, { progress = 40, age = 1234 })
end)

T.it("progress with read sends read=true", function()
    local transport, log = fake({ json(200, { success = true }) })
    local api = Api.new({ base_url = "http://h", transport = transport })
    api:sendWrite({ kind = "progress", chapter_id = 5, progress = 100, read = true }, 0)
    T.eq(Json.decode(log[1].body), { progress = 100, read = true, age = 0 })
end)

T.it("set_read and bulk_read bodies", function()
    local transport, log = fake({ json(200, { success = true }), json(200, { success = true }) })
    local api = Api.new({ base_url = "http://h", transport = transport })
    api:sendWrite({ kind = "set_read", chapter_id = 9, read = false }, 7)
    api:sendWrite({ kind = "bulk_read", bulk = { read = true, scope = "up_to", novel_id = 2, anchor_id = 30 } }, 8)
    T.eq(log[1].url, "http://h/api/v1/chapters/9/read")
    T.eq(Json.decode(log[1].body), { read = false, age = 7 })
    T.eq(log[2].url, "http://h/api/v1/chapters/bulk-read")
    T.eq(Json.decode(log[2].body), { read = true, scope = "up_to", novel_id = 2, anchor_id = 30, age = 8 })
end)

T.it("ETag: sends If-None-Match and reports 304", function()
    local transport, log = fake({ { status = 304, headers = { etag = '"abc"' }, body = "" } })
    local api = Api.new({ base_url = "http://h", transport = transport })
    local data, e, info = api:novels('"abc"')
    T.eq(data, nil)
    T.eq(e, nil)
    T.truthy(info.not_modified)
    T.eq(log[1].headers["If-None-Match"], '"abc"')
end)

T.it("returns the ETag of a 200", function()
    local transport = fake({ json(200, { novels = {} }, { etag = '"v2"' }) })
    local api = Api.new({ base_url = "http://h", transport = transport })
    local data, _, info = api:novels()
    T.truthy(data)
    T.eq(info.etag, '"v2"')
    T.truthy(info.body:find("novels", 1, true))
end)

local classify = {
    { "offline", "offline" },
    { { status = 404, headers = {}, body = '{"message":"No query results"}' }, "not_found" },
    { { status = 422, headers = {}, body = '{"message":"The read field is required."}' }, "invalid" },
    { { status = 500, headers = {}, body = "oops" }, "server" },
    { { status = 503, headers = {}, body = "" }, "server" },
    { { status = 200, headers = {}, body = "<html>Captive portal</html>" }, "server" },
}
for i, c in ipairs(classify) do
    T.it("classifies failure " .. i .. " as " .. c[2], function()
        local api = Api.new({ base_url = "http://h", transport = fake({ c[1] }) })
        local data, e = api:chapter(1)
        T.eq(data, nil)
        T.eq(e.kind, c[2])
    end)
end

T.it("422 keeps the server message", function()
    local api = Api.new({ base_url = "http://h", transport = fake({ classify[3][1] }) })
    local _, e = api:chapter(1)
    T.eq(e.message, "The read field is required.")
end)

T.it("meta: accepts Novarr, rejects others", function()
    local api = Api.new({ base_url = "http://h", transport = fake({
        json(200, { app = "novarr", api_version = 1 }),
        json(200, { app = "something" }),
        { status = 404, headers = {}, body = "<html>nope</html>" },
        { status = 200, headers = {}, body = "<html>router</html>" },
        "offline",
        json(200, { app = "Novarr", api_version = 0 }),
    }) })
    T.truthy(api:meta())
    local _, e2 = api:meta(); T.eq(e2.kind, "not_novarr")
    local _, e3 = api:meta(); T.eq(e3.kind, "not_novarr")
    local _, e4 = api:meta(); T.eq(e4.kind, "not_novarr")
    local _, e5 = api:meta(); T.eq(e5.kind, "offline")
    local _, e6 = api:meta(); T.eq(e6.kind, "not_novarr")
end)

T.it("simulated offline never calls the transport", function()
    local transport, log = fake({ json(200, {}) })
    local api = Api.new({ base_url = "http://h", transport = transport })
    Api.simulate_offline = true
    local data, e = api:home()
    Api.simulate_offline = false
    T.eq(data, nil)
    T.eq(e.kind, "offline")
    T.eq(#log, 0)
end)

T.it("retryable classes", function()
    T.truthy(Api.isRetryable({ kind = "offline" }))
    T.truthy(Api.isRetryable({ kind = "server" }))
    T.falsy(Api.isRetryable({ kind = "not_found" }))
    T.falsy(Api.isRetryable({ kind = "invalid" }))
end)

T.it("JSON null becomes nil", function()
    local v = Json.decode('{"a":null,"b":[1,null],"c":{"d":null,"e":1}}')
    T.eq(v.a, nil)
    T.eq(v.c.d, nil)
    T.eq(v.c.e, 1)
    T.eq(Json.decode("not json"), nil)
    T.eq(Json.decode(""), nil)
    T.eq(Json.encode(Json.array({})), "[]")
end)

-- ---------------------------------------------------------------- discover, add, check

T.it("browse: query string encoded, long timeouts, type/source/q/page/per_page", function()
    local transport, log = fake({ json(200, { items = {}, page = 2, has_more = false }) })
    local api = Api.new({ base_url = "http://h", transport = transport })
    local data = api:browse({ type = "search", source = "novelfull", q = "Shadow & Slave/é", page = 2, per_page = 20 })
    T.truthy(data)
    T.eq(log[1].method, "GET")
    T.eq(log[1].url, "http://h/api/v1/discover/browse?type=search&source=novelfull&q=Shadow%20%26%20Slave%2F%C3%A9&page=2&per_page=20")
    T.truthy(log[1].total_timeout >= 120, "total timeout at least 120 s")
    T.truthy(log[1].block_timeout >= 120, "block timeout as long (the server holds the answer)")
end)

T.it("browse: empty values are left out of the query", function()
    local transport, log = fake({ json(200, { items = {} }) })
    local api = Api.new({ base_url = "http://h", transport = transport })
    api:browse({ type = "popular", q = "", page = 1 })
    T.eq(log[1].url, "http://h/api/v1/discover/browse?type=popular&page=1")
end)

T.it("browse: 502 is a server error carrying the server's message apart", function()
    local api = Api.new({ base_url = "http://h", transport = fake({
        { status = 502, headers = {}, body = '{"message":"Couldn\'t load this list from the source. Try again later."}' } }) })
    local data, e = api:browse({ type = "popular" })
    T.eq(data, nil)
    T.eq(e.kind, "server")
    T.eq(e.status, 502)
    T.eq(e.message, nil)
    T.eq(e.server_message, "Couldn't load this list from the source. Try again later.")
end)

T.it("addNovel: POST title and url; 201 and 200 both return the novel", function()
    local transport, log = fake({
        json(201, { novel = { id = 9, name = "Shadow Slave" }, already_in_library = false }),
        json(200, { novel = { id = 2, name = "Shadow Slave" }, already_in_library = true }),
        { status = 422, headers = {}, body = '{"message":"The url field must be a valid URL.","errors":{"url":["x"]}}' },
        { status = 503, headers = {}, body = '{"message":"Another novel is being added right now. Try again in a moment."}' },
    })
    local api = Api.new({ base_url = "http://h", transport = transport })
    local d1, _e1, i1 = api:addNovel("Shadow Slave", "https://novelping.com/novel/shadow-slave")
    T.eq(log[1].method, "POST")
    T.eq(log[1].url, "http://h/api/v1/novels")
    T.eq(Json.decode(log[1].body), { title = "Shadow Slave", url = "https://novelping.com/novel/shadow-slave" })
    T.eq(d1.novel.id, 9)
    T.eq(i1.status, 201)
    local d2 = api:addNovel("Shadow Slave", "u")
    T.truthy(d2.already_in_library)
    local _d3, e3 = api:addNovel("x", "bad")
    T.eq(e3.kind, "invalid")
    T.eq(e3.message, "The url field must be a valid URL.")
    local _d4, e4 = api:addNovel("x", "u")
    T.eq(e4.status, 503)
    T.contains(e4.server_message, "Another novel")
end)

T.it("addNovel: 409 is a duplicate with the existing novel; force sends force=1", function()
    local transport, log = fake({
        json(409, { message = "Shadow Slave is already in the library (from novelfull.com).",
            duplicate = { id = 42, title = "Shadow Slave", source = "novelfull.com" } }),
        json(201, { novel = { id = 43, name = "Shadow Slave" }, already_in_library = false }),
        json(409, { message = "x" }),
    })
    local api = Api.new({ base_url = "http://h", transport = transport })
    local d, e = api:addNovel("Shadow Slave", "https://novelping.com/novel/shadow-slave")
    T.eq(d, nil)
    T.eq(e.kind, "duplicate")
    T.eq(e.status, 409)
    T.eq(e.duplicate, { id = 42, title = "Shadow Slave", source = "novelfull.com" })
    T.eq(Json.decode(log[1].body).force, nil, "no force the first time")
    local d2 = api:addNovel("Shadow Slave", "https://novelping.com/novel/shadow-slave", true)
    T.eq(d2.novel.id, 43)
    T.eq(Json.decode(log[2].body), { title = "Shadow Slave", url = "https://novelping.com/novel/shadow-slave", force = 1 })
    local _d3, e3 = api:addNovel("x", "u")
    T.eq(e3.kind, "duplicate")
    T.eq(e3.duplicate.id, nil, "a 409 without the duplicate object")
end)

T.it("a manga chapter fetched as text (422 medium manga) is its own kind", function()
    local api = Api.new({ base_url = "http://h", transport = fake({
        json(422, { message = "This chapter belongs to a manga: it has page images, not text. Use /api/v1/chapters/5/pages.",
            medium = "manga" }),
        json(422, { message = "Something else." }),
    }) })
    local _d, e = api:chapter(5)
    T.eq(e.kind, "manga")
    T.eq(e.status, 422)
    T.falsy(Api.isRetryable(e))
    local _d2, e2 = api:chapter(5)
    T.eq(e2.kind, "invalid")
end)

T.it("foreignAnswer: anything but a JSON object where JSON was expected", function()
    T.truthy(Api.foreignAnswer({ status = 200, body = "<html>Hotel login</html>" }))
    T.truthy(Api.foreignAnswer({ status = 302, body = "" }))
    T.truthy(Api.foreignAnswer({ status = 401, body = "<html>Login</html>" }))
    T.falsy(Api.foreignAnswer({ status = 200, body = '{"novels":[]}' }))
    T.falsy(Api.foreignAnswer({ status = 404, body = '{"message":"Not Found"}' }))
    T.falsy(Api.foreignAnswer({ status = 304, body = "" }))
    T.falsy(Api.foreignAnswer({ status = 204, body = "" }))
    T.falsy(Api.foreignAnswer(nil))
end)

T.it("check: POST starts, GET reads, the check object is returned", function()
    local transport, log = fake({
        json(202, { check = { status = "running", new_chapters = 0 } }),
        json(200, { check = { status = "done", new_chapters = 3 } }),
        json(200, { nothing = true }),
    })
    local api = Api.new({ base_url = "http://h", transport = transport })
    T.eq(api:checkStart(7).status, "running")
    T.eq(log[1].method, "POST")
    T.eq(log[1].url, "http://h/api/v1/novels/7/check")
    T.eq(api:checkStatus(7).new_chapters, 3)
    T.eq(log[2].method, "GET")
    local c, e = api:checkStatus(7)
    T.eq(c, nil)
    T.eq(e.kind, "server")
end)

T.it("searchStart: POST /discover/searches with the browse parameters; searchStatus: GET by id", function()
    local transport, log = fake({
        json(202, { search = { id = "a-b_1", status = "running" } }),
        json(200, { search = { id = "a-b_1", status = "done", result = { items = {}, page = 1, has_more = false } } }),
        json(200, { nothing = true }),
        json(404, {}),
        json(405, { message = "Method Not Allowed" }),
    })
    local api = Api.new({ base_url = "http://h", transport = transport })
    local st = api:searchStart({ type = "search", source = "nb", q = "sword", page = 2, per_page = 20 })
    T.eq(st, { id = "a-b_1", status = "running" })
    T.eq(log[1].method, "POST")
    T.eq(log[1].url, "http://h/api/v1/discover/searches")
    T.eq(Json.decode(log[1].body), { type = "search", source = "nb", q = "sword", page = 2, per_page = 20 })
    T.eq(api:searchStatus("a-b_1").status, "done")
    T.eq(log[2].method, "GET")
    T.eq(log[2].url, "http://h/api/v1/discover/searches/a-b_1")
    local s, e = api:searchStatus("a-b_1")
    T.eq(s, nil)
    T.eq(e.kind, "server")
    local _s, e404 = api:searchStart({ type = "popular", source = "nb", page = 1, per_page = 20 })
    T.eq(e404.kind, "not_found")
    T.eq(Json.decode(log[4].body).q, nil, "no q for a list")
    local _s2, e405 = api:searchStart({ type = "popular", source = "nb", page = 1 })
    T.eq({ e405.kind, e405.status }, { "invalid", 405 })
end)

T.it("highlight writes: POST with chapter, excerpt, note (null when cleared); DELETE answers 204", function()
    local transport, log = fake({
        json(201, { highlight = { id = 12, chapter_id = 5 } }),
        json(200, { highlight = { id = 12 } }),
        { status = 204, headers = {}, body = "" },
        json(404, {}),
    })
    local api = Api.new({ base_url = "http://h", transport = transport })
    local ok, data = api:sendWrite({ kind = "highlight", chapter_id = 5, excerpt = "Text", note = "n", hkey = "5:x" }, 10)
    T.truthy(ok)
    T.eq(data.highlight.id, 12)
    T.eq(log[1].url, "http://h/api/v1/highlights")
    T.eq(Json.decode(log[1].body), { chapter_id = 5, excerpt = "Text", note = "n" })
    api:sendWrite({ kind = "highlight", chapter_id = 5, excerpt = "Text", hkey = "5:x", note_cleared = true }, 10)
    T.contains(log[2].body, '"note":null')
    T.truthy(api:sendWrite({ kind = "highlight_delete", id = 12 }, 10))
    T.eq(log[3].method, "DELETE")
    T.eq(log[3].url, "http://h/api/v1/highlights/12")
    local gone, e = api:sendWrite({ kind = "highlight_delete", id = 13 }, 10)
    T.eq(gone, nil)
    T.eq(e.kind, "not_found")
end)

T.it("highlights list: GET with novel_id, page, per_page", function()
    local transport, log = fake({ json(200, { highlights = {}, page = 2, has_more = false }) })
    local api = Api.new({ base_url = "http://h", transport = transport })
    T.eq(api:highlights(42, 2, 50).page, 2)
    T.eq(log[1].url, "http://h/api/v1/highlights?novel_id=42&page=2&per_page=50")
end)

-- ---------------------------------------------------------------- device headers (1.8.0)

local DEVICE = { id = "k_AbCdEfGhIjKlMnOpQrStUv", name = "Kindle PaperWhite 5" }

T.it("every write carries X-Novarr-Device and X-Novarr-Device-Name; bodies unchanged", function()
    local transport, log = fake({ default = json(200, { success = true, highlight = { id = 3 } }) })
    local api = Api.new({ base_url = "http://h", transport = transport, device = DEVICE })
    api:sendWrite({ kind = "progress", chapter_id = 5, progress = 40 }, 12)
    api:sendWrite({ kind = "set_read", chapter_id = 9, read = true }, 7)
    api:sendWrite({ kind = "bulk_read", bulk = { read = true, scope = "all", novel_id = 2 } }, 8)
    api:sendWrite({ kind = "highlight", chapter_id = 5, excerpt = "x", hkey = "k" }, 1)
    T.eq(#log, 4)
    for i = 1, 4 do
        T.eq(log[i].headers["X-Novarr-Device"], DEVICE.id, "request " .. i)
        T.eq(log[i].headers["X-Novarr-Device-Name"], "Kindle PaperWhite 5", "request " .. i)
        T.contains(log[i].headers["User-Agent"], "NovarrKOReader/")
    end
    T.eq(Json.decode(log[1].body), { progress = 40, age = 12 })
    T.eq(Json.decode(log[2].body), { read = true, age = 7 })
end)

T.it("reads do not carry the device headers", function()
    local transport, log = fake({ json(200, { novels = {} }) })
    local api = Api.new({ base_url = "http://h", transport = transport, device = DEVICE })
    api:novels()
    T.eq(log[1].headers["X-Novarr-Device"], nil)
end)

T.it("no device (or an invalid id): writes work as before, without the headers", function()
    for _, device in ipairs({ false, { id = "short" }, { id = "bad id with spaces" } }) do
        local transport, log = fake({ json(200, { success = true }) })
        local api = Api.new({ base_url = "http://h", transport = transport, device = device or nil })
        T.truthy(api:sendWrite({ kind = "progress", chapter_id = 5, progress = 40 }, 0))
        T.eq(log[1].headers["X-Novarr-Device"], nil)
        T.eq(log[1].headers["X-Novarr-Device-Name"], nil)
    end
end)

T.it("device name header: printable ASCII, at most 40 characters, left out when empty", function()
    local transport, log = fake({ default = json(200, { success = true }) })
    local api = Api.new({ base_url = "http://h", transport = transport,
        device = { id = DEVICE.id, name = "  Kindle\n" .. string.rep("x", 60) } })
    api:sendWrite({ kind = "progress", chapter_id = 5, progress = 1 }, 0)
    T.eq(log[1].headers["X-Novarr-Device-Name"], ("Kindle" .. string.rep("x", 60)):sub(1, 40))
    api.device = { id = DEVICE.id, name = "\226\128\162" }
    api:sendWrite({ kind = "progress", chapter_id = 5, progress = 1 }, 0)
    T.eq(log[2].headers["X-Novarr-Device"], DEVICE.id)
    T.eq(log[2].headers["X-Novarr-Device-Name"], nil)
end)

T.it("writes queued by 1.7.0 (no device in the entry) get the headers when replayed", function()
    local Queue = require("novarr.queue")
    local Sync = require("novarr.sync")
    local saved = {
        { kind = "progress", chapter_id = 5, novel_id = 1, progress = 30, at = 1000, seq = 1 },
        { kind = "set_read", chapter_id = 6, novel_id = 1, read = true, at = 2000, seq = 2 },
    }
    local q = Queue.new({ load = function() return saved end, save = function() end, now = function() return 5000 end })
    local transport, log = fake({ default = json(200, { success = true }) })
    local api = Api.new({ base_url = "http://h", transport = transport, device = DEVICE })
    local r = Sync.flush({ api = api, queue = q, now = function() return 5000 end })
    T.eq(r.sent, 2)
    T.eq(log[1].headers["X-Novarr-Device"], DEVICE.id)
    T.eq(log[2].headers["X-Novarr-Device"], DEVICE.id)
    T.eq(Json.decode(log[1].body), { progress = 30, age = 4000 })
end)

-- ---------------------------------------------------------------- latest position

T.it("position: GET novels/{id}/position with a short timeout", function()
    local p = { chapter_id = 123, chapter_label = "Chapter 124: The Gate", chapter_number = "124", progress = 40,
        downloaded = true, device_id = "w_12345678", device_name = "iPhone", at = "2026-11-18T02:10:00+00:00",
        age_seconds = 7200 }
    local transport, log = fake({ json(200, { position = p }) })
    local api = Api.new({ base_url = "http://h", transport = transport, device = DEVICE })
    local got = api:position(7)
    T.eq(log[1].method, "GET")
    T.eq(log[1].url, "http://h/api/v1/novels/7/position")
    T.truthy(log[1].block_timeout <= 3)
    T.truthy(log[1].total_timeout <= 3)
    T.eq(got.chapter_id, 123)
    T.eq(got.age_seconds, 7200)
end)

T.it("position: null, an older server (404) -> false; offline / garbage -> nil, err", function()
    local api = Api.new({ base_url = "http://h", transport = fake({ json(200, { position = Json.null }) }) })
    T.eq(api:position(7), false)
    api = Api.new({ base_url = "http://h", transport = fake({ { status = 404, headers = {}, body = '{"message":"Not Found"}' } }) })
    T.eq(api:position(7), false)
    api = Api.new({ base_url = "http://h", transport = fake({ "offline" }) })
    local p, e = api:position(7)
    T.eq(p, nil)
    T.eq(e.kind, "offline")
    api = Api.new({ base_url = "http://h", transport = fake({ json(200, { position = { chapter_id = "x" } }) }) })
    p, e = api:position(7)
    T.eq(p, nil)
    T.eq(e.kind, "server")
    api = Api.new({ base_url = "http://h", transport = fake({ { status = 500, headers = {}, body = "" } }) })
    T.eq(api:position(7), nil)
end)

-- ---------------------------------------------------------------- books (1.12.0)

T.it("books: only a meta saying books = true supports them", function()
    T.eq(Api.supportsBooks({ app = "novarr", books = true }), true)
    T.eq(Api.supportsBooks({ app = "novarr" }), false, "older server: no key")
    T.eq(Api.supportsBooks({ app = "novarr", books = false }), false)
    T.eq(Api.supportsBooks({ app = "novarr", books = "yes" }), false, "only a real true")
    T.eq(Api.supportsBooks(nil), false)
end)

T.it("books: the text medium only when the server has books", function()
    T.eq(Api.textMedium(true), "text")
    T.eq(Api.textMedium(false), nil)
    T.eq(Api.textMedium(nil), nil)
end)

T.it("books: novels and home send medium=text when asked, nothing otherwise", function()
    local transport, log = fake({ default = json(200, { novels = {} }) })
    local api = Api.new({ base_url = "http://h", transport = transport })
    api:novels(nil, Api.textMedium(true))
    api:home(Api.textMedium(true))
    -- An older server: exactly the requests of 1.11 (it answers 422 to unknown media).
    api:novels(nil, Api.textMedium(false))
    api:home(Api.textMedium(false))
    api:novels()
    T.eq(log[1].url, "http://h/api/v1/novels?medium=text")
    T.eq(log[2].url, "http://h/api/v1/home?medium=text")
    T.eq(log[3].url, "http://h/api/v1/novels")
    T.eq(log[4].url, "http://h/api/v1/home")
    T.eq(log[5].url, "http://h/api/v1/novels")
end)

T.it("books: the ETag still goes with medium=text", function()
    local transport, log = fake({ { status = 304, headers = { etag = '"t1"' }, body = "" } })
    local api = Api.new({ base_url = "http://h", transport = transport })
    local _d, _e, info = api:novels('"t1"', "text")
    T.truthy(info.not_modified)
    T.eq(log[1].headers["If-None-Match"], '"t1"')
    T.eq(log[1].url, "http://h/api/v1/novels?medium=text")
end)
