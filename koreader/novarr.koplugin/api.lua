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

Api.VERSION = "1.0.0"
Api.MIN_API_VERSION = 1
Api.BLOCK_TIMEOUT = 10
Api.TOTAL_TIMEOUT = 30

-- Test hook (autopilot "offline on"): behave as if there were no network.
Api.simulate_offline = false

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

-- Classifies a LuaSocket error string: every one of them means "no answer".
local function defaultTransport(req)
    local socketutil = require("socketutil")
    local ltn12 = require("ltn12")
    local socket = require("socket")
    local http = require("socket.http")
    local requester = http
    if req.url:sub(1, 8) == "https://" then
        requester = require("ssl.https")
    end
    local sink = {}
    socketutil:set_timeout(Api.BLOCK_TIMEOUT, Api.TOTAL_TIMEOUT)
    local request = {
        url = req.url,
        method = req.method,
        headers = req.headers,
        sink = socketutil.table_sink(sink),
    }
    if req.body then
        request.source = ltn12.source.string(req.body)
    end
    local ok, code, headers, status = pcall(function()
        return socket.skip(1, requester.request(request))
    end)
    socketutil:reset_timeout()
    if not ok then
        return nil, tostring(code)
    end
    if headers == nil or type(code) ~= "number" then
        return nil, tostring(status or code or "no response")
    end
    return { status = code, headers = headers, body = table.concat(sink) }
end
Api.defaultTransport = defaultTransport

-- ---------------------------------------------------------------- client

--[[--
opts: base_url (normalised), transport (optional), log (optional),
user_agent (optional).
]]
function Api.new(opts)
    return setmetatable({
        base_url = opts.base_url,
        transport = opts.transport or defaultTransport,
        log = opts.log or function() end,
        user_agent = opts.user_agent or ("NovarrKOReader/" .. Api.VERSION),
    }, Api)
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

--[[--
Performs a request. opts: method ("GET"), body (Lua table, sent as JSON),
etag (sends If-None-Match). Returns
    data (decoded JSON or nil on 304), info {status, etag, body, not_modified}
or nil, err.
]]
function Api:request(path, opts)
    opts = opts or {}
    if Api.simulate_offline then
        return nil, err("offline", nil, "simulated offline")
    end
    if not self.base_url or self.base_url == "" then
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
    local method = opts.method or (body and "POST" or "GET")
    local url = self.base_url .. "/api/v1/" .. path
    local res, terr = self.transport({ method = method, url = url, headers = headers, body = body })
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
    if status == 404 then
        return nil, err("not_found", 404)
    end
    if status >= 500 then
        return nil, err("server", status)
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

--- Checks that the server is Novarr. Returns meta or nil, err.
function Api:meta()
    local data, e = self:request("meta")
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

--[[--
Sends one queued write (see queue.lua for the entry shapes) with its age in
ms. Returns true or nil, err.
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
    else
        return nil, err("invalid", nil, "unknown write")
    end
    local data, e = self:request(path, { method = "POST", body = body })
    if not data then return nil, e end
    return true
end

return Api
