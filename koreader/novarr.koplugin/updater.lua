--[[--
"Update the plugin over Wi-Fi": pure logic and file work, no KOReader UI.

The Novarr server publishes a manifest of the plugin folder it ships
(GET /api/v1/koreader/plugin):
    { version = "1.2.0", build = "<sha256 hex>", size = n,
      files = { { path = "main.lua", size = n, sha256 = "<hex>" }, ... } }

The build id is sha256 of "<path>\n<sha256>\n" concatenated for every file in
path order (plain byte order), skipping dotfiles, "*.tmp" and "*~". The same
value is computed here over the installed folder: different = update.

Installing never touches the installed folder until everything is staged
and verified:
  1. the manifest is validated (safe relative paths, main.lua and _meta.lua
     present, at most 200 files and 5 MB);
  2. each file is written to <plugins>/novarr.koplugin.new/: copied from the
     installed folder when its sha256 already matches, else downloaded;
     size and sha256 of every staged file are checked;
  3. every staged .lua file is compiled (not run) with loadfile;
  4. swap: installed -> novarr.koplugin.old, staging -> novarr.koplugin
     (rolled back if the second rename fails), then .old is removed.
The staging and backup names do not end in ".koplugin", so KOReader's
plugin loader never loads a leftover one; they are removed at start-up.

Every function takes its dependencies explicitly (deps):
    lfs        LuaFileSystem
    sha256     function(bytes) -> lower-case hex
    fetch      function(path) -> bytes | nil, err     (download one file)
    rename     function(from, to) -> true | nil, err  (default os.rename)
    compile    function(path) -> chunk | nil, err     (default loadfile)
    log        function(text)                          (optional)
]]

local Updater = {}

Updater.MAX_FILES = 200
Updater.MAX_BYTES = 5 * 1024 * 1024
Updater.CHECK_INTERVAL_MS = 24 * 3600 * 1000
Updater.REQUIRED = { "main.lua", "_meta.lua" }

-- ---------------------------------------------------------------- names and paths

--- Whether a file or folder name is left out of a plugin (OS and editor junk).
function Updater.skipped(name)
    return name:sub(1, 1) == "." or name:sub(-4) == ".tmp" or name:sub(-1) == "~"
end

--[[--
Whether a manifest path is safe to write under the plugin folder: segments
of [A-Za-z0-9_.-] separated by single "/", none of them "." or "..", no
leading or trailing "/". Returns true or false, reason.
]]
function Updater.validPath(path)
    if type(path) ~= "string" or path == "" then return false, "empty" end
    if path:sub(1, 1) == "/" then return false, "absolute" end
    if path:sub(-1) == "/" then return false, "trailing slash" end
    if path:find("[^A-Za-z0-9_%.%-/]") then return false, "character" end
    for segment in (path .. "/"):gmatch("([^/]*)/") do
        if segment == "" then return false, "empty segment" end
        if segment == "." or segment == ".." then return false, "dot segment" end
    end
    return true
end

--- Staging and backup folders next to the plugin folder.
function Updater.siblings(plugin_dir)
    return plugin_dir .. ".new", plugin_dir .. ".old"
end

--- `version = "x.y.z"` from the text of a _meta.lua, or nil.
function Updater.parseVersion(text)
    if type(text) ~= "string" then return nil end
    return text:match("%f[%w_]version%s*=%s*\"([^\"]+)\"") or text:match("%f[%w_]version%s*=%s*'([^']+)'")
end

-- ---------------------------------------------------------------- files

local function readFile(path)
    local f = io.open(path, "rb")
    if not f then return nil end
    local data = f:read("*a")
    f:close()
    return data
end
Updater.readFile = readFile

local function writeFile(path, data)
    local f, err = io.open(path, "wb")
    if not f then return nil, err end
    local ok, werr = f:write(data)
    local cok, cerr = f:close()
    if not ok then return nil, werr end
    if not cok then return nil, cerr end
    return true
end

local function mode(lfs, path)
    -- symlinkattributes: a link is reported as a link, never followed.
    if lfs.symlinkattributes then return lfs.symlinkattributes(path, "mode") end
    return lfs.attributes(path, "mode")
end
Updater.mode = mode

--- Removes a file or a folder with everything in it (links are removed, not followed).
function Updater.removeTree(lfs, path)
    local m = mode(lfs, path)
    if m == nil then return true end
    if m == "directory" then
        for name in lfs.dir(path) do
            if name ~= "." and name ~= ".." then
                Updater.removeTree(lfs, path .. "/" .. name)
            end
        end
        local ok, err = lfs.rmdir(path)
        if not ok then return nil, err end
        return true
    end
    local ok, err = os.remove(path)
    if not ok then return nil, err end
    return true
end

local function mkdirs(lfs, root, rel)
    local acc = root
    for segment in rel:gmatch("[^/]+") do
        acc = acc .. "/" .. segment
        if lfs.attributes(acc, "mode") ~= "directory" then
            local ok, err = lfs.mkdir(acc)
            if not ok and lfs.attributes(acc, "mode") ~= "directory" then return nil, err end
        end
    end
    return true
end

--[[--
Relative paths of the regular files under dir (recursive, links skipped,
junk skipped), sorted in byte order.
]]
function Updater.listFiles(lfs, dir)
    local out = {}
    local function walk(abs, rel)
        for name in lfs.dir(abs) do
            if name ~= "." and name ~= ".." and not Updater.skipped(name) then
                local a = abs .. "/" .. name
                local r = rel == "" and name or (rel .. "/" .. name)
                local m = mode(lfs, a)
                if m == "directory" then
                    walk(a, r)
                elseif m == "file" then
                    table.insert(out, r)
                end
            end
        end
    end
    walk(dir, "")
    table.sort(out)
    return out
end

--- The build id of a list of {path, sha256} (any order; sorted here).
function Updater.buildId(files, sha256)
    local sorted = {}
    for _i, f in ipairs(files) do table.insert(sorted, f) end
    table.sort(sorted, function(a, b) return a.path < b.path end)
    local parts = {}
    for _i, f in ipairs(sorted) do
        table.insert(parts, f.path .. "\n" .. f.sha256 .. "\n")
    end
    return sha256(table.concat(parts))
end

--[[--
Manifest of an installed plugin folder, the same shape as the server's:
{ version, build, size, files = { {path, size, sha256} }, by_path }.
]]
function Updater.localManifest(deps, dir)
    local files, by_path, size = {}, {}, 0
    for _i, rel in ipairs(Updater.listFiles(deps.lfs, dir)) do
        local data = readFile(dir .. "/" .. rel) or ""
        local f = { path = rel, size = #data, sha256 = deps.sha256(data) }
        table.insert(files, f)
        by_path[rel] = f
        size = size + #data
    end
    return {
        version = Updater.parseVersion(readFile(dir .. "/_meta.lua")),
        build = Updater.buildId(files, deps.sha256),
        size = size,
        files = files,
        by_path = by_path,
    }
end

-- ---------------------------------------------------------------- decisions

--[[--
Checks a server manifest (the `plugin` object) before anything is written.
Returns true or false, reason (a short English phrase for the log).
]]
function Updater.validateManifest(m)
    if type(m) ~= "table" or type(m.files) ~= "table" then return false, "no file list" end
    if type(m.build) ~= "string" or not m.build:match("^%x+$") then return false, "no build id" end
    if #m.files == 0 then return false, "no files" end
    if #m.files > Updater.MAX_FILES then return false, "too many files" end
    local seen, total = {}, 0
    for _i, f in ipairs(m.files) do
        if type(f) ~= "table" then return false, "bad entry" end
        local ok, why = Updater.validPath(f.path)
        if not ok then return false, "unsafe path (" .. why .. "): " .. tostring(f.path) end
        if seen[f.path] then return false, "duplicate path: " .. f.path end
        seen[f.path] = true
        if type(f.size) ~= "number" or f.size < 0 or f.size ~= math.floor(f.size) then
            return false, "bad size: " .. f.path
        end
        if type(f.sha256) ~= "string" or not f.sha256:match("^" .. string.rep("%x", 64) .. "$") then
            return false, "bad checksum: " .. f.path
        end
        total = total + f.size
    end
    -- A file and a folder of the same name ("a" and "a/b") cannot both exist.
    for path in pairs(seen) do
        local prefix = path
        while true do
            prefix = prefix:match("^(.*)/[^/]*$")
            if not prefix then break end
            if seen[prefix] then return false, "file and folder share a name: " .. prefix end
        end
    end
    for _i, req in ipairs(Updater.REQUIRED) do
        if not seen[req] then return false, "missing " .. req end
    end
    if total > Updater.MAX_BYTES then return false, "too big" end
    return true
end

--- Whether the server's build differs from the installed one.
function Updater.updateNeeded(local_build, server_build)
    return type(server_build) == "string" and server_build ~= "" and server_build ~= local_build
end

--[[--
The automatic check runs at most once per interval, only when switched on
and already online. A last check "in the future" (the clock went back)
counts as due.
]]
function Updater.autoCheckDue(opts)
    if opts.enabled == false or not opts.connected then return false end
    local last = tonumber(opts.last_checked)
    if not last then return true end
    local now = opts.now
    if now < last then return true end
    return now - last >= (opts.interval or Updater.CHECK_INTERVAL_MS)
end

Updater.AUTO_IDLE_S = 30

--[[--
"Install plugin updates automatically" (off by default): whether to install
the flagged update now. opts:
    enabled        the setting
    flagged        build id the automatic check found (nil: none)
    installed      an update was already installed in this KOReader session
    linked         the plugin folder is a development link (never updated)
    attempt        {build, day} of the last automatic attempt (or nil)
    today          "YYYY-MM-DD"
    home_top       Novarr's Home is the screen on top (nothing over it)
    reader_open    a document is open in the reader
    connected      already online (Novarr never turns Wi-Fi on for this)
    idle_s         seconds since the last user action
    idle_needed    default AUTO_IDLE_S
Returns action, reason, wait:
    "install"
    "skip", reason            nothing to do in this Home visit (setting off,
                              nothing flagged, already tried today...)
    "wait", reason, seconds   not now: look again in `seconds`
At most one attempt per build per day: an attempt is recorded when it starts.
]]
function Updater.autoInstallDue(opts)
    if not opts.enabled then return "skip", "switched off" end
    if not opts.flagged then return "skip", "no update flagged" end
    if opts.installed then return "skip", "an update is already installed (restart pending)" end
    if opts.linked then return "skip", "linked development copy" end
    local a = opts.attempt
    if type(a) == "table" and a.build == opts.flagged and a.day == opts.today then
        return "skip", "already tried today"
    end
    if opts.reader_open then return "wait", "a book is open", 30 end
    if not opts.home_top then return "wait", "Home is not on top", 30 end
    if not opts.connected then return "wait", "offline", 60 end
    local need = opts.idle_needed or Updater.AUTO_IDLE_S
    local idle = tonumber(opts.idle_s) or 0
    if idle < need then return "wait", "recent user action", math.max(1, math.ceil(need - idle)) end
    return "install", "idle on Home"
end

--[[--
One plain sentence about an available update, e.g.
"Version 1.1.0 is installed. The server has a newer build (1.2.0)."
]]
function Updater.availableText(installed, offered, T, _)
    installed = installed or "?"
    if offered == nil or offered == installed then
        return T(_("Version %1 is installed. The server has a newer build of %1."), installed)
    end
    return T(_("Version %1 is installed. The server has a newer build (%2)."), installed, offered)
end

-- ---------------------------------------------------------------- install job

local Job = {}
Job.__index = Job

--[[--
A staged install, driven one file per step() so the UI can show progress and
cancel between files.
    job = Updater.newJob(deps, plugin_dir, manifest, installed)
    ok, code, detail = job:prepare()
    repeat state, code, detail = job:step() until state ~= "more"
    ok, code, detail = job:finish()          -- compile check + swap
    job:abort()                              -- removes the staging folder
`installed` is Updater.localManifest of plugin_dir (optional; files with the
same sha256 are copied from it instead of downloaded).
Error codes: invalid_manifest, storage, download, verify, compile, swap.
]]
function Updater.newJob(deps, plugin_dir, manifest, installed)
    local new_dir, old_dir = Updater.siblings(plugin_dir)
    return setmetatable({
        deps = deps,
        plugin_dir = plugin_dir,
        new_dir = new_dir,
        old_dir = old_dir,
        manifest = manifest,
        installed = installed,
        i = 0,
        total = type(manifest) == "table" and type(manifest.files) == "table" and #manifest.files or 0,
        downloaded = 0,
        reused = 0,
        log = deps.log or function() end,
    }, Job)
end

function Job:abort()
    Updater.removeTree(self.deps.lfs, self.new_dir)
end

function Job:fail(code, detail)
    self.log("Novarr: plugin update failed (" .. code .. "): " .. tostring(detail))
    self:abort()
    self.failed = code
    return nil, code, detail
end

function Job:prepare()
    local ok, why = Updater.validateManifest(self.manifest)
    if not ok then
        -- Nothing has been written yet: no clean-up needed (and none done,
        -- so a refused manifest touches nothing at all).
        self.log("Novarr: plugin update refused: " .. tostring(why))
        self.failed = "invalid_manifest"
        return nil, "invalid_manifest", why
    end
    local lfs = self.deps.lfs
    local rok, rerr = Updater.removeTree(lfs, self.new_dir)
    if not rok then return self:fail("storage", rerr) end
    local mok, merr = lfs.mkdir(self.new_dir)
    if not mok then return self:fail("storage", merr) end
    return true
end

--- Stages the next file: "more", "staged" (all done) or nil, code, detail.
function Job:step()
    if self.failed then return nil, self.failed end
    if self.i >= self.total then return "staged" end
    self.i = self.i + 1
    local f = self.manifest.files[self.i]
    local deps = self.deps
    local dir = f.path:match("^(.*)/[^/]*$")
    if dir then
        local ok, err = mkdirs(deps.lfs, self.new_dir, dir)
        if not ok then return self:fail("storage", err) end
    end
    local have = self.installed and self.installed.by_path and self.installed.by_path[f.path]
    local data, err
    if have and have.sha256 == f.sha256 then
        data = readFile(self.plugin_dir .. "/" .. f.path)
        if data then self.reused = self.reused + 1 end
    end
    if not data then
        data, err = deps.fetch(f.path)
        if not data then return self:fail("download", f.path .. ": " .. tostring(type(err) == "table" and (err.kind .. " " .. tostring(err.status or "")) or err)) end
        self.downloaded = self.downloaded + 1
    end
    if #data ~= f.size or deps.sha256(data) ~= f.sha256 then
        return self:fail("verify", f.path)
    end
    local target = self.new_dir .. "/" .. f.path
    local wok, werr = writeFile(target, data)
    if not wok then return self:fail("storage", werr) end
    -- Read back what is on the disk: that is what KOReader will load.
    local back = readFile(target)
    if not back or #back ~= f.size or deps.sha256(back) ~= f.sha256 then
        return self:fail("verify", f.path .. " (after writing)")
    end
    if self.i >= self.total then return "staged" end
    return "more"
end

--- Compile check of the staged .lua files, then the swap. Returns true or nil, code, detail.
function Job:finish()
    if self.failed then return nil, self.failed end
    if self.i < self.total then return self:fail("verify", "not every file is staged") end
    local compile = self.deps.compile or loadfile
    for _i, f in ipairs(self.manifest.files) do
        if f.path:sub(-4) == ".lua" then
            local chunk, err = compile(self.new_dir .. "/" .. f.path)
            if not chunk then return self:fail("compile", err) end
        end
    end
    local ok, code, detail = Updater.swap(self.deps, self.plugin_dir, self.new_dir, self.old_dir)
    if not ok then return self:fail(code, detail) end
    return true
end

Updater.Job = Job

--[[--
Replaces plugin_dir with new_dir, keeping the old one as old_dir until the
second rename has worked (put back if it hasn't). Returns true or nil, "swap", detail.
]]
function Updater.swap(deps, plugin_dir, new_dir, old_dir)
    local lfs = deps.lfs
    local rename = deps.rename or os.rename
    local log = deps.log or function() end
    Updater.removeTree(lfs, old_dir)
    local ok, err = rename(plugin_dir, old_dir)
    if not ok then return nil, "swap", "moving the installed plugin aside: " .. tostring(err) end
    ok, err = rename(new_dir, plugin_dir)
    if not ok then
        local back, berr = rename(old_dir, plugin_dir)
        if not back then
            log("Novarr: plugin update could not put the old plugin back: " .. tostring(berr))
        end
        return nil, "swap", "moving the new plugin in place: " .. tostring(err)
    end
    local rok, rerr = Updater.removeTree(lfs, old_dir)
    if not rok then log("Novarr: couldn't remove " .. old_dir .. ": " .. tostring(rerr)) end
    return true
end

--[[--
Start-up clean-up: removes a staging or backup folder an interrupted update
left next to the plugin (this code runs from plugin_dir, so the installed
plugin is there and complete). Returns the list of folders removed.
]]
function Updater.cleanupLeftovers(deps, plugin_dir)
    local lfs = deps.lfs
    local new_dir, old_dir = Updater.siblings(plugin_dir)
    local removed = {}
    for _i, dir in ipairs({ new_dir, old_dir }) do
        if mode(lfs, dir) ~= nil then
            if Updater.removeTree(lfs, dir) then table.insert(removed, dir) end
        end
    end
    return removed
end

return Updater
