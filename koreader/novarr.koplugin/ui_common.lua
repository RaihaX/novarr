--[[--
Small wrappers around KOReader's standard widgets so every Novarr screen
looks native: a full-screen Menu with a title bar, InfoMessage, ConfirmBox,
InputDialog, ButtonDialog and TextViewer.
]]

local ButtonDialog = require("ui/widget/buttondialog")
local ConfirmBox = require("ui/widget/confirmbox")
local InfoMessage = require("ui/widget/infomessage")
local InputDialog = require("ui/widget/inputdialog")
local Menu = require("ui/widget/menu")
local UIManager = require("ui/uimanager")
local _ = require("gettext")

local Session = require("novarr.session")

local UI = {}

-- Marks used on the right of list rows (glyphs present in KOReader's UI font).
UI.MARK_READ = "\226\156\147"      -- ✓
UI.MARK_SAVED = "\226\151\143"     -- ●
UI.MARK_NEW = "\226\152\133"       -- ★

--[[--
Shows a "Loading…" style message and paints it before the caller blocks on
the network (the idiom bundled plugins use). Returns a function closing it.
]]
function UI.loading(text)
    local msg = InfoMessage:new{ text = text or _("Loading…"), dismissable = false }
    UIManager:show(msg)
    UIManager:forceRePaint()
    return function()
        UIManager:close(msg)
    end
end

function UI.info(text, timeout)
    UIManager:show(InfoMessage:new{ text = text, timeout = timeout })
end

--- Brief, non-blocking notice.
function UI.notify(text)
    local ok, Notification = pcall(require, "ui/widget/notification")
    if ok and Notification and Notification.notify then
        local shown = pcall(Notification.notify, Notification, text)
        if shown then return end
    end
    UIManager:show(InfoMessage:new{ text = text, timeout = 2 })
end

function UI.confirm(opts)
    local box = ConfirmBox:new{
        text = opts.text,
        ok_text = opts.ok_text or _("OK"),
        cancel_text = opts.cancel_text or _("Cancel"),
        ok_callback = Session.wrap(opts.ok or function() end),
        cancel_callback = opts.cancel and Session.wrap(opts.cancel) or nil,
    }
    UIManager:show(box)
    return box
end

--[[--
Text input dialog. opts: title, value, hint, description, input_type,
ok_text, callback(text, dialog) -> return true to keep the dialog open.
]]
function UI.input(opts)
    local dialog
    local function ok()
        local text = dialog:getInputText()
        local keep = opts.callback(text, dialog)
        if not keep then
            UIManager:close(dialog)
        end
    end
    dialog = InputDialog:new{
        title = opts.title,
        input = opts.value or "",
        input_hint = opts.hint,
        description = opts.description,
        input_type = opts.input_type,
        buttons = {
            {
                {
                    text = _("Cancel"),
                    id = "close",
                    callback = function()
                        UIManager:close(dialog)
                        if opts.cancel then Session.guard(opts.cancel) end
                    end,
                },
                {
                    text = opts.ok_text or _("OK"),
                    is_enter_default = true,
                    callback = Session.wrap(ok),
                },
            },
        },
    }
    -- For the debug autopilot: confirm without tapping.
    dialog._novarr_ok = Session.wrap(ok)
    UIManager:show(dialog)
    dialog:onShowKeyboard()
    return dialog
end

--[[--
A list of choices in a ButtonDialog. buttons: list of {text, callback} (one
per row). A Cancel row is added.
]]
function UI.choice(title, buttons)
    local dialog
    local rows = {}
    for _i, b in ipairs(buttons) do
        table.insert(rows, { {
            text = b.text,
            enabled = b.enabled ~= false,
            callback = function()
                UIManager:close(dialog)
                Session.guard(b.callback)
            end,
        } })
    end
    table.insert(rows, { {
        text = _("Cancel"),
        callback = function() UIManager:close(dialog) end,
    } })
    dialog = ButtonDialog:new{
        title = title,
        title_align = "center",
        buttons = rows,
    }
    UIManager:show(dialog)
    return dialog
end

function UI.textViewer(title, text)
    local TextViewer = require("ui/widget/textviewer")
    UIManager:show(TextViewer:new{ title = title, text = text })
end

--- Time of day (or date) for "showing saved data from …".
function UI.timeText(ms)
    if not ms then return _("an earlier visit") end
    local t = math.floor(ms / 1000)
    if os.date("%Y-%m-%d", t) == os.date("%Y-%m-%d") then
        return os.date("%H:%M", t)
    end
    return os.date("%d %b %H:%M", t)
end

--- The discreet first row shown when a refresh failed.
function UI.offlineRow(fetched_at)
    return {
        text = _("Offline — showing saved data from") .. " " .. UI.timeText(fetched_at),
        dim = true,
        select_enabled = false,
        callback = function() end,
    }
end

-- Section header row (not selectable).
function UI.headerRow(text)
    return { text = text, dim = true, bold = true, select_enabled = false, callback = function() end }
end

--[[--
Creates and shows a full-screen Novarr screen. opts:
    title, items, screen (object with kind/rebuild), on_hold(item),
    left_icon, on_left(), current (item index to open on, bolded)
Returns the Menu.
]]
function UI.showMenu(session, opts)
    local items = opts.items or {}
    if opts.current then items.current = opts.current end
    local menu
    if opts.cover_list then
        menu = require("novarr.ui_coverlist"):new{
            title = opts.title,
            item_table = items,
            title_bar_left_icon = opts.left_icon,
            covers = session:covers(),
            can_fetch = function() return session:isConnected() end,
            fetch_cover = function(url) return session:covers():download(url) end,
        }
    else
        menu = Menu:new{
            title = opts.title,
            item_table = items,
            is_borderless = true,
            is_popout = false,
            covers_fullscreen = true,
            title_bar_fm_style = true,
            title_bar_left_icon = opts.left_icon,
            single_line = true,
            name = "novarr_menu",
        }
    end
    menu.onMenuChoice = function(_menu, item)
        if item.callback then Session.guard(item.callback) end
        return true
    end
    menu.onMenuHold = function(_menu, item)
        if item.hold_callback then
            Session.guard(item.hold_callback)
        elseif opts.on_hold then
            Session.guard(opts.on_hold, item)
        end
        return true
    end
    if opts.on_left then
        menu.onLeftButtonTap = function() Session.guard(opts.on_left) end
    end
    local orig_close_widget = menu.onCloseWidget
    menu.onCloseWidget = function(m)
        session:menuClosed(m)
        if opts.screen and opts.screen.onClose then Session.guard(opts.screen.onClose, opts.screen) end
        if orig_close_widget then return orig_close_widget(m) end
    end
    session:pushMenu(menu, opts.screen)
    UIManager:show(menu)
    return menu
end

--- Replaces a screen's rows, staying on the same page when possible.
function UI.setItems(menu, items, current)
    if current then items.current = current end
    local perpage = menu.perpage or 1
    local first = menu.pageItemIndex and menu:pageItemIndex(1) or ((menu.page or 1) - 1) * perpage + 1
    if first > #items then first = #items end
    if first < 1 then first = 1 end
    menu:switchItemTable(nil, items, current or first)
end

return UI
