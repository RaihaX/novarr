local T = ...
local Address = require("novarr.address")
local Api = require("novarr.api")
local Json = require("novarr.json")

local HOME = "http://192.168.1.34:8080"
local AWAY = "https://nas.example.ts.net"

-- Address with an injected probe (answers per base) and clock.
local function make(o)
    o = o or {}
    local clock = { t = 1000000 }
    local up = o.up or {}
    local probes, switches = {}, {}
    local a = Address.new({
        home = o.home or HOME, away = o.away == nil and AWAY or o.away, active = o.active,
        now = function() return clock.t end,
        probe = function(base)
            table.insert(probes, base)
            clock.t = clock.t + (o.probe_ms or 100)
            return up[base] and true or false
        end,
        on_switch = function(base, which, why) table.insert(switches, { base, which }) end,
    })
    return a, clock, up, probes, switches
end

T.it("one address: always that one, nothing probed, URLs untouched", function()
    local a, _clock, _up, probes = make({ away = false })
    T.falsy(a:hasTwo())
    T.eq(a:base(), HOME)
    T.eq(a:failed(HOME, "timeout"), nil)
    a:invalidate("start")
    T.eq(a:base(), HOME)
    T.eq(#probes, 0)
    T.eq(a:rewrite("http://192.168.1.34:8080/c.jpg"), "http://192.168.1.34:8080/c.jpg")
    T.eq(a:which(), "home")
end)

T.it("the remembered active address is kept; an unknown one falls back to home", function()
    T.eq(make({ active = AWAY }):base(), AWAY)
    T.eq(make({ active = "http://old" }):base(), HOME)
    local a = make({ away = HOME })
    T.falsy(a:hasTwo(), "the same address twice is one address")
end)

T.it("home fails, second answers: switch, log it, retry there", function()
    local a, _clock, up, probes, switches = make({ up = { [AWAY] = true } })
    T.eq(a:failed(HOME, "connection refused"), AWAY)
    T.eq(a:base(), AWAY)
    T.eq(a:which(), "away")
    T.eq(probes, { AWAY })
    T.eq(switches, { { AWAY, "away" } })
end)

T.it("both down: one probe, then none for 60 s, then one again", function()
    local a, clock, _up, probes = make()
    T.eq(a:failed(HOME, "timeout"), nil)
    T.eq(#probes, 1)
    clock.t = clock.t + 30 * 1000
    T.eq(a:failed(HOME, "timeout"), nil)
    T.eq(a:failed(HOME, "timeout"), nil)
    T.eq(#probes, 1, "no probe within 60 s of the last one")
    clock.t = clock.t + 31 * 1000
    T.eq(a:failed(HOME, "timeout"), nil)
    T.eq(#probes, 2)
    T.eq(a:base(), HOME, "stays on the home address")
end)

T.it("a failure of an address that is no longer active retries on the active one", function()
    local a = make({ active = AWAY })
    T.eq(a:failed(HOME, "timeout"), AWAY)
end)

T.it("on the second address: a failure probes home and switches back", function()
    local a, _clock, up, probes = make({ active = AWAY, up = { [HOME] = true } })
    T.eq(a:failed(AWAY, "timeout"), HOME)
    T.eq(a:which(), "home")
    T.eq(probes, { HOME })
end)

T.it("re-probe on start / network / wake-up starts with home, lazily, before the next request", function()
    local a, clock, up, probes = make({ active = AWAY })
    a:invalidate("wake-up")
    T.eq(#probes, 0, "nothing until a request is made")
    T.eq(a:base(), AWAY, "home did not answer")
    T.eq(probes, { HOME })
    T.eq(a:base(), AWAY)
    T.eq(#probes, 1, "one re-probe per trigger")
    -- Home is back: the next trigger within 60 s of the failed probe waits...
    up[HOME] = true
    a:invalidate("network connected")
    T.eq(a:base(), AWAY)
    T.eq(#probes, 1, "a failed re-probe is not repeated within 60 s")
    -- ...but the user tapping Sync forces it.
    T.eq(a:reprobe("Sync", { force = true }), HOME)
    T.eq(#probes, 2)
    T.eq(a:which(), "home")
end)

T.it("re-probe after 60 s switches back to home", function()
    local a, clock, up, probes = make({ active = AWAY })
    a:invalidate("start")
    a:base()
    up[HOME] = true
    clock.t = clock.t + 61 * 1000
    a:invalidate("wake-up")
    T.eq(a:base(), HOME)
    T.eq(#probes, 2)
end)

T.it("re-probe while home is active probes nothing", function()
    local a, _clock, _up, probes = make()
    a:invalidate("start")
    T.eq(a:base(), HOME)
    T.eq(#probes, 0)
end)

T.it("rewrite: URLs on either address go to the active one", function()
    local a = make({ active = AWAY })
    T.eq(a:rewrite("http://192.168.1.34:8080/storage/covers/2-md.jpg"), "https://nas.example.ts.net/storage/covers/2-md.jpg")
    T.eq(a:rewrite("https://nas.example.ts.net/novels/6/epub"), "https://nas.example.ts.net/novels/6/epub")
    T.eq(a:rewrite("http://192.168.1.34/x.jpg"), "http://192.168.1.34/x.jpg", "another port is another host")
    T.eq(a:rewrite("https://images.example.com/c.jpg"), "https://images.example.com/c.jpg", "other hosts untouched")
    T.eq(a:rewrite("HTTP://192.168.1.34:8080/a?b=1"), "https://nas.example.ts.net/a?b=1", "scheme and host case")
    a.active = HOME
    T.eq(a:rewrite("https://nas.example.ts.net:443/c.jpg"), "http://192.168.1.34:8080/c.jpg", "default port spelled out")
end)

T.it("rewrite: path prefixes of the addresses are swapped", function()
    local a = make({ home = "http://192.168.1.34:8080", away = "https://nas.ts.net/novarr", active = "https://nas.ts.net/novarr" })
    T.eq(a:rewrite("http://192.168.1.34:8080/storage/c.jpg"), "https://nas.ts.net/novarr/storage/c.jpg")
    a.active = a.home
    T.eq(a:rewrite("https://nas.ts.net/novarr/storage/c.jpg"), "http://192.168.1.34:8080/storage/c.jpg")
    T.eq(a:rewrite("https://nas.ts.net/other/c.jpg"), "http://192.168.1.34:8080/other/c.jpg", "origin only")
end)

T.it("canonical: cache keys on the home address; owns", function()
    local a = make({ active = AWAY })
    T.eq(a:canonical("https://nas.example.ts.net/c.jpg"), "http://192.168.1.34:8080/c.jpg")
    T.eq(a:canonical("http://192.168.1.34:8080/c.jpg"), "http://192.168.1.34:8080/c.jpg")
    T.truthy(a:owns("https://nas.example.ts.net/c.jpg"))
    T.falsy(a:owns("https://cdn.example.com/c.jpg"))
end)

-- Api + router: one retry on the other address, only for "no answer".
local function api(o)
    local a, clock, up, probes = make(o)
    local log = {}
    local answers = o.answers
    local transport = function(req)
        table.insert(log, req.url)
        local base = req.url:match("^(https?://[^/]+)")
        local r = answers[base]
        if r == "down" then return nil, "connection refused" end
        return { status = r or 200, headers = {}, body = Json.encode({ app = "novarr", api_version = 1, novels = {} }) }
    end
    return Api.new({ base_url = HOME, router = a, transport = transport }), log, a, probes
end

T.it("Api: no answer from home -> switches and retries once on the second address", function()
    local client, log, a = api({ up = { [AWAY] = true }, answers = { [HOME] = "down" } })
    local data, e = client:novels()
    T.truthy(data, "the action succeeds")
    T.eq(e, nil)
    T.eq(log, { HOME .. "/api/v1/novels", AWAY .. "/api/v1/novels" })
    T.eq(a:which(), "away")
    client:novels()
    T.eq(log[3], AWAY .. "/api/v1/novels", "later requests go to the second address")
end)

T.it("Api: HTTP errors (404, 500) never switch", function()
    local client, log, a, probes = api({ up = { [AWAY] = true }, answers = { [HOME] = 404 } })
    local _d, e = client:novel(1)
    T.eq(e.kind, "not_found")
    T.eq(#log, 1)
    T.eq(#probes, 0)
    client, log, a, probes = api({ up = { [AWAY] = true }, answers = { [HOME] = 503 } })
    _d, e = client:novels()
    T.eq(e.kind, "server")
    T.eq(#probes, 0)
    T.eq(a:which(), "home")
end)

T.it("Api: both down -> offline, one probe, the next request does not probe again", function()
    local client, log, _a, probes = api({ answers = { [HOME] = "down", [AWAY] = "down" } })
    local _d, e = client:novels()
    T.eq(e.kind, "offline")
    T.eq(#probes, 1)
    _d, e = client:home()
    T.eq(e.kind, "offline")
    T.eq(#probes, 1)
    T.eq(#log, 2, "one request each, no retries")
end)

T.it("Api: plugin files go through the same retry", function()
    local client, log = api({ up = { [AWAY] = true }, answers = { [HOME] = "down" } })
    local bytes = client:pluginFile("main.lua")
    T.truthy(bytes)
    T.eq(log[2], AWAY .. "/api/v1/koreader/plugin/files/main.lua")
end)

T.it("external URLs (Discover covers on a source's image host) are never rewritten", function()
    local ext = "https://images.novelping.com/novel/shadow-slave.jpg"
    for _i, active in ipairs({ HOME, AWAY }) do
        local a = make({ active = active })
        T.eq(a:rewrite(ext), ext)
        T.eq(a:canonical(ext), ext)
        T.falsy(a:owns(ext))
    end
    local one = make({ away = false })
    T.eq(one:rewrite(ext), ext)
    T.falsy(one:owns(ext))
    -- Same host as Novarr but another port or scheme: not Novarr either.
    local a = make({ active = AWAY })
    T.eq(a:rewrite("https://192.168.1.34/x.jpg"), "https://192.168.1.34/x.jpg")
end)
