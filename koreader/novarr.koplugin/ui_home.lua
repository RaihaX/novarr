--[[--
Novarr Home: a Kindle-like shelf (see ui_shelf.lua). The novel being read
as a large "hero" block, then a grid of covers (other novels in progress,
most recent first; novels with new chapters; the rest of the library), and
a row of buttons: Library · Downloads · Sync · Settings.

One tap on a novel opens its resume chapter straight away; hold opens the
Novel screen. Everything shown prefers this device's own reading (marks
and positions not yet in the server's data) over the cached snapshot.
Opens instantly from the cached library, then refreshes.
]]

local UIManager = require("ui/uimanager")
local _ = require("gettext")
local T = require("ffi/util").template

local Model = require("novarr.model")
local ReadingInfo = require("novarr.readinginfo")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local Home = {}
Home.__index = Home

--- Lines under the hero's title: author, "Chapter N of M", "X chapters left · about Y".
function Home:heroLines(novel, started)
    local s = self.session
    local lines = {}
    if novel.author then table.insert(lines, novel.author) end
    local cl = s:chapterList(novel.id)
    local resume = s:resumeChapter(novel.id, novel)
    if cl and resume then
        local n, m = Model.ordinal(cl.list, resume)
        if n then
            local verb = started and _("Chapter %1 of %2") or _("Start reading: chapter %1 of %2")
            table.insert(lines, T(verb, ReadingInfo.thousands(n), ReadingInfo.thousands(m)))
        end
    elseif not started then
        table.insert(lines, _("Start reading"))
    end
    local left, secs = s:novelEstimate(novel.id)
    if left and left > 0 then
        local text = left == 1 and _("1 chapter left") or T(_("%1 chapters left"), ReadingInfo.thousands(left))
        if secs then text = text .. " · " .. ReadingInfo.formatDurationLong(secs) end
        table.insert(lines, text)
    end
    return lines
end

function Home:novelItem(novel, kind, started)
    local s = self.session
    local read, total = s:readCount(novel)
    local item = {
        kind = kind,
        text = novel.name,
        title = novel.name,
        novel_id = novel.id,
        url = novel.cover_url,
        badge = tonumber(novel.chapters_new) or 0,
        progress = total > 0 and read / total or 0,
        callback = function() s:openNovel(novel.id) end,
        hold_callback = function() s:showNovel(novel.id) end,
    }
    if kind == "hero" then
        item.url = novel.cover_large_url or novel.cover_url
        item.lines = self:heroLines(novel, started)
        -- Text layout (covers off): one row with the chapter.
        local resume = s:resumeChapter(novel.id, novel)
        local cl = s:chapterList(novel.id)
        local n = cl and resume and Model.ordinal(cl.list, resume)
        item.mandatory = n and T(_("ch %1"), ReadingInfo.thousands(n)) or (started and "" or _("Start"))
        item.bold = true
    else
        item.mandatory = string.format("%d/%d", read, total)
        if item.badge > 0 then item.mandatory = UI.MARK_NEW .. " " .. item.mandatory end
    end
    return item
end

function Home:items()
    local s = self.session
    local data, fetched_at = s:cached("novels")
    local items = {}
    if s.last_errors.novels then
        table.insert(items, { kind = "note", text = _("Offline — showing saved data from") .. " " .. UI.timeText(fetched_at),
            select_enabled = false, callback = function() end })
    end
    local novels = data and data.novels or {}
    local last_local = {}
    for _i, n in ipairs(novels) do last_local[n.id] = s:localLastRead(n.id) end
    local hero, started, rest = Model.shelfOrder(novels, last_local)
    self.hero_id = hero and hero.id
    if hero then
        table.insert(items, self:novelItem(hero, "hero", started))
    elseif not data then
        table.insert(items, { kind = "row", text = _("Nothing saved yet: connect to load your library."),
            dim = true, select_enabled = false, callback = function() end })
    end
    for _i, novel in ipairs(rest) do
        table.insert(items, self:novelItem(novel, "cell"))
    end
    local function button(text, cb)
        table.insert(items, { kind = "button", text = text, callback = cb })
    end
    button(_("Library"), function() require("novarr.ui_library").show(s) end)
    button(_("Downloads"), function() require("novarr.ui_downloads").show(s) end)
    local pending = s.queue:count()
    button(pending > 0 and T(_("Sync (%1)"), pending) or _("Sync"), function()
        s:syncNow(function() self:refresh(true) end)
    end)
    -- A quiet dot when the daily check found a plugin update (no popup).
    local update = require("novarr.ui_update").available(s)
    button(update and _("Settings •") or _("Settings"), function() require("novarr.ui_settings").show(s) end)
    return items
end

function Home:rebuild()
    if self.menu then UI.setItems(self.menu, self:items()) end
end

--- Sends pending writes, fetches the library (ETag) and the hero's chapter list, redraws.
function Home:refresh(quiet)
    local s = self.session
    local function run()
        local closer = (not quiet) and UI.loading(_("Loading…"))
        -- Our own pending marks go first so the server's answer includes them.
        if s.queue:count() > 0 then s:flushQueue() end
        local _data, e = s:fetchInto("novels", function(etag) return s.api:novels(etag) end, true)
        if not e then
            -- The hero shows "Chapter N of M": it needs that novel's chapter list.
            local data = s:cached("novels")
            local last_local = {}
            for _i, n in ipairs(data and data.novels or {}) do last_local[n.id] = s:localLastRead(n.id) end
            local hero = Model.shelfOrder(data and data.novels or {}, last_local)
            if hero then s:refreshChapterList(hero.id) end
        end
        if closer then closer() end
        if e and e.kind ~= "offline" then
            UI.info(s:errorText(e))
        end
        self:rebuild()
        if not e then self:scheduleUpdateCheck() end
    end
    s:whenConnected(run, function()
        s.last_errors.novels = s.last_errors.novels or { time = Session.nowMs(), kind = "offline" }
        self:rebuild()
    end, { quiet = true, reason = "Novarr Home" })
end

--[[--
The quiet daily plugin update check, a few seconds after Home has loaded
its data online (it never runs before the screen has painted, and the
check itself never turns Wi-Fi on).
]]
function Home:scheduleUpdateCheck()
    local s = self.session
    if not self.update_task then
        self.update_task = Session.wrap(function()
            require("novarr.ui_update").autoCheck(s, function() s:refreshScreens() end)
        end)
    end
    UIManager:unschedule(self.update_task)
    UIManager:scheduleIn(3, self.update_task)
end

function Home:onClose()
    if self.update_task then UIManager:unschedule(self.update_task) end
end

function Home.show(session)
    local self = setmetatable({ session = session, kind = "home" }, Home)
    self.menu = UI.showMenu(session, {
        title = _("Novarr"),
        shelf = true,
        items = self:items(),
        screen = self,
    })
    -- Let the cached screen paint first, then refresh.
    UIManager:nextTick(Session.wrap(function() self:refresh() end))
    return self
end

return Home
