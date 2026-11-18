local T, plugin_dir = ...
-- Runners that predate position.lua have no preload for it.
if not package.preload["novarr.position"] and plugin_dir then
    package.preload["novarr.position"] = function() return dofile(plugin_dir .. "/position.lua") end
end
local Model = require("novarr.model")
local Position = require("novarr.position")

-- A LuaSettings stand-in (readSetting/saveSetting/flush), with a "disk" copy
-- that only changes on flush, to check that values are written out.
local function settings(disk)
    disk = disk or {}
    local s = { data = {}, disk = disk, flushes = 0 }
    for k, v in pairs(disk) do s.data[k] = v end
    function s:readSetting(k) return self.data[k] end
    function s:saveSetting(k, v) self.data[k] = v end
    function s:flush()
        self.flushes = self.flushes + 1
        for k in pairs(self.disk) do self.disk[k] = nil end
        for k, v in pairs(self.data) do self.disk[k] = v end
    end
    return s
end

-- ---------------------------------------------------------------- shouldOfferPosition

local P = {
    chapter_id = 123, chapter_label = "Chapter 124: The Gate", chapter_number = "124", progress = 40,
    downloaded = true, device_id = "w_phone12345", device_name = "iPhone", at = "2026-11-18T02:10:00+00:00",
    age_seconds = 7200,
}

local function with(changes)
    local p = {}
    for k, v in pairs(P) do p[k] = v end
    for k, v in pairs(changes or {}) do p[k] = v end
    return p
end

local function offer(o)
    local base = { position = P, device_id = "k_mine123456", chapter_id = 100, local_progress = 10,
        local_newer = false, declined_at = nil, can_open = true }
    for k, v in pairs(o or {}) do base[k] = v end
    if o and o.position == false then base.position = nil end
    local ok, why = Model.shouldOfferPosition(base)
    return ok, why
end

T.it("shouldOfferPosition: offered when another device read elsewhere more recently", function()
    T.eq(offer(), true)
end)

T.it("shouldOfferPosition rule 1: no position, no device id, or this device", function()
    T.eq(offer({ position = false }), false)
    T.eq(offer({ position = with({ chapter_id = "nope" }) }), false)
    T.eq(offer({ position = with({ device_id = false }) }), false)
    T.eq(offer({ position = with({ device_id = "" }) }), false)
    local ok, why = offer({ position = with({ device_id = "k_mine123456" }) })
    T.eq(ok, false)
    T.eq(why, "read here")
end)

T.it("shouldOfferPosition rule 2: newer reading on this device wins", function()
    local ok, why = offer({ local_newer = true })
    T.eq(ok, false)
    T.eq(why, "newer reading here")
end)

T.it("shouldOfferPosition rule 3: same chapter needs 5 points or more", function()
    T.eq(offer({ chapter_id = 123, local_progress = 40 }), false)
    T.eq(offer({ chapter_id = 123, local_progress = 36 }), false)   -- 4 points
    T.eq(offer({ chapter_id = 123, local_progress = 44 }), false)
    T.eq(offer({ chapter_id = 123, local_progress = 35 }), true)    -- exactly 5
    T.eq(offer({ chapter_id = 123, local_progress = 45 }), true)
    T.eq(offer({ chapter_id = 123, local_progress = false }), true) -- unknown: the start, 0
    T.eq(offer({ chapter_id = 123, position = with({ progress = 3 }), local_progress = false }), false)
    -- Same chapter with no progress on the server: nowhere else to go.
    T.eq(offer({ chapter_id = 123, position = with({ progress = false }) }), false)
    -- Another chapter: whatever the progress.
    T.eq(offer({ chapter_id = 122, position = with({ progress = false }), local_progress = 99 }), true)
end)

T.it("shouldOfferPosition rule 4: this exact position was declined", function()
    T.eq(offer({ declined_at = P.at }), false)
    T.eq(offer({ declined_at = "2026-11-17T01:00:00+00:00" }), true) -- a newer position asks again
end)

T.it("shouldOfferPosition rule 5: the chapter must be openable", function()
    local ok, why = offer({ can_open = false })
    T.eq(ok, false)
    T.eq(why, "cannot open")
end)

-- ---------------------------------------------------------------- texts

T.it("relativeTime", function()
    T.eq(Model.relativeTime(0), "just now")
    T.eq(Model.relativeTime(59), "just now")
    T.eq(Model.relativeTime(60), "1 minute ago")
    T.eq(Model.relativeTime(119), "1 minute ago")
    T.eq(Model.relativeTime(120), "2 minutes ago")
    T.eq(Model.relativeTime(3599), "59 minutes ago")
    T.eq(Model.relativeTime(3600), "1 hour ago")
    T.eq(Model.relativeTime(7200), "2 hours ago")
    T.eq(Model.relativeTime(86399), "23 hours ago")
    T.eq(Model.relativeTime(86400), "yesterday")
    T.eq(Model.relativeTime(2 * 86400), "2 days ago")
    T.eq(Model.relativeTime(40 * 86400), "40 days ago")
    T.eq(Model.relativeTime(nil), "just now")
    T.eq(Model.relativeTime(-5), "just now")
    T.eq(Model.relativeTime(7200, function(s) return s == "%1 hours ago" and "vor %1 Stunden" or s end), "vor 2 Stunden")
end)

T.it("positionPromptText follows the contract", function()
    T.eq(Model.positionPromptText(P), "Chapter 124: The Gate \194\183 40% was read on iPhone 2 hours ago. Go there?")
    T.eq(Model.positionPromptText(with({ chapter_label = "", progress = 100, device_name = false, age_seconds = 30 })),
        "Chapter 124 was read on another device just now. Go there?")
    T.eq(Model.positionPromptText(with({ chapter_label = false, progress = 0 })),
        "Chapter 124 was read on iPhone 2 hours ago. Go there?")
    T.eq(Model.positionPromptText(with({ progress = 1 })), "Chapter 124: The Gate \194\183 1% was read on iPhone 2 hours ago. Go there?")
    T.eq(Model.positionPromptText(with({ progress = 99 })), "Chapter 124: The Gate \194\183 99% was read on iPhone 2 hours ago. Go there?")
    -- A label with a percent sign stays as it is.
    T.eq(Model.positionPromptText(with({ chapter_label = "100% %1", progress = false })),
        "100% %1 was read on iPhone 2 hours ago. Go there?")
end)

-- ---------------------------------------------------------------- device identity

T.it("device id: made once, valid, and stable across restarts", function()
    local disk = {}
    local s1 = settings(disk)
    local id1, name1 = Position.identity(s1, { model = "KindlePaperWhite5" })
    T.truthy(Position.validId(id1), "valid id")
    T.truthy(id1:match("^k_[A-Za-z0-9]+$"), "k_ prefix")
    T.eq(#id1, 24)
    T.eq(name1, "Kindle PaperWhite 5")
    T.eq(disk.device_id, id1, "written out")
    -- "Restart": a new settings object over the same file.
    local s2 = settings(disk)
    local id2 = Position.identity(s2, { model = "KindlePaperWhite5" })
    T.eq(id2, id1)
    T.eq(s2.flushes, 0, "nothing written when the id exists")
    T.eq((Position.identity(s2)), id1)
end)

T.it("device id: a broken stored id is replaced; random bytes are used when given", function()
    local s = settings({ device_id = "bad id!" })
    local id = Position.identity(s, { random_bytes = function(n) return string.rep("\0", n) end })
    T.eq(id, "k_" .. string.rep("A", 22))
    T.eq(s.disk.device_id, id)
    -- Without a random source (or a short read) it still makes a valid id.
    T.truthy(Position.validId(Position.newDeviceId(function() return nil end)))
    T.truthy(Position.validId(Position.newDeviceId(function() return "ab" end)))
    T.truthy(Position.validId(Position.newDeviceId()))
end)

T.it("device name: from KOReader's model, else Kindle; at most 40 characters", function()
    T.eq(Position.deviceName("KindlePaperWhite5"), "Kindle PaperWhite 5")
    T.eq(Position.deviceName("KindleOasis3"), "Kindle Oasis 3")
    T.eq(Position.deviceName("Kindle4"), "Kindle 4")
    T.eq(Position.deviceName("KindleVoyage"), "Kindle Voyage")
    T.eq(Position.deviceName(nil), "Kindle")
    T.eq(Position.deviceName(""), "Kindle")
    T.eq(Position.deviceName("1234"), "Kindle")
    T.eq(Position.deviceName({}), "Kindle")
    T.truthy(#Position.deviceName(string.rep("Abc", 30)) <= 40)
    -- A user-set name wins; a blank one does not.
    local s = settings({ device_id = "k_AbCdEfGhIjKlMnOpQrStUv", device_name = "Bedroom Kindle" })
    local _id, name = Position.identity(s, { model = "KindleOasis3" })
    T.eq(name, "Bedroom Kindle")
    s = settings({ device_id = "k_AbCdEfGhIjKlMnOpQrStUv", device_name = "  " })
    _id, name = Position.identity(s, { model = "KindleOasis3" })
    T.eq(name, "Kindle Oasis 3")
end)

-- ---------------------------------------------------------------- "Stay here"

T.it("declined marker: kept per novel and written out", function()
    local disk = {}
    local s = settings(disk)
    T.eq(Position.declinedAt(s, 7), nil)
    Position.decline(s, 7, P.at)
    Position.decline(s, 8, "2026-11-01T00:00:00+00:00")
    T.eq(Position.declinedAt(s, 7), P.at)
    T.truthy(s.flushes > 0)
    local again = settings(disk)
    T.eq(Position.declinedAt(again, 7), P.at)
    T.eq(Position.declinedAt(again, 8), "2026-11-01T00:00:00+00:00")
    T.eq(Position.declinedAt(again, 9), nil)
    -- A newer position declined replaces the old one.
    Position.decline(again, 7, "2026-11-18T05:00:00+00:00")
    T.eq(Position.declinedAt(settings(disk), 7), "2026-11-18T05:00:00+00:00")
    -- No `at`: nothing to remember.
    Position.decline(again, 9, nil)
    T.eq(Position.declinedAt(again, 9), nil)
    -- Garbage in the setting is ignored.
    T.eq(Position.declinedAt(settings({ position_declined = "x" }), 7), nil)
end)

T.it("declined marker feeds rule 4 end to end", function()
    local s = settings()
    T.eq(offer({ declined_at = Position.declinedAt(s, 7) }), true)
    Position.decline(s, 7, P.at)
    T.eq(offer({ declined_at = Position.declinedAt(s, 7) }), false)
    T.eq(offer({ position = with({ at = "2026-11-18T09:00:00+00:00" }), declined_at = Position.declinedAt(s, 7) }), true)
end)

-- ---------------------------------------------------------------- rule 2 input

T.it("localNewer: overlay or queued progress/read younger than the position", function()
    local now = 10000000
    local age = 7200 -- the position is 2 h old
    local recent = now - 3600 * 1000 -- 1 h ago
    local old = now - 3 * 3600 * 1000 -- 3 h ago
    T.eq(Position.localNewer({}, {}, 7, age, now), false)
    T.eq(Position.localNewer({ ["5"] = { progress = 30, at = recent } }, {}, 7, age, now), true)
    T.eq(Position.localNewer({ ["5"] = { read = true, at = recent } }, {}, 7, age, now), true)
    T.eq(Position.localNewer({ ["5"] = { progress = 30, at = old } }, {}, 7, age, now), false)
    -- An unread mark is not reading.
    T.eq(Position.localNewer({ ["5"] = { read = false, at = recent } }, {}, 7, age, now), false)
    -- Queue: this novel's progress or read marks only.
    T.eq(Position.localNewer({}, { { kind = "progress", novel_id = 7, chapter_id = 5, progress = 3, at = recent } }, 7, age, now), true)
    T.eq(Position.localNewer({}, { { kind = "progress", novel_id = 8, chapter_id = 5, progress = 3, at = recent } }, 7, age, now), false)
    T.eq(Position.localNewer({}, { { kind = "set_read", novel_id = 7, chapter_id = 5, read = true, at = recent } }, 7, age, now), true)
    T.eq(Position.localNewer({}, { { kind = "set_read", novel_id = 7, chapter_id = 5, read = false, at = recent } }, 7, age, now), false)
    T.eq(Position.localNewer({}, { { kind = "bulk_read", novel_id = 7, bulk = { read = true }, at = recent } }, 7, age, now), false)
    T.eq(Position.localNewer({}, { { kind = "progress", novel_id = 7, chapter_id = 5, progress = 3, at = old } }, 7, age, now), false)
    -- No age from the server: nothing can be compared, so it does not block the question.
    T.eq(Position.localNewer({ ["5"] = { progress = 30, at = recent } }, {}, 7, nil, now), false)
end)
