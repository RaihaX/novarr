--[[--
The plugin's one HTTP client: every request (API calls, covers, chapters,
the EPUB download, plugin update files) goes through HttpClient.request.

* http:// uses LuaSocket's socket.http, with KOReader's HTTP proxy when one
  is set (socket.http.PROXY), exactly as before.
* https:// without a proxy uses LuaSec's ssl.https, exactly as before.
* https:// with a proxy set (the Tailscale plugin sets one): ssl.https
  refuses that ("proxy not supported"), so the request is tunnelled: a TCP
  connection to the proxy, "CONNECT host:port", then TLS over that
  connection with the very same settings ssl.https uses (taken from
  ssl.https.tcp itself: protocol, options, CA file and verify mode, SNI and
  the host name check), then the ordinary HTTP exchange over it.
* Redirects are followed here (at most 5, GET/HEAD without a body, 301,
  302, 303, 307, 308), so every hop chooses its own way (direct, proxy or
  tunnel), whatever the scheme of the next address.

The proxy is read from socket.http.PROXY at each request. socket.http falls
back to that global when a request has no proxy of its own, so for the
tunnelled exchange it is cleared for the duration of that one call and put
back afterwards, also when the call fails (KOReader runs single-threaded).

request(opts) -> res {status, headers, status_line, url} | nil, err, step
    opts: url, method ("GET"), headers, body (string), make_sink (called
    once, after the timeouts are set, returns an ltn12 sink), block_timeout,
    total_timeout, log.
    err is LuaSocket/LuaSec's error text; step is "proxy" when the proxy
    itself failed (unreachable, refused the CONNECT, garbled answer).

The parsing helpers (parseUrl, proxyAddress, connectRequest,
parseStatusLine, resolveLocation, shouldFollow) are pure and unit tested.
]]

local HttpClient = {}

HttpClient.MAX_REDIRECTS = 5
HttpClient.DEFAULT_PROXY_PORT = 3128 -- as socket.http
HttpClient.USER_AGENT = "NovarrKOReader"

local REDIRECTS = { [301] = true, [302] = true, [303] = true, [307] = true, [308] = true }
local DEFAULT_PORT = { http = 80, https = 443 }

-- ---------------------------------------------------------------- pure helpers

--[[--
Splits an absolute http(s) URL. Returns {scheme, host (no brackets), port
(number, default 80/443), path (with query, "/" when empty), origin
("scheme://host[:port]" with the default port left out)} or nil.
]]
function HttpClient.parseUrl(u)
    if type(u) ~= "string" then return nil end
    local scheme, rest = u:match("^([%a][%w+.-]*)://(.*)$")
    if not scheme then return nil end
    scheme = scheme:lower()
    if not DEFAULT_PORT[scheme] then return nil end
    local authority, path = rest:match("^([^/?#]*)(.*)$")
    if not authority or authority == "" or authority:find("@", 1, true) then return nil end
    local host, port = authority:match("^%[([%x:.]+)%](.*)$")
    if not host then host, port = authority:match("^([^:]*)(.*)$") end
    if not host or host == "" then return nil end
    local n = DEFAULT_PORT[scheme]
    if port ~= "" then
        local digits = port:match("^:(%d+)$")
        if not digits then return nil end
        n = tonumber(digits)
        if n < 1 or n > 65535 then return nil end
    end
    path = path:gsub("#.*$", "")
    if path == "" or path:sub(1, 1) == "?" then path = "/" .. path end
    host = host:lower()
    local shown = host:find(":", 1, true) and ("[" .. host .. "]") or host
    local origin = scheme .. "://" .. shown .. (n ~= DEFAULT_PORT[scheme] and (":" .. n) or "")
    return { scheme = scheme, host = host, port = n, path = path, origin = origin }
end

--[[--
Host and port of KOReader's HTTP proxy setting ("http://127.0.0.1:1056",
also without the scheme). Returns host, port or nil, reason (an https://
or other proxy scheme is not supported).
]]
function HttpClient.proxyAddress(proxy)
    if type(proxy) ~= "string" or proxy == "" then return nil, "no proxy" end
    local text = proxy:gsub("^%s+", ""):gsub("%s+$", "")
    if not text:find("://", 1, true) then text = "http://" .. text end
    local scheme, rest = text:match("^([%a][%w+.-]*)://(.*)$")
    if not scheme or scheme:lower() ~= "http" then return nil, "unsupported proxy scheme" end
    local authority = rest:match("^([^/?#]*)") or ""
    authority = authority:gsub("^.*@", "") -- user info, if any, is not used for CONNECT
    local host, port = authority:match("^%[([%x:.]+)%](.*)$")
    if not host then host, port = authority:match("^([^:]*)(.*)$") end
    if not host or host == "" then return nil, "no proxy host" end
    local n = HttpClient.DEFAULT_PROXY_PORT
    if port ~= "" then
        local digits = port:match("^:(%d+)$")
        if not digits then return nil, "bad proxy port" end
        n = tonumber(digits)
        if n < 1 or n > 65535 then return nil, "bad proxy port" end
    end
    return host, n
end

--- "host:port" as CONNECT and its Host header want it (IPv6 in brackets).
function HttpClient.authority(host, port)
    if host:find(":", 1, true) then host = "[" .. host .. "]" end
    return host .. ":" .. tostring(port)
end

--- The CONNECT request sent to the proxy for host:port.
function HttpClient.connectRequest(host, port, user_agent)
    local a = HttpClient.authority(host, port)
    return "CONNECT " .. a .. " HTTP/1.1\r\n"
        .. "Host: " .. a .. "\r\n"
        .. "User-Agent: " .. (user_agent or HttpClient.USER_AGENT) .. "\r\n"
        .. "Proxy-Connection: keep-alive\r\n"
        .. "\r\n"
end

--- Status code (number) and reason of an HTTP status line, or nil.
function HttpClient.parseStatusLine(line)
    if type(line) ~= "string" then return nil end
    local code, reason = line:match("^HTTP/%d+%.?%d*%s+(%d%d%d)%s*(.-)%s*$")
    if not code then return nil end
    return tonumber(code), reason
end

--[[--
The absolute address a Location header points to, from the address that
answered: absolute, scheme-relative (//host/x), absolute path (/x) or a
path relative to the current one (x, ../x).
]]
function HttpClient.resolveLocation(current, location)
    if type(location) ~= "string" then return nil end
    location = location:gsub("%s", "")
    if location == "" then return nil end
    if location:match("^[%a][%w+.-]*://") then return location end
    local cur = HttpClient.parseUrl(current)
    if not cur then return nil end
    if location:sub(1, 2) == "//" then return cur.scheme .. ":" .. location end
    local path
    if location:sub(1, 1) == "/" then
        path = location
    else
        local dir = cur.path:gsub("[?#].*$", ""):match("^(.*/)") or "/"
        path = dir .. location
    end
    -- Resolve "." and ".." segments of the path (the query is kept as is).
    local query = path:match("%?.*$") or ""
    path = path:gsub("%?.*$", "")
    local segs = {}
    for seg in path:gmatch("[^/]+") do
        if seg == ".." then
            table.remove(segs)
        elseif seg ~= "." then
            table.insert(segs, seg)
        end
    end
    local joined = "/" .. table.concat(segs, "/")
    local dir_like = path:sub(-1) == "/" or path:match("/%.%.?$") ~= nil
    if dir_like and #segs > 0 then joined = joined .. "/" end
    return cur.origin .. joined .. query
end

--- Whether a response is a redirect this client follows.
function HttpClient.shouldFollow(code, method, has_body, location)
    if not REDIRECTS[code] then return false end
    if has_body then return false end
    method = method or "GET"
    if method ~= "GET" and method ~= "HEAD" then return false end
    return type(location) == "string" and location:gsub("%s", "") ~= ""
end

-- ---------------------------------------------------------------- tunnel

-- Forward the connection object's calls to the (TLS) socket, like LuaSec's https.lua.
local function forward(conn)
    local mt = getmetatable(conn.sock)
    local index = mt and mt.__index
    if type(index) ~= "table" then return end
    for name, method in pairs(index) do
        if type(method) == "function" and name ~= "connect" and name ~= "settimeout" and name ~= "close" then
            conn[name] = function(self, ...) return method(self.sock, ...) end
        end
    end
end

--[[--
A LuaSocket `create` function that reaches host:port through the HTTP proxy
at proxy_host:proxy_port with CONNECT, then speaks TLS to the host. The TLS
settings come from ssl.https.tcp itself, so they are exactly those of a
direct https request. on_fail(step_text, err) is called for a proxy failure.
]]
function HttpClient.tunnel(proxy_host, proxy_port, user_agent, on_fail)
    local socket = require("socket")
    local ssl = require("ssl")
    local https = require("ssl.https")
    local params = {}
    https.tcp(params) -- fills in protocol, options, cafile/verify and mode = "client"
    return function()
        local sock, terr = socket.tcp()
        if not sock then return nil, terr end
        local conn = { sock = sock }
        function conn:settimeout()
            return self.sock:settimeout(https.TIMEOUT)
        end
        function conn:close()
            return self.sock:close()
        end
        function conn:connect(host, port)
            local ok, err = self.sock:connect(proxy_host, proxy_port)
            if not ok then
                on_fail("connecting to the proxy", err)
                return nil, "proxy unreachable: " .. tostring(err)
            end
            ok, err = self.sock:send(HttpClient.connectRequest(host, port, user_agent))
            if not ok then
                on_fail("sending CONNECT", err)
                return nil, "proxy: " .. tostring(err)
            end
            local line
            line, err = self.sock:receive("*l")
            local code = HttpClient.parseStatusLine(line)
            if not code then
                on_fail("reading the CONNECT answer", err or line)
                return nil, "proxy: bad answer to CONNECT: " .. tostring(err or line)
            end
            -- Skip the proxy's headers up to the blank line.
            for _i = 1, 100 do
                local h, herr = self.sock:receive("*l")
                if not h then
                    on_fail("reading the CONNECT answer", herr)
                    return nil, "proxy: " .. tostring(herr)
                end
                if h == "" then break end
            end
            if code < 200 or code >= 300 then
                on_fail("CONNECT " .. HttpClient.authority(host, port), "answered " .. tostring(line))
                return nil, "proxy refused CONNECT (" .. code .. ")"
            end
            local s, werr = ssl.wrap(self.sock, params)
            if not s then return nil, werr end
            self.sock = s
            s:sni(host)
            s:settimeout(https.TIMEOUT)
            local hok, herr = s:dohandshake()
            if not hok then return nil, herr end
            if not s:checkhostname(host) then
                return nil, "hostname does not match certificate"
            end
            forward(self)
            return 1
        end
        return conn
    end
end

-- ---------------------------------------------------------------- request

local function copy(t)
    local out = {}
    for k, v in pairs(t or {}) do out[k] = v end
    return out
end

-- One request to one address, no redirects. Returns ok, code, headers, status, step.
local function hop(url, opts, sink, log)
    local http = require("socket.http")
    local ltn12 = require("ltn12")
    local u = HttpClient.parseUrl(url)
    if not u then return nil, "bad url" end
    local method = opts.method or "GET"
    local reqt = {
        url = url,
        method = method,
        headers = copy(opts.headers),
        sink = sink,
        redirect = false,
        -- A redirect's own body is never written to the caller's sink.
        response_headers = function(code, headers)
            if HttpClient.shouldFollow(code, method, opts.body ~= nil, headers and headers.location) then
                return true
            end
        end,
    }
    if opts.body then reqt.source = ltn12.source.string(opts.body) end
    local proxy = http.PROXY
    if u.scheme == "https" and proxy then
        local phost, pport = HttpClient.proxyAddress(proxy)
        if not phost then
            log(string.format("Novarr HTTP: proxy step: can't use the proxy %s for %s (%s)", tostring(proxy), u.origin, tostring(pport)))
            return nil, "proxy: " .. tostring(pport), nil, nil, "proxy"
        end
        local failed_step
        reqt.create = HttpClient.tunnel(phost, pport, opts.user_agent, function(step, err)
            failed_step = step
            log(string.format("Novarr HTTP: proxy step failed: %s (proxy %s, for %s): %s",
                step, HttpClient.authority(phost, pport), HttpClient.authority(u.host, u.port), tostring(err)))
        end)
        -- socket.http would send this request to http.PROXY: clear it for this one call.
        http.PROXY = nil
        local ok, a, code, headers, status = pcall(http.request, reqt)
        http.PROXY = proxy
        if not ok then return nil, tostring(a), nil, nil, failed_step and "proxy" end
        if a == nil then return nil, tostring(code), nil, nil, failed_step and "proxy" end
        return a, code, headers, status
    end
    local requester = u.scheme == "https" and require("ssl.https") or http
    local ok, a, code, headers, status = pcall(requester.request, reqt)
    if not ok then return nil, tostring(a) end
    if a == nil then return nil, tostring(code) end
    return a, code, headers, status
end

function HttpClient.request(opts)
    local socketutil = require("socketutil")
    local log = opts.log or function() end
    socketutil:set_timeout(opts.block_timeout or 10, opts.total_timeout or 30)
    local ok, res, err, step = pcall(function()
        local sink = opts.make_sink and opts.make_sink() or nil
        local url = opts.url
        for _i = 0, HttpClient.MAX_REDIRECTS do
            local a, code, headers, status, hstep = hop(url, opts, sink, log)
            if not a then return nil, code, hstep end
            if type(code) ~= "number" or headers == nil then
                return nil, tostring(status or code or "no response")
            end
            local location = headers.location
            if not HttpClient.shouldFollow(code, opts.method, opts.body ~= nil, location) then
                return { status = code, headers = headers, status_line = status, url = url }
            end
            local nxt = HttpClient.resolveLocation(url, location)
            if not nxt or not HttpClient.parseUrl(nxt) then
                return { status = code, headers = headers, status_line = status, url = url }
            end
            log(string.format("Novarr HTTP: %d redirect to %s", code, (HttpClient.parseUrl(nxt) or {}).origin or "?"))
            url = nxt
        end
        return nil, "too many redirects"
    end)
    socketutil:reset_timeout()
    if not ok then return nil, tostring(res) end
    return res, err, step
end

return HttpClient
