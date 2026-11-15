--[[--
Persistent queue of pending server writes (pure logic, storage injected).

Entry kinds:
    progress  { chapter_id, novel_id, progress = 0..100 int, read = true|nil }
    set_read  { chapter_id, novel_id, read = bool }
    bulk_read { novel_id, bulk = { read, scope, ids, novel_id, anchor_id } }
Every entry also has `at` (wall-clock ms when recorded) and `seq` (unique,
increasing).

Coalescing (only ever looks at entries after the last bulk_read, so nothing
is reordered across a bulk_read):
  * a newer progress for a chapter replaces the pending progress for that
    chapter (a pending read=true is kept: read marks are never lost);
  * a set_read removes pending progress and set_read entries for that chapter;
  * bulk_read is appended as is.

Flushing sends entries oldest first. The `send` callback returns one of
"ok" (remove), "drop" (rejected for good, e.g. 404/422: remove and log) or
"retry" (offline/server trouble: stop, keep this and the rest).
]]

local Queue = {}
Queue.__index = Queue

Queue.MAX_ENTRIES = 2000
Queue.MAX_AGE_MS = 30 * 24 * 3600 * 1000
-- How many sent entries between two saves while flushing. A power cut can
-- then re-send at most this many writes, which the server tolerates (marks
-- are idempotent, progress carries its age).
Queue.SAVE_EVERY = 10

local VALID_KINDS = { progress = true, set_read = true, bulk_read = true }

local function isValid(e)
    if type(e) ~= "table" or not VALID_KINDS[e.kind] then return false end
    if type(e.at) ~= "number" then return false end
    if e.kind == "bulk_read" then
        return type(e.bulk) == "table" and type(e.bulk.read) == "boolean"
    end
    if type(e.chapter_id) ~= "number" then return false end
    if e.kind == "progress" then
        return e.progress == nil or type(e.progress) == "number"
    end
    return type(e.read) == "boolean"
end

--[[--
opts:
    load()      -> list or nil (raw decoded file contents, may be garbage)
    save(list)  -> persists the list
    now()       -> current wall-clock time in ms
    log(msg)    -> optional logger
    max_entries -> optional cap (default 2000)
]]
function Queue.new(opts)
    local self = setmetatable({
        _load = opts.load,
        _save = opts.save,
        now = opts.now or function() return os.time() * 1000 end,
        log = opts.log or function() end,
        max_entries = opts.max_entries or Queue.MAX_ENTRIES,
        items = {},
        seq = 0,
        flushing = false,
    }, Queue)
    self:reload()
    return self
end

--- Re-reads the persisted queue, dropping anything malformed.
function Queue:reload()
    local raw = self._load and self._load()
    local items = {}
    local seq = 0
    if type(raw) == "table" then
        for _i, e in ipairs(raw) do
            if isValid(e) then
                table.insert(items, e)
                if type(e.seq) == "number" and e.seq > seq then seq = e.seq end
            else
                self.log("Novarr queue: dropping malformed entry")
            end
        end
    elseif raw ~= nil then
        self.log("Novarr queue: stored queue is unreadable, starting empty")
    end
    self.items = items
    self.seq = seq
end

function Queue:persist()
    if self._save then self._save(self.items) end
end

function Queue:count()
    return #self.items
end

local function lastBulkIndex(items)
    for i = #items, 1, -1 do
        if items[i].kind == "bulk_read" then return i end
    end
    return 0
end

--- Age to send for an entry, in ms, clamped to 0..30 days.
function Queue.age(entry, now)
    local age = math.floor((now or 0) - (entry.at or 0))
    if age ~= age then return 0 end -- NaN
    if age < 0 then return 0 end
    if age > Queue.MAX_AGE_MS then return Queue.MAX_AGE_MS end
    return age
end

--[[--
Adds an entry (see the module doc for coalescing). `entry` is a table with
kind and its fields; `at` defaults to now. Returns the stored entry.
]]
function Queue:enqueue(entry)
    assert(VALID_KINDS[entry.kind], "unknown queue entry kind")
    local e = {}
    for k, v in pairs(entry) do e[k] = v end
    e.at = e.at or self.now()
    if e.kind == "progress" and e.progress ~= nil then
        e.progress = math.max(0, math.min(100, math.floor(e.progress + 0.5)))
    end
    if e.read == false and e.kind == "progress" then e.read = nil end

    local items = self.items
    local floor = lastBulkIndex(items)
    if e.kind == "progress" then
        for i = #items, floor + 1, -1 do
            local old = items[i]
            if old.kind == "progress" and old.chapter_id == e.chapter_id then
                if old.read then e.read = true end
                table.remove(items, i)
            end
        end
    elseif e.kind == "set_read" then
        for i = #items, floor + 1, -1 do
            local old = items[i]
            if (old.kind == "progress" or old.kind == "set_read") and old.chapter_id == e.chapter_id then
                table.remove(items, i)
            end
        end
    end

    self.seq = self.seq + 1
    e.seq = self.seq
    table.insert(items, e)

    while #items > self.max_entries do
        local dropped = table.remove(items, 1)
        self.log("Novarr queue: full, dropping oldest " .. tostring(dropped.kind))
    end
    self:persist()
    return e
end

--[[--
Sends pending entries in order through send(entry, age_ms) which returns
"ok", "drop" or "retry" (anything else counts as "retry"). `on_done(entry,
result)` is called for every entry that leaves the queue. Returns a summary
{ sent, dropped, remaining, stalled }.
]]
function Queue:flush(send, on_done)
    if self.flushing then
        return { sent = 0, dropped = 0, remaining = #self.items, stalled = false, busy = true }
    end
    self.flushing = true
    local sent, dropped, since_save = 0, 0, 0
    local stalled = false
    local ok, err = pcall(function()
        while #self.items > 0 do
            local e = self.items[1]
            local result = send(e, Queue.age(e, self.now()))
            if result == "ok" or result == "drop" then
                table.remove(self.items, 1)
                if result == "ok" then
                    sent = sent + 1
                else
                    dropped = dropped + 1
                    self.log("Novarr queue: server rejected a " .. tostring(e.kind) .. " write, dropped it")
                end
                if on_done then on_done(e, result) end
                since_save = since_save + 1
                if since_save >= Queue.SAVE_EVERY then
                    self:persist()
                    since_save = 0
                end
            else
                stalled = true
                break
            end
        end
    end)
    self.flushing = false
    if since_save > 0 or not ok then self:persist() end
    if not ok then
        self.log("Novarr queue: flush failed: " .. tostring(err))
        stalled = true
    end
    return { sent = sent, dropped = dropped, remaining = #self.items, stalled = stalled }
end

--- Removes everything (used when the server address changes).
function Queue:clear()
    self.items = {}
    self:persist()
end

--- Copy of the entries (for dumps and tests).
function Queue:list()
    local out = {}
    for i, e in ipairs(self.items) do out[i] = e end
    return out
end

return Queue
