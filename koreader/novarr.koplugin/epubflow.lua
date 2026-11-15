--[[--
State machine behind "Download as EPUB" (pure Lua, unit tested; all I/O is
injected). Server contract: GET/POST {server}/api/v1/novels/{id}/epub ->
{"epub": {status, stale, generated_at, size, filename, download_url, message}}.

    GET status
      ready, not stale  -> "up_to_date" if the copy here has the same
                           generated_at, else download
      missing / failed / ready+stale -> POST (start a build), then wait
      building          -> wait
    wait: poll GET every 3 s (scheduled, never blocking)
      ready             -> download
      failed            -> show the server's message
      10 minutes passed -> "taking long" (the build carries on on the server)
      cancel            -> stop polling only
    POST 422            -> show the server's message
    offline / errors    -> the usual message

opts:
    get()            -> epub_table | nil, err      (err = Api error table)
    post()           -> epub_table | nil, err
    schedule(secs, fn), unschedule(fn)
    now()            -> seconds
    stored           -> {generated_at, etag} of the copy on this device, or nil
    on_download(epub)          start the download
    on_up_to_date(epub)
    on_waiting()               show the "building" dialog
    on_finish(kind, epub|err)  kind: "failed" | "timeout" | "cancelled" | "error"
]]

local EpubFlow = {}
EpubFlow.__index = EpubFlow

EpubFlow.POLL_SECONDS = 3
EpubFlow.TIMEOUT_SECONDS = 10 * 60

function EpubFlow.new(opts)
    local self = setmetatable({ o = opts, state = "idle" }, EpubFlow)
    self.poll = function() self:pollOnce() end
    return self
end

local function isUpToDate(epub, stored)
    if not stored then return false end
    if stored.generated_at and epub.generated_at then
        return stored.generated_at == epub.generated_at
    end
    return false
end

function EpubFlow:finish(kind, payload)
    if self.state == "done" then return end
    self.state = "done"
    self.o.unschedule(self.poll)
    self.o.on_finish(kind, payload)
end

function EpubFlow:download(epub)
    self.state = "done"
    self.o.unschedule(self.poll)
    self.o.on_download(epub)
end

function EpubFlow:start()
    self.state = "checking"
    local epub, err = self.o.get()
    if not epub then return self:finish("error", err) end
    local status = epub.status
    if status == "ready" and not epub.stale then
        if isUpToDate(epub, self.o.stored) then
            self.state = "done"
            return self.o.on_up_to_date(epub)
        end
        return self:download(epub)
    elseif status == "building" then
        return self:wait()
    elseif status == "missing" or status == "failed" or (status == "ready" and epub.stale) then
        return self:build()
    end
    return self:finish("error", { kind = "server", message = "unknown status " .. tostring(status) })
end

function EpubFlow:build()
    self.state = "starting"
    local epub, err = self.o.post()
    if not epub then return self:finish("error", err) end
    if epub.status == "ready" and not epub.stale then
        -- Nothing needed doing after all (200).
        return self:download(epub)
    end
    if epub.status == "failed" then return self:finish("failed", epub) end
    return self:wait()
end

function EpubFlow:wait()
    self.state = "waiting"
    self.started = self.o.now()
    self.o.on_waiting()
    self.o.schedule(EpubFlow.POLL_SECONDS, self.poll)
end

function EpubFlow:pollOnce()
    if self.state ~= "waiting" then return end
    local epub, err = self.o.get()
    if self.state ~= "waiting" then return end -- cancelled meanwhile
    if epub then
        if epub.status == "ready" then return self:download(epub) end
        if epub.status == "failed" then return self:finish("failed", epub) end
    elseif err and err.kind ~= "offline" and err.kind ~= "server" then
        return self:finish("error", err)
    end
    -- still building (or a passing network hiccup): keep waiting
    if self.o.now() - self.started >= EpubFlow.TIMEOUT_SECONDS then
        return self:finish("timeout")
    end
    self.o.schedule(EpubFlow.POLL_SECONDS, self.poll)
end

--- Stops polling (the build carries on on the server).
function EpubFlow:cancel()
    if self.state == "waiting" then self:finish("cancelled") end
end

return EpubFlow
