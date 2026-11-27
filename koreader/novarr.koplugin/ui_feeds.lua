--[[--
What the server has to tell the reader (1.13.0):

* Notifications (GET notifications, meta "requests"): read while Home
  refreshes online (at start and on Sync). A request that became ready
  shows one small notice, "Ready to read: X", with Open; each item is
  shown once (the feed's cursor is kept, which marks it seen). The last
  ones are listed under More > Notifications.
* New chapters (GET updates, meta "updates"): read at the same time and
  kept on this device, one entry per series, until it is opened. Until the
  feed has anything (its first read only starts it), the library's series
  with new chapters are shown.
* My stats (GET stats): when the server says it has them (meta "stats")
  or answered GET stats once; text only.

Polling never asks for Wi-Fi and uses short timeouts (Api.FEED_*).
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")
local T = require("ffi/util").template

local Feeds = require("novarr.feeds")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local UIFeeds = {}

-- Asked again at most this often when the server's meta says nothing about stats.
UIFeeds.STATS_PROBE_S = 24 * 3600

--[[--
Reads the feeds the server offers (call only when already connected).
opts.notice: show "Ready to read" for new items (default true). Returns
the new ready items.
]]
function UIFeeds.poll(session, opts)
    opts = opts or {}
    local ready = {}
    if session:feature("requests") then
        local data = session.api:notifications(session:get("notifications_cursor"))
        if type(data) == "table" and type(data.cursor) == "string" then
            local items = type(data.items) == "table" and data.items or {}
            session:set("notifications_cursor", data.cursor)
            if #items > 0 then
                session:set("notifications", Feeds.mergeNotifications(session:get("notifications"), items))
                ready = Feeds.readyItems(items)
            end
        end
    end
    if session:feature("updates") then
        local data = session.api:updates(session:get("updates_cursor"))
        if type(data) == "table" and type(data.cursor) == "string" then
            session:set("updates_cursor", data.cursor)
            if type(data.series) == "table" and #data.series > 0 then
                session:set("updates", Feeds.mergeUpdates(session:get("updates"), data.series))
            end
        end
    end
    UIFeeds.probeStats(session)
    if #ready > 0 and opts.notice ~= false then UIFeeds.notice(session, ready) end
    return ready
end

--- "Ready to read: X" with Open (the first one) and Later.
function UIFeeds.notice(session, ready)
    local text = Feeds.readyText(ready, _, T)
    if not text then return end
    local id = tonumber(ready[1].novel_id)
    -- Shown: Home's More button carries no dot for these.
    local newest = 0
    for _i, it in ipairs(ready) do newest = math.max(newest, tonumber(it.id) or 0) end
    if newest > (tonumber(session:get("notifications_seen_id")) or 0) then
        session:set("notifications_seen_id", newest)
    end
    UI.confirm({
        text = text,
        ok_text = _("Open"),
        cancel_text = #ready > 1 and _("See all") or _("Later"),
        ok = function() session:showNovel(id) end,
        cancel = #ready > 1 and function() UIFeeds.showNotifications(session) end or nil,
    })
end

--[[--
Whether the server has stats, found by trying GET stats (once a day) when
its meta says nothing either way (the stats endpoint is newer than meta's
keys). The answer is cached for My stats.
]]
function UIFeeds.probeStats(session)
    local f = session:get("server_features")
    if type(f) ~= "table" or f.stats then return end
    local last = tonumber(session:get("stats_probed_at")) or 0
    if os.time() - last < UIFeeds.STATS_PROBE_S then return end
    session:set("stats_probed_at", os.time())
    local _d, e = session:fetchInto("stats", function()
        local data, err = session.api:stats()
        if data == false then return nil, { kind = "not_found" } end
        if not data then return nil, err end
        return data, nil, { status = 200, body = require("novarr.json").encode(data) }
    end, false)
    if not e then
        session:set("server_stats_found", true)
    elseif e.kind == "not_found" then
        session:set("server_stats_found", false)
    end
end

-- ---------------------------------------------------------------- new chapters

local NewScreen = {}
NewScreen.__index = NewScreen

function NewScreen:entries()
    local s = self.session
    local stored = s:get("updates")
    if type(stored) == "table" and #stored > 0 then return stored, true end
    local lib = s:cached("novels")
    return Feeds.fromLibrary(lib and lib.novels or {}), false
end

function NewScreen:items()
    local s = self.session
    local entries, from_feed = self:entries()
    local items = {}
    for _i, e in ipairs(entries) do
        local n = tonumber(e.new_count) or 0
        local summary = s:novelSummary(e.novel_id)
        local name = e.name or (summary and summary.name) or T(_("Series %1"), e.novel_id)
        table.insert(items, {
            text = name .. (e.chapter_label and ("  ·  " .. T(_("from %1"), e.chapter_label)) or ""),
            mandatory = n == 1 and _("1 new") or T(_("%1 new"), n),
            callback = function() self:open(e) end,
            hold_callback = function() s:showNovel(e.novel_id) end,
        })
    end
    if #entries == 0 then
        table.insert(items, { text = _("No new chapters in the series you read."), dim = true, callback = function() end })
    elseif from_feed then
        table.insert(items, { text = _("Clear this list"), callback = function()
            s:set("updates", {})
            self:rebuild()
        end })
    end
    return items
end

function NewScreen:open(e)
    local s = self.session
    -- Opened: its new chapters are being read; off the list.
    s:set("updates", Feeds.dropSeries(s:get("updates"), e.novel_id))
    if tonumber(e.chapter_id) then
        s:openChapter(e.novel_id, tonumber(e.chapter_id), { check_position = true })
    else
        s:openNovel(e.novel_id)
    end
end

function NewScreen:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

function NewScreen:refresh()
    local s = self.session
    s:whenOnline(function()
        local closer = UI.loading(_("Loading…"))
        UIFeeds.poll(s, { notice = false })
        closer()
        self:rebuild()
    end)
end

function UIFeeds.showNew(session)
    local self = setmetatable({ session = session, kind = "new_chapters" }, NewScreen)
    self.menu = UI.showMenu(session, { title = _("New chapters"), items = self:items(), screen = self })
    UIManager:nextTick(Session.wrap(function() self:refresh() end))
    return self
end

-- ---------------------------------------------------------------- notifications

local NoteScreen = {}
NoteScreen.__index = NoteScreen

function NoteScreen:items()
    local s = self.session
    local list = s:get("notifications") or {}
    local items = {}
    for _i, n in ipairs(list) do
        local id = tonumber(n.novel_id)
        local ready = n.kind == "request_available"
        table.insert(items, {
            text = (n.title or "") .. ((n.body and n.body ~= "") and ("  ·  " .. n.body) or ""),
            mandatory = ready and id and _("Open") or nil,
            bold = ready,
            callback = function()
                if id then
                    s:showNovel(id)
                elseif n.request_id then
                    require("novarr.ui_requests").showMine(s)
                end
            end,
        })
    end
    if #list == 0 then
        table.insert(items, { text = _("Nothing new about your requests."), dim = true, callback = function() end })
    end
    return items
end

function NoteScreen:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

function UIFeeds.showNotifications(session)
    local self = setmetatable({ session = session, kind = "notifications" }, NoteScreen)
    self.menu = UI.showMenu(session, { title = _("Notifications"), items = self:items(), screen = self, multi_line = true })
    UIManager:nextTick(Session.wrap(function()
        session:whenOnline(function()
            UIFeeds.poll(session, { notice = false })
            self:rebuild()
        end)
    end))
    return self
end

-- ---------------------------------------------------------------- stats

function UIFeeds.showStats(session)
    local function show(data, offline_at)
        local lines = Feeds.statsLines(data, _)
        if not lines then
            UI.info(data and _("The server sent stats this version can't read.")
                or _("Your stats haven't been loaded yet: connect to load them."))
            return
        end
        if offline_at then
            table.insert(lines, 1, _("Offline — showing saved data from") .. " " .. UI.timeText(offline_at))
            table.insert(lines, 2, "")
        end
        UI.textViewer(_("My stats"), table.concat(lines, "\n"))
    end
    session:whenOnline(function()
        local closer = UI.loading(_("Loading…"))
        local data, e = session.api:stats()
        closer()
        if data then
            session.store:putCache("stats", require("novarr.json").encode(data), nil)
            show(data)
            return
        end
        if data == false then
            session:set("server_stats_found", false)
            UI.info(_("This Novarr server doesn't keep reading stats yet."))
            return
        end
        local cached, at = session:cached("stats")
        if cached then show(cached, at) else UI.info(session:errorText(e)) end
    end, function()
        local cached, at = session:cached("stats")
        show(cached, at)
    end)
end

return UIFeeds
