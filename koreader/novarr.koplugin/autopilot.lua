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
    reader_goto_end      jump to the last page (in a group file: of the current chapter), then turn once more
    reader_prev_page     turn a page back (on page 1: previous chapter, at its end)
    reader_goto_start    jump to the first page
    reader_close         close the reader (back to the file manager)
    suspend / resume     simulated sleep (sleep screen) and wake
    open_file PATH       open any document in KOReader's reader
    text TEXT            type TEXT into the open input dialog and confirm
    button TEXT          tap the button labelled TEXT in the topmost dialog (the quick menu's too)
    confirm / cancel     answer the topmost dialog (OK / Cancel; closes a message)
    main_menu N          open KOReader's main menu on tab N
    dispatch EVENT       send an event to the top widget (as a gesture/Dispatcher action does)
    broadcast EVENT      broadcast an event to every widget (e.g. NetworkConnected)
    offline on|off       make the plugin's network layer act as if offline
    wifi_backend emulator|fake   Wi-Fi emulation: requests fail while the emulated Wi-Fi
                         is off. emulator = KOReader's own NetworkMgr (the desktop
                         emulator's fake Wi-Fi), fake = Novarr's pretend Wi-Fi
                         (connect delay and failures below)
    wifi on|off          the user switches Wi-Fi on/off (as KOReader's Wi-Fi menu entry does)
    wifi_delay SECONDS   fake Wi-Fi: how long connecting takes
    wifi_fail N          fake Wi-Fi: the next N connection attempts fail
    wifi_scale X         multiply every Wi-Fi management duration (20 s on, timeouts, backoff)
    wifi_eval            run the Wi-Fi decision now
    tls_ca PATH          trust only this CA file for https (direct and through a proxy alike)
    http_proxy URL|off   KOReader's HTTP proxy setting (NetworkMgr:setHTTPProxy)
    reader_chapter_turns N [MS]   turn pages (one every MS ms, default 50) until N chapter
                         changes happened, each logged; the script waits for it
    reader_turn_to_chapter ID|last [MS]   turn pages (one every MS ms, default 50) until
                         chapter ID (last: the last stored one in reading order) is the
                         one being read; the script waits for it
    reader_turns_timed N MS  N page turns, one every MS ms, each timed (paint included);
                         logs the slowest and the average; the script waits for it
    sync                 "Sync now"
    set KEY VALUE        change a plugin setting (number, true/false or text)
    unset KEY            remove a plugin setting (back to its default)
    update_check         Settings' "check for update" (asks the server, offers to install)
    update_auto          the quiet daily update check, as Home runs it
    can_restart on|off   pretend KOReader can (or can't) restart itself; a restart
                         is never performed under the autopilot, only logged
    wait MS              pause
    shot PATH.png        screenshot with KOReader's screen facility
    dump PATH.json       write the plugin's internal state
    log TEXT             write TEXT to KOReader's log
    mem LABEL            log process RSS and Lua heap (after a full GC)
    time_pages N         turn the topmost screen's page N times and log the time per turn
    reader_turn_timed [-1]   one page turn in the reader, timed (paint included)
    reader_page_until_chapter_change [MAX] [back]   turn pages until the chapter changes
    reader_goto_chapter_end  last page of the current chapter (in a group file)
    reader_goto_chapter ID [PCT]   KOReader's own go-to (a jump) to a chapter in the open group file
    show_chapters        the chapter list of the novel open in the reader
    quick_menu           open the quick menu for the open chapter (as the gesture does)
    hold_footer          hold the middle of the status bar (Novarr's hold handler; the quick
                         menu when the hold_menu setting allows it, else logged as passed on)
    check_timing POLL MAX   checks for new chapters: poll every POLL s, give up after MAX s
    checks_reset         forget every check for new chapters
    check NOVEL_ID       "Check for new chapters" for a novel (as the Novel screen's entry)
    discover             open the Discover screen
    search_timing POLL MAX   background Discover searches: poll every POLL s, give up after MAX s
    discover_blocking on|off   act as if the server had (not) answered 404 to background searches
    highlight_add TEXT   highlight the first occurrence of TEXT in the open document (KOReader's
                         own saveHighlight, as the highlight dialog does)
    highlight_heading N  highlight the heading of the N-th chapter of the open group file
    highlight_note TEXT|NOTE   give the highlight containing TEXT this note (KOReader's note dialog, Save)
    highlight_style TEXT   change that highlight's style (KOReader's style choice)
    highlight_delete TEXT  delete that highlight (KOReader's own deleteHighlight)
    highlight_delete_heading N  delete the highlight on the N-th chapter's heading
    user_action          count as a user action now (the automatic plugin install waits 30 s after one)
    auto_install_idle S  the automatic plugin install waits S seconds without a user action
    lua PATH             run a Lua file (debugging)
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
    for _i, key in ipairs({ "button_table", "buttons_table", "buttontable", "movable", "dialog_frame", "frame", "inner_frame", "cancel_button" }) do
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

function steps.reader_goto_end(session)
    local ui = reader()
    local r = session.reader and session.reader.reading
    if r and r.group then
        -- A group file: the end of the current chapter (then the turn goes into the next one).
        require("novarr.reading").goInside(session.reader, r.chapter_id, { at_end = true })
    else
        ui:handleEvent(Event:new("GotoPage", ui.document:getPageCount()))
    end
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
    -- "dispatch SetFontSize 30": an optional numeric argument for the event.
    local name, value = tostring(arg):match("^(%S+)%s+(%S+)$")
    if name then
        UIManager:sendEvent(Event:new(name, tonumber(value) or value))
    else
        UIManager:sendEvent(Event:new(arg))
    end
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

-- Simulated sleep and wake (the emulator's suspend shows the sleep screen).
function steps.suspend()
    UIManager:suspend()
end

function steps.resume()
    local h = UIManager.event_handlers and UIManager.event_handlers.Resume
    if h then h() else UIManager:resume() end
end

-- Turns pages back N times (paging back from the first page opens the previous chapter).
function steps.reader_prev_page()
    reader():handleEvent(Event:new("GotoViewRel", -1))
end

function steps.reader_goto_start()
    reader():handleEvent(Event:new("GotoPage", 1))
end

function steps.offline(_, arg)
    Api.simulate_offline = (arg == "on")
end

function steps.wifi_backend(session, arg)
    local Wifi = require("novarr.wifi")
    if arg == "fake" then
        Wifi.setBackend(Wifi.fakeBackend({ on = true }))
    else
        Wifi.setBackend(Wifi.realBackend)
    end
    Api.offline_check = function() return not Wifi.backend.isConnected() end
    say("wifi backend", Wifi.backend.name, "on:", tostring(Wifi.backend.isOn()))
end

function steps.wifi(_, arg)
    local Wifi = require("novarr.wifi")
    if not Wifi.backend.userToggle then error("this Wi-Fi backend can't be switched") end
    Wifi.backend.userToggle(arg == "on")
end

function steps.wifi_delay(_, arg)
    local Wifi = require("novarr.wifi")
    if Wifi.backend.name ~= "fake" then error("wifi_delay needs wifi_backend fake") end
    Wifi.backend.delay = tonumber(arg) or 2
end

function steps.wifi_fail(_, arg)
    local Wifi = require("novarr.wifi")
    if Wifi.backend.name ~= "fake" then error("wifi_fail needs wifi_backend fake") end
    Wifi.backend.fail = tonumber(arg) or 1
end

function steps.wifi_scale(_, arg)
    require("novarr.wifi").scale = tonumber(arg) or 1
end

function steps.wifi_eval()
    require("novarr.wifi").evaluate("autopilot")
end

function steps.tls_ca(_, arg)
    require("ssl").set_system_ca(arg)
end

function steps.http_proxy(_, arg)
    local NetworkMgr = require("ui/network/manager")
    NetworkMgr:setHTTPProxy(arg ~= "off" and arg or nil)
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

function steps.unset(session, arg)
    if not arg then error("unset needs KEY") end
    session:unset(arg)
end

function steps.update_check(session)
    require("novarr.ui_update").check(session, function() session:refreshScreens() end)
end

function steps.update_auto(session)
    require("novarr.ui_update").autoCheck(session, function() session:refreshScreens() end)
end

function steps.can_restart(session, arg)
    session.autopilot_can_restart = (arg == "on")
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

-- ---- joined chapters

local function readingState(session)
    local r = session.reader and session.reader.reading
    return r and r.chapter_id, r
end

-- One forward page turn, painted, timed; logs the chapter before/after and
-- whether KOReader switched documents.
local function timedTurn(session, diff)
    local socket = require("socket")
    local before, r = readingState(session)
    local doc = reader().document
    local switches = session.switch_count or 0
    local t0 = socket.gettime()
    reader():handleEvent(Event:new("GotoViewRel", diff or 1))
    UIManager:forceRePaint()
    local ms = (socket.gettime() - t0) * 1000
    local after = readingState(session)
    local ui = require("apps/reader/readerui").instance
    local switched = (session.switch_count or 0) ~= switches or not ui or ui.document ~= doc
    return ms, before, after, switched, r
end

-- reader_turn_timed [-1]: one page turn (back with -1), timed.
function steps.reader_turn_timed(session, arg)
    local ms, before, after, switched = timedTurn(session, tonumber(arg) or 1)
    say(string.format("TURN %.1f ms (paint included), chapter %s -> %s, document switch: %s",
        ms, tostring(before), tostring(after), switched and "yes" or "no"))
end

-- reader_page_until_chapter_change [MAX] [back]: turns pages until the chapter changes
-- (inside a group file) or a document switch starts; logs the turns and the crossing turn's time.
function steps.reader_page_until_chapter_change(session, arg)
    local max = tonumber((arg or ""):match("^(%d+)")) or 400
    local diff = (arg or ""):find("back") and -1 or 1
    local start = readingState(session)
    for i = 1, max do
        local ms, before, after, switched = timedTurn(session, diff)
        if switched or after ~= start then
            say(string.format("CROSSED after %d turn(s): chapter %s -> %s, last turn %.1f ms, document switch: %s",
                i, tostring(start), tostring(after), ms, switched and "yes" or "no"))
            return
        end
        if session.opening_path then
            say(string.format("CROSSED after %d turn(s): a document switch started (chapter %s)", i, tostring(before)))
            return
        end
    end
    say(string.format("NOT CROSSED after %d turns (chapter %s)", max, tostring(start)))
end

-- reader_chapter_turns N: page forward until N chapter changes (inside group files or by
-- document switches) happened; logs each change and the slowest turn.
function steps.reader_chapter_turns(session, arg)
    local want = tonumber((arg or ""):match("^(%d+)")) or 1
    local gap = (tonumber((arg or ""):match("^%d+%s+(%d+)")) or 50) / 1000
    local changes, turns, slowest = 0, 0, 0
    local seen = readingState(session)
    Autopilot.busy = true
    local function step()
        local ok, err = pcall(function()
            if session.opening_path then return end -- a document switch is under way
            -- A change can also happen between two turns (the next file opened a tick later).
            local now_at = readingState(session)
            if now_at and now_at ~= seen then
                changes = changes + 1
                say(string.format("CHAPTER %d/%d: %s -> %s after %d turn(s)", changes, want, tostring(seen), tostring(now_at), turns))
                seen = now_at
                if changes >= want then return end
            end
            local ms = timedTurn(session, 1)
            turns = turns + 1
            if ms > slowest then slowest = ms end
            local after = readingState(session)
            if after and after ~= seen then
                changes = changes + 1
                say(string.format("CHAPTER %d/%d: %s -> %s after %d turn(s)", changes, want, tostring(seen), tostring(after), turns))
                seen = after
            end
        end)
        if not ok then
            say("ERROR", "reader_chapter_turns:", err)
            Autopilot.busy = false
            return
        end
        if changes < want and turns < want * 400 then
            UIManager:scheduleIn(gap, step)
        else
            say(string.format("CHAPTER TURNS done: %d change(s), %d turn(s), slowest turn %.1f ms", changes, turns, slowest))
            Autopilot.busy = false
        end
    end
    step()
end

-- reader_turn_to_chapter ID [MS]: page forward until chapter ID is the one being read.
function steps.reader_turn_to_chapter(session, arg)
    local target = tonumber((arg or ""):match("^(%d+)"))
    if (arg or ""):match("^last") then
        local _c, r = readingState(session)
        local cl = r and session:chapterList(r.novel_id)
        if not cl then error("no chapter list") end
        for i = #cl.list, 1, -1 do
            if session.store:hasChapter(r.novel_id, cl.list[i].id) then target = cl.list[i].id break end
        end
        say("last stored chapter:", tostring(target))
    end
    if not target then error("reader_turn_to_chapter needs a chapter id") end
    local gap = (tonumber((arg or ""):match("^%S+%s+(%d+)")) or 50) / 1000
    local turns = 0
    Autopilot.busy = true
    local function step()
        local ok, err = pcall(function()
            if session.opening_path then return end
            if readingState(session) == target then return end
            timedTurn(session, 1)
            turns = turns + 1
        end)
        if not ok then
            say("ERROR", "reader_turn_to_chapter:", err)
            Autopilot.busy = false
            return
        end
        if readingState(session) == target or turns >= 3000 then
            say(string.format("TURNED TO chapter %s after %d turn(s)", tostring(readingState(session)), turns))
            Autopilot.busy = false
        else
            UIManager:scheduleIn(gap, step)
        end
    end
    step()
end

-- reader_turns_timed N MS: N forward page turns, one every MS ms, each timed.
function steps.reader_turns_timed(session, arg)
    local n = tonumber((arg or ""):match("^(%d+)")) or 10
    local gap = (tonumber((arg or ""):match("^%d+%s+(%d+)")) or 2000) / 1000
    local done, total, slowest, late_max = 0, 0, 0, 0
    local socket = require("socket")
    local planned = socket.gettime()
    Autopilot.busy = true
    local function step()
        local ok, err = pcall(function()
            if session.opening_path then return end
            -- How late this turn starts (something else, e.g. a prefetch request, held the UI).
            local late = math.max(0, (socket.gettime() - planned) * 1000)
            if late > late_max then late_max = late end
            local ms, before, after, switched = timedTurn(session, 1)
            done = done + 1
            total = total + ms
            if ms > slowest then slowest = ms end
            say(string.format("TIMED TURN %d: %.1f ms, started %.0f ms late, chapter %s -> %s%s", done, ms, late, tostring(before), tostring(after), switched and " (document switch)" or ""))
        end)
        if not ok then
            say("ERROR", "reader_turns_timed:", err)
            Autopilot.busy = false
            return
        end
        if done < n then
            planned = socket.gettime() + gap
            UIManager:scheduleIn(gap, step)
        else
            say(string.format("TIMED TURNS done: %d turn(s), slowest %.1f ms, average %.1f ms, latest start %.0f ms", done, slowest, total / math.max(1, done), late_max))
            Autopilot.busy = false
        end
    end
    step()
end

-- reader_goto_chapter_end: the last page of the current chapter (inside a group file), else of the document.
function steps.reader_goto_chapter_end(session)
    local _id, r = readingState(session)
    local ui = reader()
    if r and r.group then
        local Reading = require("novarr.reading")
        Reading.goInside(session.reader, r.chapter_id, { at_end = true })
        return
    end
    ui:handleEvent(Event:new("GotoPage", ui.document:getPageCount()))
end

-- reader_goto_chapter ID [PERCENT]: KOReader's own "go to" (a jump) to a chapter of the open group file.
function steps.reader_goto_chapter(session, arg)
    local id, pct = (arg or ""):match("^(%d+)%s*(%d*)$")
    local _c, r = readingState(session)
    if not r or not r.group then error("no group file open") end
    local L = require("novarr.reading").layout(session.reader)
    local i = r.group.index[tonumber(id)]
    if not i then error("chapter " .. tostring(id) .. " is not in the open group file") end
    local page = L.pages[i]
    if pct and pct ~= "" then
        page = require("novarr.reading").pageForPercent(session.reader, i, tonumber(pct))
    end
    reader():handleEvent(Event:new("GotoPage", page))
end

-- show_chapters: the chapter list of the novel open in the reader (Novarr menu ▸ Chapter list).
function steps.show_chapters(session)
    local _c, r = readingState(session)
    if not r then error("no Novarr chapter open") end
    session:showChapters(r.novel_id)
end

-- quick_menu: the quick menu for the open chapter (Novarr menu gesture).
function steps.quick_menu(session)
    if not session.reader or not session.reader.reading then error("no Novarr chapter open") end
    require("novarr.reading").showQuickMenu(session.reader)
end

-- hold_footer: a hold in the middle of the status bar, through Novarr's own hold handler.
function steps.hold_footer(session)
    local plugin = session.reader
    if not plugin or not plugin.reading then error("no Novarr chapter open") end
    local footer = plugin.ui.view and plugin.ui.view.footer
    local d = footer and footer.footer_content and footer.footer_content.dimen
    if not d or not d.x then error("the status bar has no position (not shown yet?)") end
    local pos = { x = d.x + math.floor(d.w / 2), y = d.y + math.floor(d.h / 2) }
    local handled = require("novarr.reading").onFooterHold(plugin, { ges = "hold", pos = pos })
    say("hold on the status bar at", pos.x, pos.y, handled and "opened the quick menu" or "passed on to KOReader")
end

-- ---- discover and checks

function steps.check_timing(_, arg)
    local poll, max = (arg or ""):match("^(%d+)%s+(%d+)$")
    if not poll then error("check_timing needs POLL MAX (seconds)") end
    local CheckState = require("novarr.checkstate")
    CheckState.POLL_S, CheckState.MAX_S = tonumber(poll), tonumber(max)
end

function steps.checks_reset()
    require("novarr.checks").reset()
end

function steps.check(_, arg)
    local id = tonumber(arg)
    if not id then error("check needs a novel id") end
    require("novarr.checks").ask(id)
end

function steps.discover(session)
    require("novarr.ui_discover").show(session)
end

function steps.search_timing(_, arg)
    local poll, max = (arg or ""):match("^(%d+)%s+(%d+)$")
    if not poll then error("search_timing needs POLL MAX (seconds)") end
    local SearchState = require("novarr.searchstate")
    SearchState.POLL_S, SearchState.MAX_S = tonumber(poll), tonumber(max)
end

function steps.discover_blocking(session, arg)
    require("novarr.session").discover_blocking = (arg == "on") or nil
end

-- ---- highlights (KOReader's own functions, as its highlight menu calls them)

local function annotationIndex(ui, text)
    for i, a in ipairs(ui.annotation.annotations) do
        if a.drawer and type(a.text) == "string" and a.text:find(text, 1, true) then return i, a end
    end
    error("no highlight containing " .. tostring(text))
end

function steps.highlight_add(_, arg)
    local ui = reader()
    local hits = ui.document:findAllText(arg, false, 0, 5)
    local hit = type(hits) == "table" and hits[1]
    if not hit then error("text not found in the document: " .. tostring(arg)) end
    local pos0, pos1 = hit.start, hit["end"]
    ui.highlight.selected_text = { pos0 = pos0, pos1 = pos1, text = ui.document:getTextFromXPointers(pos0, pos1) or arg }
    local index = ui.highlight:saveHighlight(false)
    pcall(ui.highlight.clear, ui.highlight)
    say("highlighted", arg, "index", tostring(index))
end

-- highlight_heading N: highlight the heading of the N-th chapter of the open group file
-- (1 for a single chapter file); logs that chapter's id.
function steps.highlight_heading(session, arg)
    local _c, r = readingState(session)
    if not r then error("no Novarr chapter open") end
    local id = r.group and r.group.ids[tonumber(arg) or 1] or r.chapter_id
    local cl = session:chapterList(r.novel_id)
    local row = cl and cl.by_id[id]
    if not row or not row.label then error("no label for chapter " .. tostring(id)) end
    say("heading of chapter", id, ":", row.label)
    steps.highlight_add(session, row.label)
end

-- highlight_delete_heading N: delete the highlight on the N-th chapter's heading (see highlight_heading).
function steps.highlight_delete_heading(session, arg)
    local _c, r = readingState(session)
    if not r then error("no Novarr chapter open") end
    local id = r.group and r.group.ids[tonumber(arg) or 1] or r.chapter_id
    local cl = session:chapterList(r.novel_id)
    local row = cl and cl.by_id[id]
    if not row or not row.label then error("no label for chapter " .. tostring(id)) end
    steps.highlight_delete(session, row.label)
end

function steps.highlight_note(_, arg)
    local text, note = (arg or ""):match("^(.-)|(.*)$")
    if not text then error("highlight_note needs TEXT|NOTE") end
    local ui = reader()
    local index = annotationIndex(ui, text)
    ui.highlight:editNote(index, false)
    local w = topWidget()
    if not w or not w.setInputText then error("no note dialog") end
    w:setInputText(note)
    local b = findButton(w, "Save")
    if not b then error("no Save button in the note dialog") end
    b.callback()
end

function steps.highlight_style(_, arg)
    local ui = reader()
    local _i, item = annotationIndex(ui, arg)
    item.drawer = item.drawer == "underscore" and "lighten" or "underscore"
    ui:handleEvent(Event:new("AnnotationsModified", { item }))
end

function steps.highlight_delete(_, arg)
    local ui = reader()
    local index = annotationIndex(ui, arg)
    ui.highlight:deleteHighlight(index)
end

function steps.user_action()
    require("novarr.session").last_input = require("novarr.session").nowMs()
end

function steps.auto_install_idle(_, arg)
    require("novarr.ui_update").AUTO_IDLE_S = tonumber(arg) or 30
end

-- Runs a Lua file as function(session, Autopilot helpers); logs what it returns.
function steps.lua(session, arg)
    local chunk = assert(loadfile(arg))
    local res = chunk(session, { reader = reader, say = say, writeFile = writeFile })
    if res ~= nil then say("lua:", type(res) == "table" and Json.encode(res) or tostring(res)) end
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
        if Autopilot.busy then
            -- An asynchronous step: wait for it to finish, then pause as usual.
            local function waiter()
                if Autopilot.busy then
                    UIManager:scheduleIn(0.2, waiter)
                else
                    UIManager:scheduleIn(pause, run)
                end
            end
            UIManager:scheduleIn(0.2, waiter)
            return
        end
        UIManager:scheduleIn(pause, run)
    end
    -- Give KOReader time to finish starting up.
    UIManager:scheduleIn(2, run)
end

return Autopilot
