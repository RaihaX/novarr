local T = ...
local F = require("novarr.feeds")

local function field(list, name)
    local out = {}
    for _i, e in ipairs(list) do table.insert(out, e[name]) end
    return out
end

T.it("readyItems: available requests with a series to open", function()
    local items = {
        { id = 1, kind = "request_approved", novel_id = 4 },
        { id = 2, kind = "request_available", novel_id = 4, title = "Ready to read: Dune" },
        { id = 3, kind = "request_available" },
        { id = 4, kind = "request_declined" },
    }
    T.eq(field(F.readyItems(items), "id"), { 2 })
    T.eq(F.readyItems(nil), {})
end)

T.it("readyText: one, or the first and how many more", function()
    local a = { title = "Ready to read: Dune" }
    local b = { title = "Something else" }
    T.eq(F.subject(a), "Dune")
    T.eq(F.subject(b), "Something else")
    T.eq(F.readyText({ a }), "Ready to read: Dune")
    T.eq(F.readyText({ a, b, b }), "Ready to read: Dune and 2 more")
    T.eq(F.readyText({}), nil)
end)

T.it("mergeNotifications: newest first, no repeats, capped", function()
    local stored = { { id = 3 }, { id = 1 } }
    local merged = F.mergeNotifications(stored, { { id = 4 }, { id = 3 }, { id = 5 } })
    T.eq(field(merged, "id"), { 5, 4, 3, 1 })
    local many = {}
    for i = 1, 40 do table.insert(many, { id = i }) end
    local capped = F.mergeNotifications({}, many)
    T.eq(#capped, F.KEEP_NOTIFICATIONS)
    T.eq(capped[1].id, 40)
end)

T.it("mergeUpdates: one entry per series, counts add up, the first chapter to read stays", function()
    local stored = { { novel_id = 1, name = "A", new_count = 2, chapter_id = 10, chapter_label = "Ch 10", latest_at = "2026-11-20T10:00:00Z" } }
    local merged = F.mergeUpdates(stored, {
        { novel_id = 1, name = "A", new_count = 3, chapter_id = 12, chapter_label = "Ch 12", latest_at = "2026-11-27T10:00:00Z" },
        { novel_id = 2, name = "B", new_count = 1, chapter_id = 30, latest_at = "2026-11-26T10:00:00Z" },
    })
    T.eq(field(merged, "novel_id"), { 1, 2 })
    T.eq(merged[1].new_count, 5)
    T.eq(merged[1].chapter_id, 10)
    T.eq(merged[1].chapter_label, "Ch 10")
    T.eq(merged[1].latest_at, "2026-11-27T10:00:00Z")
    T.eq(stored[1].new_count, 2, "the stored list is not changed")
    T.eq(field(F.dropSeries(merged, 1), "novel_id"), { 2 })
end)

T.it("mergeUpdates: capped at KEEP_UPDATES, oldest dropped", function()
    local series = {}
    for i = 1, 60 do table.insert(series, { novel_id = i, new_count = 1, latest_at = string.format("2026-11-%02dT00:00:00Z", (i % 28) + 1) }) end
    T.eq(#F.mergeUpdates({}, series), F.KEEP_UPDATES)
    T.eq(F.mergeUpdates(nil, nil), {})
end)

T.it("fromLibrary: series with new chapters, most recently updated first", function()
    local out = F.fromLibrary({
        { id = 1, name = "A", chapters_new = 0, updated_at = "2026-11-27" },
        { id = 2, name = "B", chapters_new = 4, updated_at = "2026-11-20" },
        { id = 3, name = "C", chapters_new = 1, updated_at = "2026-11-26" },
    })
    T.eq(field(out, "novel_id"), { 3, 2 })
    T.eq(out[2].new_count, 4)
end)

T.it("statsLines: periods, streak and top series, read leniently", function()
    local lines = F.statsLines({ stats = {
        today = { chapters = 3, words = 4200, minutes = 35 },
        week = { chapters = 21, words = 30500 },
        all_time = { chapters = 1234, words = 2345678, minutes = 125 },
        streak = { current = 4, best = 10 },
        top_series = { { name = "Dune", chapters = 40 }, { novel = { name = "Other" }, words = 100 } },
    } })
    T.eq(lines, {
        "Today: 3 chapters · 4,200 words · 35 min",
        "This week: 21 chapters · 30,500 words",
        "All time: 1,234 chapters · 2,345,678 words · 2h 05m",
        "Streak: 4 days (best 10)",
        "",
        "Top series",
        "1. Dune  ·  40 chapters",
        "2. Other  ·  100 words",
    })
end)

T.it("statsLines: other spellings; nothing readable is nil", function()
    local lines = F.statsLines({ today = 1, this_week = { chapters_read = 5 }, total = { count = 9 }, streak = 1 })
    T.eq(lines, { "Today: 1 chapter", "This week: 5 chapters", "All time: 9 chapters", "Streak: 1 day" })
    T.eq(F.statsLines({ hello = "world" }), nil)
    T.eq(F.statsLines(nil), nil)
    T.eq(F.thousands(1234567), "1,234,567")
    T.eq(F.thousands(999), "999")
end)
