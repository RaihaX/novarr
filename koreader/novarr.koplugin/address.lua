--[[--
Which server address requests use (pure logic: probe, clock and storage are
injected).

The user may give two addresses of the *same* Novarr server: the home one
(server_url) and an optional second one for when away from home
(server_url_away, e.g. a Tailscale name). Cache, write queue and stored
chapters are shared: only the base URL of requests changes.

* The active address is kept in memory (and remembered by the caller as
  server_active through on_switch); with one address it is always that one.
* failed(base, err): a request to `base` got no HTTP answer at all. When it
  was the active address, the other one is probed (GET /api/v1/meta, short
  timeout); if it answers as Novarr it becomes active and the caller retries
  once. At most one such probe per FAILOVER_INTERVAL_MS (60 s), so a device
  that is really offline does not stall every request on probes.
* invalidate(reason): KOReader started, the network came up, the device
  woke up: the next request first re-probes, starting with the home address
  (only needed while the second one is active). A failed re-probe of the
  home address is not repeated within 60 s, unless forced (the user tapped
  Sync).
* rewrite(url): URLs the server returned (covers, EPUB download, ...) carry
  the host the request used; a URL on either configured address is moved
  to the active one. canonical(url) moves it to the home address (stable
  cache keys whichever address fetched the list).
]]

local HttpClient = require("novarr.http_client")

local Address = {}
Address.__index = Address

Address.FAILOVER_INTERVAL_MS = 60 * 1000
Address.REPROBE_INTERVAL_MS = 60 * 1000
Address.PROBE_TIMEOUT = 4

--[[--
opts: home, away (optional), active (optional, remembered), probe(base) ->
true when it answers as Novarr, now() -> ms, log(text), on_switch(base, which, why).
]]
function Address.new(opts)
    local self = setmetatable({
        probe = assert(opts.probe),
        now = opts.now or function() return os.time() * 1000 end,
        log = opts.log or function() end,
        on_switch = opts.on_switch or function() end,
        last_failover = nil,   -- ms of the last failover probe
        home_failed_at = nil,  -- ms of the last failed re-probe of the home address
        pending = nil,         -- reason of a pending re-probe
    }, Address)
    self:configure(opts.home, opts.away, opts.active)
    return self
end

function Address:configure(home, away, active)
    if not away or away == "" or away == home then away = nil end
    self.home = home
    self.away = away
    if active and (active == home or (away and active == away)) then
        self.active = active
    else
        self.active = home
    end
end

function Address:hasTwo()
    return self.home ~= nil and self.away ~= nil
end

--- "home" or "away": which address is in use.
function Address:which()
    if self.away and self.active == self.away then return "away" end
    return "home"
end

local function switch(self, base, why)
    if base == self.active then return end
    self.active = base
    self.log(string.format("Novarr: now using the %s address %s (%s)", self:which() == "away" and "second" or "home", tostring(base), why))
    self.on_switch(base, self:which(), why)
end

--- The address to use for the next request (runs a pending re-probe first).
function Address:base()
    if self.pending then
        local reason = self.pending
        self.pending = nil
        self:reprobe(reason)
    end
    return self.active
end

--- Asks for a re-probe before the next request (start, network up, wake-up, Sync).
function Address:invalidate(reason)
    if self:hasTwo() then self.pending = reason or "re-check" end
end

--[[--
Re-probe, starting with the home address: while the second address is
active, the home one is probed and used again when it answers. While the
home address is active nothing is probed (the next request is the check).
opts.force ignores the 60 s pause after a failed re-probe.
]]
function Address:reprobe(reason, opts)
    if not self:hasTwo() or self.active == self.home then return self.active end
    local now = self.now()
    if not (opts and opts.force) and self.home_failed_at and now - self.home_failed_at < Address.REPROBE_INTERVAL_MS then
        return self.active
    end
    local t0 = now
    if self.probe(self.home) then
        self.home_failed_at = nil
        switch(self, self.home, "re-probe after " .. tostring(reason) .. ", " .. (self.now() - t0) .. " ms")
    else
        self.home_failed_at = self.now()
        self.log(string.format("Novarr: home address %s not answering (re-probe after %s, %d ms), staying on the second address",
            tostring(self.home), tostring(reason), self.now() - t0))
    end
    return self.active
end

--[[--
A request to `base` got no answer. Returns the address to retry on (once),
or nil to give up (one address, probe paused, the other one not answering).
]]
function Address:failed(base, err)
    if not self:hasTwo() then return nil end
    if base ~= self.active then
        -- Another request switched in the meantime: retry there.
        return self.active
    end
    local now = self.now()
    if self.last_failover and now - self.last_failover < Address.FAILOVER_INTERVAL_MS then
        return nil
    end
    self.last_failover = now
    local other = base == self.home and self.away or self.home
    local t0 = now
    if self.probe(other) then
        if other == self.home then self.home_failed_at = nil end
        switch(self, other, string.format("%s did not answer (%s); probe %d ms", tostring(base), tostring(err), self.now() - t0))
        return other
    end
    self.log(string.format("Novarr: %s did not answer (%s) and %s neither (probe %d ms)",
        tostring(base), tostring(err), tostring(other), self.now() - t0))
    if other == self.home then self.home_failed_at = self.now() end
    return nil
end

-- ---------------------------------------------------------------- URLs

-- origin, path prefix of a base URL.
local function split(base)
    local u = base and HttpClient.parseUrl(base)
    if not u then return nil end
    local prefix = u.path:gsub("[?#].*$", ""):gsub("/+$", "")
    return u.origin, prefix
end

-- url moved from one of the configured addresses to `to`, or nil when it is on neither.
local function move(self, url, to)
    local u = HttpClient.parseUrl(url)
    if not u or not to then return nil end
    local to_origin, to_prefix = split(to)
    if not to_origin then return nil end
    local bases = { self.home, self.away }
    -- First the whole base (origin and path prefix, the longer prefix first), then the origin alone.
    local matches = {}
    for _i, b in ipairs(bases) do
        local origin, prefix = split(b)
        if origin and origin == u.origin then
            local p = u.path
            if prefix == "" or p == prefix or p:sub(1, #prefix + 1) == prefix .. "/" or p:sub(1, #prefix + 1) == prefix .. "?" then
                table.insert(matches, prefix)
            end
        end
    end
    table.sort(matches, function(x, y) return #x > #y end)
    if matches[1] then
        return to_origin .. to_prefix .. u.path:sub(#matches[1] + 1)
    end
    for _i, b in ipairs(bases) do
        local origin = split(b)
        if origin and origin == u.origin then
            return to_origin .. u.path
        end
    end
    return nil
end

--- url on the active address (unchanged when it is on neither configured address).
function Address:rewrite(url)
    if not self:hasTwo() then return url end
    return move(self, url, self.active) or url
end

--- Whether url is on one of the configured addresses.
function Address:owns(url)
    return move(self, url, self.home) ~= nil
end

--- url on the home address (for cache keys), unchanged when on neither.
function Address:canonical(url)
    if not self:hasTwo() then return url end
    return move(self, url, self.home) or url
end

return Address
