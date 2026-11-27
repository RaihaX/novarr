--[[--
Pure helpers (1.13.0) for "Request a book / web novel / manga" (/ comic, 1.14.0) and "My
requests". No KOReader dependencies (unit tested: spec/test_requests.lua).

Server (API v1, App\Services\Requests):
    POST requests/searches {q, medium}  202 {search: {id, status = "running"}}
    GET  requests/searches/{id}         {search: {status = running|done|failed,
                                         message, results = [result], skipped_sources}}
         result: {key, title, author, description, medium, sources = [{source_name, chapters}],
                  in_library, novel_id, requested = {status, by_me} | nil, series?, series_index?}
    POST requests {search_id, key, note?}   201 {request, joined = false} | 200 {request, joined = true}
    GET  requests?scope=mine            {requests = [request], page, has_more}
         request: {id, title, author, medium, status = pending|approved|declined|available,
                   decline_reason, novel_id, created_at, can_cancel, mine = requester|follower, wanted}
    DELETE requests/{id}                204
]]

local Requests = {}

-- Books first: on a Kindle that is what is asked for most.
Requests.MEDIA = { "book", "novel", "manga" }

--[[--
The media the request picker offers (1.14.0), in order: Books, Web
novels, Manga, then Comics when the server has them (features("comics"):
Session:feature; an older server answers 422 to medium "comic").
]]
function Requests.media(features)
    local out = {}
    for _i, m in ipairs(Requests.MEDIA) do table.insert(out, m) end
    if type(features) == "function" and features("comics") then table.insert(out, "comic") end
    return out
end

--[[--
Whether a medium's search box takes an ISBN (books and comics, 1.14.0:
they are searched like books, pasted Goodreads / Amazon links too).
]]
function Requests.takesIsbn(medium)
    return medium == "book" or medium == "comic"
end

Requests.POLL_SECONDS = 3
Requests.MAX_QUERY = 100 -- characters, as the server counts them
-- A search asks every source of the medium (some through FlareSolverr).
Requests.TIMEOUT_SECONDS = 4 * 60

--- Trims and squeezes spaces; nil when nothing is left or it is too long (the server takes 100 characters).
function Requests.cleanQuery(text)
    if type(text) ~= "string" then return nil end
    local q = text:gsub("%s+", " "):gsub("^ ", ""):gsub(" $", "")
    if q == "" then return nil end
    -- UTF-8 characters: continuation bytes do not count.
    if #(q:gsub("[\128-\191]", "")) > Requests.MAX_QUERY then return nil end
    return q
end

--[[--
What a result offers:
    "open"       in the library already (novel_id): open it
    "mine"       the reader asked for it (or joined): nothing more to do
    "me_too"     someone else asked for it and it is still open: join
    "request"    nobody asked: request it
]]
function Requests.resultAction(result)
    if type(result) ~= "table" then return "request" end
    if result.in_library and tonumber(result.novel_id) then return "open" end
    local r = result.requested
    if type(r) == "table" then
        if r.by_me then return "mine" end
        if r.status == "pending" or r.status == "approved" then return "me_too" end
    end
    return "request"
end

--- One line under a result's title: author · series #n · sources (chapters).
function Requests.resultLine(result)
    local parts = {}
    if type(result.author) == "string" and result.author ~= "" then table.insert(parts, result.author) end
    if type(result.series) == "string" and result.series ~= "" then
        local n = tonumber(result.series_index)
        table.insert(parts, n and (result.series .. " #" .. (n == math.floor(n) and tostring(math.floor(n)) or tostring(n))) or result.series)
    end
    local names = {}
    for _i, s in ipairs(type(result.sources) == "table" and result.sources or {}) do
        if type(s) == "table" and type(s.source_name) == "string" and s.source_name ~= "" then
            table.insert(names, s.source_name)
        end
        if #names >= 3 then break end
    end
    if #names > 0 then table.insert(parts, table.concat(names, ", ")) end
    return table.concat(parts, " · ")
end

--[[--
A request's status as one short phrase (tr: gettext). status:
pending | approved | declined | available.
]]
function Requests.statusText(request, tr)
    tr = tr or function(s) return s end
    local s = type(request) == "table" and request.status or nil
    if s == "available" then return tr("Ready to read") end
    if s == "approved" then return tr("Approved, on its way") end
    if s == "declined" then
        if type(request.decline_reason) == "string" and request.decline_reason ~= "" then
            return tr("Declined") .. ": " .. request.decline_reason
        end
        return tr("Declined")
    end
    if s == "pending" then
        if request.wanted then return tr("Waiting: the owner looks for it") end
        return tr("Waiting for approval")
    end
    return tostring(s or "")
end

--- Whether the reader can open a request's series here (ready, with its novel).
function Requests.canOpen(request)
    return type(request) == "table" and request.status == "available" and tonumber(request.novel_id) ~= nil
end

--[[--
The poll loop of a request search (like epubflow.lua: all I/O injected).
opts: get(id) -> search | nil, err; schedule(secs, fn); unschedule(fn);
now() -> seconds; on_done(search); on_finish(kind, payload) with kind
"failed" (payload: search) | "timeout" | "cancelled" | "error" (payload: err).
]]
local Poll = {}
Poll.__index = Poll
Requests.Poll = Poll

function Poll.new(id, opts)
    local self = setmetatable({ id = id, o = opts, state = "waiting" }, Poll)
    self.poll = function() self:step() end
    return self
end

function Poll:start()
    self.started = self.o.now()
    self.o.schedule(Requests.POLL_SECONDS, self.poll)
end

function Poll:finish(kind, payload)
    if self.state == "done" then return end
    self.state = "done"
    self.o.unschedule(self.poll)
    if kind == "done" then
        self.o.on_done(payload)
    else
        self.o.on_finish(kind, payload)
    end
end

function Poll:step()
    if self.state ~= "waiting" then return end
    local search, e = self.o.get(self.id)
    if self.state ~= "waiting" then return end -- cancelled meanwhile
    if search then
        if search.status == "done" then return self:finish("done", search) end
        if search.status == "failed" then return self:finish("failed", search) end
    elseif e and e.kind ~= "offline" and e.kind ~= "server" then
        -- 404: the search expired; anything else that is not a passing hiccup.
        return self:finish("error", e)
    end
    if self.o.now() - self.started >= Requests.TIMEOUT_SECONDS then
        return self:finish("timeout")
    end
    self.o.schedule(Requests.POLL_SECONDS, self.poll)
end

function Poll:cancel()
    if self.state == "waiting" then self:finish("cancelled") end
end

return Requests
