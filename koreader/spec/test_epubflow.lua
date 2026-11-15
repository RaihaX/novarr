local T = ...
local Flow = require("novarr.epubflow")

-- Fake world: scripted GET/POST answers and a manual clock/scheduler.
local function world(gets, posts, stored)
    local w = { log = {}, clock = 0, pending = nil, gets = gets, posts = posts or {}, ng = 0, np = 0 }
    w.flow = Flow.new({
        get = function()
            w.ng = w.ng + 1
            local r = w.gets[w.ng] or w.gets[#w.gets]
            table.insert(w.log, "GET")
            if r.err then return nil, r.err end
            return r
        end,
        post = function()
            w.np = w.np + 1
            local r = w.posts[w.np]
            table.insert(w.log, "POST")
            if r.err then return nil, r.err end
            return r
        end,
        schedule = function(secs, fn) w.pending = fn; w.delay = secs end,
        unschedule = function(fn) if w.pending == fn then w.pending = nil end end,
        now = function() return w.clock end,
        stored = stored,
        on_download = function(e) table.insert(w.log, "download:" .. tostring(e.generated_at)) end,
        on_up_to_date = function() table.insert(w.log, "up_to_date") end,
        on_waiting = function() table.insert(w.log, "waiting") end,
        on_finish = function(kind, p) table.insert(w.log, "finish:" .. kind .. (p and p.message and (":" .. p.message) or "")) end,
    })
    -- Runs scheduled polls until none is pending (or max steps).
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

local READY = { status = "ready", stale = false, generated_at = "G1", download_url = "u" }

T.it("ready and new -> download", function()
    local w = world({ READY })
    w.flow:start()
    T.eq(w.log, { "GET", "download:G1" })
end)

T.it("ready and same generated_at as the copy here -> up to date", function()
    local w = world({ READY }, nil, { generated_at = "G1" })
    w.flow:start()
    T.eq(w.log, { "GET", "up_to_date" })
end)

T.it("missing -> build, poll every 3 s, then download", function()
    local w = world({ { status = "missing" }, { status = "building" }, { status = "building" }, READY },
        { { status = "building" } })
    w.flow:start()
    T.eq(w.delay, 3)
    w.run()
    T.eq(w.log, { "GET", "POST", "waiting", "GET", "GET", "GET", "download:G1" })
end)

T.it("ready but stale -> rebuild without asking", function()
    local w = world({ { status = "ready", stale = true, generated_at = "G0" }, { status = "ready", generated_at = "G2" } },
        { { status = "building" } }, { generated_at = "G0" })
    w.flow:start()
    w.run()
    T.eq(w.log, { "GET", "POST", "waiting", "GET", "download:G2" })
end)

T.it("failed -> rebuild; failing again shows the server's message", function()
    local w = world({ { status = "failed" }, { status = "failed", message = "Out of disk" } }, { { status = "building" } })
    w.flow:start()
    w.run()
    T.eq(w.log, { "GET", "POST", "waiting", "GET", "finish:failed:Out of disk" })
end)

T.it("building on the first GET -> straight to waiting (no POST)", function()
    local w = world({ { status = "building" }, READY })
    w.flow:start()
    w.run()
    T.eq(w.log, { "GET", "waiting", "GET", "download:G1" })
end)

T.it("POST answering 200 ready -> download at once", function()
    local w = world({ { status = "missing" } }, { READY })
    w.flow:start()
    T.eq(w.log, { "GET", "POST", "download:G1" })
end)

T.it("POST 422 -> the server's message", function()
    local w = world({ { status = "missing" } }, { { err = { kind = "invalid", status = 422, message = "No chapters downloaded." } } })
    w.flow:start()
    T.eq(w.log, { "GET", "POST", "finish:error:No chapters downloaded." })
end)

T.it("offline on the first GET -> error (offline)", function()
    local w = world({ { err = { kind = "offline" } } })
    local kind
    w.flow.o.on_finish = function(k, e) kind = k .. ":" .. e.kind end
    w.flow:start()
    T.eq(kind, "error:offline")
end)

T.it("a network hiccup while waiting keeps polling", function()
    local w = world({ { status = "building" }, { err = { kind = "offline" } }, READY })
    w.flow:start()
    w.run()
    T.eq(w.log, { "GET", "waiting", "GET", "GET", "download:G1" })
end)

T.it("cancel stops polling only", function()
    local w = world({ { status = "building" } })
    w.flow:start()
    w.flow:cancel()
    T.eq(w.pending, nil)
    w.run()
    T.eq(w.log, { "GET", "waiting", "finish:cancelled" })
end)

T.it("gives up after 10 minutes of building", function()
    local w = world({ { status = "building" } })
    w.flow:start()
    w.run()
    T.eq(w.log[#w.log], "finish:timeout")
    T.eq(w.clock, 600)
    T.eq(w.ng, 1 + 200)
end)
