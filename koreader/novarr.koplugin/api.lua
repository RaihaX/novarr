--[[--
Novarr API v1 client: one request helper (timeouts, headers, JSON) and the
calls the plugin needs. The HTTP transport is injectable for tests; the
default one uses KOReader's LuaSocket/LuaSec with socketutil timeouts.

Errors are tables { kind = ..., status = n|nil, message = text|nil }:
    offline     no HTTP answer at all (no route, timeout, refused, DNS) - retry later
    not_found   404
    invalid     422 (or another 4xx: the request itself is wrong) - never retried
    server      5xx, or an answer that is not JSON (captive portal page) - retry later
    not_novarr  /meta did not say app = "novarr"
    bad_url     the server address is unusable
]]

local Json = require("novarr.json")

local Api = {}
Api.__index = Api

-- The plugin version: main.lua sets it from _meta.lua (the single source).
Api.VERSION = "dev"
Api.MIN_API_VERSION = 1
Api.BLOCK_TIMEOUT = 10
Api.TOTAL_TIMEOUT = 30

-- Test hook (autopilot "offline on"): behave as if there were no network.
Api.simulate_offline = false
-- Test hook (autopilot Wi-Fi emulation): a function returning true while the
-- emulated Wi-Fi is off, so requests fail as they would on the device.
Api.offline_check = nil
-- Where the HTTP client logs (main.lua points it at KOReader's logger).
Api.log = function() end

--- Whether the test hooks say there is no network (never true on a device).
function Api.isOffline()
    if Api.simulate_offline then return true end
    if Api.offline_check then
        local ok, off = pcall(Api.offline_check)
        return ok and off and true or false
    end
    return false
end

-- ---------------------------------------------------------------- URL

--[[--
Turns what the user typed into a base URL, like the iOS app: trims spaces,
adds http:// when no scheme is given, lower-cases scheme and host, drops
query/fragment, trailing slashes and a pasted /api or /api/v1 suffix.
Returns the URL or nil when no usable http(s) host remains.
]]
function Api.normalizeUrl(raw)
    if type(raw) ~= "string" then return nil end
    local text = raw:gsub("^%s+", ""):gsub("%s+$", "")
    if text == "" or text:find("%s") then return nil end
    if not text:find("://", 1, true) then
        text = "http://" .. text
    end
    local scheme, rest = text:match("^([%a][%w+.-]*)://(.*)$")
    if not scheme then return nil end
    scheme = scheme:lower()
    if scheme ~= "http" and scheme ~= "https" then return nil end
    rest = rest:gsub("[?#].*$", "")
    local authority, path = rest:match("^([^/]*)(.*)$")
    if not authority or authority == "" then return nil end
    -- No user info: Novarr has no authentication.
    if authority:find("@", 1, true) then return nil end
    local host, port = authority:match("^(%[[%x:.]+%])(.*)$")
    if not host then
        host, port = authority:match("^([^:]*)(.*)$")
    end
    if not host or host == "" then return nil end
    if port ~= "" then
        local digits = port:match("^:(%d+)$")
        if not digits then return nil end
        local n = tonumber(digits)
        if n < 1 or n > 65535 then return nil end
        port = ":" .. tostring(n)
    end
    if not host:match("^%[") and not host:match("^[%w%.%-_]+$") then return nil end
    host = host:lower()
    path = path:gsub("/+$", "")
    local lower_path = path:lower()
    if lower_path:sub(-7) == "/api/v1" then
        path = path:sub(1, -8)
    elseif lower_path:sub(-4) == "/api" then
        path = path:sub(1, -5)
    end
    path = path:gsub("/+$", "")
    return scheme .. "://" .. host .. port .. path
end

-- ---------------------------------------------------------------- transport

-- Any failure here means "no HTTP answer" (no route, timeout, refused, DNS,
-- proxy unreachable or refusing the tunnel): the caller treats it as offline.
local function defaultTransport(req)
    local socketutil = require("socketutil")
    local HttpClient = require("novarr.http_client")
    local sink = {}
    local res, terr = HttpClient.request({
        url = req.url,
        method = req.method,
        headers = req.headers,
        body = req.body,
        user_agent = req.headers and req.headers["User-Agent"],
        block_timeout = req.block_timeout or Api.BLOCK_TIMEOUT,
        total_timeout = req.total_timeout or Api.TOTAL_TIMEOUT,
        make_sink = function() return socketutil.table_sink(sink) end,
        log = Api.log,
    })
    if not res then return nil, terr end
    return { status = res.status, headers = res.headers, body = table.concat(sink) }
end
Api.defaultTransport = defaultTransport

-- ---------------------------------------------------------------- client

--[[--
opts: base_url (normalised), transport (optional), log (optional),
user_agent (optional), router (optional, see address.lua: router:base()
gives the address to use, router:failed(base, err) the one to retry on),
device (optional {id, name}: this device, sent with every write as
X-Novarr-Device / X-Novarr-Device-Name so the server knows where the
latest reading happened).
]]
function Api.new(opts)
    return setmetatable({
        base_url = opts.base_url,
        router = opts.router,
        transport = opts.transport or defaultTransport,
        log = opts.log or function() end,
        user_agent = opts.user_agent or ("NovarrKOReader/" .. Api.VERSION),
        device = opts.device,
    }, Api)
end

--- The device headers for a write ({} without a usable device id).
function Api:deviceHeaders()
    local d = self.device
    local out = {}
    if type(d) ~= "table" or type(d.id) ~= "string" or not d.id:match("^[%w_%-]+$")
            or #d.id < 8 or #d.id > 64 then
        return out
    end
    out["X-Novarr-Device"] = d.id
    -- Header values: printable ASCII only, at most 40 characters.
    local name = type(d.name) == "string" and d.name:gsub("[^\32-\126]", ""):gsub("^%s+", ""):gsub("%s+$", "") or ""
    if name ~= "" then out["X-Novarr-Device-Name"] = name:sub(1, 40) end
    return out
end

local function err(kind, status, message)
    return { kind = kind, status = status, message = message }
end
Api.err = err

--- Whether a failure should leave queued writes in place for a later retry.
function Api.isRetryable(e)
    if not e then return false end
    return e.kind == "offline" or e.kind == "server" or e.kind == "not_novarr" or e.kind == "bad_url"
end

local function header(headers, name)
    if type(headers) ~= "table" then return nil end
    return headers[name] or headers[name:lower()]
end

--- The base URL requests go to now.
function Api:base()
    if self.router then return self.router:base() end
    return self.base_url
end

--[[--
Sends req (method, headers, body) to <base><suffix>. A request that gets no
answer at all is retried once on the other server address when the router
switches to it. Returns res, transport error, the base used.
]]
function Api:exchange(suffix, req)
    local base = self:base()
    local function send(b)
        local r = {}
        for k, v in pairs(req) do r[k] = v end
        r.url = b .. suffix
        return self.transport(r)
    end
    local res, terr = send(base)
    if not res and self.router then
        local other = self.router:failed(base, terr)
        if other and other ~= base then
            self.log("Novarr API: retrying " .. suffix .. " on " .. other)
            base = other
            res, terr = send(base)
        end
    end
    return res, terr, base
end

--[[--
Performs a request. opts: method ("GET"), body (Lua table, sent as JSON),
etag (sends If-None-Match), headers (extra request headers). Returns
    data (decoded JSON or nil on 304), info {status, etag, body, not_modified}
or nil, err.
]]
function Api:request(path, opts)
    opts = opts or {}
    if Api.isOffline() then
        return nil, err("offline", nil, "simulated offline")
    end
    if not self:base() or self:base() == "" then
        return nil, err("bad_url")
    end
    local headers = {
        ["Accept"] = "application/json",
        ["User-Agent"] = self.user_agent,
    }
    local body
    if opts.body ~= nil then
        local encoded, eerr = Json.encode(opts.body)
        if not encoded then return nil, err("invalid", nil, eerr) end
        body = encoded
        headers["Content-Type"] = "application/json"
        headers["Content-Length"] = tostring(#body)
    end
    if opts.etag then
        headers["If-None-Match"] = opts.etag
    end
    for k, v in pairs(opts.headers or {}) do headers[k] = v end
    local method = opts.method or (body and "POST" or "GET")
    local res, terr = self:exchange("/api/v1/" .. path, { method = method, headers = headers, body = body,
        block_timeout = opts.block_timeout, total_timeout = opts.total_timeout })
    if not res then
        self.log("Novarr API: " .. method .. " " .. path .. " failed: " .. tostring(terr))
        return nil, err("offline", nil, terr)
    end
    local status = res.status
    local etag = header(res.headers, "etag")
    if status == 304 then
        return nil, nil, { status = 304, etag = etag or opts.etag, not_modified = true }
    end
    local text = res.body or ""
    if status == 204 then
        return {}, nil, { status = 204 } -- No Content (DELETE)
    end
    if status == 404 then
        return nil, err("not_found", 404)
    end
    if status >= 500 then
        -- Discover/add answer 502/503 with a plain {"message"}: kept apart from
        -- e.message (which callers read as "the request was refused").
        local e = err("server", status)
        local d = Json.decode(text)
        if type(d) == "table" and type(d.message) == "string" and d.message ~= "" then
            e.server_message = d.message
        end
        return nil, e
    end
    local data, derr = Json.decode(text)
    if status >= 400 then
        local message = type(data) == "table" and type(data.message) == "string" and data.message or nil
        return nil, err("invalid", status, message)
    end
    if status < 200 or status >= 300 then
        return nil, err("server", status)
    end
    if type(data) ~= "table" then
        -- A 200 that is not JSON: a captive portal, a proxy page, the web UI...
        self.log("Novarr API: " .. path .. " answered with something that is not JSON: " .. tostring(derr))
        return nil, err("server", status, "not json")
    end
    return data, nil, { status = status, etag = etag, body = text }
end

-- ---------------------------------------------------------------- calls

--- Checks that the server is Novarr. Returns meta or nil, err. opts: block_timeout, total_timeout.
function Api:meta(opts)
    local data, e = self:request("meta", opts)
    if not data then
        if e and (e.kind == "not_found" or e.kind == "server" or e.kind == "invalid") and e.status ~= nil and e.status < 500 then
            return nil, err("not_novarr", e.status)
        end
        if e and e.kind == "server" and e.message == "not json" then
            return nil, err("not_novarr", e.status)
        end
        return nil, e
    end
    if type(data.app) ~= "string" or data.app:lower() ~= "novarr" then
        return nil, err("not_novarr")
    end
    if (tonumber(data.api_version) or 0) < Api.MIN_API_VERSION then
        return nil, err("not_novarr", nil, "api too old")
    end
    return data
end

function Api:home()
    return self:request("home")
end

function Api:novels(etag)
    return self:request("novels", { etag = etag })
end

function Api:novel(id)
    return self:request(string.format("novels/%d", id))
end

function Api:chapters(novel_id, etag)
    return self:request(string.format("novels/%d/chapters", novel_id), { etag = etag })
end

function Api:chapter(id)
    return self:request(string.format("chapters/%d", id))
end

--- EPUB status of a novel: {status, stale, generated_at, size, filename, download_url, message}.
function Api:epubStatus(novel_id)
    local data, e = self:request(string.format("novels/%d/epub", novel_id))
    if not data then return nil, e end
    if type(data.epub) ~= "table" then return nil, err("server", nil, "bad epub status") end
    return data.epub
end

--- Asks the server to (re)build a novel's EPUB. 202 building / 200 nothing to do / 422 no chapters.
function Api:epubBuild(novel_id, force)
    local data, e = self:request(string.format("novels/%d/epub", novel_id),
        { method = "POST", body = force and { force = true } or {} })
    if not data then return nil, e end
    if type(data.epub) ~= "table" then return nil, err("server", nil, "bad epub status") end
    return data.epub
end

-- ---------------------------------------------------------------- discover, add, check

-- Browse/search can take minutes (searches through FlareSolverr): the server
-- holds the answer, so the per-read (block) timeout must be as long as the total.
Api.BROWSE_BLOCK_TIMEOUT = 125
Api.BROWSE_TOTAL_TIMEOUT = 150

--- Percent-encodes a query string value (RFC 3986 unreserved characters kept).
function Api.urlEncode(s)
    return (tostring(s):gsub("[^%w%-%._~]", function(c) return string.format("%%%02X", c:byte()) end))
end

--- Builds "a=1&b=x" from a list of {key, value} pairs (nil/"" values left out).
function Api.query(pairs_list)
    local out = {}
    for _i, kv in ipairs(pairs_list) do
        if kv[2] ~= nil and kv[2] ~= "" then
            table.insert(out, Api.urlEncode(kv[1]) .. "=" .. Api.urlEncode(kv[2]))
        end
    end
    return table.concat(out, "&")
end

--- The Discover sources and their lists: {sources = [...]}.
function Api:discover()
    return self:request("discover")
end

--[[--
One page of a source's list or search. p: type, source, q, page, per_page,
block_timeout, total_timeout. Returns {items, page, has_more} or nil, err
(kind "server", status 502 when the source failed).
]]
function Api:browse(p)
    local q = Api.query({ { "type", p.type }, { "source", p.source }, { "q", p.q },
        { "page", p.page and tostring(p.page) }, { "per_page", p.per_page and tostring(p.per_page) } })
    return self:request("discover/browse?" .. q, {
        block_timeout = p.block_timeout or Api.BROWSE_BLOCK_TIMEOUT,
        total_timeout = p.total_timeout or Api.BROWSE_TOTAL_TIMEOUT,
    })
end

local function searchObject(data, e)
    if not data then return nil, e end
    if type(data.search) ~= "table" then return nil, err("server", nil, "bad search") end
    return data.search
end

--[[--
Starts a background browse/search (POST /discover/searches): p as for
browse(). Returns the search object {id, status, message, result} or nil,
err (not_found / invalid 405 on a server without background searches).
]]
function Api:searchStart(p)
    local body = { type = p.type, source = p.source, page = p.page, per_page = p.per_page }
    if p.q and p.q ~= "" then body.q = p.q end
    return searchObject(self:request("discover/searches", { method = "POST", body = body }))
end

--- A background search's state (GET /discover/searches/{id}). Returns the search object or nil, err.
function Api:searchStatus(id)
    return searchObject(self:request("discover/searches/" .. Api.urlEncode(id),
        { block_timeout = 10, total_timeout = 15 }))
end

--[[--
Adds a novel from a Discover result. Returns data {novel, already_in_library},
info {status = 201|200} or nil, err (422 invalid, 503 server).
]]
function Api:addNovel(title, url)
    local data, e, info = self:request("novels", { method = "POST", body = { title = title, url = url } })
    if not data then return nil, e end
    if type(data.novel) ~= "table" or not data.novel.id then return nil, err("server", nil, "bad novel") end
    return data, nil, info
end

local function checkObject(data, e)
    if not data then return nil, e end
    if type(data.check) ~= "table" then return nil, err("server", nil, "bad check") end
    return data.check
end

--- Starts (or joins) a server check for new chapters. Returns the check object or nil, err.
function Api:checkStart(novel_id)
    return checkObject(self:request(string.format("novels/%d/check", novel_id), { method = "POST", body = {} }))
end

--- The novel's check: {status, started_at, finished_at, chapters_before, chapters_now, new_chapters, message}.
function Api:checkStatus(novel_id)
    return checkObject(self:request(string.format("novels/%d/check", novel_id)))
end

-- The latest-position check runs as a chapter opens: it must never hold the reader long.
Api.POSITION_BLOCK_TIMEOUT = 2
Api.POSITION_TOTAL_TIMEOUT = 3

--[[--
Where this novel was read last, on any device (GET novels/{id}/position):
the position object {chapter_id, chapter_label, chapter_number, progress,
downloaded, device_id, device_name, at, age_seconds}, or false when the
server has none (null, or an older server without the endpoint: 404), or
nil, err. Short timeouts (3 s at most).
]]
function Api:position(novel_id)
    local data, e = self:request(string.format("novels/%d/position", novel_id),
        { block_timeout = Api.POSITION_BLOCK_TIMEOUT, total_timeout = Api.POSITION_TOTAL_TIMEOUT })
    if not data then
        if e and e.kind == "not_found" then return false end
        return nil, e
    end
    if data.position == nil then return false end
    if type(data.position) ~= "table" or not tonumber(data.position.chapter_id) then
        return nil, err("server", nil, "bad position")
    end
    return data.position
end

--[[--
Sends one queued write (see queue.lua for the entry shapes) with its age in
ms, with this device's headers (also for writes queued by an older version
of the plugin). Returns true or nil, err.
]]
function Api:sendWrite(entry, age)
    local path, body
    if entry.kind == "progress" then
        path = string.format("chapters/%d/progress", entry.chapter_id)
        body = { age = age }
        if entry.progress ~= nil then body.progress = entry.progress end
        if entry.read then body.read = true end
        if body.progress == nil and not body.read then
            return true -- nothing to say
        end
    elseif entry.kind == "set_read" then
        path = string.format("chapters/%d/read", entry.chapter_id)
        body = { read = entry.read and true or false, age = age }
    elseif entry.kind == "bulk_read" then
        path = "chapters/bulk-read"
        local b = entry.bulk
        body = { read = b.read and true or false, scope = b.scope or "ids", age = age }
        if b.ids then body.ids = Json.array(b.ids) end
        if b.novel_id then body.novel_id = b.novel_id end
        if b.anchor_id then body.anchor_id = b.anchor_id end
    elseif entry.kind == "highlight" then
        path = "highlights"
        body = { chapter_id = entry.chapter_id, excerpt = entry.excerpt }
        if entry.note then
            body.note = entry.note
        elseif entry.note_cleared then
            body.note = Json.null -- the note was removed in KOReader
        end
    elseif entry.kind == "highlight_delete" then
        local res, e = self:request(string.format("highlights/%d", entry.id),
            { method = "DELETE", headers = self:deviceHeaders() })
        if res then return true end
        return nil, e
    else
        return nil, err("invalid", nil, "unknown write")
    end
    local data, e = self:request(path, { method = "POST", body = body, headers = self:deviceHeaders() })
    if not data then return nil, e end
    return true, data
end

--[[--
A novel's highlights, newest first: {highlights = [{id, novel_id, chapter_id,
chapter_label, excerpt, note, created_at}], page, has_more}.
]]
function Api:highlights(novel_id, page, per_page)
    local q = Api.query({ { "novel_id", novel_id and tostring(novel_id) }, { "page", page and tostring(page) },
        { "per_page", per_page and tostring(per_page) } })
    return self:request("highlights?" .. q)
end

-- ---------------------------------------------------------------- plugin update

--[[--
The plugin manifest the server offers (the `plugin` object: version, build,
size, files). A server without plugin updates answers not_found.
]]
function Api:pluginManifest()
    local data, e = self:request("koreader/plugin")
    if not data then return nil, e end
    if type(data.plugin) ~= "table" then return nil, err("server", nil, "bad plugin manifest") end
    return data.plugin
end

--- One file of the plugin, as raw bytes. Returns bytes or nil, err.
function Api:pluginFile(path)
    if Api.isOffline() then
        return nil, err("offline", nil, "simulated offline")
    end
    if not self:base() or self:base() == "" then
        return nil, err("bad_url")
    end
    local res, terr = self:exchange("/api/v1/koreader/plugin/files/" .. path, { method = "GET",
        headers = { ["Accept"] = "application/octet-stream", ["User-Agent"] = self.user_agent } })
    if not res then
        self.log("Novarr API: GET plugin file " .. path .. " failed: " .. tostring(terr))
        return nil, err("offline", nil, terr)
    end
    if res.status == 404 then return nil, err("not_found", 404) end
    if res.status ~= 200 then return nil, err(res.status >= 500 and "server" or "invalid", res.status) end
    return res.body or ""
end

return Api
