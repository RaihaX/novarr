--[[--
Home's "More" button (1.13.0): the reader's own things, each shown only
when the server offers it (Session:feature, from its meta):

    My lists              lists / favourites     (ui_lists.lua)
    New chapters          updates, or the library's own counts (ui_feeds.lua)
    Request a book…       requests               (ui_requests.lua)
    My requests           requests
    Notifications         requests
    My stats              stats                  (ui_feeds.lua)
    Manga                 the library holds manga (ui_library.lua)
    Comics                the library holds comics, server has comics + CBZ (1.14.0)
    Downloads             always (also on Home)

One ButtonDialog with a row per entry: big targets, a single refresh.
]]

local _ = require("gettext")

local Collections = require("novarr.collections")
local UI = require("novarr.ui_common")

local More = {}

--- Whether Home's More button carries the quiet dot: a ready request not opened yet.
function More.hasNews(session)
    local list = session:get("notifications")
    local seen = tonumber(session:get("notifications_seen_id")) or 0
    for _i, n in ipairs(type(list) == "table" and list or {}) do
        if (tonumber(n.id) or 0) > seen and n.kind == "request_available" then return true end
    end
    return false
end

function More.show(session, home)
    local lib = session:cached("novels")
    local counts = Collections.mediaCounts(lib and lib.novels or {})
    local has_manga = counts.manga > 0
    -- Comics (1.14.0): only when the server has them and sends them as CBZ.
    local comics_ok = session:comicsReadable()
    local has_comics = comics_ok and counts.comic > 0
    local names = {
        lists = _("My lists"),
        new_chapters = _("New chapters"),
        request = session:feature("comics") and _("Request a book, web novel, manga or comic…") or _("Request a book, web novel or manga…"),
        my_requests = _("My requests"),
        notifications = More.hasNews(session) and (_("Notifications") .. " •") or _("Notifications"),
        stats = _("My stats"),
        manga = _("Manga"),
        comic = _("Comics"),
    }
    local actions = {
        lists = function() require("novarr.ui_lists").show(session) end,
        new_chapters = function() require("novarr.ui_feeds").showNew(session) end,
        request = function() require("novarr.ui_requests").start(session) end,
        my_requests = function() require("novarr.ui_requests").showMine(session) end,
        notifications = function()
            -- Seen: the dot goes.
            local list = session:get("notifications")
            if type(list) == "table" and list[1] then session:set("notifications_seen_id", tonumber(list[1].id)) end
            if home then home:rebuild() end
            require("novarr.ui_feeds").showNotifications(session)
        end,
        stats = function() require("novarr.ui_feeds").showStats(session) end,
        manga = function() require("novarr.ui_library").show(session, "manga") end,
        comic = function() require("novarr.ui_library").show(session, "comic") end,
    }
    local buttons = {}
    for _i, id in ipairs(Collections.moreEntries(function(name) return session:feature(name) end, has_manga, has_comics)) do
        table.insert(buttons, { text = names[id], callback = actions[id] })
    end
    table.insert(buttons, { text = _("Downloads"), callback = function() require("novarr.ui_downloads").show(session) end })
    UI.choice(_("More"), buttons)
end

return More
