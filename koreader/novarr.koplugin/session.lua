--[[--
Shared Novarr state and actions for every plugin instance (file manager and
reader each get their own plugin instance; this module outlives them).

Holds the settings, the store, the write queue and the API client, the stack
of open Novarr screens, and the actions screens and the reader call:
opening chapters, downloading, syncing, marking read.
]]

local DataStorage = require("datastorage")
local LuaSettings = require("luasettings")
local NetworkMgr = require("ui/network/manager")
local UIManager = require("ui/uimanager")
local lfs = require("libs/libkoreader-lfs")
local logger = require("logger")
local _ = require("gettext")
local T = require("ffi/util").template

local Address = require("novarr.address")
local Api = require("novarr.api")
local Auth = require("novarr.auth")
local ChapterDoc = require("novarr.chapterdoc")
local Groups = require("novarr.groups")
local Json = require("novarr.json")
local Model = require("novarr.model")
local Position = require("novarr.position")
local Queue = require("novarr.queue")
local ReadingInfo = require("novarr.readinginfo")
local Store = require("novarr.store")
local Sync = require("novarr.sync")

local Session = {
    menus = {},          -- open Novarr screens, bottom to top ({menu=, screen=})
    reader = nil,        -- reader-side plugin instance showing a Novarr chapter
    plugin = nil,        -- most recent plugin instance
    return_to = nil,     -- {novel_id} to show again when the reader closes to the file manager
    opened_from_novarr = false,
    origin_kind = nil,   -- kind of the Novarr screen the open book was opened from (Model.returnScreens)
    switching = false,   -- a Novarr chapter is replacing another one in the reader
    chapter_lists = {},  -- novel_id -> {list=, by_id=, fetched_at=} memory cache
    last_errors = {},    -- screen key -> {time, kind} of the last failed refresh
    empty_chapters = {}, -- chapter ids the server had no text for (this session)
    bad_bodies = {},     -- stored chapter ids whose file has no readable text (left out of group files)
    group_target = nil,  -- {path, chapter_id, at_end, decision}: where to go in a group file being opened
}

Session.VERSION = Api.VERSION
-- Seconds after the position check / a chapter open before a re-probe it skipped runs.
Session.DEFERRED_REPROBE_S = 5

local DEFAULTS = {
    prefetch = 100,
    wifi_auto_off = false, -- Wi-Fi stays on (1.7.0): the position reaches the server as it changes
    remove_after_reading = true,
    keep_read = 2,
    mark_read_at_end = true,
    show_reading_info = true,
    show_clock = true,
    show_sync_status = true,
    show_covers = true,
    open_on_start = true,
    keep_style = true,
    auto_update_check = true,
    auto_update_install = false,
    join_chapters = true,
    group_size = Groups.DEFAULT_SIZE,
    position_prompt = true, -- ask to go to the latest place read on another device (position.lua)
    info_position = "bottom", -- reading info: "bottom" | "top" | "both" (reading.lua)
    hold_menu = true,         -- hold the status bar for the quick menu (ui_quickmenu.lua)
}

local purgeSidecar -- defined with the removal functions below

local function nowMs()
    local ok, socket = pcall(require, "socket")
    if ok and socket.gettime then
        return math.floor(socket.gettime() * 1000)
    end
    return os.time() * 1000
end
Session.nowMs = nowMs

local function log(msg)
    logger.info(msg)
end
Session.log = log

-- ---------------------------------------------------------------- setup

function Session:init()
    if self.initialized then return end
    self.initialized = true
    self.settings = LuaSettings:open(DataStorage:getSettingsDir() .. "/novarr.lua")
    self.root = DataStorage:getFullDataDir() .. "/novarr"
    self.store = Store.new({ root = self.root, lfs = lfs, json = Json, now = nowMs, log = log })
    self.store:mkdirs(self.root)
    self.queue = Queue.new({
        load = function() return self.store:loadQueue() end,
        save = function(items) self.store:saveQueue(items) end,
        now = nowMs,
        log = log,
    })
    self:migrateSettings()
    -- This device, as the server sees it (the "read on another device" question).
    self.device_id, self.device_name = Position.identity(self.settings, {
        model = Session.deviceModel(), random_bytes = Position.urandom,
    })
    self.address = Address.new({
        probe = function(base) return self:probeAddress(base) end,
        now = nowMs,
        log = log,
        on_switch = function(base) self:set("server_active", base) end,
        -- A re-probe the position check or a chapter open skipped: run it a moment after.
        defer = function(fn)
            -- fn is always the address's own (stable) function: one scheduled run at a time.
            self.deferred_reprobe = self.deferred_reprobe or function() Session.guard(fn) end
            UIManager:unschedule(self.deferred_reprobe)
            UIManager:scheduleIn(Session.DEFERRED_REPROBE_S, self.deferred_reprobe)
        end,
    })
    self:rebuildApi()
    -- KOReader started: the first request checks the home address again.
    self.address:invalidate("start")
    -- The time of the last user action (the automatic plugin install waits for 30 s without one).
    Session.last_input = nowMs()
    pcall(function()
        UIManager.event_hook:register("InputEvent", function() Session.last_input = nowMs() end)
    end)
end

--[[--
Old defaults of chapters to fetch ahead become the current one (100),
unless the user chose that number: 3 (before 1.1), then 20 (before 1.3).
]]
function Session:migrateSettings()
    local changed = false
    local user_set = self.settings:readSetting("prefetch_user_set")
    if not self.settings:readSetting("prefetch_migrated") then
        if self.settings:readSetting("prefetch") == 3 and not user_set then
            self.settings:delSetting("prefetch")
        end
        self.settings:saveSetting("prefetch_migrated", true)
        changed = true
    end
    if not self.settings:readSetting("prefetch_migrated_100") then
        if self.settings:readSetting("prefetch") == 20 and not user_set then
            self.settings:delSetting("prefetch")
        end
        self.settings:saveSetting("prefetch_migrated_100", true)
        changed = true
    end
    -- 1.7.0: Wi-Fi stays on unless the setting is switched on again after
    -- this (the old default, on, delayed the reading position).
    if not self.settings:readSetting("wifi_stays_on_migrated") then
        self.settings:delSetting("wifi_auto_off")
        self.settings:saveSetting("wifi_stays_on_migrated", true)
        changed = true
    end
    if changed then self.settings:flush() end
end

--- KOReader's model name of this device ("KindlePaperWhite5"), or nil.
function Session.deviceModel()
    local ok, Device = pcall(require, "device")
    if not ok or type(Device) ~= "table" then return nil end
    local okm, model = pcall(function() return Device.model end)
    return okm and type(model) == "string" and model or nil
end

--[[--
Sets this device's name (Settings): the cleaned name (Position.cleanName)
is kept in the setting device_name; nothing left removes it (back to the
model name). The API client sends the new name from the next request on.
Returns the name in use.
]]
function Session:setDeviceName(text)
    local name = Position.cleanName(text)
    if name then self:set("device_name", name) else self:unset("device_name") end
    self.device_id, self.device_name = Position.identity(self.settings, { model = Session.deviceModel() })
    self:rebuildApi()
    return self.device_name
end

--- Points the API client at the configured address(es); keeps the remembered active one.
function Session:rebuildApi()
    self.address:configure(self:get("server_url"), self:get("server_url_away"), self:get("server_active"))
    self.api = Api.new({ base_url = self:get("server_url"), router = self.address, log = log,
        device = { id = self.device_id, name = self.device_name },
        -- The token is read at each request: linking or unlinking needs no rebuild.
        auth = {
            token = function() return self:authToken() end,
            blocked = function() return self:needsLink() end,
            on_unauthenticated = function(e) self:onUnauthenticated(e) end,
            on_forbidden = function(e) self:onForbidden(e) end,
        } })
end

--- Whether `base` answers as a Novarr server within the probe timeout (no Wi-Fi prompt).
function Session:probeAddress(base)
    if not base or Api.isOffline() then return false end
    local api = Api.new({ base_url = base, log = log })
    local meta = api:meta({ block_timeout = Address.PROBE_TIMEOUT, total_timeout = Address.PROBE_TIMEOUT })
    -- Both addresses are this server: what it says about sign-in is kept.
    if meta then self:noteMeta(meta) end
    return meta ~= nil
end

-- ---------------------------------------------------------------- account (auth.lua)

--- The bearer token held for this server, or nil.
function Session:authToken()
    return Auth.token(self.settings, self:serverUrl())
end

--- The linked account {id, name, username, role}, or nil.
function Session:authUser()
    return Auth.user(self.settings, self:serverUrl())
end

--- Whether this server requires sign-in (meta said so, or a 401 came).
function Session:authRequired()
    return Auth.required(self.settings, self:serverUrl())
end

--- Sign-in required and no token: nothing is sent until this Kindle is linked.
function Session:needsLink()
    return Auth.needsLink(self.settings, self:serverUrl())
end

--- "admin" or "reader" ("admin" whenever sign-in is not required).
function Session:role()
    return Auth.role(self.settings, self:serverUrl())
end

function Session:allows(action)
    return Auth.allows(self:role(), action)
end

--[[--
/api/v1/meta of this server answered: keeps whether sign-in is required,
the server's instance id (server_instance) when it gives one, and whether
it has books (server_books, 1.12.0).
No longer required: nothing is blocked any more (a held token is kept, and
still sent).
]]
function Session:noteMeta(meta, opts)
    local server = self:serverUrl()
    if not server then return end
    -- Which install this is (the same id on every address of it): a new
    -- address with the same id is the same server (Session:setServer).
    -- opts.keep_instance: the meta of an address the user typed as the second
    -- one, never trusted over an id already known from the home address.
    local instance = Address.instanceId(type(meta) == "table" and meta.instance or nil)
    local known = self:get("server_instance")
    if instance and known ~= instance and not (opts and opts.keep_instance and known) then
        self:set("server_instance", instance)
    end
    if Auth.setRequired(self.settings, server, Auth.requiredFromMeta(meta)) then
        log("Novarr: the server " .. (Auth.requiredFromMeta(meta) and "requires" or "does not require") .. " sign-in")
    end
    -- Books (1.12.0): only a server that says so is asked for medium=text.
    local books = Api.supportsBooks(meta)
    if (self:get("server_books") == true) ~= books then
        self:set("server_books", books)
        log("Novarr: the server " .. (books and "has" or "has no") .. " books")
    end
end

--- The medium the library is asked for: "text" (novels and books) when the server has books, else nil.
function Session:textMedium()
    return Api.textMedium(self:get("server_books") == true)
end

--- Fetches the library (GET novels, with the medium this server supports) into the "novels" cache.
function Session:fetchLibrary()
    local medium = self:textMedium()
    return self:fetchInto("novels", function(etag) return self.api:novels(etag, medium) end, true)
end

--[[--
Whether a novel is a book (medium "book": complete, never gets new
chapters). From its cached detail or the library; unknown counts as a novel.
]]
function Session:isBook(novel_id)
    local detail = self:cached("novel_" .. novel_id)
    if detail and type(detail.novel) == "table" and detail.novel.medium then
        return Model.isBook(detail.novel)
    end
    return Model.isBook(self:novelSummary(novel_id))
end

--[[--
Asks this server's meta (short timeouts) when sign-in is not known yet,
while this Kindle is blocked waiting to be linked (the server may have
switched sign-in off), or once per start while its instance id is not
known or it has not said it has books (1.12.0). Called by user actions only (Home's refresh, Link).
Returns meta or nil.
]]
function Session:refreshAuthState(force)
    local server = self:serverUrl()
    if not server then return nil end
    -- Also once per KOReader start while the server's instance id is not known (Session:isSameServer),
    -- or while it has not said it has books (it may have been updated since).
    local instance_known = (self:get("server_instance") ~= nil and self:get("server_books") == true) or self.instance_asked
    if not force and Auth.known(self.settings, server) and not self:needsLink() and instance_known then return nil end
    self.instance_asked = true
    local meta = self.api:meta({ block_timeout = 5, total_timeout = 8 })
    if meta then self:noteMeta(meta) end
    return meta
end

--[[--
A 401 from Novarr (api.lua's on_unauthenticated): the token is forgotten,
sign-in is marked required (so nothing more is sent until linked: no retry
storm), the queue is left as it is, and the user is told once, a moment
later, with a way to link (unless an error message already said it:
errorText marks that).
]]
function Session:onUnauthenticated(e)
    local server = self:serverUrl()
    if not server then return end
    local had = Auth.forget(self.settings, server)
    Auth.setRequired(self.settings, server, true)
    log("Novarr: the server answered 401" .. (had and ", forgetting the token" or "") ..
        (e and e.message and (": " .. e.message) or "") .. "; nothing is sent until this Kindle is linked again")
    if self.auth_told then return end
    if not self.auth_notice_task then
        self.auth_notice_task = function()
            Session.guard(function()
                if self.auth_told or not self:needsLink() then return end
                if Session.asleep then
                    UIManager:scheduleIn(30, self.auth_notice_task)
                    return
                end
                self.auth_told = true
                self:refreshScreens()
                require("novarr.ui_auth").notifyUnlinked(self)
            end)
        end
    end
    UIManager:unschedule(self.auth_notice_task)
    UIManager:scheduleIn(1, self.auth_notice_task)
end

--[[--
Asks the server who the linked account is now (GET auth/me) and keeps the
answer: the role decides what the screens offer (Discover). Only with a
token held and already connected. force: a 403 just came (at most once a
minute); else at most once a day (Home's refresh). Returns true when the
saved account changed (the open screens are rebuilt then).
]]
function Session:refreshUser(force)
    local server = self:serverUrl()
    if not server or not self:authToken() or not self:isConnected() then return false end
    local now = os.time()
    if not Auth.userRefreshDue(self:get("auth_me_at"), now, force) then return false end
    self:set("auth_me_at", now)
    local user, e = self.api:me()
    if not user then
        log("Novarr: asking the server for the account failed (" .. tostring(e and e.kind) .. ")")
        return false
    end
    local changed = Auth.updateUser(self.settings, server, user)
    if changed then
        log("Novarr: the account changed on the server; role now " .. self:role())
        self:refreshScreens()
    end
    return changed
end

--- A 403 from Novarr (api.lua's on_forbidden): the role may have changed; asked again a moment later.
function Session:onForbidden(e)
    log("Novarr: the server answered 403" .. (e and e.message and (": " .. e.message) or ""))
    if not self:authToken() then return end
    if not self.forbidden_task then
        self.forbidden_task = function() Session.guard(function() self:refreshUser(true) end) end
    end
    UIManager:unschedule(self.forbidden_task)
    UIManager:scheduleIn(1, self.forbidden_task)
end

--- Linked (pairing): keeps the token and the account, then sends what waited.
function Session:linked(token, user)
    Auth.link(self.settings, self:serverUrl(), token, user)
    self.auth_told = false
    log("Novarr: linked to the account of " .. tostring(user and user.name))
    self:refreshScreens()
    if self.queue:count() > 0 then self:flushSoon(1) end
end

--[[--
Unlink: DELETE /auth/token (best effort, only when connected), then the
token is forgotten here. Sign-in stays required, so nothing is sent until
this Kindle is linked again. Returns true when the server was told (or
there was no token to revoke); false when it could not be (offline, an
error): the access then still shows in the account on the website.
]]
function Session:unlink()
    local told = not self:authToken()
    if self:authToken() and self:isConnected() then
        local _ok, e = self.api:revokeToken()
        if e then
            log("Novarr: revoking the token on the server failed (" .. tostring(e.kind) .. "), forgetting it here anyway")
        else
            told = true
        end
    end
    Auth.forget(self.settings, self:serverUrl())
    self.auth_told = true -- the user did it: no "not linked" notice
    self:refreshScreens()
    return told
end

--[[--
Headers for a URL the server gave (the EPUB download): the bearer token,
only when the URL is on one of this server's addresses (never sent
anywhere else). Returns a table (empty without a token).
]]
function Session:authHeadersFor(url)
    local bearer = Auth.bearer(self:authToken())
    if bearer and self.address and type(url) == "string" and self.address:owns(url) then
        return { ["Authorization"] = bearer }
    end
    return {}
end

--- The address requests use now (the home one unless a second one is set and in use).
function Session:activeUrl()
    return self.address and self.address.active or self:serverUrl()
end

function Session:get(key)
    local v = self.settings:readSetting(key)
    if v == nil then return DEFAULTS[key] end
    return v
end

function Session:set(key, value)
    self.settings:saveSetting(key, value)
    self.settings:flush()
end

function Session:unset(key)
    self.settings:delSetting(key)
    self.settings:flush()
end

function Session:serverUrl()
    return self:get("server_url")
end

-- ---------------------------------------------------------------- guards

--[[--
Runs fn in pcall; on error logs it and shows a brief message instead of
letting it reach KOReader. Returns fn's first result.
]]
function Session.guard(fn, ...)
    local ok, res = pcall(fn, ...)
    if not ok then
        logger.err("Novarr: unexpected error:", res, debug.traceback())
        pcall(function()
            local InfoMessage = require("ui/widget/infomessage")
            UIManager:show(InfoMessage:new{ text = _("Novarr ran into a problem. Details are in KOReader's log."), timeout = 3 })
        end)
        return nil
    end
    return res
end

--- Wraps fn so every call is guarded.
function Session.wrap(fn)
    return function(...) return Session.guard(fn, ...) end
end

-- ---------------------------------------------------------------- network

--- Cheap check: is there a network connection (never turns Wi-Fi on).
function Session:isConnected()
    if Api.isOffline() then return false end
    local ok, res = pcall(NetworkMgr.isConnected, NetworkMgr)
    return ok and res and true or false
end

--[[--
Runs fn when connected (an explicit user action: Sync, Download, Discover,
Link, Check for new chapters, Add...). Not connected: when Novarr itself
had switched Wi-Fi off (wifi.lua), it is switched on again without asking
("Turning Wi-Fi on…" shows when that takes more than 2 s, unless
opts.quiet); otherwise Novarr asks "Turn Wi-Fi on?" (Wifi.connectForUser,
following KOReader's "Action when Wi-Fi is off" setting). on_unavailable
runs whenever no connection results: the user said no, switching Wi-Fi on
failed or timed out (or the offline test hook is on). Returns true when fn
ran right away.
]]
function Session:whenConnected(fn, on_unavailable, opts)
    opts = opts or {}
    local function unavailable()
        if on_unavailable then Session.guard(on_unavailable) end
    end
    if Api.simulate_offline then
        unavailable()
        return false
    end
    if self:isConnected() then
        fn()
        return true
    end
    local wrapped = Session.wrap(fn)
    local Wifi = require("novarr.wifi")
    if Wifi.restore(opts.reason or "a Novarr action needs the network", wrapped, unavailable,
            { notice = not opts.quiet and _("Turning Wi-Fi on…") or nil }) then
        return false
    end
    Wifi.connectForUser(wrapped, unavailable, { reason = opts.reason })
    return false
end

--[[--
Opening a screen (Home, Library, a novel, its chapters): fn runs only when
already connected (a quiet refresh); otherwise on_offline runs at once (the
screen shows its saved data). Never asks to turn Wi-Fi on. Returns true
when fn ran.
]]
function Session:whenOnline(fn, on_offline)
    if self:isConnected() then
        fn()
        return true
    end
    if on_offline then on_offline() end
    return false
end

-- ---------------------------------------------------------------- messages

function Session:errorText(e)
    local kind = e and e.kind or "offline"
    local server = self:activeUrl() or ""
    if kind == "bad_url" then
        return _("That doesn't look like a server address. Try something like 192.168.1.34:8080.")
    elseif kind == "offline" then
        return T(_("Couldn't reach the Novarr server at %1.\n\nCheck that the server is running and that this device is on the same network."), server)
    elseif kind == "not_novarr" then
        return T(_("%1 answered, but it isn't a Novarr server (or its API isn't available)."), server)
    elseif kind == "not_found" then
        return _("The server doesn't know this item any more (it may have been removed).")
    elseif kind == "unauthenticated" then
        -- The message says how to link: the "not linked" notice is not needed any more.
        self.auth_told = true
        return _("This Kindle isn't linked to your Novarr account (or the link was removed).\n\nLink it in Novarr → Settings → Link this Kindle. Your reading on this Kindle is kept and sent once it is linked.")
    elseif kind == "manga" then
        return _("Manga can't be read on this device. Open it on the website or the Android app.")
    elseif kind == "forbidden" then
        return e.message or _("Your Novarr account isn't allowed to do this.")
    elseif kind == "server" and e.message == "login page" then
        return _("The Novarr server sent an answer that doesn't make sense. Is something else (a login page or a proxy) in the way?")
    elseif kind == "invalid" then
        if e.message then
            return T(_("The server refused the request: %1"), e.message)
        end
        return _("The server refused the request.")
    end
    if e.status then
        return T(_("The Novarr server had a problem (error %1). Try again later."), e.status)
    end
    return _("The Novarr server sent an answer that doesn't make sense. Is something else (a login page or a proxy) in the way?")
end

-- ---------------------------------------------------------------- cache

--[[--
Fetches a list endpoint into the cache. key: cache key, call(etag) performs
the request. Returns data (from server or cache), err (nil on success).
On 304 the cached copy is kept.
]]
function Session:fetchInto(key, call, use_etag)
    local cached = self.store:getCache(key)
    local started = nowMs()
    local data, e, info = call(use_etag and cached and cached.etag or nil)
    if info and info.not_modified and cached then
        self.store:touchCache(key, started)
        self.last_errors[key] = nil
        return cached.data, nil, started
    end
    if not data then
        if info and info.not_modified then
            -- 304 without a cached body: fetch again without the ETag.
            data, e, info = call(nil)
        end
        if not data then
            self.last_errors[key] = { time = nowMs(), kind = e and e.kind }
            return cached and cached.data or nil, e or Api.err("server")
        end
    end
    self.store:putCache(key, info.body, info.etag, started)
    self.last_errors[key] = nil
    local novel_id = key:match("^chapters_(%d+)$")
    if novel_id then self.chapter_lists[tonumber(novel_id)] = nil end
    return data, nil, started
end

function Session:cached(key)
    local c = self.store:getCache(key)
    return c and c.data or nil, c and c.fetched_at or nil
end

--- Chapter list for a novel from memory/disk cache: {list, by_id, pos} or nil.
function Session:chapterList(novel_id)
    local mem = self.chapter_lists[novel_id]
    if mem then return mem end
    local t0 = nowMs()
    local cache = self.store:getCache("chapters_" .. novel_id)
    if not cache or type(cache.data) ~= "table" or type(cache.data.chapters) ~= "table" then
        return nil
    end
    local list = cache.data.chapters
    local by_id, pos = {}, {}
    for i, c in ipairs(list) do
        by_id[c.id] = c
        pos[c.id] = i
    end
    mem = { list = list, by_id = by_id, pos = pos, fetched_at = cache.fetched_at }
    self.chapter_lists[novel_id] = mem
    log(string.format("Novarr: loaded saved chapter list of novel %d (%d chapters) in %d ms", novel_id, #list, nowMs() - t0))
    return mem
end

--- Fetches a novel's chapter list (ETag) and settles the overlay. Returns list entry, err.
function Session:refreshChapterList(novel_id)
    local _data, e, started = self:fetchInto("chapters_" .. novel_id, function(etag)
        return self.api:chapters(novel_id, etag)
    end, true)
    if not e and started then
        self.store:settleOverlay(novel_id, started)
    end
    return self:chapterList(novel_id), e
end

--- Novel summary from the cached detail, library or home.
function Session:novelSummary(novel_id)
    local detail = self:cached("novel_" .. novel_id)
    if detail and detail.novel then return detail.novel end
    local novels = self:cached("novels")
    if novels and novels.novels then
        for _i, n in ipairs(novels.novels) do
            if n.id == novel_id then return n end
        end
    end
    local home = self:cached("home")
    if home then
        for _i, list in ipairs({ home.continue or {}, home.new_chapters or {} }) do
            for _i, row in ipairs(list) do
                if row.novel and row.novel.id == novel_id then return row.novel end
            end
        end
    end
    local index = self.store:getIndex(novel_id)
    if index.novel_name then
        return { id = novel_id, name = index.novel_name }
    end
    return nil
end

-- ---------------------------------------------------------------- read state

--- Effective read state of a chapter: read (bool), progress (int|nil).
function Session:chapterState(novel_id, chapter_id)
    local cl = self:chapterList(novel_id)
    local row = cl and cl.by_id[chapter_id]
    if not row then
        local entry = self.store:chapterEntry(novel_id, chapter_id)
        if entry then row = { read = entry.read, read_progress = entry.read_progress } end
    end
    return Store.effective(row, self.store:getOverlay(novel_id)[tostring(chapter_id)])
end

--[[--
Local adjustment of a novel's read count from the overlay (marks not yet in
the server's numbers). Only computed for novels with pending marks.
]]
function Session:readDelta(novel_id)
    local overlay = self.store:getOverlay(novel_id)
    if next(overlay) == nil then return 0 end
    local cl = self:chapterList(novel_id)
    if not cl then return 0 end
    local delta = 0
    for key, entry in pairs(overlay) do
        if entry.read ~= nil then
            local row = cl.by_id[tonumber(key)]
            if row and row.read ~= entry.read then
                delta = delta + (entry.read and 1 or -1)
            end
        end
    end
    return delta
end

--[[--
Resume chapter for a novel: the server's resume chapter combined with this
device's own reading not sent yet, by the rule every device shares (see
Model.resolveResume for the rule and its tests).
]]
function Session:resumeChapter(novel_id, summary)
    summary = summary or self:novelSummary(novel_id) or {}
    local cl = self:chapterList(novel_id)
    if not cl then return summary.resume_chapter_id end
    -- Read state and progress: the rule needs both.
    return Model.resolveResume(cl.list, summary.resume_chapter_id, self.store:getOverlay(novel_id),
        function(c) return self:chapterState(novel_id, c.id) end)
end

--- Time (ms) of this device's latest reading in a novel not yet in the server's data, or nil.
function Session:localLastRead(novel_id)
    local latest
    for _k, e in pairs(self.store:getOverlay(novel_id)) do
        if type(e) == "table" and (e.read == true or (e.progress or 0) > 0) and e.at then
            if not latest or e.at > latest then latest = e.at end
        end
    end
    return latest
end

--- Read count of a novel including this device's marks the server has not seen yet.
function Session:readCount(novel)
    local read = (tonumber(novel.chapters_read) or 0) + self:readDelta(novel.id)
    local total = tonumber(novel.chapters_total) or 0
    return math.max(0, math.min(read, total)), total
end

--[[--
One tap on a novel (Home, Library): open its resume chapter straight away,
or the Novel screen when there is nothing to resume. Once it is open, the
server is asked whether another device read further (position.lua).
]]
function Session:openNovel(novel_id)
    local summary = self:novelSummary(novel_id) or { id = novel_id }
    local action, chapter_id = Model.tapTarget(summary, self:resumeChapter(novel_id, summary))
    if action == "open" then
        return self:openChapter(novel_id, chapter_id, { check_position = true })
    end
    self:showNovel(novel_id)
end

-- ---------------------------------------------------------------- estimates

--- Average word count per chapter: the API's word_count when present, else stored chapters.
function Session:averageWords(novel_id)
    local cl = self:chapterList(novel_id)
    local avg = cl and ReadingInfo.averageWords(cl.list)
    if avg then return avg end
    local rows = {}
    for _i, entry in pairs(self.store:getIndex(novel_id).chapters) do table.insert(rows, entry) end
    return ReadingInfo.averageWords(rows)
end

--- Seconds per chapter for a novel (measured or estimated) and the source.
function Session:secondsPerChapter(novel_id)
    return ReadingInfo.secondsPerChapter(self.store:getTiming(novel_id), self:averageWords(novel_id))
end

--- Records the active reading time of a finished chapter. Returns true if kept.
function Session:recordChapterTime(novel_id, seconds)
    local _list, kept = ReadingInfo.addSample(self.store:getTiming(novel_id), seconds)
    if kept then self.store:saveTiming() end
    return kept
end

--[[--
A chapter finished on this device by reading it to its end (stats.lua):
recorded with the time and its active reading seconds (nil when not timed).
]]
function Session:recordChapterRead(novel_id, seconds)
    local Stats = require("novarr.stats")
    Stats.add(self.store:getStats(novel_id), os.time(), seconds)
    self.store:saveStats()
end

--[[--
The Novel screen's reading stats: {today, week, average, catch_up, unread}
(see stats.lua for the rules). `now` defaults to the current time.
]]
function Session:novelStats(novel_id, now)
    local Stats = require("novarr.stats")
    now = now or os.time()
    local events = self.store:getStats(novel_id)
    local today, week = Stats.counts(events, now)
    local average = Stats.average(Stats.durations(self.store:getTiming(novel_id), events))
    local unread
    local cl = self:chapterList(novel_id)
    if cl then
        unread = ReadingInfo.chaptersLeft(cl.list, 0, function(c) return (self:chapterState(novel_id, c.id)) end)
    end
    return { today = today, week = week, average = average, unread = unread,
        catch_up = Stats.catchUp(unread, average) }
end

--[[--
Chapters left and seconds left for a novel. With `current_id`: chapters
after the open one (time includes the rest of it, at `percent`). Without:
from the resume chapter on (inclusive). Returns left (nil when the chapter
list is not cached), seconds (nil when unknown).
]]
function Session:novelEstimate(novel_id, current_id, percent)
    local cl = self:chapterList(novel_id)
    if not cl then return nil end
    local is_read = function(c) return (self:chapterState(novel_id, c.id)) end
    local per = self:secondsPerChapter(novel_id)
    if current_id then
        local pos = cl.pos[current_id]
        if not pos then return nil end
        local left = ReadingInfo.chaptersLeft(cl.list, pos, is_read)
        return left, ReadingInfo.secondsLeft(per, left, percent)
    end
    local resume = self:resumeChapter(novel_id)
    local pos = resume and cl.pos[resume] or 1
    local left = ReadingInfo.chaptersLeft(cl.list, pos - 1, is_read)
    return left, per and per * left or nil
end

--[[--
Records a read mark locally and queues it. kind: "end" (read at the end of
the chapter, via the progress call), "set" (explicit mark read/unread).
]]
function Session:markChapter(novel_id, chapter_id, read, kind)
    if kind == "end" then
        self.store:setOverlay(novel_id, chapter_id, { read = true, progress = 100, reading = true })
        self.queue:enqueue({ kind = "progress", chapter_id = chapter_id, novel_id = novel_id, progress = 100, read = true })
    else
        -- A hand mark: the read state only, never the resume anchor (Model.resolveResume).
        self.store:setOverlay(novel_id, chapter_id, { read = read, progress = read and 100 or nil, hand = true })
        self.queue:enqueue({ kind = "set_read", chapter_id = chapter_id, novel_id = novel_id, read = read })
    end
end

--- Records reading progress locally and queues it (coalesced).
function Session:recordProgress(novel_id, chapter_id, percent)
    percent = math.max(0, math.min(100, math.floor(percent + 0.5)))
    self.store:setOverlay(novel_id, chapter_id, { progress = percent, reading = true })
    self.queue:enqueue({ kind = "progress", chapter_id = chapter_id, novel_id = novel_id, progress = percent })
end

--[[--
Bulk mark (scope "all" or "up_to"): one queued call, overlay updated for
every chapter of the cached list in range.
]]
function Session:bulkMark(novel_id, scope, anchor_id, read)
    local cl = self:chapterList(novel_id)
    if cl then
        local last = #cl.list
        if scope == "up_to" and anchor_id then last = cl.pos[anchor_id] or 0 end
        for i = 1, last do
            local c = cl.list[i]
            self.store:setOverlay(novel_id, c.id, { read = read, progress = read and 100 or nil, hand = true }, false)
        end
        self.store:saveOverlay(novel_id)
    end
    local bulk = { read = read, scope = scope, novel_id = novel_id }
    if scope == "up_to" then bulk.anchor_id = anchor_id end
    self.queue:enqueue({ kind = "bulk_read", novel_id = novel_id, bulk = bulk })
end

-- ---------------------------------------------------------------- sync

--[[--
Sends pending writes now (no UI). Returns the summary. A flush that sent
something and emptied the queue is the last sync (last_sync_at, os.time(),
for the status line); the status line is redrawn when something was sent
while a chapter is open.
]]
function Session:flushQueue(max_entries)
    if self.queue:count() == 0 then
        return { sent = 0, dropped = 0, remaining = 0 }
    end
    -- Not linked: nothing can be sent; the writes wait (no request at all).
    if self:needsLink() then
        return { sent = 0, dropped = 0, remaining = self.queue:count(), stalled = true,
            error = Api.err("unauthenticated", nil, "not linked") }
    end
    local r = Sync.flush({ api = self.api, queue = self.queue, store = self.store, now = nowMs, log = log,
        max_entries = max_entries, on_sent = function(entry, data) self:writeSent(entry, data) end })
    local done = (r.sent or 0) + (r.dropped or 0)
    if done > 0 then
        if (r.remaining or 0) == 0 then self:set("last_sync_at", os.time()) end
        local reader = self.reader
        local Reading = package.loaded["novarr.reading"]
        if reader and reader.reading and type(Reading) == "table" and Reading.refreshFooter then
            pcall(Reading.refreshFooter, reader)
        end
    end
    return r
end

--[[--
What the status line says about syncing: {queue = writes waiting, chapters =
finished chapters among them, last_sync_at = os.time() of the last complete
sync or nil}.
]]
function Session:syncState()
    local chapters = 0
    for _i, e in ipairs(self.queue:list()) do
        if (e.kind == "progress" or e.kind == "set_read") and e.read == true then chapters = chapters + 1 end
    end
    return { queue = self.queue:count(), chapters = chapters, last_sync_at = tonumber(self:get("last_sync_at")) }
end

-- A queued write reached the server: highlights keep the server's id (to delete them later).
function Session:writeSent(entry, data)
    local Highlights = require("novarr.highlights")
    if entry.kind == "highlight" then
        local h = type(data) == "table" and type(data.highlight) == "table" and data.highlight or nil
        if h and tonumber(h.id) then
            Highlights.remember(self.store:getHighlightMap(), entry.hkey, h.id, nowMs())
            self.store:saveHighlightMap()
            log(string.format("Novarr: highlight on chapter %d sent (id %d)", entry.chapter_id, h.id))
        end
    elseif entry.kind == "highlight_delete" then
        if Highlights.forget(self.store:getHighlightMap(), entry.hkey, entry.id) > 0 then
            self.store:saveHighlightMap()
        end
        log(string.format("Novarr: highlight %d deleted on the server", entry.id))
    end
end

--- Reading time and the part with Wi-Fi on, per day (wifitime.lua; store: wifitime.json).
function Session:wifiTime()
    return self.store:getWifiTime()
end

-- ---------------------------------------------------------------- highlights

local function md5(text) return require("ffi/sha2").md5(text) end
Session.hash = md5

--[[--
Queues a highlight for the server (POST /highlights, idempotent on chapter
+ excerpt): sent when connected, retried, kept over restarts. The excerpt
and note are trimmed and cut to the server's limits. note_cleared: the
note was removed (sent as null). Returns the key, or nil when there is
nothing to send.
]]
function Session:queueHighlight(novel_id, chapter_id, excerpt, note, note_cleared)
    local Highlights = require("novarr.highlights")
    excerpt = Highlights.clean(excerpt, Highlights.MAX_EXCERPT)
    if not excerpt or type(chapter_id) ~= "number" then return nil end
    note = Highlights.clean(note, Highlights.MAX_NOTE)
    local hkey = Highlights.key(chapter_id, excerpt, md5)
    self.queue:enqueue({ kind = "highlight", chapter_id = chapter_id, novel_id = novel_id, hkey = hkey,
        excerpt = excerpt, note = note, note_cleared = (not note and note_cleared) and true or nil })
    log(string.format("Novarr: highlight on chapter %d queued (%d characters%s)", chapter_id,
        Highlights.ulen(excerpt), note and ", with a note" or ""))
    self:flushSoon()
    return hkey
end

--[[--
A highlight deleted in KOReader: one not sent yet just leaves the queue;
one whose server id is known is deleted there (DELETE /highlights/{id},
queued). An unknown id: nothing is sent. Returns "deleted" | "dropped" | "unknown".
]]
function Session:unqueueHighlight(chapter_id, excerpt)
    local Highlights = require("novarr.highlights")
    excerpt = Highlights.clean(excerpt, Highlights.MAX_EXCERPT)
    if not excerpt or type(chapter_id) ~= "number" then return "unknown" end
    local hkey = Highlights.key(chapter_id, excerpt, md5)
    local dropped = self.queue:dropHighlight(hkey)
    local id = Highlights.lookup(self.store:getHighlightMap(), hkey)
    if id then
        self.queue:enqueue({ kind = "highlight_delete", id = id, hkey = hkey })
        log(string.format("Novarr: highlight %d on chapter %d: delete queued", id, chapter_id))
        self:flushSoon()
        return "deleted"
    end
    log(string.format("Novarr: highlight on chapter %d deleted: %s", chapter_id,
        dropped and "it had not been sent yet" or "its server id is unknown, nothing sent"))
    return dropped and "dropped" or "unknown"
end

--- Deletes a highlight by its server id (the Highlights list): queued, sent at once when connected.
function Session:deleteHighlightId(id)
    self.queue:enqueue({ kind = "highlight_delete", id = id })
    self:flushSoon(0.2)
end

--- Sends pending writes in a few seconds when already connected (never prompts).
function Session:flushSoon(delay)
    if not self:isConnected() or self:needsLink() then return end
    if not self.flush_soon_task then
        self.flush_soon_task = function()
            Session.guard(function()
                local res = self:quietFlush(50)
                if res and (res.sent or 0) + (res.dropped or 0) > 0 then
                    local W = package.loaded["novarr.wifi"]
                    if type(W) == "table" then W.progress() end
                end
            end)
        end
    end
    UIManager:unschedule(self.flush_soon_task)
    UIManager:scheduleIn(delay or 3, self.flush_soon_task)
end

--- "Sync now": user action with Wi-Fi prompt and feedback.
function Session:syncNow(done)
    local UI = require("novarr.ui_common")
    if not self:serverUrl() then return end
    if self:needsLink() then
        -- Nothing can be sent before this Kindle is linked: offer that instead.
        require("novarr.ui_auth").offerLink(self, done)
        return
    end
    local function run()
        local closer = UI.loading(_("Syncing with Novarr…"))
        -- The user tapped Sync: is the home address back?
        self.address:reprobe("Sync", { force = true })
        local r = self:flushQueue()
        -- Pull a fresh library so the screens show the server's view.
        self:fetchLibrary()
        closer()
        if r.remaining > 0 then
            UI.info(T(_("Couldn't send everything: %1 change(s) are still waiting.\n\n%2"), r.remaining, self:errorText(r.error)))
        elseif (r.sent or 0) + (r.dropped or 0) > 0 then
            UI.notify(T(_("Synced %1 change(s) with Novarr."), r.sent + r.dropped))
        else
            UI.notify(_("Everything is in sync."))
        end
        self:refreshScreens()
        if done then done() end
    end
    self:whenConnected(run, function()
        UI.info(_("No network connection. Your changes are kept and will be sent later."))
    end)
end

--- Quiet background flush: only when already connected, never prompts.
function Session:quietFlush(max_entries)
    if self.queue:count() == 0 or not self:serverUrl() or not self:isConnected() then return end
    -- Not linked: background syncs stop until it is (the queue is kept).
    if self:needsLink() then return end
    local r = self:flushQueue(max_entries or 50)
    log(string.format("Novarr: background sync sent %d, dropped %d, %d left", r.sent or 0, r.dropped or 0, r.remaining or 0))
    return r
end

-- ---------------------------------------------------------------- screens

function Session:pushMenu(menu, screen)
    table.insert(self.menus, { menu = menu, screen = screen })
end

function Session:menuClosed(menu)
    for i = #self.menus, 1, -1 do
        if self.menus[i].menu == menu then
            table.remove(self.menus, i)
        end
    end
end

function Session:topMenu()
    local top = self.menus[#self.menus]
    return top and top.menu, top and top.screen
end

function Session:closeAllMenus()
    while #self.menus > 0 do
        local entry = table.remove(self.menus)
        UIManager:close(entry.menu)
    end
end

--- Rebuilds every open screen from cached data (after local changes).
function Session:refreshScreens()
    for _i, entry in ipairs(self.menus) do
        if entry.screen and entry.screen.rebuild then
            Session.guard(entry.screen.rebuild, entry.screen)
        end
    end
end

function Session:showHome()
    if not self:serverUrl() then
        require("novarr.ui_settings").askServer(self, function()
            require("novarr.ui_home").show(self)
        end)
        return
    end
    -- One Home at a time: reuse an open one.
    for _i, entry in ipairs(self.menus) do
        if entry.screen and entry.screen.kind == "home" then
            while #self.menus > 0 and self.menus[#self.menus].menu ~= entry.menu do
                UIManager:close(table.remove(self.menus).menu)
            end
            entry.screen:refresh()
            return
        end
    end
    require("novarr.ui_home").show(self)
end

function Session:showChapters(novel_id)
    require("novarr.ui_chapters").show(self, novel_id)
end

function Session:showNovel(novel_id)
    require("novarr.ui_novel").show(self, novel_id)
end

-- ---------------------------------------------------------------- chapters

--[[--
Fetches a chapter from the server and stores it. Returns path or nil plus
a reason ("not_downloaded" or an API error table). opts.no_reprobe: the
user is waiting for this chapter to open, so a pending re-probe of the home
address runs afterwards, not in front of it (address.lua).
]]
function Session:downloadChapter(chapter_id, opts)
    local data, e = self.api:chapter(chapter_id, { no_reprobe = opts and opts.no_reprobe or nil })
    if not data then return nil, e end
    local ch = data.chapter
    if type(ch) ~= "table" or not ch.id or not ch.novel_id then
        return nil, Api.err("server", nil, "bad chapter")
    end
    if not ch.downloaded then
        return nil, "not_downloaded", ch
    end
    if type(ch.paragraphs) ~= "table" or #ch.paragraphs == 0 then
        self.empty_chapters[ch.id] = true
        return nil, "empty", ch
    end
    local html = ChapterDoc.build(ch, "en")
    local path, werr = self.store:saveChapter(ch, html)
    if not path then
        logger.warn("Novarr: cannot save chapter", chapter_id, werr)
        return nil, Api.err("storage", nil, werr)
    end
    -- Remember the server's position/read flag with the stored copy.
    local entry = self.store:chapterEntry(ch.novel_id, ch.id)
    if entry then
        entry.read = ch.read
        entry.read_progress = ch.read_progress
        self.store:saveIndex(ch.novel_id)
    end
    return path, nil, ch
end

-- ---------------------------------------------------------------- reading style

-- KOReader keeps font, size, margins, line spacing... per document. Novarr
-- chapters are separate documents, so without help a change made in one
-- chapter would be gone in the next. These settings are carried over.
local STYLE_KEYS = {
    font_face = true, hyph_alg = true, style_tweaks = true, embedded_css = true,
    embedded_fonts = true, smooth_scaling = true, floating_punctuation = true,
    hyph_trust_soft_hyphens = true, hyph_soft_hyphens_only = true,
    hyph_force_algorithmic = true, nightmode_images = true,
}

local function isStyleKey(k)
    return type(k) == "string" and (STYLE_KEYS[k] or k:sub(1, 5) == "copt_")
end

--- Remembers the look of the Novarr chapter open in the reader, if any.
function Session:captureStyle()
    if self:get("keep_style") == false then return end
    local ReaderUI = require("apps/reader/readerui")
    local inst = ReaderUI.instance
    local file = inst and inst.document and inst.document.file
    if not file or not inst.doc_settings then return end
    if not self:isChapterPath(file) then return end
    -- Have the reader modules write their current values first.
    if inst.saveSettings then pcall(inst.saveSettings, inst) end
    local data = inst.doc_settings.data
    if type(data) ~= "table" then return end
    -- The alt status bar Novarr switched on for "Reading info position: top"
    -- (reading.lua) is not the user's choice: not carried to other chapters.
    local forced_top = data.novarr_top_bar == true
    local style, n = {}, 0
    for k, v in pairs(data) do
        if isStyleKey(k) and not (forced_top and k == "copt_status_line") then
            style[k] = v
            n = n + 1
        end
    end
    if forced_top then
        -- The user's own value from before stays remembered.
        local old = self:get("reading_style")
        if type(old) == "table" then style.copt_status_line = old.copt_status_line end
    end
    if n > 0 then self:set("reading_style", style) end
end

--- Gives a chapter that KOReader has not opened yet the remembered look.
function Session:applyStyle(path)
    if self:get("keep_style") == false then return end
    local style = self:get("reading_style")
    if type(style) ~= "table" or not next(style) then return end
    local DocSettings = require("docsettings")
    if DocSettings:hasSidecarFile(path) then return end
    local ds = DocSettings:open(path)
    for k, v in pairs(style) do
        ds:saveSetting(k, v)
    end
    ds:flush()
end

--[[--
Opens a document in the reader. opts.seamless: the chapter is already on
the device, so switch without KOReader's "Opening file" box and with a
partial refresh (ReaderUI's own `seamless` option; older versions ignore
the extra argument). opts.at_end: show its last page (paging back).
]]
-- Two quick page turns at the end of a chapter ask for the next chapter
-- twice; a second document switch while the first is still under way
-- confuses KOReader ("ReaderUI instance mismatch"). The first one wins.
function Session:openBusy()
    return self.opening_path ~= nil and self.opening_at ~= nil and nowMs() - self.opening_at < 5000
end

function Session:openFile(path, opts)
    opts = opts or {}
    local now = nowMs()
    if self:openBusy() then
        log("Novarr: already opening a chapter, ignoring a second request")
        return
    end
    self.opening_at = now
    self.open_t0 = now
    self.open_at_end = opts.at_end and path or nil
    -- Joined chapters: which chapter to show in the group file (see Reading.onReady).
    self.group_target = opts.group_target
    self:captureStyle()
    self:applyStyle(path)
    -- Opened from Novarr's screens with no Novarr chapter open yet: remember
    -- the screen on top, where closing the book comes back to (Model.returnScreens).
    -- Next/previous chapter and the like (a chapter is open) keep the first one.
    if not self.reader then
        local _menu, screen = self:topMenu()
        self.origin_kind = screen and screen.kind or nil
    end
    self:closeAllMenus()
    -- Never let the clean-up of the closing chapter remove the one being opened.
    self.opening_path = path
    local ReaderUI = require("apps/reader/readerui")
    log(string.format("Novarr: opening %s%s", path:match("[^/]*$"), opts.seamless and " (seamless)" or ""))
    self.switch_count = (self.switch_count or 0) + 1
    if ReaderUI.instance and ReaderUI.instance.document then
        self.switching = true
        self.leaving_path = ReaderUI.instance.document.file
        ReaderUI.instance:switchDocument(path, opts.seamless and true or nil)
    else
        ReaderUI:showReader(path, nil, opts.seamless and true or nil)
    end
end

--[[--
Opens a chapter: the stored copy right away, else fetched (with the Wi-Fi
prompt), else a clear message. opts.from_reader: called from the reader.
opts.check_position: opened from Novarr's screens (a novel, Continue, the
chapter list): asks first whether another device read further, or once
connected (position.lua). Next/previous chapter while reading never asks.
]]
function Session:openChapter(novel_id, chapter_id, opts)
    local UI = require("novarr.ui_common")
    opts = opts or {}
    if opts.check_position and novel_id then
        -- A fresh open from the screens: whatever was due before is replaced.
        Position.clearDue(self)
        -- The question is showing: its answer opens the reader (once).
        if Position.beforeOpen(novel_id, chapter_id, opts) then return true end
    end
    local path = novel_id and self.store:chapterPath(novel_id, chapter_id)
    if path and lfs.attributes(path, "mode") == "file" then
        self.opened_from_novarr = true
        if not self:openJoined(novel_id, chapter_id, opts) then
            self:openFile(path, { seamless = opts.from_reader, at_end = opts.at_end })
        end
        return true
    end
    local cl = novel_id and self:chapterList(novel_id)
    local row = cl and cl.by_id[chapter_id]
    if row and row.downloaded == false then
        UI.info(self:notDownloadedText(row))
        return false
    end
    local function fetch()
        local closer = UI.loading(_("Fetching chapter…"))
        local fetched, why, ch = self:downloadChapter(chapter_id, { no_reprobe = true })
        closer()
        if fetched then
            self.opened_from_novarr = true
            if not self:openJoined(novel_id, chapter_id, opts) then
                self:openFile(fetched, { seamless = opts.from_reader, at_end = opts.at_end })
            end
        elseif why == "not_downloaded" then
            UI.info(self:notDownloadedText(row or ch))
        elseif why == "empty" then
            UI.info(_("This chapter has no text on the Novarr server (it may have failed to download from its source)."))
        elseif type(why) == "table" and why.kind == "offline" then
            UI.info(_("This chapter isn't saved on this device, and the Novarr server can't be reached right now."))
        else
            UI.info(self:errorText(why))
        end
    end
    self:whenConnected(fetch, function()
        UI.info(_("This chapter isn't saved on this device, and there's no network connection."))
    end)
    return false
end

-- ---------------------------------------------------------------- joined chapters

--- Whether chapters open joined in groups (the setting; read at each open).
function Session:joinedOn()
    return self:get("join_chapters") ~= false
end

function Session:groupSize()
    return Groups.size(self:get("group_size"))
end

--[[--
The group file for a stored chapter, built or refreshed when needed (see
Groups.prepare). Returns g or nil, reason.
]]
function Session:prepareGroup(novel_id, chapter_id)
    local cl = self:chapterList(novel_id)
    if not cl then return nil, "no_list" end
    local summary = self:novelSummary(novel_id)
    local t0 = nowMs()
    local g, why = Groups.prepare(self.store, {
        novel_id = novel_id, chapter_id = chapter_id, list = cl.list, n = self:groupSize(),
        novel_name = summary and summary.name or self.store:getIndex(novel_id).novel_name,
        lang = "en", bad = self.bad_bodies, now = nowMs,
        can_write = function(bytes)
            local free = self:freeBytes()
            return not free or free - bytes >= Session.MIN_FREE_BYTES
        end,
    })
    if g then
        log(string.format("Novarr: group file %s (%d chapters, %s) ready in %d ms",
            g.path:match("[^/]*$"), #g.ids, g.decision, nowMs() - t0))
    end
    return g, why
end

--[[--
Opens a stored chapter inside its group file (joined chapters on). Returns
false when the chapter has to be opened on its own instead (setting off,
its place in the reading order unknown, no space for the group file...).
]]
function Session:openJoined(novel_id, chapter_id, opts)
    opts = opts or {}
    local cl = self:chapterList(novel_id)
    local mode, reason = Groups.openMode(self:get("join_chapters"), cl and cl.list, chapter_id)
    if mode ~= "joined" then
        if reason ~= "setting off" then
            log("Novarr: opening chapter " .. tostring(chapter_id) .. " on its own (" .. tostring(reason) .. ")")
        end
        return false
    end
    if self:openBusy() then
        log("Novarr: already opening a chapter, ignoring a second request")
        return true
    end
    -- Already in the group file on screen: just go there.
    local reader = self.reader
    local r = reader and reader.reading
    if r and r.group and r.novel_id == novel_id and r.group.index[chapter_id] then
        self:closeAllMenus()
        local forced = Position.takeForced(self, novel_id, chapter_id)
        if forced then
            require("novarr.reading").goToProgress(reader, chapter_id, forced)
        else
            require("novarr.reading").jumpTo(reader, chapter_id, { at_end = opts.at_end, resume = true })
        end
        -- No document opens, so no onReady: a latest-position check due for this novel runs from here.
        Position.chapterReady(novel_id, false)
        return true
    end
    local g, why = self:prepareGroup(novel_id, chapter_id)
    if not g then
        log("Novarr: opening chapter " .. tostring(chapter_id) .. " on its own (" .. tostring(why) .. ")")
        return false
    end
    self:openFile(g.path, { seamless = opts.from_reader, group_target = {
        path = g.path, chapter_id = chapter_id, at_end = opts.at_end, decision = g.decision,
    } })
    return true
end

--- Chapter ids (set) of the group file open in the reader for this novel, or nil.
function Session:openGroupSet(novel_id)
    local r = self.reader and self.reader.reading
    if r and r.group and (novel_id == nil or r.novel_id == novel_id) then return r.group.index end
    return nil
end

--- Removes a group file (not the open one) with its KOReader data. Returns true if removed.
function Session:removeGroupFile(novel_id, block, n)
    local path = self.store:groupPath(novel_id, block, n)
    if path == self:currentPath() or path == self.opening_path then return false end
    purgeSidecar(path)
    self.store:removeGroup(novel_id, block, n)
    return true
end

--- Removes the (closed) group files of a novel that hold a chapter (after that chapter was removed).
function Session:removeGroupsWith(novel_id, chapter_id)
    for _i, gf in ipairs(self.store:groupFiles(novel_id)) do
        local m = self.store:readGroupManifest(novel_id, gf.block, gf.n)
        local holds = not m or type(m.ids) ~= "table"
        for _j, id in ipairs(m and type(m.ids) == "table" and m.ids or {}) do
            if id == chapter_id then holds = true end
        end
        if holds then self:removeGroupFile(novel_id, gf.block, gf.n) end
    end
end

function Session:notDownloadedText(row)
    local text = _("Novarr hasn't downloaded this chapter from its source yet, so there is nothing to read.")
    if row and row.reason and row.reason ~= "" then
        text = text .. "\n\n" .. row.reason
    end
    return text
end

-- ---------------------------------------------------------------- removal

function purgeSidecar(path)
    pcall(function()
        local DocSettings = require("docsettings")
        DocSettings:open(path):purge()
    end)
    pcall(function()
        require("readhistory"):removeItemByPath(path)
    end)
end
Session.purgeSidecar = purgeSidecar

--- Whether a path is one of Novarr's chapter files.
function Session:isChapterPath(path)
    if type(path) ~= "string" then return false end
    if ChapterDoc.isNovarrPath(path, self.root) then return true end
    if not self.real_root then
        local ok, real = pcall(function() return require("ffi/util").realpath(self.root) end)
        self.real_root = ok and real or self.root
    end
    return ChapterDoc.isNovarrPath(path, self.real_root)
end

--[[--
Keeps KOReader's History free of Novarr chapters: removes every chapter
entry except `keep` (the chapter being read, so History and "open last
file" can still resume it). KOReader's `lastfile` setting is not touched.
]]
function Session:pruneHistory(keep)
    local ok, ReadHistory = pcall(require, "readhistory")
    if not ok or type(ReadHistory.hist) ~= "table" then return 0 end
    -- History stores real paths.
    local okr, realpath = pcall(function() return require("ffi/util").realpath end)
    if keep and okr and realpath then keep = realpath(keep) or keep end
    local paths = {}
    for _i, item in ipairs(ReadHistory.hist) do
        if type(item) == "table" and item.file then table.insert(paths, item.file) end
    end
    local prune = Model.historyToPrune(paths, keep, function(p) return self:isChapterPath(p) end)
    if #prune == 0 then return 0 end
    local drop = {}
    for _i, p in ipairs(prune) do drop[p] = true end
    -- Remove without flushing each time, then write the history once.
    for i = #ReadHistory.hist, 1, -1 do
        local item = ReadHistory.hist[i]
        if item and drop[item.file] then
            if not pcall(ReadHistory.removeItem, ReadHistory, item, i, true) then
                table.remove(ReadHistory.hist, i)
            end
        end
    end
    if ReadHistory._flush then pcall(ReadHistory._flush, ReadHistory) end
    log("Novarr: removed " .. #prune .. " chapter(s) from KOReader's history")
    return #prune
end

function Session:currentPath()
    local ReaderUI = require("apps/reader/readerui")
    local inst = ReaderUI.instance
    return inst and inst.document and inst.document.file or nil
end

--- Removes one stored chapter with its KOReader data. Returns true if removed.
function Session:removeChapter(novel_id, chapter_id)
    local path = self.store:chapterPath(novel_id, chapter_id)
    if not path or path == self:currentPath() or path == self.opening_path then return false end
    -- A chapter of the group file open in the reader is part of the open document.
    local open = self:openGroupSet(novel_id)
    if open and open[chapter_id] then return false end
    purgeSidecar(path)
    self.store:removeChapter(novel_id, chapter_id)
    self:removeGroupsWith(novel_id, chapter_id)
    return true
end

--- Removes all stored chapters of a novel (except the one open in the reader).
function Session:removeNovelDownloads(novel_id)
    local current = self:currentPath()
    -- The group file open in the reader and the chapters in it are kept.
    local open = self:openGroupSet(novel_id) or {}
    local kept = false
    local dir = self.store:chaptersDir(novel_id)
    for _i, entry in pairs(self.store:getIndex(novel_id).chapters) do
        local path = dir .. "/" .. entry.file
        if path == current or open[entry.id] then
            kept = true
        else
            purgeSidecar(path)
        end
    end
    for _i, gf in ipairs(self.store:groupFiles(novel_id)) do
        if gf.path == current then
            kept = true
        else
            purgeSidecar(gf.path)
        end
    end
    if kept then
        for _i, entry in pairs(self.store:getIndex(novel_id).chapters) do
            local p = dir .. "/" .. entry.file
            if p ~= current and not open[entry.id] then self.store:removeChapter(novel_id, entry.id) end
        end
        for _i, gf in ipairs(self.store:groupFiles(novel_id)) do
            if gf.path ~= current then self.store:removeGroup(novel_id, gf.block, gf.n) end
        end
    else
        self.store:removeNovel(novel_id)
    end
    return kept
end

--[[--
Remove-after-reading rule: delete stored chapters marked read except the 2
most recent (in reading order) and except `current_id`. Unread and part-read
chapters are never touched.
]]
function Session:cleanupRead(novel_id, current_id)
    if not self:joinedOn() then
        -- Joined chapters switched off: their files are of no use any more.
        for _i, gf in ipairs(self.store:groupFiles(novel_id)) do
            self:removeGroupFile(novel_id, gf.block, gf.n)
        end
    end
    if not self:get("remove_after_reading") then return 0 end
    local index = self.store:getIndex(novel_id)
    local cl = self:chapterList(novel_id)
    local stored = {}
    for _i, entry in pairs(index.chapters) do
        local order = cl and cl.pos[entry.id]
        if not order then
            order = (tonumber(entry.book) or 0) * 1000000 + (tonumber(entry.chapter) or 0)
        end
        table.insert(stored, { id = entry.id, order = order })
    end
    local current_order
    for _i, st in ipairs(stored) do
        if st.id == current_id then current_order = st.order end
    end
    local ids = Model.removalCandidates(stored, function(id) return self:chapterState(novel_id, id) end,
        self:get("keep_read"), current_id, current_order)
    local n = 0
    for _i, id in ipairs(ids) do
        if self:removeChapter(novel_id, id) then n = n + 1 end
    end
    if n > 0 then log("Novarr: removed " .. n .. " read chapter(s) of novel " .. novel_id) end
    return n
end

--[[--
Clean-up while a group file of `block` is open (joined chapters):
  * group files other than the open block's and its two neighbours' go
    (they are rebuilt when needed);
  * with remove-after-reading on: read chapters of earlier blocks are
    deleted except the `keep_read` (2) latest of them, so paging back still
    works. The open block, later blocks, unread and part-read chapters, and
    chapters whose place in the reading order is unknown are never touched.
]]
function Session:cleanupJoined(novel_id, block, n)
    local groups_removed = 0
    for _i, gf in ipairs(self.store:groupFiles(novel_id)) do
        if gf.n ~= n or not Groups.keepGroupFile(gf.block, block) then
            if self:removeGroupFile(novel_id, gf.block, gf.n) then groups_removed = groups_removed + 1 end
        end
    end
    if groups_removed > 0 then log("Novarr: removed " .. groups_removed .. " group file(s) of novel " .. novel_id) end
    if not self:get("remove_after_reading") then return 0 end
    local cl = self:chapterList(novel_id)
    if not cl then return 0 end
    local _ids, ord = Groups.ordinals(cl.list)
    local stored = {}
    for _i, entry in pairs(self.store:getIndex(novel_id).chapters) do
        local o = ord[entry.id]
        table.insert(stored, { id = entry.id, ord = o, block = o and Groups.block(o, n) or nil })
    end
    local ids = Groups.removalCandidates(stored, function(id) return (self:chapterState(novel_id, id)) end,
        self:get("keep_read"), block)
    local removed = 0
    for _i, id in ipairs(ids) do
        if self:removeChapter(novel_id, id) then removed = removed + 1 end
    end
    if removed > 0 then log("Novarr: removed " .. removed .. " read chapter(s) of novel " .. novel_id) end
    return removed
end

-- ---------------------------------------------------------------- downloads

--- Free bytes on the storage volume, or nil when unknown.
function Session:freeBytes()
    local ok, usage = pcall(function() return require("util").diskUsage(self.root) end)
    if ok and type(usage) == "table" and usage.available then
        return usage.available
    end
    return nil
end

Session.MIN_FREE_BYTES = 20 * 1024 * 1024

--[[--
Downloads chapters one per UI tick with a cancellable progress dialog.
ids: chapter ids in order. done(summary) is called at the end.
]]
function Session:downloadMany(novel_id, ids, done)
    local UI = require("novarr.ui_common")
    if #ids == 0 then
        UI.info(_("Everything you chose is already on this device."))
        return
    end
    local job = { total = #ids, done = 0, skipped = 0, failed = 0, cancelled = false, i = 0 }
    self.download_job = job
    local dialog = require("novarr.ui_progress"):new{
        title = _("Downloading chapters"),
        text = T(_("0 of %1 saved"), job.total),
        cancel_callback = function() job.cancelled = true end,
    }
    UIManager:show(dialog)
    local function finish(message)
        self.download_job = nil
        UIManager:close(dialog)
        if message then UI.info(message) end
        self:refreshScreens()
        if done then done(job) end
    end
    local step
    step = Session.wrap(function()
        if job.cancelled then
            finish(T(_("Download cancelled. %1 chapter(s) saved."), job.done))
            return
        end
        if job.i >= #ids then
            local msg = T(_("Saved %1 chapter(s) for offline reading."), job.done)
            if job.skipped > 0 then
                msg = msg .. "\n" .. T(_("%1 chapter(s) skipped: the server has no text for them yet."), job.skipped)
            end
            if job.failed > 0 then
                msg = msg .. "\n" .. T(_("%1 chapter(s) couldn't be fetched."), job.failed)
            end
            finish(msg)
            return
        end
        local free = self:freeBytes()
        if free and free < Session.MIN_FREE_BYTES then
            finish(T(_("Stopped: less than 20 MB of free space left on this device. %1 chapter(s) saved."), job.done))
            return
        end
        if not self:isConnected() then
            finish(T(_("Stopped: the network connection was lost. %1 chapter(s) saved."), job.done))
            return
        end
        job.i = job.i + 1
        local path, why = self:downloadChapter(ids[job.i])
        if path then
            job.done = job.done + 1
        elseif why == "not_downloaded" or why == "empty" then
            job.skipped = job.skipped + 1
        elseif type(why) == "table" and why.kind == "offline" then
            finish(T(_("Stopped: the Novarr server can't be reached any more. %1 chapter(s) saved."), job.done))
            return
        elseif type(why) == "table" and why.kind == "manga" then
            finish(self:errorText(why))
            return
        elseif type(why) == "table" and why.kind == "storage" then
            finish(T(_("Stopped: couldn't write to this device's storage. %1 chapter(s) saved."), job.done))
            return
        else
            job.failed = job.failed + 1
        end
        dialog:setText(T(_("%1 of %2 saved"), job.done, job.total)
            .. (job.skipped > 0 and ("\n" .. T(_("%1 skipped (no text on the server yet)"), job.skipped)) or ""))
        UIManager:scheduleIn(0.05, step)
    end)
    self:whenConnected(function()
        UIManager:scheduleIn(0.2, step)
    end, function()
        finish(_("No network connection: chapters can't be downloaded now."))
    end)
end

-- ---------------------------------------------------------------- server

--[[--
Switching to a different server: cached lists, local marks, the queue and
stored chapters belong to the old one (ids differ), so they are cleared.
The same server under another address (the second address, or a new one
whose meta has the same instance id: opts.same_server, see
Session:isSameServer) keeps everything, and the account link moves to the
new address.
]]
function Session:setServer(url, opts)
    opts = opts or {}
    local old = self:serverUrl()
    local away = self:get("server_url_away")
    if away and away == url then
        -- The second address becomes the home one: the same server, nothing is cleared.
        self:unset("server_url_away")
        -- The account link is the same server's: it follows the home address.
        Auth.moveServer(self.settings, old, url)
    elseif old and old ~= url and opts.same_server then
        -- A new address of the same install (same instance id): nothing is cleared.
        Auth.moveServer(self.settings, old, url)
        log("Novarr: " .. tostring(url) .. " is the same server as " .. tostring(old) .. " (same instance): everything kept")
    elseif old and old ~= url then
        -- A different server: its account link (and whether it needs one) is not this one's.
        Auth.clear(self.settings)
        self.auth_told = false
        self.store:clearCache()
        self.store:clearOverlays()
        self.store:clearStats()
        self.store:clearHighlightMap()
        self.queue:clear()
        self:unset("last_sync_at")
        self:unset("position_declined")
        self:unset("server_instance")
        self:unset("server_books")
        self:unset("auth_me_at")
        for _i, novel_id in ipairs(self.store:novelsWithDownloads()) do
            self:removeNovelDownloads(novel_id)
        end
        self.chapter_lists = {}
    end
    self:set("server_url", url)
    self:unset("server_active")
    self:rebuildApi()
end

--[[--
Sets (or with nil removes) the second address of the same server. Nothing
stored is cleared: cache, queue and chapters stay as they are.
]]
function Session:setAwayServer(url)
    if url and url ~= "" and url ~= self:serverUrl() then
        self:set("server_url_away", url)
    else
        self:unset("server_url_away")
    end
    if self:get("server_active") and self:get("server_active") ~= self:serverUrl()
            and self:get("server_active") ~= self:get("server_url_away") then
        self:unset("server_active")
    end
    self:rebuildApi()
end

--[[--
Fetches a URL the server gave (a cover, the EPUB file): moved to the address
in use first; when it gets no answer and it is on one of the configured
addresses, retried once on the other one if that answers. make_sink() is
called for each attempt (a fresh sink: the file is opened again). opts:
headers, block_timeout, total_timeout. Returns res {status, headers} or nil, err.
]]
function Session:fetchUrl(url, opts, make_sink)
    if Api.isOffline() then return nil, "simulated offline" end
    local HttpClient = require("novarr.http_client")
    local function attempt(u)
        return HttpClient.request({
            url = u, method = "GET", headers = opts.headers,
            user_agent = "NovarrKOReader/" .. Api.VERSION,
            block_timeout = opts.block_timeout, total_timeout = opts.total_timeout,
            make_sink = make_sink, log = log,
        })
    end
    local addr = self.address
    local res, err = attempt(addr:rewrite(url))
    if not res and addr:owns(url) then
        local other = addr:failed(addr.active, err)
        if other then
            log("Novarr: retrying a download on " .. other)
            res, err = attempt(addr:rewrite(url))
        end
    end
    return res, err
end

--[[--
Whether a new address (whose /api/v1/meta answered `meta`) is the server
already configured: the second address, or the same instance id as the one
remembered (both present: an older server gives none).
]]
function Session:isSameServer(url, meta)
    if url == self:serverUrl() or url == self:get("server_url_away") then return true end
    return Address.sameInstance(self:get("server_instance"), type(meta) == "table" and meta.instance or nil)
end

--[[--
Before switching to a different server: one quick try to send the queued
changes to the old one, if it answers within the probe timeout (4 s), so
reading is not lost. Returns the flush summary, or nil when nothing was
tried (empty queue, not linked, not answering).
]]
function Session:flushBeforeSwitch()
    if self.queue:count() == 0 or not self:serverUrl() or self:needsLink() then return nil end
    if not self:probeAddress(self:activeUrl()) then
        log("Novarr: the old server isn't answering; its queued changes can't be sent before switching")
        return nil
    end
    local r = self:flushQueue()
    log(string.format("Novarr: before switching server: sent %d, dropped %d, %d left", r.sent or 0, r.dropped or 0, r.remaining or 0))
    return r
end

--- Whether switching to url would discard local data.
function Session:hasLocalData()
    return self.queue:count() > 0 or #self.store:novelsWithDownloads() > 0
end

-- ---------------------------------------------------------------- covers

local function fetchCover(session, url, path)
    if Api.isOffline() then return nil, "offline" end
    local socketutil = require("socketutil")
    local fh
    local function open()
        if fh then pcall(function() fh:close() end) end
        fh = io.open(path, "wb")
        return fh and socketutil.file_sink(fh) or nil
    end
    local probe = io.open(path, "wb")
    if not probe then return nil, "cannot write" end
    probe:close()
    local res, err = session:fetchUrl(url, {
        headers = { ["User-Agent"] = "NovarrKOReader/" .. Api.VERSION, ["Accept"] = "image/jpeg, image/png, image/webp, image/gif" },
        block_timeout = 5, total_timeout = 15,
    }, open)
    if fh then pcall(function() fh:close() end) end
    if not res then return nil, tostring(err) end
    if res.status ~= 200 or type(res.headers) ~= "table" then return nil, tostring(res.status) end
    return res.headers["content-type"] or "image/jpeg"
end

--- The cover cache (created on first use).
function Session:covers()
    if not self.cover_cache then
        local Covers = require("novarr.covers")
        self.cover_cache = Covers.new({
            dir = self.root .. "/covers",
            lfs = lfs,
            hash = function(s) return require("ffi/sha2").md5(s) end,
            -- The same cover whichever server address the list came from.
            key = function(url) return self.address:canonical(url) end,
            fetch = function(url, path) return fetchCover(self, url, path) end,
            log = log,
        })
    end
    return self.cover_cache
end

Session.DISCOVER_COVERS_MAX_BYTES = 8 * 1024 * 1024

--[[--
Covers of Discover results (on the sources' own image hosts, not Novarr):
their own folder, capped at 8 MB (the oldest go first), so browsing never
fills the device. Fetched as they are (no server address rewriting: these
URLs are on neither Novarr address), through the same HTTP client (https
through KOReader's proxy included).
]]
function Session:discoverCovers()
    if not self.discover_cover_cache then
        local Covers = require("novarr.covers")
        self.discover_cover_cache = Covers.new({
            dir = self.root .. "/discover-covers",
            lfs = lfs,
            hash = function(s) return require("ffi/sha2").md5(s) end,
            fetch = function(url, path) return fetchCover(self, url, path) end,
            max_bytes = Session.DISCOVER_COVERS_MAX_BYTES,
            log = log,
        })
    end
    return self.discover_cover_cache
end

--- Whether KOReader's sleep screen is set to the book cover.
function Session:sleepCoverOn()
    return G_reader_settings:readSetting("screensaver_type") == "cover"
end

--[[--
Switches KOReader's sleep screen to the book cover (and hides the
"Sleeping" message over it), or puts back what was set before.
]]
function Session:setSleepCover(on)
    if on then
        if not self:sleepCoverOn() then
            self:set("sleep_prev_type", G_reader_settings:readSetting("screensaver_type") or "disable")
            self:set("sleep_prev_message", G_reader_settings:isTrue("screensaver_show_message"))
            -- Remember the stretch settings exactly (a missing limit is "full stretch").
            self:set("sleep_prev_stretch", G_reader_settings:isTrue("screensaver_stretch_images"))
            self:set("sleep_prev_stretch_limit", G_reader_settings:readSetting("screensaver_stretch_limit_percentage") or false)
        end
        G_reader_settings:saveSetting("screensaver_type", "cover")
        G_reader_settings:makeFalse("screensaver_show_message")
        -- Fill the screen like a Kindle: full stretch, no bars.
        G_reader_settings:makeTrue("screensaver_stretch_images")
        G_reader_settings:delSetting("screensaver_stretch_limit_percentage")
    else
        G_reader_settings:saveSetting("screensaver_type", self:get("sleep_prev_type") or "disable")
        if self:get("sleep_prev_message") ~= false then
            G_reader_settings:makeTrue("screensaver_show_message")
        end
        if self:get("sleep_prev_stretch") then
            G_reader_settings:makeTrue("screensaver_stretch_images")
        else
            G_reader_settings:makeFalse("screensaver_stretch_images")
        end
        local limit = self:get("sleep_prev_stretch_limit")
        if type(limit) == "number" then
            G_reader_settings:saveSetting("screensaver_stretch_limit_percentage", limit)
        else
            G_reader_settings:delSetting("screensaver_stretch_limit_percentage")
        end
    end
    if G_reader_settings.flush then pcall(G_reader_settings.flush, G_reader_settings) end
end

function Session:showCovers()
    return self:get("show_covers") ~= false
end

-- ---------------------------------------------------------------- debug

--- Plugin state for the autopilot "dump" step.
function Session:dumpState()
    local menus = {}
    for _i, entry in ipairs(self.menus) do
        local m = entry.menu
        local items = {}
        local indexes = {}
        if m.visible then
            indexes = m.visible
        elseif m.pages then
            indexes = m.pages[m.page] or {}
        else
            local perpage = m.perpage or 14
            local first = ((m.page or 1) - 1) * perpage + 1
            for i = first, math.min(#(m.item_table or {}), first + perpage - 1) do table.insert(indexes, i) end
        end
        for _i, i in ipairs(indexes) do
            local it = m.item_table[i]
            local c = it.cover or it.hero
            local cache = m.covers or self:covers()
            table.insert(items, { text = it.text, mandatory = it.mandatory, dim = it.dim or nil,
                cover = c and { url = c.url, cached = cache:cached(c.url) ~= nil, info = c.info } or nil })
        end
        table.insert(menus, {
            kind = entry.screen and entry.screen.kind,
            title = m.title,
            page = m.page,
            page_num = m.page_num,
            item_count = #(m.item_table or {}),
            visible = items,
        })
    end
    local downloads = {}
    for _i, d in ipairs(self.store:downloads()) do
        local ids = {}
        for _i, entry in pairs(self.store:getIndex(d.novel_id).chapters) do table.insert(ids, entry.id) end
        table.sort(ids)
        d.chapter_ids = ids
        local groups = {}
        for _i, gf in ipairs(self.store:groupFiles(d.novel_id)) do
            local m = self.store:readGroupManifest(d.novel_id, gf.block, gf.n)
            table.insert(groups, { file = gf.path:match("[^/]*$"), size = gf.size,
                ids = m and type(m.ids) == "table" and Json.array(m.ids) or nil, title = m and m.title or nil })
        end
        d.groups = Json.array(groups)
        table.insert(downloads, d)
    end
    local overlays = {}
    for _i, novel_id in ipairs(self.store:novelsWithDownloads()) do
        overlays[tostring(novel_id)] = self.store:getOverlay(novel_id)
    end
    -- Also overlays of novels without downloads.
    local odir = self.root .. "/overlay"
    if lfs.attributes(odir, "mode") == "directory" then
        for f in lfs.dir(odir) do
            local id = f:match("^(%d+)%.json$")
            if id then overlays[id] = self.store:getOverlay(tonumber(id)) end
        end
    end
    local reading
    if self.reader and self.reader.reading then
        local r = self.reader.reading
        local okf, footer = pcall(function() return require("novarr.reading").footerText(self.reader) end)
        reading = { novel_id = r.novel_id, chapter_id = r.chapter_id, percent = r.percent, ended = r.ended,
            footer_text = okf and footer or nil, chapters_left = r.left, seconds_per_chapter = r.per_chapter,
            ordinal = r.ordinal, ordinal_of = r.ordinal_of, at_end_open = r.at_end_open,
            page = (function()
                local ReaderUI = require("apps/reader/readerui")
                local inst = ReaderUI.instance
                local ok, p = pcall(function() return inst:getCurrentPage() end)
                local okc, c = pcall(function() return inst.document:getPageCount() end)
                return ok and okc and (tostring(p) .. "/" .. tostring(c)) or nil
            end)(),
            active_seconds = r.timer and r.timer.seconds or nil,
            mode = r.group and "joined" or "single",
            view_mode = self.reader.ui and self.reader.ui.view and self.reader.ui.view.view_mode or nil,
            group = r.group and {
                block = r.group.block, n = r.group.n, ids = Json.array(r.group.ids),
                index = r.group.index[r.chapter_id],
                start_pages = r.layout and Json.array(r.layout.pages) or nil,
                start_ys = r.layout and Json.array(r.layout.ys) or nil,
                position = (function()
                    local ok, p = pcall(function() return require("novarr.reading").where(self.reader) end)
                    return ok and p or nil
                end)(),
            } or nil,
            switches = self.switch_count,
            annotations = (function()
                local out = {}
                local ui = self.reader.ui
                for _i, a in ipairs(ui and ui.annotation and ui.annotation.annotations or {}) do
                    table.insert(out, { text = a.text, note = a.note, drawer = a.drawer })
                end
                return Json.array(out)
            end)(),
            timing_samples = Json.array(self.store:getTiming(r.novel_id)) }
    end
    return {
        time = nowMs(),
        server_url = self:serverUrl(),
        server_url_away = self:get("server_url_away"),
        server_active = self:get("server_active"),
        active_url = self:activeUrl(),
        address_in_use = self.address:which(),
        simulate_offline = Api.simulate_offline,
        settings = {
            prefetch = self:get("prefetch"),
            prefetch_migrated = self.settings:readSetting("prefetch_migrated"),
            prefetch_migrated_100 = self.settings:readSetting("prefetch_migrated_100"),
            prefetch_user_set = self.settings:readSetting("prefetch_user_set"),
            wifi_auto_off = self:get("wifi_auto_off"),
            remove_after_reading = self:get("remove_after_reading"),
            mark_read_at_end = self:get("mark_read_at_end"),
            join_chapters = self:get("join_chapters"),
            group_size = self:get("group_size"),
            auto_update_check = self:get("auto_update_check"),
            auto_update_install = self:get("auto_update_install"),
            update_auto_attempt = self:get("update_auto_attempt"),
            update_available_build = self:get("update_available_build"),
            update_available_version = self:get("update_available_version"),
            update_checked_at = self:get("update_checked_at"),
            position_prompt = self:get("position_prompt"),
            device_name = self.settings:readSetting("device_name"),
        },
        position = Position.dump(self),
        -- Never the token itself.
        auth = (function()
            local u = self:authUser()
            return { required = self:authRequired(), linked = self:authToken() ~= nil, needs_link = self:needsLink(),
                role = self:role(), user = u and u.name or nil, told = self.auth_told or nil }
        end)(),
        plugin = {
            version = Api.VERSION,
            dir = self.plugin_dir,
            installed_update = self.update_installed,
        },
        queue = Json.array(self.queue:list()),
        checks = (function()
            local C = package.loaded["novarr.checks"]
            return type(C) == "table" and C.dump() or nil
        end)(),
        discover = (function()
            for _i, entry in ipairs(self.menus) do
                if entry.screen and entry.screen.kind == "discover" then
                    return require("novarr.ui_discover").dumpOf(entry.screen)
                end
            end
            return nil
        end)(),
        highlights = (function()
            local out = { known_ids = 0 }
            for _k in pairs(self.store:getHighlightMap()) do out.known_ids = out.known_ids + 1 end
            for _i, entry in ipairs(self.menus) do
                if entry.screen and entry.screen.kind == "highlights" then
                    out.screen = require("novarr.ui_highlights").dumpOf(entry.screen)
                end
            end
            return out
        end)(),
        wifitime = (function()
            local ok, d = pcall(function() return require("novarr.wifitime").dump(self:wifiTime(), os.time()) end)
            return ok and d or nil
        end)(),
        discover_search = (function()
            for _i, entry in ipairs(self.menus) do
                if entry.screen and entry.screen.kind == "discover" then
                    return require("novarr.ui_discover").searchDumpOf(entry.screen)
                end
            end
            return nil
        end)(),
        caughtup = (function()
            local CU = package.loaded["novarr.ui_caughtup"]
            local page = type(CU) == "table" and CU.current or nil
            if not page then return nil end
            return { novel_id = page.info.novel_id, status = page.status_text,
                has_next = page.info.has_next and page.info.has_next() or false }
        end)(),
        stats = (function()
            local out = {}
            for _i, entry in ipairs(self.menus) do
                if entry.screen and entry.screen.kind == "novel" then
                    local id = entry.screen.novel_id
                    out[tostring(id)] = self:novelStats(id)
                    out[tostring(id)].events = #self.store:getStats(id)
                end
            end
            return out
        end)(),
        wifi = (function()
            local ok, d = pcall(function() return require("novarr.wifi").dump() end)
            if ok and d then d.events = Json.array(d.events) end
            return ok and d or nil
        end)(),
        reading_prefetch = self.reader and self.reader.reading and {
            pending = self.reader.reading.prefetch and #self.reader.reading.prefetch or 0,
            done = self.reader.reading.prefetch_done, total = self.reader.reading.prefetch_total,
        } or nil,
        downloads = Json.array(downloads),
        overlays = overlays,
        menus = Json.array(menus),
        reading = reading,
        current_document = self:currentPath(),
        mem_log = self.mem_log and Json.array(self.mem_log) or nil,
        history = (function()
            local ok, RH = pcall(require, "readhistory")
            local out = {}
            if ok and type(RH.hist) == "table" then
                for _i, item in ipairs(RH.hist) do table.insert(out, item.file) end
            end
            return Json.array(out)
        end)(),
        lastfile = G_reader_settings:readSetting("lastfile"),
        screensaver = {
            type = G_reader_settings:readSetting("screensaver_type"),
            stretch = G_reader_settings:readSetting("screensaver_stretch_images"),
            stretch_limit = G_reader_settings:readSetting("screensaver_stretch_limit_percentage"),
            show_message = G_reader_settings:readSetting("screensaver_show_message"),
        },
        current_title = (function()
            local ReaderUI = require("apps/reader/readerui")
            local inst = ReaderUI.instance
            return inst and inst.doc_props and inst.doc_props.display_title or nil
        end)(),
        storage_root = self.root,
        -- What is on screen, bottom to top (a dialog's text, else the widget's name).
        windows = (function()
            local out = {}
            for _i, w in ipairs(UIManager._window_stack or {}) do
                local wd = w.widget or {}
                local text = type(wd.text) == "string" and wd.text or nil
                table.insert(out, text or wd.name or "?")
            end
            return Json.array(out)
        end)(),
    }
end

return Session
