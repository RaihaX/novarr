--[[--
The quick menu while reading a Novarr chapter: a KOReader ButtonDialog with
the novel, the chapter and the whole status line as its title, and the
buttons of quickmenu.lua. Opened by holding the status bar (or the top bar
when it carries the Novarr line) and by the "Novarr: quick menu" gesture.
One at a time: a second request while it is open does nothing.
]]

local ButtonDialog = require("ui/widget/buttondialog")
local UIManager = require("ui/uimanager")

local Model = require("novarr.model")
local QuickMenu = require("novarr.quickmenu")
local Session = require("novarr.session")

local QuickMenuUI = {}

local open_dialog -- the quick menu on screen, if any

--- The open chapter's label: the chapter list's, else the stored copy's.
local function chapterLabel(r)
    local cl = Session:chapterList(r.novel_id)
    local row = cl and cl.by_id[r.chapter_id]
    if row then return Model.chapterLabel(row) end
    local entry = Session.store:chapterEntry(r.novel_id, r.chapter_id)
    return entry and entry.label or nil
end

function QuickMenuUI.state(plugin)
    local Reading = require("novarr.reading")
    local r = plugin.reading
    local summary = Session:novelSummary(r.novel_id)
    local novel_name = summary and summary.name or Session.store:getIndex(r.novel_id).novel_name
    -- A neighbour that exists but is not saved still counts: going there says why it can't open.
    local prev_id = Reading.neighbour(r, -1)
    local next_id, _dl, _label, missing = Reading.neighbour(r, 1)
    local CheckState = require("novarr.checkstate")
    local check = require("novarr.checks").state(r.novel_id)
    return {
        novel_name = novel_name,
        chapter_label = chapterLabel(r),
        status = Reading.statusText(plugin),
        has_prev = prev_id ~= nil,
        has_next = next_id ~= nil or missing ~= nil,
        pending = Session.queue:count(),
        checking = check ~= nil and CheckState.active(check) or false,
        -- Nothing restricts the check (auth.lua lets readers ask for one too).
        can_check = Session:allows("check"),
        -- A book never gets new chapters: no check (quickmenu.lua).
        book = Session:isBook(r.novel_id),
    }
end

--- What each button does (after the dialog has closed).
local function act(plugin, id)
    local Reading = require("novarr.reading")
    local r = plugin.reading
    if not r then return end
    local novel_id = r.novel_id
    if id == "chapters" then
        Session:showChapters(novel_id)
    elseif id == "novel" then
        Session:showNovel(novel_id)
    elseif id == "prev" then
        Reading.goPrev(plugin)
    elseif id == "next" then
        Reading.goNext(plugin)
    elseif id == "sync" then
        Session:syncNow()
    elseif id == "check" then
        require("novarr.checks").ask(novel_id)
    elseif id == "home" then
        Session:showHome()
    end
end

function QuickMenuUI.show(plugin)
    if open_dialog and UIManager.isWidgetShown then
        -- Gone without closing (the reader went away under it): forget it.
        local ok, shown = pcall(UIManager.isWidgetShown, UIManager, open_dialog)
        if ok and not shown then open_dialog = nil end
    end
    if open_dialog or not plugin.reading then return end
    local state = QuickMenuUI.state(plugin)
    local dialog
    local rows = {}
    for _i, row in ipairs(QuickMenu.rows(state)) do
        local buttons = {}
        for _j, b in ipairs(row) do
            local id = b.id
            table.insert(buttons, {
                text = b.text,
                enabled = b.enabled,
                callback = function()
                    UIManager:close(dialog)
                    if id ~= "close" then Session.guard(act, plugin, id) end
                end,
            })
        end
        table.insert(rows, buttons)
    end
    dialog = ButtonDialog:new{
        title = QuickMenu.title(state),
        title_align = "center",
        buttons = rows,
    }
    -- Closed by a button, a tap outside or Back: a new one may open.
    local orig_close_widget = dialog.onCloseWidget
    dialog.onCloseWidget = function(d, ...)
        if open_dialog == d then open_dialog = nil end
        if orig_close_widget then return orig_close_widget(d, ...) end
    end
    open_dialog = dialog
    UIManager:show(dialog)
    return dialog
end

return QuickMenuUI
