--[[--
"Read on another device": when a novel is opened from Novarr's screens (a
tap on Home/Library, Continue, a chapter in the list) and another device has
read it more recently, a question over the open chapter offers to go there:

    Chapter 124 · 40% was read on iPhone 2 hours ago. Go there?
    [Go to latest]  [Stay here]

Open first, ask after: the chapter this device would open anyway opens at
once (never waiting for the network); a moment after it is on screen, and
only when already connected, the server is asked where the novel was read
last (GET novels/{id}/position, 3 s at most). Any failure, an older server
(404), no connection: no question. Moving to the next/previous chapter while
reading never asks. The rule is Model.shouldOfferPosition.

    Go          that chapter opens at the other device's place, overriding
                KOReader's saved position for it (Session.forced_progress,
                applied once in Reading.onReady); the same chapter or one in
                the open group file: a jump inside the document.
    Stay here   the position's `at` is remembered per novel (setting
                position_declined): not asked again until a newer one.

Also this device's identity, sent with every write (api.lua): an id made
once (k_ + 22 random characters, setting device_id) and a short name
("Kindle", or KOReader's model name: "Kindle PaperWhite 5").

The helpers taking a `settings` object (readSetting/saveSetting/flush, like
LuaSettings) and the pure ones have no KOReader dependency (unit tested);
the rest loads KOReader modules on use.
]]

local Model = require("novarr.model")

local Position = {}

Position.CHECK_DELAY = 1           -- seconds after the chapter is ready (after its first paint)
Position.REQUEST_TTL_MS = 120000   -- an open asked for this long ago no longer asks
Position.FORCED_TTL_MS = 120000    -- a "Go" whose chapter did not open this soon is forgotten
Position.DEFAULT_NAME = "Kindle"
Position.MAX_NAME = 40

local ID_CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789"

-- ---------------------------------------------------------------- device identity

--- Whether s is a usable device id (contract: 8..64 of [A-Za-z0-9_-]).
function Position.validId(s)
    return type(s) == "string" and #s >= 8 and #s <= 64 and s:match("^[%w_%-]+$") ~= nil
end

--[[--
A new device id: "k_" and 22 characters of [A-Za-z0-9]. random_bytes(n)
gives n random bytes (string) or nil; without it (or when it fails)
math.random is used, seeded from the clock.
]]
function Position.newDeviceId(random_bytes)
    local n = 22
    local bytes = random_bytes and random_bytes(n)
    if type(bytes) ~= "string" or #bytes < n then
        bytes = nil
        local seed = os.time()
        local ok, socket = pcall(require, "socket")
        if ok and type(socket) == "table" and socket.gettime then
            seed = math.floor((socket.gettime() * 1000000) % 2147483647)
        end
        math.randomseed(seed)
    end
    local out = { "k_" }
    for i = 1, n do
        local v = bytes and bytes:byte(i) or math.random(0, 255)
        local k = (v % #ID_CHARS) + 1
        out[#out + 1] = ID_CHARS:sub(k, k)
    end
    return table.concat(out)
end

--[[--
A short, readable name from KOReader's Device.model ("KindlePaperWhite5" ->
"Kindle PaperWhite 5", "KindleOasis3" -> "Kindle Oasis 3"). Anything odd or
empty: "Kindle". At most 40 characters.
]]
function Position.deviceName(model)
    if type(model) ~= "string" then return Position.DEFAULT_NAME end
    local name = model:gsub("[^%w _%-]", "")
    name = name:gsub("(%l)(%u)", "%1 %2"):gsub("(%a)(%d)", "%1 %2")
    name = name:gsub("Paper White", "PaperWhite"):gsub("%s+", " "):gsub("^%s+", ""):gsub("%s+$", "")
    if name == "" or not name:match("%a") then return Position.DEFAULT_NAME end
    if #name > Position.MAX_NAME then name = name:sub(1, Position.MAX_NAME):gsub("%s+$", "") end
    return name
end

--[[--
This device's id and name. The id is made once and kept in the settings
(device_id); a user-set device_name setting wins over the model name.
opts: model (KOReader's Device.model), random_bytes (see newDeviceId).
Returns id, name.
]]
function Position.identity(settings, opts)
    opts = opts or {}
    local id = settings:readSetting("device_id")
    if not Position.validId(id) then
        id = Position.newDeviceId(opts.random_bytes)
        settings:saveSetting("device_id", id)
        if settings.flush then settings:flush() end
    end
    local name = settings:readSetting("device_name")
    if type(name) ~= "string" or not name:match("%S") then
        name = Position.deviceName(opts.model)
    end
    return id, name:sub(1, Position.MAX_NAME)
end

--- n bytes from /dev/urandom, or nil.
function Position.urandom(n)
    local f = io.open("/dev/urandom", "rb")
    if not f then return nil end
    local s = f:read(n)
    f:close()
    return s
end

-- ---------------------------------------------------------------- "Stay here"

--- The position `at` declined for a novel, or nil.
function Position.declinedAt(settings, novel_id)
    local t = settings:readSetting("position_declined")
    if type(t) ~= "table" then return nil end
    return t[tostring(novel_id)]
end

--- Remembers that the position at `at` was declined for a novel.
function Position.decline(settings, novel_id, at)
    if at == nil then return end
    local t = settings:readSetting("position_declined")
    if type(t) ~= "table" then t = {} end
    t[tostring(novel_id)] = at
    settings:saveSetting("position_declined", t)
    if settings.flush then settings:flush() end
end

-- ---------------------------------------------------------------- rule inputs

--[[--
Whether this device holds reading of a novel newer than the server's
position (age_seconds old): an overlay entry (local progress/read mark) or a
queued progress/read write recorded less than age_seconds ago. Ages, not
clocks, are compared (now_ms and the entries' `at` are this device's clock).
]]
function Position.localNewer(overlay, queue_items, novel_id, age_seconds, now_ms)
    local age = tonumber(age_seconds)
    if not age then return false end
    local limit = age * 1000
    local function newer(at)
        at = tonumber(at)
        return at ~= nil and now_ms - at < limit
    end
    for _k, e in pairs(overlay or {}) do
        if type(e) == "table" and (e.read == true or e.progress ~= nil) and newer(e.at) then return true end
    end
    for _i, q in ipairs(queue_items or {}) do
        if type(q) == "table" and q.novel_id == novel_id and newer(q.at)
                and (q.kind == "progress" or (q.kind == "set_read" and q.read == true)) then
            return true
        end
    end
    return false
end

-- ---------------------------------------------------------------- KOReader side

local function S() return require("novarr.session") end
local function R() return require("novarr.reading") end
local function log(text) require("logger").info("Novarr: latest position: " .. text) end

--- An open from Novarr's screens asked for the check (Session:openChapter, opts.check_position).
function Position.request(Session, novel_id)
    Session.position_check = { novel_id = novel_id, at = Session.nowMs() }
end

--- Takes the pending check request for novel_id (nil when none, or too old).
function Position.take(Session, novel_id)
    local req = Session.position_check
    Session.position_check = nil
    if not req or req.novel_id ~= novel_id then return nil end
    if Session.nowMs() - req.at > Position.REQUEST_TTL_MS then return nil end
    return req
end

--[[--
The chapter `plugin` shows is ready (or was jumped to inside the open group
file): if its open asked for it, check the server's position a moment later.
]]
function Position.afterOpen(plugin, novel_id)
    local Session = S()
    if not Position.take(Session, novel_id) then return end
    local UIManager = require("ui/uimanager")
    if plugin.position_task then UIManager:unschedule(plugin.position_task) end
    plugin.position_task = function()
        Session.guard(Position.check, plugin, novel_id)
    end
    UIManager:scheduleIn(Position.CHECK_DELAY, plugin.position_task)
end

function Position.unschedule(plugin)
    if plugin.position_task then
        require("ui/uimanager"):unschedule(plugin.position_task)
        plugin.position_task = nil
    end
end

--- Asks the server and, when the rule says so, shows the question.
function Position.check(plugin, novel_id)
    local Session = S()
    plugin.position_task = nil
    local r = plugin.reading
    if not r or r.novel_id ~= novel_id or not r.ready then return end
    -- Never turns Wi-Fi on, never prompts: only when already connected.
    if not Session:serverUrl() or not Session:isConnected() then
        log("not connected, not asked")
        return
    end
    local t0 = Session.nowMs()
    local p, e = Session.api:position(novel_id)
    if not p then
        log(p == false and "none on the server" or ("not available (" .. tostring(e and e.kind) .. ")"))
        return
    end
    r = plugin.reading
    if not r or r.novel_id ~= novel_id then return end
    local chapter_id = tonumber(p.chapter_id)
    local stored = Session.store:hasChapter(novel_id, chapter_id)
    local in_file = r.group ~= nil and r.group.index[chapter_id] ~= nil
    local ok, why = Model.shouldOfferPosition({
        position = p,
        device_id = Session.device_id,
        chapter_id = r.chapter_id,
        local_progress = R().currentPercent(plugin),
        local_newer = Position.localNewer(Session.store:getOverlay(novel_id), Session.queue:list(), novel_id,
            p.age_seconds, Session.nowMs()),
        declined_at = Position.declinedAt(Session.settings, novel_id),
        -- Opened the way any chapter is: stored here, or fetched (connected, and the server has its text).
        can_open = stored or in_file or (p.downloaded == true and Session:isConnected()),
    })
    log(string.format("chapter %s (%s) on %s, %d ms: %s", tostring(chapter_id), tostring(p.progress),
        tostring(p.device_name), Session.nowMs() - t0, ok and "asking" or why))
    if ok then Position.ask(plugin, novel_id, p) end
end

function Position.ask(plugin, novel_id, p)
    local _ = require("gettext")
    local UI = require("novarr.ui_common")
    local Session = S()
    UI.confirm({
        text = Model.positionPromptText(p, _),
        ok_text = _("Go to latest"),
        cancel_text = _("Stay here"),
        ok = function() Position.go(plugin, novel_id, p) end,
        -- Also a tap outside the box (ConfirmBox closes through its cancel callback).
        cancel = function() Position.decline(Session.settings, novel_id, p.at) end,
    })
end

--- "Go to latest": that chapter at the other device's place.
function Position.go(plugin, novel_id, p)
    local Session = S()
    local chapter_id = tonumber(p.chapter_id)
    local progress = math.max(0, math.min(100, tonumber(p.progress) or 0))
    local r = plugin.reading
    if r and r.novel_id == novel_id and (r.chapter_id == chapter_id or (r.group and r.group.index[chapter_id])) then
        log("going to chapter " .. chapter_id .. " at " .. progress .. "% in the open document")
        R().goToProgress(plugin, chapter_id, progress)
        return
    end
    log("opening chapter " .. chapter_id .. " at " .. progress .. "%")
    Session.forced_progress = { novel_id = novel_id, chapter_id = chapter_id, progress = progress, at = Session.nowMs() }
    -- Never switch documents from inside a dialog's callback.
    require("ui/uimanager"):nextTick(Session.wrap(function()
        Session:openChapter(novel_id, chapter_id, { from_reader = plugin.reading ~= nil })
    end))
end

--[[--
Takes the "Go to latest" place for a chapter being opened: its progress
(0..100) when that chapter is the one Go asked for, else nil.
]]
function Position.takeForced(Session, novel_id, chapter_id)
    local f = Session.forced_progress
    if not f or f.novel_id ~= novel_id or f.chapter_id ~= chapter_id then return nil end
    Session.forced_progress = nil
    if Session.nowMs() - f.at > Position.FORCED_TTL_MS then return nil end
    return f.progress
end

return Position
