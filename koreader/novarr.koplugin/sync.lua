--[[--
Delivers the write queue to the server and keeps the overlay in step.

Per entry: success -> removed, overlay entry marked delivered; 404/422
(not_found/invalid) -> dropped and logged, overlay marked delivered too (it
will never reach the server, so the server's value must win again);
offline/server trouble -> stop, keep this entry and everything after it.
]]

local Api = require("novarr.api")

local Sync = {}

--[[--
Flushes the queue. ctx = { api, queue, store, now, log, max_entries (optional),
on_sent(entry, data) (optional: called with the server's answer of every sent write) }.
Returns the queue summary {sent, dropped, remaining, stalled, error}.
]]
function Sync.flush(ctx)
    local last_error
    local sent_count = 0
    local limit = ctx.max_entries
    local summary = ctx.queue:flush(function(entry, age)
        if limit and sent_count >= limit then
            return "retry"
        end
        sent_count = sent_count + 1
        local ok, e = ctx.api:sendWrite(entry, age)
        if ok then
            -- The answer (sendWrite's second value) matters for highlights: the server's id.
            if ctx.on_sent then ctx.on_sent(entry, e) end
            return "ok"
        end
        last_error = e
        if Api.isRetryable(e) then return "retry" end
        if ctx.log then
            ctx.log(string.format("Novarr sync: dropping %s write (chapter %s): %s %s",
                tostring(entry.kind), tostring(entry.chapter_id), tostring(e and e.kind), tostring(e and e.status)))
        end
        return "drop"
    end, function(entry)
        local store = ctx.store
        if entry.kind == "highlight" or entry.kind == "highlight_delete" then return end
        if not store or not entry.novel_id then return end
        local now = ctx.now and ctx.now() or nil
        if entry.kind == "bulk_read" then
            store:markNovelOverlaySent(entry.novel_id, entry.at, now)
        else
            store:markOverlaySent(entry.novel_id, entry.chapter_id, entry.at, now)
        end
    end)
    summary.error = last_error
    return summary
end

return Sync
