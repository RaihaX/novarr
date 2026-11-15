--[[--
DEBUG AUTOPILOT. Inert unless the environment variable NOVARR_AUTOPILOT
points to a script file; only then is this module loaded at all.

The script has one step per line (blank lines and lines starting with #
are ignored). Steps run one after another via UIManager:scheduleIn, with a
pause after each (default 1.5 s; "wait MS" adds more):

    open                 open the Novarr home screen
    tap_item N           select the Nth entry on the visible page of the topmost Novarr screen
    hold_item N          long-press that entry
    tap_text TEXT        select the first entry whose text is (or starts with) TEXT, on any page
    hold_text TEXT       long-press it
    back                 close the topmost Novarr screen
    next_page / prev_page   page the topmost Novarr screen
    left_button          tap the title bar's left button of the topmost Novarr screen
    reader_next_page     turn a page in the reader
    reader_goto_end      jump to the last page, then turn once more (end of chapter)
    reader_close         close the reader (back to the file manager)
    open_file PATH       open any document in KOReader's reader
    text TEXT            type TEXT into the open input dialog and confirm
    button TEXT          tap the button labelled TEXT in the topmost dialog
    confirm / cancel     answer the topmost dialog (OK / Cancel; closes a message)
    main_menu N          open KOReader's main menu on tab N
    dispatch EVENT       send an event to the top widget (as a gesture/Dispatcher action does)
    broadcast EVENT      broadcast an event to every widget (e.g. NetworkConnected)
    offline on|off       make the plugin's network layer act as if offline
    sync                 "Sync now"
    set KEY VALUE        change a plugin setting (number, true/false or text)
    wait MS              pause
    shot PATH.png        screenshot with KOReader's screen facility
    dump PATH.json       write the plugin's internal state
    log TEXT             write TEXT to KOReader's log
    mem LABEL            log process RSS and Lua heap (after a full GC)
    time_pages N         turn the topmost screen's page N times and log the time per turn
    quit                 exit KOReader

Every step is logged as "NovarrAutopilot: ..." in KOReader's log, with
"NovarrAutopilot: ERROR" for failures (the script carries on).
]]

local Device = require("device")
local Event = require("ui/event")
local UIManager = require("ui/uimanager")
local logger = require("logger")

local Api = require("novarr.api")
local Json = require("novarr.json")

local Autopilot = {}

Autopilot.STEP_PAUSE = 1.5

local function say(...)
    logger.info("NovarrAutopilot:", ...)
end

local function readLines(path)
    local f = io.open(path, "r")
    if not f then return nil end
    local lines = {}
    for line in f:lines() do
        line = line:gsub("\r$", ""):gsub("^%s+", ""):gsub("%s+$", "")
        if line ~= "" and not line:match("^#") then table.insert(lines, line) end
    end
    f:close()
    return lines
end

local function windowStack()
    return UIManager._window_stack or {}
end

--- Topmost non-toast widget.
local function topWidget()
    local stack = windowStack()
    for i = #stack, 1, -1 do
        local w = stack[i].widget
        if w and not w.toast then return w end
    end
end

local function topMenu(session)
    local menu = session:topMenu()
    if not menu then error("no Novarr screen is open") end
    return menu
end

local function pageItem(menu, n)
    local perpage = menu.perpage or 1
    local index = menu.pageItemIndex and menu:pageItemIndex(n) or ((menu.page or 1) - 1) * perpage + n
    local item = menu.item_table[index]
    if not item then error("no entry " .. n .. " on this page") end
    return item, index
end

-- Depth-first search for a Button-like widget with this text.
local function findButton(widget, text, depth)
    depth = depth or 0
    if type(widget) ~= "table" or depth > 40 then return nil end
    if widget.text == text and type(widget.callback) == "function" then
        return widget
    end
    for _i, child in ipairs(widget) do
        local found = findButton(child, text, depth + 1)
        if found then return found end
    end
    for _i, key in ipairs({ "button_table", "buttons_table", "movable", "dialog_frame", "frame", "inner_frame", "cancel_button" }) do
        if type(widget[key]) == "table" then
            local found = findButton(widget[key], text, depth + 1)
            if found then return found end
        end
    end
    return nil
end

local function reader()
    local ReaderUI = require("apps/reader/readerui")
    local ui = ReaderUI.instance
    if not ui or not ui.document then error("the reader is not open") end
    return ui
end

local function writeFile(path, data)
    local dir = path:match("^(.*)/[^/]*$")
    if dir then os.execute("mkdir -p '" .. dir:gsub("'", "'\\''") .. "'") end
    local f = assert(io.open(path, "w"))
    f:write(data)
    f:close()
end

local steps = {}

function steps.open(session)
    session:showHome()
end

function steps.tap_item(session, arg)
    local menu = topMenu(session)
    local item = pageItem(menu, tonumber(arg))
    say("tapping", item.text)
    menu:onMenuSelect(item)
end

function steps.hold_item(session, arg)
    local menu = topMenu(session)
    local item = pageItem(menu, tonumber(arg))
    say("holding", item.text)
    menu:onMenuHold(item)
end

-- First entry (any page) whose text equals or starts with TEXT.
local function itemByText(menu, text)
    for i, item in ipairs(menu.item_table) do
        if item.text == text then return item, i end
    end
    for i, item in ipairs(menu.item_table) do
        if type(item.text) == "string" and item.text:sub(1, #text) == text then return item, i end
    end
    error("no entry named " .. tostring(text))
end

function steps.tap_text(session, arg)
    local menu = topMenu(session)
    local item, index = itemByText(menu, arg)
    menu:switchItemTable(nil, menu.item_table, index)
    menu:onMenuSelect(item)
end

function steps.hold_text(session, arg)
    local menu = topMenu(session)
    local item, index = itemByText(menu, arg)
    menu:switchItemTable(nil, menu.item_table, index)
    menu:onMenuHold(item)
end

function steps.back(session)
    topMenu(session):onClose()
end

function steps.next_page(session)
    topMenu(session):onNextPage()
end

function steps.prev_page(session)
    topMenu(session):onPrevPage()
end

function steps.left_button(session)
    topMenu(session):onLeftButtonTap()
end

function steps.reader_next_page()
    reader():handleEvent(Event:new("GotoViewRel", 1))
end

function steps.reader_goto_end()
    local ui = reader()
    local count = ui.document:getPageCount()
    ui:handleEvent(Event:new("GotoPage", count))
    UIManager:scheduleIn(0.3, function()
        local ok, err = pcall(function() reader():handleEvent(Event:new("GotoViewRel", 1)) end)
        if not ok then say("ERROR", "reader_goto_end:", err) end
    end)
end

function steps.reader_close()
    reader():onHome()
end

function steps.open_file(session, arg)
    local ReaderUI = require("apps/reader/readerui")
    session:closeAllMenus()
    if ReaderUI.instance and ReaderUI.instance.document then
        ReaderUI.instance:switchDocument(arg)
    else
        ReaderUI:showReader(arg)
    end
end

function steps.text(_, arg)
    local w = topWidget()
    if not w or not w.getInputText then error("no input dialog on top") end
    w:setInputText(arg or "")
    if w._novarr_ok then
        w._novarr_ok()
    else
        error("the input dialog has no confirm action")
    end
end

function steps.button(_, arg)
    local w = topWidget()
    local b = w and findButton(w, arg)
    if not b then error("no button labelled " .. tostring(arg)) end
    b.callback()
end

local function answer(ok)
    local w = topWidget()
    if not w then error("no dialog") end
    if ok and w.ok_callback then
        UIManager:close(w)
        w.ok_callback()
    elseif not ok and w.cancel_callback then
        UIManager:close(w)
        w.cancel_callback()
    elseif w.name == "novarr_menu" then
        error("the top widget is a Novarr screen, not a dialog")
    else
        UIManager:close(w)
    end
end

function steps.confirm() answer(true) end
function steps.cancel() answer(false) end

function steps.main_menu(_, arg)
    local ReaderUI = require("apps/reader/readerui")
    local FileManager = require("apps/filemanager/filemanager")
    local ui = (ReaderUI.instance and ReaderUI.instance.document and ReaderUI.instance) or FileManager.instance
    if not ui or not ui.menu then error("no main menu") end
    ui.menu:onShowMenu(tonumber(arg))
end

function steps.dispatch(_, arg)
    UIManager:sendEvent(Event:new(arg))
end

function steps.broadcast(_, arg)
    UIManager:broadcastEvent(Event:new(arg))
end

-- Memory: process RSS (KB) and Lua heap (KB), logged and kept for dumps.
function steps.mem(session, arg)
    collectgarbage("collect")
    local rss = "?"
    local ok, ffi = pcall(require, "ffi")
    if ok then
        pcall(ffi.cdef, "int getpid(void);")
        local okp, pid = pcall(function() return ffi.C.getpid() end)
        if okp then
            local p = io.popen("ps -o rss= -p " .. tonumber(pid))
            if p then rss = (p:read("*a") or ""):gsub("%s", "") p:close() end
        end
    end
    local lua_kb = math.floor(collectgarbage("count"))
    say(string.format("MEM %s rss_kb=%s lua_kb=%d", arg or "", rss, lua_kb))
    session.mem_log = session.mem_log or {}
    table.insert(session.mem_log, { label = arg, rss_kb = tonumber(rss), lua_kb = lua_kb })
end

-- Times N page turns of the topmost screen (paint included).
function steps.time_pages(session, arg)
    local menu = topMenu(session)
    local n = tonumber(arg) or 5
    local socket = require("socket")
    local t0 = socket.gettime()
    for _i = 1, n do
        menu:onNextPage()
        UIManager:forceRePaint()
    end
    local ms = (socket.gettime() - t0) * 1000 / n
    say(string.format("PAGETURN %s: %.1f ms per page (%d turns, paint included)", menu.name == "novarr_menu" and (menu.pages and "covers" or "text") or "?", ms, n))
end

function steps.offline(_, arg)
    Api.simulate_offline = (arg == "on")
end

function steps.sync(session)
    session:syncNow()
end

function steps.set(session, arg)
    local key, value = (arg or ""):match("^(%S+)%s+(.+)$")
    if not key then error("set needs KEY VALUE") end
    if value == "true" then value = true
    elseif value == "false" then value = false
    elseif tonumber(value) then value = tonumber(value) end
    session:set(key, value)
end

function steps.shot(_, arg)
    UIManager:forceRePaint()
    local ok, err = Device.screen:shot(arg)
    if ok == false then error("screenshot failed: " .. tostring(err)) end
end

function steps.dump(session, arg)
    local text = Json.encode(session:dumpState())
    writeFile(arg, text or "{}")
end

function steps.log(_, arg)
    say(arg)
end

function steps.quit()
    UIManager:quit()
end

function Autopilot.start(session, script_path)
    local lines = readLines(script_path)
    if not lines then
        say("ERROR", "cannot read script", script_path)
        return
    end
    say("starting", script_path, #lines, "steps")
    local i = 0
    local run
    run = function()
        i = i + 1
        local line = lines[i]
        if not line then
            say("finished")
            return
        end
        local cmd, arg = line:match("^(%S+)%s*(.*)$")
        local pause = Autopilot.STEP_PAUSE
        if cmd == "wait" then
            pause = (tonumber(arg) or 0) / 1000
            say(string.format("[%d] wait %s", i, arg))
        else
            say(string.format("[%d] %s", i, line))
            local fn = steps[cmd]
            if not fn then
                say("ERROR", "unknown step", cmd)
            else
                local ok, err = xpcall(function() fn(session, arg ~= "" and arg or nil) end, debug.traceback)
                if not ok then say("ERROR", line, err) end
            end
            if cmd == "quit" then return end
        end
        UIManager:scheduleIn(pause, run)
    end
    -- Give KOReader time to finish starting up.
    UIManager:scheduleIn(2, run)
end

return Autopilot
