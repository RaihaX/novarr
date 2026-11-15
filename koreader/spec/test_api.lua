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
