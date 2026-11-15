local T = ...
local Queue = require("novarr.queue")

local function newQueue(initial, now)
    local saved = { value = initial, saves = 0 }
    local clock = { t = now or 1000000 }
    local q = Queue.new({
        load = function() return saved.value end,
        save = function(items)
            saved.saves = saved.saves + 1
            local copy = {}
            for i, e in ipairs(items) do copy[i] = e end
            saved.value = copy
        end,
        now = function() return clock.t end,
    })
    return q, saved, clock
end

local function kinds(q)
    local out = {}
    for _, e in ipairs(q.items) do
        table.insert(out, e.kind .. ":" .. tostring(e.chapter_id or (e.bulk and e.bulk.scope)))
    end
    return table.concat(out, ",")
end

T.it("a newer progress replaces the pending one for the chapter", function()
    local q = newQueue()
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 10 })
    q:enqueue({ kind = "progress", chapter_id = 2, progress = 50 })
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 30.4 })
    T.eq(kinds(q), "progress:2,progress:1")
    T.eq(q.items[2].progress, 30)
end)

T.it("coalescing keeps a pending read=true", function()
    local q = newQueue()
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 100, read = true })
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 20 })
    T.eq(#q.items, 1)
    T.eq(q.items[1].read, true)
    T.eq(q.items[1].progress, 20)
end)

T.it("set_read supersedes pending entries for that chapter", function()
    local q = newQueue()
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 10 })
    q:enqueue({ kind = "set_read", chapter_id = 1, read = true })
    q:enqueue({ kind = "progress", chapter_id = 2, progress = 10 })
    q:enqueue({ kind = "set_read", chapter_id = 1, read = false })
    T.eq(kinds(q), "progress:2,set_read:1")
    T.eq(q.items[2].read, false)
end)

T.it("progress after a set_read is kept after it", function()
    local q = newQueue()
    q:enqueue({ kind = "set_read", chapter_id = 1, read = false })
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 5 })
    T.eq(kinds(q), "set_read:1,progress:1")
end)

T.it("never coalesces across a bulk_read", function()
    local q = newQueue()
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 10 })
    q:enqueue({ kind = "set_read", chapter_id = 2, read = true })
    q:enqueue({ kind = "bulk_read", novel_id = 9, bulk = { read = false, scope = "all", novel_id = 9 } })
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 60 })
    q:enqueue({ kind = "set_read", chapter_id = 2, read = true })
    T.eq(kinds(q), "progress:1,set_read:2,bulk_read:all,progress:1,set_read:2")
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 70 })
    T.eq(kinds(q), "progress:1,set_read:2,bulk_read:all,set_read:2,progress:1")
end)

T.it("flushes in order and removes sent entries", function()
    local q, saved = newQueue()
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 10 })
    q:enqueue({ kind = "set_read", chapter_id = 2, read = true })
    q:enqueue({ kind = "progress", chapter_id = 3, progress = 10 })
    local order = {}
    local r = q:flush(function(e) table.insert(order, e.chapter_id) return "ok" end)
    T.eq(order, { 1, 2, 3 })
    T.eq(r.sent, 3)
    T.eq(r.remaining, 0)
    T.eq(#saved.value, 0)
end)

T.it("drops 404/422 entries and keeps going", function()
    local q = newQueue()
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 10 })
    q:enqueue({ kind = "progress", chapter_id = 2, progress = 10 })
    q:enqueue({ kind = "progress", chapter_id = 3, progress = 10 })
    local done = {}
    local r = q:flush(function(e) return e.chapter_id == 2 and "drop" or "ok" end,
        function(e, result) table.insert(done, e.chapter_id .. result) end)
    T.eq(r.sent, 2)
    T.eq(r.dropped, 1)
    T.eq(done, { "1ok", "2drop", "3ok" })
end)

T.it("stops at a retryable failure and keeps the rest in order", function()
    local q, saved = newQueue()
    for i = 1, 4 do q:enqueue({ kind = "progress", chapter_id = i, progress = 10 }) end
    local r = q:flush(function(e) return e.chapter_id >= 3 and "retry" or "ok" end)
    T.eq(r.sent, 2)
    T.truthy(r.stalled)
    T.eq(r.remaining, 2)
    T.eq(kinds(q), "progress:3,progress:4")
    T.eq(#saved.value, 2)
    -- unknown results count as retry
    local r2 = q:flush(function() return nil end)
    T.eq(r2.sent, 0)
    T.eq(r2.remaining, 2)
end)

T.it("an error thrown while sending keeps the queue", function()
    local q = newQueue()
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 10 })
    local r = q:flush(function() error("boom") end)
    T.truthy(r.stalled)
    T.eq(q:count(), 1)
    T.falsy(q.flushing)
end)

T.it("recovers from a corrupt or malformed stored queue", function()
    local q = newQueue("garbage")
    T.eq(q:count(), 0)
    local q2 = newQueue({
        { kind = "progress", chapter_id = 1, progress = 5, at = 1, seq = 4 },
        { kind = "nope", at = 1 },
        "string",
        { kind = "set_read", chapter_id = "x", read = true, at = 1 },
        { kind = "set_read", chapter_id = 2, read = true }, -- no time
        { kind = "bulk_read", bulk = { read = true, scope = "all", novel_id = 1 }, at = 2, seq = 5 },
    })
    T.eq(kinds(q2), "progress:1,bulk_read:all")
    local e = q2:enqueue({ kind = "progress", chapter_id = 3, progress = 1 })
    T.eq(e.seq, 6)
end)

T.it("age is now - recorded, clamped to 0..30 days", function()
    T.eq(Queue.age({ at = 1000 }, 3500), 2500)
    T.eq(Queue.age({ at = 5000 }, 1000), 0) -- clock went backwards
    T.eq(Queue.age({ at = 0 }, 100 * 24 * 3600 * 1000), 30 * 24 * 3600 * 1000)
    local q, _, clock = newQueue(nil, 10000)
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 10 })
    clock.t = 12500
    local ages = {}
    q:flush(function(_, age) table.insert(ages, age) return "ok" end)
    T.eq(ages, { 2500 })
end)

T.it("caps the queue, dropping the oldest", function()
    local q = Queue.new({ load = function() return nil end, save = function() end, max_entries = 3 })
    for i = 1, 5 do q:enqueue({ kind = "set_read", chapter_id = i, read = true }) end
    T.eq(kinds(q), "set_read:3,set_read:4,set_read:5")
    T.eq(Queue.MAX_ENTRIES, 2000)
end)

T.it("persists while flushing a long queue (every 10)", function()
    local q, saved = newQueue()
    for i = 1, 25 do q:enqueue({ kind = "set_read", chapter_id = i, read = true }) end
    saved.saves = 0
    q:flush(function() return "ok" end)
    T.eq(saved.saves, 3) -- after 10, 20 and the final 5
end)

T.it("progress values are clamped integers", function()
    local q = newQueue()
    q:enqueue({ kind = "progress", chapter_id = 1, progress = 140 })
    q:enqueue({ kind = "progress", chapter_id = 2, progress = -3 })
    q:enqueue({ kind = "progress", chapter_id = 3, progress = 49.6, read = false })
    T.eq(q.items[1].progress, 100)
    T.eq(q.items[2].progress, 0)
    T.eq(q.items[3].progress, 50)
    T.eq(q.items[3].read, nil)
end)
