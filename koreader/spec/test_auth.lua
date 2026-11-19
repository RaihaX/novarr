local T, plugin_dir = ...
-- Runners that predate auth.lua have no preload for it.
if not package.preload["novarr.auth"] and plugin_dir then
    package.preload["novarr.auth"] = function() return dofile(plugin_dir .. "/auth.lua") end
end
local Api = require("novarr.api")
local Auth = require("novarr.auth")
local Json = require("novarr.json")
local Policy = require("novarr.wifipolicy")
local Queue = require("novarr.queue")
local Sync = require("novarr.sync")

-- A LuaSettings stand-in (readSetting/saveSetting/delSetting/flush).
local function settings(data)
    local s = { data = data or {}, flushes = 0 }
    function s:readSetting(k) return self.data[k] end
    function s:saveSetting(k, v) self.data[k] = v end
    function s:delSetting(k) self.data[k] = nil end
    function s:flush() self.flushes = self.flushes + 1 end
    return s
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

local function json(status, value)
    return { status = status, headers = {}, body = Json.encode(value) }
end

local HOME, AWAY, OTHER = "http://192.168.1.34:8080", "https://novarr.example.ts.net", "http://10.0.0.9"
local TOKEN = "1|abcdefABCDEF0123456789"

-- An Api wired to Auth over a fake settings object, as Session:rebuildApi does.
local function linkedApi(responses, st, server)
    server = server or HOME
    local transport, log = fake(responses)
    local unauth = {}
    local api = Api.new({ base_url = server, transport = transport,
        device = { id = "k_AbCdEfGhIjKlMnOpQrStUv", name = "Kindle" },
        auth = {
            token = function() return Auth.token(st, server) end,
            blocked = function() return Auth.needsLink(st, server) end,
            on_unauthenticated = function(e)
                table.insert(unauth, e)
                Auth.forget(st, server)
                Auth.setRequired(st, server, true)
            end,
        } })
    return api, log, unauth
end

-- ---------------------------------------------------------------- header added / omitted

T.it("Authorization: Bearer on API requests when a token is held", function()
    local st = settings()
    Auth.link(st, HOME, TOKEN, { name = "Reyhan", role = "admin" })
    local api, log = linkedApi({ default = json(200, { novels = {}, success = true }) }, st)
    api:novels()
    api:sendWrite({ kind = "progress", chapter_id = 5, progress = 40 }, 0)
    T.eq(log[1].headers["Authorization"], "Bearer " .. TOKEN)
    T.eq(log[2].headers["Authorization"], "Bearer " .. TOKEN)
    -- The device headers stay as they are.
    T.eq(log[2].headers["X-Novarr-Device"], "k_AbCdEfGhIjKlMnOpQrStUv")
end)

T.it("no token: no Authorization header (and no auth at all: as before 1.9.0)", function()
    local st = settings()
    local api, log = linkedApi({ default = json(200, { novels = {} }) }, st)
    api:novels()
    T.eq(log[1].headers["Authorization"], nil)
    local transport, log2 = fake({ default = json(200, { novels = {} }) })
    Api.new({ base_url = HOME, transport = transport }):novels()
    T.eq(log2[1].headers["Authorization"], nil)
end)

T.it("never on meta, pairing, POST auth/token or the plugin update endpoints", function()
    local st = settings()
    Auth.link(st, HOME, TOKEN, {})
    local api, log = linkedApi({ default = json(200, { app = "novarr", api_version = 1, status = "pending",
        code = "K7M4QX", secret = "ab", plugin = { version = "1.9.0" } }) }, st)
    api:meta()
    api:pairingStart()
    api:pairingStatus("ab12")
    api:request("auth/token", { method = "POST", body = { username = "u", password = "p" } })
    api:pluginManifest()
    api:pluginFile("main.lua")
    T.eq(#log, 6)
    for i = 1, 6 do T.eq(log[i].headers["Authorization"], nil, log[i].url) end
end)

T.it("the exempt list follows the contract", function()
    T.truthy(Api.authExempt("meta"))
    T.truthy(Api.authExempt("auth/pairings", "POST"))
    T.truthy(Api.authExempt("auth/pairings/abc123"))
    T.truthy(Api.authExempt("auth/token", "POST"))
    T.falsy(Api.authExempt("auth/token", "DELETE"))
    T.falsy(Api.authExempt("auth/me"))
    T.truthy(Api.authExempt("koreader/plugin"))
    T.truthy(Api.authExempt("koreader/plugin/files/main.lua"))
    T.falsy(Api.authExempt("koreader/pluginx"))
    T.falsy(Api.authExempt("novels"))
    T.falsy(Api.authExempt("discover/browse?type=x"))
    T.falsy(Api.authExempt("metadata"))
end)

T.it("Unlink: DELETE auth/token carries the token; its 401 does not count as signed out", function()
    local st = settings()
    Auth.link(st, HOME, TOKEN, {})
    local api, log, unauth = linkedApi({ json(401, { message = "Unauthenticated.", code = "unauthenticated" }) }, st)
    local _ok, e = api:revokeToken()
    T.eq(log[1].method, "DELETE")
    T.eq(log[1].url, HOME .. "/api/v1/auth/token")
    T.eq(log[1].headers["Authorization"], "Bearer " .. TOKEN)
    T.eq(e.kind, "unauthenticated")
    T.eq(#unauth, 0)
end)

T.it("bearer: only printable tokens without spaces, at most MAX_TOKEN", function()
    T.eq(Auth.bearer(TOKEN), "Bearer " .. TOKEN)
    T.eq(Auth.bearer(""), nil)
    T.eq(Auth.bearer("a b"), nil)
    T.eq(Auth.bearer("abc\r\nX-Evil: 1"), nil)
    T.eq(Auth.bearer(string.rep("a", Auth.MAX_TOKEN + 1)), nil)
    T.eq(Auth.bearer(nil), nil)
end)

-- ---------------------------------------------------------------- token per server

T.it("one token for the server, whichever address; another server reads as empty", function()
    local st = settings()
    T.truthy(Auth.link(st, HOME, TOKEN, { name = "Reyhan", username = "reyhan", role = "reader", id = 3 }))
    T.eq(Auth.token(st, HOME), TOKEN)
    T.eq(Auth.user(st, HOME), { id = 3, name = "Reyhan", username = "reyhan", role = "reader" })
    T.eq(Auth.token(st, OTHER), nil)
    T.eq(Auth.user(st, OTHER), nil)
    T.falsy(Auth.required(st, OTHER))
    -- Stored once, in the plugin's settings.
    T.eq(st.data.auth.server, HOME)
    T.truthy(st.flushes > 0)
end)

T.it("switching to a different server forgets it; the away address becoming home keeps it", function()
    local st = settings()
    Auth.link(st, HOME, TOKEN, { name = "R" })
    -- Session:setServer(away): the same server.
    T.truthy(Auth.moveServer(st, HOME, AWAY))
    T.eq(Auth.token(st, AWAY), TOKEN)
    T.eq(Auth.token(st, HOME), nil)
    -- Moving from an address the record is not for does nothing.
    T.falsy(Auth.moveServer(st, OTHER, HOME))
    -- Session:setServer(other): cleared.
    T.truthy(Auth.clear(st))
    T.eq(st.data.auth, nil)
    T.eq(Auth.token(st, AWAY), nil)
    T.falsy(Auth.clear(st))
end)

T.it("forget keeps 'sign-in required' (so nothing is sent until linked again)", function()
    local st = settings()
    Auth.link(st, HOME, TOKEN, {})
    T.truthy(Auth.forget(st, HOME))
    T.eq(Auth.token(st, HOME), nil)
    T.truthy(Auth.required(st, HOME))
    T.truthy(Auth.needsLink(st, HOME))
    T.falsy(Auth.forget(st, OTHER))
end)

-- ---------------------------------------------------------------- meta's auth flag

T.it("meta: auth absent, false or true", function()
    T.falsy(Auth.requiredFromMeta({ app = "novarr" }))
    T.falsy(Auth.requiredFromMeta({ app = "novarr", auth = { required = false } }))
    T.falsy(Auth.requiredFromMeta({ app = "novarr", auth = { required = "yes" } }))
    T.falsy(Auth.requiredFromMeta({ app = "novarr", auth = true }))
    T.falsy(Auth.requiredFromMeta(nil))
    T.truthy(Auth.requiredFromMeta({ app = "novarr", auth = { required = true, methods = { "password", "pairing" } } }))
end)

T.it("absent/false: no account UI, nothing blocked, admin; true without a token: Link", function()
    for _, meta in ipairs({ { app = "novarr" }, { app = "novarr", auth = { required = false } } }) do
        local st = settings()
        Auth.setRequired(st, HOME, Auth.requiredFromMeta(meta))
        T.truthy(Auth.known(st, HOME))
        T.eq(Auth.accountRows(st, HOME), {})
        T.falsy(Auth.needsLink(st, HOME))
        T.eq(Auth.role(st, HOME), "admin")
        T.eq(Auth.homeButtons(Auth.role(st, HOME), Auth.needsLink(st, HOME)),
            { "library", "discover", "downloads", "sync", "settings" })
    end
    local st = settings()
    T.falsy(Auth.known(st, HOME))
    Auth.setRequired(st, HOME, Auth.requiredFromMeta({ app = "novarr", auth = { required = true } }))
    T.truthy(Auth.needsLink(st, HOME))
    T.eq(Auth.accountRows(st, HOME), { { id = "link" } })
    T.eq(Auth.homeButtons(Auth.role(st, HOME), true), { "library", "discover", "downloads", "link", "settings" })
    Auth.link(st, HOME, TOKEN, { name = "Reyhan", role = "admin" })
    T.eq(Auth.accountRows(st, HOME), { { id = "account", name = "Reyhan", role = "admin" }, { id = "unlink" } })
    -- The server switched sign-in off later: no account UI, the token is kept.
    Auth.setRequired(st, HOME, false)
    T.eq(Auth.accountRows(st, HOME), {})
    T.eq(Auth.token(st, HOME), TOKEN)
end)

T.it("not linked: nothing but exempt requests goes out", function()
    local st = settings()
    Auth.setRequired(st, HOME, true)
    local api, log = linkedApi({ default = json(200, { app = "novarr", api_version = 1, novels = {} }) }, st)
    local data, e = api:novels()
    T.eq(data, nil)
    T.eq(e.kind, "unauthenticated")
    T.eq(e.status, nil)
    T.eq(#log, 0)
    T.truthy(api:meta())
    T.eq(#log, 1)
end)

-- ---------------------------------------------------------------- 401 / 403

T.it("401 keeps the queue, forgets the token, and stops the next flush from sending", function()
    local st = settings()
    Auth.link(st, HOME, TOKEN, { name = "R" })
    local api, log, unauth = linkedApi({ default = json(401, { message = "Unauthenticated.", code = "unauthenticated" }) }, st)
    local queue = Queue.new({ load = function() return nil end, save = function() end, now = function() return 1000 end })
    queue:enqueue({ kind = "progress", chapter_id = 10, novel_id = 1, progress = 50 })
    queue:enqueue({ kind = "set_read", chapter_id = 11, novel_id = 1, read = true })
    queue:enqueue({ kind = "highlight", chapter_id = 10, novel_id = 1, hkey = "k", excerpt = "x" })
    local before = queue:list()
    local r = Sync.flush({ api = api, queue = queue, now = function() return 2000 end })
    T.eq(#log, 1) -- stopped at the first entry
    T.eq(r.sent, 0)
    T.eq(r.dropped, 0)
    T.eq(r.remaining, 3)
    T.eq(r.error.kind, "unauthenticated")
    T.eq(queue:list(), before) -- not dropped, not reordered
    T.eq(#unauth, 1)
    T.eq(unauth[1].message, "Unauthenticated.")
    T.eq(Auth.token(st, HOME), nil)
    T.truthy(Auth.needsLink(st, HOME))
    -- A background flush now sends nothing at all (no retry storm).
    local r2 = Sync.flush({ api = api, queue = queue })
    T.eq(#log, 1)
    T.eq(r2.remaining, 3)
    -- Linked again: everything goes.
    Auth.link(st, HOME, "2|newtoken", {})
    local api2, log2 = linkedApi({ default = json(200, { success = true, highlight = { id = 1 } }) }, st)
    local r3 = Sync.flush({ api = api2, queue = queue })
    T.eq(r3.sent, 3)
    T.eq(queue:count(), 0)
    T.eq(log2[1].headers["Authorization"], "Bearer 2|newtoken")
end)

T.it("401 and unauthenticated are retryable; 403 is not", function()
    T.truthy(Api.isRetryable({ kind = "unauthenticated", status = 401 }))
    T.falsy(Api.isRetryable({ kind = "forbidden", status = 403 }))
end)

T.it("403: forbidden with the server's message", function()
    local st = settings()
    Auth.link(st, HOME, TOKEN, { role = "reader" })
    local api, _log, unauth = linkedApi({ json(403, { message = "Readers can't add novels.", code = "forbidden" }) }, st)
    local data, e = api:addNovel("t", "u")
    T.eq(data, nil)
    T.eq(e.kind, "forbidden")
    T.eq(e.status, 403)
    T.eq(e.message, "Readers can't add novels.")
    T.eq(#unauth, 0)
    T.eq(Auth.token(st, HOME), TOKEN) -- still linked
end)

T.it("a 401 that is not JSON (a proxy's login page) is server trouble, not a sign-out", function()
    local st = settings()
    Auth.link(st, HOME, TOKEN, {})
    local api, _log, unauth = linkedApi({ { status = 401, headers = {}, body = "<html>Login</html>" } }, st)
    local _d, e = api:novels()
    T.eq(e.kind, "server")
    T.eq(e.status, 401)
    T.eq(e.message, "login page")
    T.truthy(Api.isRetryable(e))
    T.eq(#unauth, 0)
    T.eq(Auth.token(st, HOME), TOKEN)
end)

T.it("meta answering 401 still means 'not Novarr' (exempt: no sign-out)", function()
    local st = settings()
    Auth.link(st, HOME, TOKEN, {})
    local api, _log, unauth = linkedApi({ json(401, { message = "x" }) }, st)
    local _m, e = api:meta()
    T.eq(e.kind, "not_novarr")
    T.eq(#unauth, 0)
end)

-- ---------------------------------------------------------------- reader role

T.it("reader: no Discover, no adding; reading, sync and downloads stay", function()
    local st = settings()
    Auth.link(st, HOME, TOKEN, { name = "Kid", role = "reader" })
    T.eq(Auth.role(st, HOME), "reader")
    T.eq(Auth.homeButtons("reader", false), { "library", "downloads", "sync", "settings" })
    T.falsy(Auth.allows("reader", "discover"))
    T.falsy(Auth.allows("reader", "add_novel"))
    for _, action in ipairs({ "read", "sync", "check", "download", "epub", "highlights", "mark_read" }) do
        T.truthy(Auth.allows("reader", action), action)
    end
    T.truthy(Auth.allows("admin", "discover"))
    T.eq(Auth.accountRows(st, HOME)[1].role, "reader")
end)

T.it("role is admin when sign-in is not required, and for unknown roles", function()
    local st = settings()
    Auth.link(st, HOME, TOKEN, { role = "reader" })
    Auth.setRequired(st, HOME, false)
    T.eq(Auth.role(st, HOME), "admin")
    T.eq(Auth.cleanUser({ role = "owner", username = "x" }), { name = "x", username = "x", role = "admin" })
    T.eq(Auth.cleanUser("nope"), nil)
end)

-- ---------------------------------------------------------------- pairing

local function pairingWorld(starts, polls)
    local w = { log = {}, clock = 0, pending = nil, delay = nil, np = 0, linked = nil }
    w.flow = Auth.Pairing.new({
        start = function()
            table.insert(w.log, "POST")
            local r = starts
            if r.err then return nil, r.err end
            return r
        end,
        poll = function(secret)
            w.np = w.np + 1
            table.insert(w.log, "GET " .. secret)
            local r = polls[w.np] or polls[#polls]
            if r.err then return nil, r.err end
            return r
        end,
        schedule = function(secs, fn) w.pending = fn; w.delay = secs end,
        unschedule = function(fn) if w.pending == fn then w.pending = nil end end,
        now = function() return w.clock end,
        on_code = function(code) table.insert(w.log, "code " .. code) end,
        on_linked = function(token, user) w.linked = { token = token, user = user } end,
        on_finish = function(kind, p)
            table.insert(w.log, "finish:" .. kind .. (type(p) == "table" and p.name and (":" .. p.name) or ""))
        end,
    })
    function w.run(max)
        for _i = 1, max or 1000 do
            local fn = w.pending
            if not fn then return end
            w.pending = nil
            w.clock = w.clock + w.delay
            fn()
        end
    end
    return w
end

local CODE = { code = "K7M4QX", secret = "ab12cd", expires_in = 600, interval = 3 }

T.it("pairing: code shown as 'K7M 4QX', polls every interval, pending -> linked", function()
    local w = pairingWorld(CODE, { { status = "pending" }, { status = "pending" },
        { status = "linked", token = TOKEN, user = { name = "Reyhan", role = "admin", id = 1 } } })
    w.flow:start()
    T.eq(w.log, { "POST", "code K7M 4QX" })
    T.eq(w.delay, 3)
    w.run()
    T.eq(w.log, { "POST", "code K7M 4QX", "GET ab12cd", "GET ab12cd", "GET ab12cd", "finish:linked:Reyhan" })
    T.eq(w.linked.token, TOKEN)
    T.eq(w.linked.user.role, "admin")
    T.eq(w.flow.state, "done")
    T.eq(w.pending, nil)
    T.eq(w.clock, 9)
end)

T.it("pairing: 404 while polling -> expired", function()
    local w = pairingWorld(CODE, { { status = "pending" }, { err = { kind = "not_found", status = 404 } } })
    w.flow:start()
    w.run()
    T.eq(w.log[#w.log], "finish:expired")
    T.eq(w.linked, nil)
    T.eq(w.pending, nil)
end)

T.it("pairing: the code's own lifetime ends it as expired", function()
    local w = pairingWorld({ code = "K7M4QX", secret = "s", expires_in = 10, interval = 3 }, { { status = "pending" } })
    w.flow:start()
    w.run()
    T.eq(w.log[#w.log], "finish:expired")
    T.eq(w.np, 4) -- at 3, 6, 9 and 12 s
end)

T.it("pairing: cancel stops polling, nothing more is sent", function()
    local w = pairingWorld(CODE, { { status = "pending" } })
    w.flow:start()
    w.run(2)
    T.eq(w.np, 2)
    w.flow:cancel()
    T.eq(w.log[#w.log], "finish:cancelled")
    T.eq(w.pending, nil)
    w.flow:pollOnce() -- a late timer
    T.eq(w.np, 2)
    w.flow:cancel() -- twice: one finish only
    local n = 0
    for _, l in ipairs(w.log) do if l:match("^finish") then n = n + 1 end end
    T.eq(n, 1)
end)

T.it("pairing: cancelled while a poll was waiting for its answer", function()
    local w
    w = pairingWorld(CODE, { { status = "linked", token = TOKEN, user = {} } })
    w.flow.o.poll = function()
        w.flow:cancel() -- the dialog closed meanwhile
        return { status = "linked", token = TOKEN, user = {} }
    end
    w.flow:start()
    w.run()
    T.eq(w.linked, nil)
    T.eq(w.log[#w.log], "finish:cancelled")
end)

T.it("pairing: no answer (offline, 5xx, 429) keeps polling; other errors end it", function()
    local w = pairingWorld(CODE, { { err = { kind = "offline" } }, { err = { kind = "server", status = 503 } },
        { err = { kind = "invalid", status = 429 } }, { status = "linked", token = TOKEN, user = { name = "R" } } })
    w.flow:start()
    w.run()
    T.eq(w.log[#w.log], "finish:linked:R")
    local w2 = pairingWorld(CODE, { { err = { kind = "invalid", status = 422, message = "no" } } })
    w2.flow:start()
    w2.run()
    T.eq(w2.log[#w2.log], "finish:error")
end)

T.it("pairing: start failing, a malformed code or a linked answer without token", function()
    local w = pairingWorld({ err = { kind = "offline" } }, {})
    w.flow:start()
    T.eq(w.log, { "POST", "finish:error" })
    local w2 = pairingWorld({ code = "K7M4Q0", secret = "s" }, {}) -- 0 is not in the alphabet
    w2.flow:start()
    T.eq(w2.log, { "POST", "finish:error" })
    local w3 = pairingWorld(CODE, { { status = "linked", token = "" } })
    w3.flow:start()
    w3.run()
    T.eq(w3.log[#w3.log], "finish:error")
    T.eq(w3.linked, nil)
end)

T.it("pairing: interval clamped to 2..30 s, defaults when missing", function()
    local w = pairingWorld({ code = "K7M4QX", secret = "s", interval = 0.1 }, { { status = "pending" } })
    w.flow:start()
    T.eq(w.delay, Auth.MIN_INTERVAL)
    local w2 = pairingWorld({ code = "k7m4qx", secret = "s" }, { { status = "pending" } })
    w2.flow:start()
    T.eq(w2.delay, Auth.DEFAULT_INTERVAL)
    T.eq(w2.log[2], "code K7M 4QX")
end)

T.it("pairing requests: POST with device id and name, GET with the secret", function()
    local transport, log = fake({ json(201, CODE), json(200, { status = "pending" }) })
    local api = Api.new({ base_url = HOME, transport = transport,
        device = { id = "k_AbCdEfGhIjKlMnOpQrStUv", name = "Kindle" } })
    local data = api:pairingStart()
    T.eq(data.code, "K7M4QX")
    T.eq(log[1].method, "POST")
    T.eq(log[1].url, HOME .. "/api/v1/auth/pairings")
    T.eq(Json.decode(log[1].body), { device_id = "k_AbCdEfGhIjKlMnOpQrStUv", device_name = "Kindle" })
    T.eq(api:pairingStatus("ab12cd").status, "pending")
    T.eq(log[2].url, HOME .. "/api/v1/auth/pairings/ab12cd")
end)

T.it("formatCode / validCode", function()
    T.eq(Auth.formatCode("K7M4QX"), "K7M 4QX")
    T.eq(Auth.formatCode("k7m4qx"), "K7M 4QX")
    T.eq(Auth.formatCode(nil), "")
    T.truthy(Auth.validCode("K7M4QX"))
    T.falsy(Auth.validCode("K7M4Q"))
    T.falsy(Auth.validCode("I0O1LX"))
end)

-- ---------------------------------------------------------------- Wi-Fi while not linked

T.it("Wi-Fi management never switches Wi-Fi on for a Kindle that is not linked", function()
    local s = { enabled = true, managing = true, wifi_on = false, off_by_novarr = true, queue = 0,
        finished_unsynced = 0, sync_due = "woke up", position_due = "woke up", target = 100, has_list = true,
        ahead = 0, now = 1000, not_linked = true }
    local action, reason = Policy.decide(s)
    T.eq(action, "nothing")
    T.contains(reason, "not linked")
    s.not_linked = nil
    T.eq((Policy.decide(s)), "on")
end)
