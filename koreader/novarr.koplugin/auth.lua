--[[--
Linking this Kindle to a Novarr account (1.9.0). Pure logic: no KOReader
module is loaded here (ui_auth.lua is the KOReader side), so all of it is
unit tested.

Server contract (see the server's auth docs):

  * GET /api/v1/meta says `"auth": {"required": true|false, ...}`. A
    missing key (older server) or `required: false`: everything behaves as
    before 1.9.0 and no account UI is shown at all.
  * Every /api/v1 request except meta, POST auth/token, auth/pairings and
    the plugin update endpoints (koreader/plugin*) carries
    `Authorization: Bearer <token>` (api.lua, Api.authExempt).
  * 401 {"code": "unauthenticated"}: the token is forgotten, queued writes
    are KEPT (retried after linking again), nothing is sent until the
    Kindle is linked again (no retry storm), the user is told once.
    403 {"code": "forbidden"}: the server's message is shown.
  * Pairing: POST auth/pairings {device_id, device_name} -> 201 {code,
    secret, expires_in, interval}; the user types the code on the website
    (Account -> Link a device); the device polls GET auth/pairings/{secret}
    every `interval` s: {status = "pending"} | {status = "linked", token,
    user} (handed over once) | 404 (expired, unknown or collected).

Stored in the plugin settings (novarr.lua) under "auth", for ONE server:

    auth = { server = <home address>, required = true|false|nil,
             token = "...", user = { id, name, username, role } }

The home and away addresses are the same server, so the record is keyed by
the home address (server_url) and serves both; the record of any other
server reads as empty, and Session:setServer clears it when switching to a
different server (or moves it when the away address becomes the home one).

`settings` is a LuaSettings-like object (readSetting/saveSetting/delSetting/
flush), as in position.lua.
]]

local Auth = {}

Auth.KEY = "auth"
Auth.MAX_TOKEN = 1024          -- the contract says "up to 255": some room to spare
Auth.CODE_ALPHABET = "ABCDEFGHJKMNPQRSTUVWXYZ23456789"
Auth.DEFAULT_INTERVAL = 3      -- seconds between two polls when the server gives none
Auth.MIN_INTERVAL = 2
Auth.MAX_INTERVAL = 30
Auth.DEFAULT_EXPIRES_IN = 600  -- seconds a code lives when the server gives no number

-- ---------------------------------------------------------------- values

--- Whether /api/v1/meta says sign-in is required (absent or anything but true: no).
function Auth.requiredFromMeta(meta)
    return type(meta) == "table" and type(meta.auth) == "table" and meta.auth.required == true
end

--[[--
Whether t can be sent as a bearer token: a non-empty string of printable
ASCII without spaces (nothing that could break the header line), at most
MAX_TOKEN characters.
]]
function Auth.validToken(t)
    return type(t) == "string" and #t > 0 and #t <= Auth.MAX_TOKEN and t:match("^[\33-\126]+$") ~= nil
end

--- The Authorization header value for a token, or nil.
function Auth.bearer(token)
    if not Auth.validToken(token) then return nil end
    return "Bearer " .. token
end

--[[--
The user object kept from the server: {id, name, username, role}; role is
"reader" only when the server says so, else "admin". name falls back to
username. nil when u is not a table.
]]
function Auth.cleanUser(u)
    if type(u) ~= "table" then return nil end
    local function str(v)
        if type(v) ~= "string" then return nil end
        v = v:gsub("^%s+", ""):gsub("%s+$", "")
        if v == "" then return nil end
        return v
    end
    local name = str(u.name) or str(u.username)
    return {
        id = tonumber(u.id),
        name = name,
        username = str(u.username),
        role = u.role == "reader" and "reader" or "admin",
    }
end

--- "K7M4QX" -> "K7M 4QX" (any other length: as given, upper-cased).
function Auth.formatCode(code)
    if type(code) ~= "string" then return "" end
    code = code:upper():gsub("%s+", "")
    if #code == 6 then return code:sub(1, 3) .. " " .. code:sub(4, 6) end
    return code
end

--- Whether code is a pairing code of the contract (6 characters of CODE_ALPHABET, any case).
function Auth.validCode(code)
    if type(code) ~= "string" then return false end
    code = code:upper()
    if #code ~= 6 then return false end
    for i = 1, 6 do
        if not Auth.CODE_ALPHABET:find(code:sub(i, i), 1, true) then return false end
    end
    return true
end

-- ---------------------------------------------------------------- the stored record

local function readRecord(settings)
    local r = settings:readSetting(Auth.KEY)
    if type(r) ~= "table" then return nil end
    return r
end

local function save(settings, rec)
    settings:saveSetting(Auth.KEY, rec)
    if settings.flush then settings:flush() end
end

--[[--
The record for `server` (the home address): a copy of the stored one when
it is for that server, else an empty {server = server}. Never nil.
]]
function Auth.record(settings, server)
    local r = readRecord(settings)
    if not r or not server or r.server ~= server then return { server = server } end
    local out = {}
    for k, v in pairs(r) do out[k] = v end
    return out
end

--- Sets whether the server requires sign-in (from meta, or true after a 401). Returns true when it changed.
function Auth.setRequired(settings, server, required)
    if not server then return false end
    local rec = Auth.record(settings, server)
    required = required and true or false
    if rec.required == required then return false end
    rec.required = required
    save(settings, rec)
    return true
end

--- Keeps the token and the user after a successful pairing (sign-in is required: the server asked).
function Auth.link(settings, server, token, user)
    if not server or not Auth.validToken(token) then return false end
    local rec = Auth.record(settings, server)
    rec.token = token
    rec.user = Auth.cleanUser(user)
    rec.required = true
    save(settings, rec)
    return true
end

--- Forgets the token and the user (401, Unlink). Whether sign-in is required is kept.
function Auth.forget(settings, server)
    local r = readRecord(settings)
    if not r or r.server ~= server then return false end
    local had = r.token ~= nil
    r.token = nil
    r.user = nil
    save(settings, r)
    return had
end

--- Forgets everything (switching to a different server).
function Auth.clear(settings)
    if readRecord(settings) == nil then return false end
    settings:delSetting(Auth.KEY)
    if settings.flush then settings:flush() end
    return true
end

--[[--
The away address became the home one (the same server): the record follows
it. Does nothing when the record is for another server.
]]
function Auth.moveServer(settings, old_home, new_home)
    local r = readRecord(settings)
    if not r or r.server ~= old_home or not new_home then return false end
    r.server = new_home
    save(settings, r)
    return true
end

function Auth.token(settings, server)
    local t = Auth.record(settings, server).token
    return Auth.validToken(t) and t or nil
end

function Auth.user(settings, server)
    local u = Auth.record(settings, server).user
    return type(u) == "table" and u or nil
end

--- true only when the server said sign-in is required (meta, or a 401).
function Auth.required(settings, server)
    return Auth.record(settings, server).required == true
end

--- Whether the server's sign-in state is known (meta answered, or a 401 came).
function Auth.known(settings, server)
    return Auth.record(settings, server).required ~= nil
end

--[[--
Sign-in is required and no token is held: nothing but meta, pairing and
plugin updates is sent (api.lua answers "unauthenticated" locally), and the
screens offer "Link this Kindle".
]]
function Auth.needsLink(settings, server)
    return Auth.required(settings, server) and Auth.token(settings, server) == nil
end

--- "admin" or "reader". With sign-in not required: always "admin" (today's UI).
function Auth.role(settings, server)
    if not Auth.required(settings, server) then return "admin" end
    local u = Auth.user(settings, server)
    if u and u.role == "reader" then return "reader" end
    return "admin"
end

-- ---------------------------------------------------------------- what the UI shows

--[[--
What a role may do in the plugin. Readers can read, mark read/unread, sync,
highlights, stats, check for new chapters and download (chapters, EPUB);
they cannot add novels (Discover) or change anything else on the server.
]]
local READER_DENIED = { discover = true, add_novel = true }

function Auth.allows(role, action)
    if role == "reader" then return not READER_DENIED[action] end
    return true
end

--[[--
The buttons along the bottom of Home, in order, as ids: library, discover
(not for readers), downloads, sync (or "link" while the Kindle needs
linking: nothing can be synced then), settings.
]]
function Auth.homeButtons(role, needs_link)
    local out = { "library" }
    if Auth.allows(role, "discover") then table.insert(out, "discover") end
    table.insert(out, "downloads")
    table.insert(out, needs_link and "link" or "sync")
    table.insert(out, "settings")
    return out
end

--[[--
The account rows of the Settings screen: none when sign-in is not
required; {id = "link"} when it is and no token is held; else
{id = "account", name = ..., role = ...} and {id = "unlink"}.
]]
function Auth.accountRows(settings, server)
    if not Auth.required(settings, server) then return {} end
    if not Auth.token(settings, server) then return { { id = "link" } } end
    local u = Auth.user(settings, server) or {}
    return {
        { id = "account", name = u.name or u.username, role = Auth.role(settings, server) },
        { id = "unlink" },
    }
end

-- ---------------------------------------------------------------- pairing

--[[--
The pairing state machine (like epubflow.lua: all I/O injected).

opts:
    start()          -> data {code, secret, expires_in, interval} | nil, err
    poll(secret)     -> data {status, token, user} | nil, err (err.kind "not_found" on 404)
    schedule(secs, fn), unschedule(fn)
    now()            -> seconds
    on_code(display_code, data)  the code to show ("K7M 4QX")
    on_linked(token, user)       keep them (called before on_finish)
    on_finish(kind, payload)     kind: "linked" (user) | "expired" | "cancelled" | "error" (err)

States: idle -> starting -> pending -> done. While pending, one poll every
`interval` s (2..30, default 3); the code's own expiry (expires_in) also
ends it as "expired". A poll that gets no answer (offline, 5xx, 429) just
waits for the next one; any other error ends it.
]]
local Pairing = {}
Pairing.__index = Pairing
Auth.Pairing = Pairing

function Pairing.new(opts)
    local self = setmetatable({ o = opts, state = "idle", polls = 0 }, Pairing)
    self.poll = function() self:pollOnce() end
    return self
end

function Pairing:finish(kind, payload)
    if self.state == "done" then return end
    self.state = "done"
    self.o.unschedule(self.poll)
    self.o.on_finish(kind, payload)
end

local function clampInterval(v)
    v = tonumber(v)
    if not v or v ~= v then return Auth.DEFAULT_INTERVAL end
    if v < Auth.MIN_INTERVAL then return Auth.MIN_INTERVAL end
    if v > Auth.MAX_INTERVAL then return Auth.MAX_INTERVAL end
    return v
end

function Pairing:start()
    self.state = "starting"
    local data, e = self.o.start()
    if self.state ~= "starting" then return end -- cancelled meanwhile
    if not data then return self:finish("error", e) end
    if type(data) ~= "table" or not Auth.validCode(data.code) or type(data.secret) ~= "string" or data.secret == "" then
        return self:finish("error", { kind = "server", message = "bad pairing" })
    end
    self.secret = data.secret
    self.interval = clampInterval(data.interval)
    local expires_in = tonumber(data.expires_in)
    if not expires_in or expires_in <= 0 then expires_in = Auth.DEFAULT_EXPIRES_IN end
    self.expires_at = self.o.now() + expires_in
    self.code = Auth.formatCode(data.code)
    self.state = "pending"
    self.o.on_code(self.code, data)
    if self.state ~= "pending" then return end -- closed from on_code
    self.o.schedule(self.interval, self.poll)
end

-- Errors that only mean "no answer this time": keep polling.
local function passing(e)
    if type(e) ~= "table" then return true end
    return e.kind == "offline" or e.kind == "server" or e.status == 429
end

function Pairing:pollOnce()
    if self.state ~= "pending" then return end
    self.polls = self.polls + 1
    local data, e = self.o.poll(self.secret)
    if self.state ~= "pending" then return end -- cancelled while waiting for the answer
    if data then
        if data.status == "linked" then
            if not Auth.validToken(data.token) then
                return self:finish("error", { kind = "server", message = "bad token" })
            end
            local user = Auth.cleanUser(data.user)
            self.state = "done"
            self.o.unschedule(self.poll)
            self.o.on_linked(data.token, user)
            self.o.on_finish("linked", user)
            return
        elseif data.status ~= "pending" then
            return self:finish("error", { kind = "server", message = "unknown status " .. tostring(data.status) })
        end
    elseif e and e.kind == "not_found" then
        return self:finish("expired")
    elseif not passing(e) then
        return self:finish("error", e)
    end
    if self.o.now() >= self.expires_at then return self:finish("expired") end
    self.o.schedule(self.interval, self.poll)
end

--- Stops (Cancel, the dialog closed). Nothing more is sent.
function Pairing:cancel()
    if self.state == "starting" or self.state == "pending" then self:finish("cancelled") end
end

return Auth
