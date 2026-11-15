--[[--
"Download as EPUB": has the Novarr server build an EPUB of the whole novel
when needed, downloads it and saves it as an ordinary book in
<home folder>/Novarr/.

The API (GET/POST /api/v1/novels/{id}/epub, see epubflow.lua) says whether
an EPUB exists and is current; a missing, failed or stale one is rebuilt on
the server while a cancellable "building" dialog polls every 3 s. The file
is then streamed from download_url straight to a temp file (ltn12 file
sink, never held in memory) and renamed into place. The download itself
blocks KOReader with a "Downloading…" notice (a LuaSocket request cannot be
cancelled half way without a subprocess).

Reading the EPUB is plain KOReader reading: it is not under the chapters
folder, so the reader hooks never treat it as a Novarr chapter.
]]

local UIManager = require("ui/uimanager")
local logger = require("logger")
local _ = require("gettext")
local T = require("ffi/util").template

local Api = require("novarr.api")
local ChapterDoc = require("novarr.chapterdoc")
local Model = require("novarr.model")
local Session = require("novarr.session")
local UI = require("novarr.ui_common")

local Epub = {}

Epub.BLOCK_TIMEOUT = 15
Epub.TOTAL_TIMEOUT = 15 * 60 -- big files over slow Wi-Fi

--- Folder for EPUBs: the file manager's home folder + /Novarr (or the plugin's data folder).
function Epub.folder(session)
    local home = G_reader_settings:readSetting("home_dir")
    if not home or home == "" then
        local ok, Device = pcall(require, "device")
        home = ok and Device.home_dir or nil
    end
    local lfs = require("libs/libkoreader-lfs")
    if not home or lfs.attributes(home, "mode") ~= "directory" then
        home = session.root
    end
    return (home:gsub("/+$", "")) .. "/Novarr"
end

--- FAT-safe "<name>.epub" from the server's filename (or the novel name).
Epub.fileName = function(_unused, name) return ChapterDoc.epubFileName(name) end

-- Streams url into a file (make_sink opens it, once per attempt) with the
-- EPUB timeouts, through the session (address in use, proxy). Returns code, headers or nil, err.
local function fetchTo(session, url, make_sink)
    if Api.isOffline() then return nil, "simulated offline" end
    local res, err = session:fetchUrl(url, {
        headers = { ["User-Agent"] = "NovarrKOReader/" .. Api.VERSION, ["Accept"] = "application/epub+zip" },
        block_timeout = Epub.BLOCK_TIMEOUT, total_timeout = Epub.TOTAL_TIMEOUT,
    }, make_sink)
    if not res then return nil, tostring(err) end
    return res.status, res.headers
end

local function header(h, name)
    return h and (h[name] or h[name:lower()]) or nil
end

function Epub.download(session, novel_id, novel_name)
    session:whenConnected(function()
        Session.guard(Epub.run, session, novel_id, novel_name)
    end, function()
        UI.info(_("No network connection: the EPUB can't be downloaded now."))
    end)
end

local function offerOpen(session, text, path)
    UI.confirm({
        text = text,
        ok_text = _("Open now"),
        cancel_text = _("Close"),
        ok = function() session:openFile(path) end,
    })
end

function Epub.run(session, novel_id, novel_name)
    local EpubFlow = require("novarr.epubflow")
    local epubs = session.store:getEpubs()
    local key = tostring(novel_id)
    local closer = UI.loading(_("Checking the EPUB on the server…"))
    local function closeLoading()
        if closer then closer() closer = nil end
    end
    local dialog
    local function closeDialog()
        if dialog then UIManager:close(dialog) dialog = nil end
    end
    local flow
    flow = EpubFlow.new({
        get = function() return session.api:epubStatus(novel_id) end,
        post = function() return session.api:epubBuild(novel_id) end,
        schedule = function(secs, fn) UIManager:scheduleIn(secs, fn) end,
        unschedule = function(fn) UIManager:unschedule(fn) end,
        now = os.time,
        stored = epubs[key],
        on_up_to_date = function()
            closeLoading()
            offerOpen(session, T(_("The EPUB of %1 on this device is up to date."), novel_name or ""), epubs[key].path)
        end,
        on_waiting = function()
            closeLoading()
            dialog = require("novarr.ui_progress"):new{
                title = _("Building EPUB"),
                text = _("The server is building the EPUB…"),
                cancelling_text = _("Stopping…"),
                cancel_callback = function()
                    flow:cancel()
                end,
            }
            UIManager:show(dialog)
        end,
        on_download = function(epub)
            closeLoading()
            closeDialog()
            -- Let the dialogs disappear before the blocking download.
            UIManager:nextTick(Session.wrap(function() Epub.fetchFile(session, novel_id, novel_name, epub) end))
        end,
        on_finish = function(kind, payload)
            closeLoading()
            closeDialog()
            if kind == "failed" then
                UI.info(payload and payload.message or _("The server couldn't build the EPUB."))
            elseif kind == "timeout" then
                UI.info(_("The EPUB is taking a long time to build. The server carries on: tap Download as EPUB again later to fetch it."))
            elseif kind == "cancelled" then
                UI.notify(_("Stopped waiting. The server carries on building the EPUB."))
            elseif payload and payload.kind == "not_found" then
                UI.info(_("This server doesn't offer EPUB downloads (Novarr may need updating), or the novel no longer exists."))
            elseif payload and payload.kind == "invalid" and payload.message then
                UI.info(payload.message)
            else
                UI.info(session:errorText(payload))
            end
        end,
    })
    flow.poll = Session.wrap(flow.poll)
    Epub.current_flow = flow -- for the autopilot / dumps
    flow:start()
    closeLoading()
end

--- Streams the built EPUB to <home>/Novarr/<filename> (temp file + rename).
function Epub.fetchFile(session, novel_id, novel_name, epub)
    local url = epub.download_url
    if not url or url == "" then
        UI.info(_("The server said the EPUB is ready but gave no address to download it from."))
        return
    end
    local size = tonumber(epub.size)
    local free = session:freeBytes()
    if size and free and free < size + Session.MIN_FREE_BYTES then
        UI.info(T(_("Not enough free space: the EPUB needs %1 and at least 20 MB must stay free."), Model.formatBytes(size)))
        return
    end
    local epubs = session.store:getEpubs()
    local key = tostring(novel_id)
    local existing = epubs[key]
    local folder = Epub.folder(session)
    session.store:mkdirs(folder)
    local name = Epub.fileName(nil, epub.filename or novel_name)
    local path = folder .. "/" .. name
    local tmp = path .. ".part"
    local fh, ferr = io.open(tmp, "wb")
    if not fh then
        UI.info(T(_("Couldn't write to %1: %2"), folder, tostring(ferr)))
        return
    end
    local socketutil = require("socketutil")
    local closer = UI.loading(size and T(_("Downloading EPUB (%1)…"), Model.formatBytes(size)) or _("Downloading EPUB…"))
    local first = true
    local code, headers = fetchTo(session, url, function()
        -- A retry on the other server address starts the file again.
        if not first then
            pcall(function() fh:close() end)
            fh = io.open(tmp, "wb")
        end
        first = false
        return fh and socketutil.file_sink(fh) or nil
    end)
    closer()
    if fh then pcall(function() fh:close() end) end
    local lfs = require("libs/libkoreader-lfs")
    local got = lfs.attributes(tmp, "size") or 0
    if code ~= 200 or got == 0 or (size and got ~= size) then
        os.remove(tmp)
        logger.warn("Novarr: EPUB download failed", code, got, size)
        if code == 404 then
            UI.info(_("The EPUB disappeared from the server before it could be downloaded. Try again."))
        elseif code and code ~= 200 then
            UI.info(session:errorText({ kind = code >= 500 and "server" or "invalid", status = code }))
        else
            UI.info(_("The download was interrupted. Check the connection and try again."))
        end
        return
    end
    if existing and existing.path ~= path then
        Session.purgeSidecar(existing.path)
        os.remove(existing.path)
    end
    local renamed = os.rename(tmp, path)
    if not renamed then
        os.remove(path)
        renamed = os.rename(tmp, path)
    end
    if not renamed then
        os.remove(tmp)
        UI.info(T(_("Couldn't save the EPUB in %1."), folder))
        return
    end
    epubs[key] = {
        path = path,
        name = novel_name,
        generated_at = epub.generated_at,
        etag = header(headers, "etag"),
        size = got,
        saved_at = Session.nowMs(),
    }
    session.store:saveEpubs()
    session:refreshScreens()
    offerOpen(session, T(_("Saved %1 (%2) in %3.\n\nProgress in this EPUB stays on this device; it does not sync with Novarr."),
        name, Model.formatBytes(got), folder), path)
end

--- Removes a downloaded EPUB and its KOReader data.
function Epub.remove(session, novel_id)
    local epubs = session.store:getEpubs()
    local e = epubs[tostring(novel_id)]
    if not e then return false end
    if e.path == session:currentPath() then return false end
    Session.purgeSidecar(e.path)
    os.remove(e.path)
    epubs[tostring(novel_id)] = nil
    session.store:saveEpubs()
    return true
end

return Epub
