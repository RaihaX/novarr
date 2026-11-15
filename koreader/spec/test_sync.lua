local T = ...
local Sync = require("novarr.sync")
local Queue = require("novarr.queue")
local Store = require("novarr.store")
local Api = require("novarr.api")
local Json = require("novarr.json")
local lfs = require("libkoreader-lfs")

local function setup(responses)
    local root = T.tmpdir()
    local clock = { t = 1000 }
    local now = function() return clock.t end
    local store = Store.new({ root = root, lfs = lfs, json = Json, now = now })
    local queue = Queue.new({ load = function() return store:loadQueue() end,
        save = function(items) store:saveQueue(items) end, now = now })
    local sent = {}
    local api = Api.new({ base_url = "http://h", transport = function(req)
        table.insert(sent, req)
        local r = responses[#sent]
        if r == "offline" then return nil, "timeout" end
        return { status = r, headers = {}, body = r == 200 and '{"success":true}' or '{"message":"x"}' }
    end })
    return { api = api, queue = queue, store = store, now = now }, clock, sent, root
end

T.it("drains the queue, drops 404/422, marks the overlay delivered", function()
    local ctx, clock, sent = setup({ 200, 404, 422, 200 })
    ctx.store:setOverlay(1, 10, { progress = 50 })
    ctx.queue:enqueue({ kind = "progress", chapter_id = 10, novel_id = 1, progress = 50 })
    ctx.queue:enqueue({ kind = "set_read", chapter_id = 11, novel_id = 1, read = true })
    ctx.queue:enqueue({ kind = "set_read", chapter_id = 12, novel_id = 1, read = true })
    ctx.queue:enqueue({ kind = "bulk_read", novel_id = 1, bulk = { read = true, scope = "all", novel_id = 1 } })
    clock.t = 5000
    local r = Sync.flush(ctx)
    T.eq(r.sent, 2)
    T.eq(r.dropped, 2)
    T.eq(r.remaining, 0)
    T.eq(#sent, 4)
    T.eq(Json.decode(sent[1].body).age, 4000)
    T.eq(ctx.store:getOverlay(1)["10"].sent_at, 5000)
    -- the persisted queue is empty
    T.eq(#ctx.store:loadQueue(), 0)
end)

T.it("stops when the connection drops and keeps the rest for later", function()
    local ctx, _, sent = setup({ 200, "offline" })
    for i = 1, 3 do ctx.queue:enqueue({ kind = "set_read", chapter_id = i, novel_id = 1, read = true }) end
    local r = Sync.flush(ctx)
    T.eq(r.sent, 1)
    T.truthy(r.stalled)
    T.eq(r.error.kind, "offline")
    T.eq(#sent, 2)
    T.eq(#ctx.store:loadQueue(), 2)
    -- a fresh queue object (after a restart) sees the same two entries
    local q2 = Queue.new({ load = function() return ctx.store:loadQueue() end, save = function() end })
    T.eq(q2:count(), 2)
    T.eq(q2.items[1].chapter_id, 2)
end)

T.it("a 500 is retried later, not dropped", function()
    local ctx = setup({ 500 })
    ctx.queue:enqueue({ kind = "set_read", chapter_id = 1, novel_id = 1, read = true })
    local r = Sync.flush(ctx)
    T.eq(r.remaining, 1)
    T.eq(r.dropped, 0)
end)

T.it("max_entries limits one quiet flush", function()
    local ctx, _, sent = setup({ 200, 200, 200 })
    for i = 1, 3 do ctx.queue:enqueue({ kind = "set_read", chapter_id = i, novel_id = 1, read = true }) end
    ctx.max_entries = 2
    local r = Sync.flush(ctx)
    T.eq(r.sent, 2)
    T.eq(r.remaining, 1)
    T.eq(#sent, 2)
end)
