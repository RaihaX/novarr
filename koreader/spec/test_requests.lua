local T = ...
local R = require("novarr.requests")

T.it("media: books first", function()
    T.eq(R.MEDIA, { "book", "novel", "manga" })
end)

T.it("media (1.14.0): Comics last, only when the server has comics", function()
    local function features(on) return function(name) return on[name] == true end end
    T.eq(R.media(features({ comics = true })), { "book", "novel", "manga", "comic" })
    T.eq(R.media(features({})), { "book", "novel", "manga" }, "an older server: no Comics")
    T.eq(R.media(nil), { "book", "novel", "manga" })
    T.eq(R.MEDIA, { "book", "novel", "manga" }, "the base list is not changed")
end)

T.it("takesIsbn: books and comics are searched by title, author or ISBN", function()
    T.truthy(R.takesIsbn("book"))
    T.truthy(R.takesIsbn("comic"))
    T.falsy(R.takesIsbn("novel"))
    T.falsy(R.takesIsbn("manga"))
end)

T.it("cleanQuery: trims and squeezes; empty or over 100 characters is nil", function()
    T.eq(R.cleanQuery("  The   Name of  the Wind "), "The Name of the Wind")
    T.eq(R.cleanQuery("   "), nil)
    T.eq(R.cleanQuery(nil), nil)
    T.eq(R.cleanQuery(string.rep("a", 100)), string.rep("a", 100))
    T.eq(R.cleanQuery(string.rep("a", 101)), nil)
    -- 100 characters of two bytes each are still 100 characters.
    local e = string.rep("\195\169", 100)
    T.eq(R.cleanQuery(e), e)
    T.eq(R.cleanQuery("978-0-7653-2635-5"), "978-0-7653-2635-5", "an ISBN")
end)

T.it("resultAction: open, mine, me too, request", function()
    T.eq(R.resultAction({ in_library = true, novel_id = 4 }), "open")
    T.eq(R.resultAction({ in_library = true }), "request", "no novel to open")
    T.eq(R.resultAction({ requested = { status = "pending", by_me = true } }), "mine")
    T.eq(R.resultAction({ requested = { status = "approved", by_me = false } }), "me_too")
    T.eq(R.resultAction({ requested = { status = "pending", by_me = false } }), "me_too")
    T.eq(R.resultAction({ requested = { status = "available", by_me = false } }), "request")
    T.eq(R.resultAction({}), "request")
    T.eq(R.resultAction(nil), "request")
end)

T.it("resultLine: author, series #n, up to three sources", function()
    T.eq(R.resultLine({ author = "F. Herbert", series = "Dune", series_index = 1,
        sources = { { source_name = "Open Library" }, { source_name = "Goodreads" }, { source_name = "A" }, { source_name = "B" } } }),
        "F. Herbert · Dune #1 · Open Library, Goodreads, A")
    T.eq(R.resultLine({ series = "S", series_index = 1.5 }), "S #1.5")
    T.eq(R.resultLine({}), "")
end)

T.it("statusText: each status in a few words", function()
    T.eq(R.statusText({ status = "available" }), "Ready to read")
    T.eq(R.statusText({ status = "approved" }), "Approved, on its way")
    T.eq(R.statusText({ status = "declined", decline_reason = "Not found anywhere" }), "Declined: Not found anywhere")
    T.eq(R.statusText({ status = "declined" }), "Declined")
    T.eq(R.statusText({ status = "pending" }), "Waiting for approval")
    T.eq(R.statusText({ status = "pending", wanted = true }), "Waiting: the owner looks for it")
    T.eq(R.statusText({ status = "pending" }, function(s) return "<" .. s .. ">" end), "<Waiting for approval>")
    T.truthy(R.canOpen({ status = "available", novel_id = 3 }))
    T.falsy(R.canOpen({ status = "available" }))
    T.falsy(R.canOpen({ status = "approved", novel_id = 3 }))
end)

-- A fake clock and scheduler for the poll loop.
local function world(answers)
    local w = { log = {}, clock = 0, pending = nil, n = 0 }
    w.poll = R.Poll.new("s1", {
        get = function(id)
            w.n = w.n + 1
            table.insert(w.log, "GET " .. id)
            local a = answers[w.n] or answers[#answers]
            if a.err then return nil, a.err end
            return a
        end,
        schedule = function(secs, fn) w.pending = fn w.delay = secs end,
        unschedule = function(fn) if w.pending == fn then w.pending = nil end end,
        now = function() return w.clock end,
        on_done = function(search) table.insert(w.log, "done:" .. #(search.results or {})) end,
        on_finish = function(kind, p) table.insert(w.log, "finish:" .. kind .. (p and p.kind and (":" .. p.kind) or "")) end,
    })
    function w.tick()
        local fn = w.pending
        w.pending = nil
        w.clock = w.clock + (w.delay or 0)
        if fn then fn() end
    end
    function w.run(max)
        for _i = 1, max or 1000 do
            if not w.pending then return end
            w.tick()
        end
    end
    return w
end

T.it("poll: every 3 s until done", function()
    local w = world({ { status = "running" }, { status = "running" }, { status = "done", results = { {}, {} } } })
    w.poll:start()
    T.eq(w.delay, 3)
    w.run()
    T.eq(w.log, { "GET s1", "GET s1", "GET s1", "done:2" })
    T.eq(w.clock, 9)
end)

T.it("poll: failed, expired (404) and passing hiccups", function()
    local w = world({ { status = "failed", message = "boom" } })
    w.poll:start()
    w.run()
    T.eq(w.log[#w.log], "finish:failed")
    w = world({ { err = { kind = "offline" } }, { err = { kind = "server" } }, { status = "done" } })
    w.poll:start()
    w.run()
    T.eq(w.log[#w.log], "done:0", "offline / server errors: keep waiting")
    w = world({ { err = { kind = "not_found" } } })
    w.poll:start()
    w.run()
    T.eq(w.log[#w.log], "finish:error:not_found")
end)

T.it("poll: gives up after the timeout; cancel stops it", function()
    local w = world({ { status = "running" } })
    w.poll:start()
    w.run()
    T.eq(w.log[#w.log], "finish:timeout")
    T.eq(w.clock, R.TIMEOUT_SECONDS)
    w = world({ { status = "running" } })
    w.poll:start()
    w.tick()
    w.poll:cancel()
    T.eq(w.pending, nil)
    T.eq(w.log[#w.log], "finish:cancelled")
    w.poll:cancel()
    T.eq(#w.log, 2, "a second cancel does nothing")
end)
