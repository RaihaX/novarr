local T = ...
local QM = require("novarr.quickmenu")

local function flat(rows)
    local out = {}
    for _i, row in ipairs(rows) do
        local ids = {}
        for _j, b in ipairs(row) do table.insert(ids, b.id) end
        table.insert(out, table.concat(ids, "|"))
    end
    return out
end

local function byId(rows, id)
    for _i, row in ipairs(rows) do
        for _j, b in ipairs(row) do if b.id == id then return b end end
    end
end

T.it("title: novel, chapter, status, missing parts left out", function()
    T.eq(QM.title({ novel_name = "Nine Heavens", chapter_label = "Chapter 12", status = "ch 12 of 300" }),
        "Nine Heavens\nChapter 12\nch 12 of 300")
    T.eq(QM.title({ novel_name = "Nine Heavens", status = "3 left" }), "Nine Heavens\n3 left")
    T.eq(QM.title({ chapter_label = "Chapter 12", status = "" }), "Chapter 12")
    T.eq(QM.title({}), nil)
    T.eq(QM.title(nil), nil)
end)

T.it("rows: five rows of two, in order", function()
    T.eq(flat(QM.rows({ has_prev = true, has_next = true, has_latest = true, pending = 0, can_check = true })),
        { "chapters|novel", "prev|next", "latest|home", "sync|check", "koreader|close" })
end)

T.it("rows: latest chapter only when there is a newer one", function()
    T.eq(byId(QM.rows({ has_latest = true }), "latest").enabled, true)
    T.eq(byId(QM.rows({ has_latest = false }), "latest").enabled, false)
    T.eq(byId(QM.rows({}), "latest").enabled, false)
    T.eq(byId(QM.rows({}), "latest").text, "Latest chapter")
end)

T.it("rows: previous and next follow what exists", function()
    local rows = QM.rows({ has_prev = false, has_next = true })
    T.eq(byId(rows, "prev").enabled, false)
    T.eq(byId(rows, "next").enabled, true)
    rows = QM.rows({ has_prev = true })
    T.eq(byId(rows, "prev").enabled, true)
    T.eq(byId(rows, "next").enabled, false)
end)

T.it("rows: sync shows the writes waiting", function()
    T.eq(byId(QM.rows({ pending = 0 }), "sync").text, "Sync now")
    T.eq(byId(QM.rows({ pending = 3 }), "sync").text, "Sync now (3)")
    T.eq(byId(QM.rows({}), "sync").text, "Sync now")
end)

T.it("rows: check for new chapters", function()
    local b = byId(QM.rows({ can_check = true }), "check")
    T.eq({ b.text, b.enabled }, { "Check for new chapters", true })
    b = byId(QM.rows({ checking = true, can_check = true }), "check")
    T.eq({ b.text, b.enabled }, { "Checking…", false })
    T.eq(byId(QM.rows({ can_check = false }), "check").enabled, false)
    T.eq(byId(QM.rows({}), "check").enabled, true, "not restricted unless said so")
end)

T.it("rows: the other buttons are always enabled", function()
    local rows = QM.rows({})
    for _i, id in ipairs({ "chapters", "novel", "sync", "home", "koreader", "close" }) do
        T.eq(byId(rows, id).enabled, true, id)
    end
end)

T.it("rows: a book has no check for new chapters and a Book page", function()
    local rows = QM.rows({ book = true, can_check = true, has_prev = true, has_next = true })
    T.eq(flat(rows), { "chapters|novel", "prev|next", "latest|home", "sync", "koreader|close" })
    T.eq(byId(rows, "check"), nil)
    T.eq(byId(rows, "novel").text, "Book page")
    T.eq(byId(QM.rows({ can_check = true }), "novel").text, "Novel page")
    T.eq(byId(QM.rows({ book = true, checking = true }), "check"), nil, "not even while one runs")
end)
