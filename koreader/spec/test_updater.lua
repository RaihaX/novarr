local T, plugin_dir = ...
local Updater = require("novarr.updater")
local Api = require("novarr.api")
local lfs = require("libkoreader-lfs")
local sha256 = require("ffi/sha2").sha256

-- Build id of the shared fixture (_meta.lua = "a", main.lua = "b",
-- sub/x.lua = "c"); tests/Feature/ApiV1KoreaderPluginTest.php asserts the same value.
local FIXTURE_BUILD = "538de10c3947b2654ade1d18b8bb73b2ecbb675c67da2dcb4f72b01571e7b47c"

local function write(path, data)
    local dir = path:match("^(.*)/[^/]*$")
    os.execute("mkdir -p '" .. dir .. "'")
    local f = assert(io.open(path, "wb"))
    f:write(data)
    f:close()
end

local function read(path)
    local f = io.open(path, "rb")
    if not f then return nil end
    local d = f:read("*a")
    f:close()
    return d
end

local function exists(path)
    return lfs.attributes(path, "mode") ~= nil
end

--- A plugin folder with these files ({path = bytes}) under a fresh "plugins" dir.
local function plugin(files)
    local plugins = T.tmpdir() .. "/plugins"
    local dir = plugins .. "/novarr.koplugin"
    os.execute("mkdir -p '" .. dir .. "'")
    for path, data in pairs(files) do write(dir .. "/" .. path, data) end
    return dir, plugins
end

--- A server manifest for these files.
local function manifest(files, version)
    local list = {}
    for path, data in pairs(files) do
        table.insert(list, { path = path, size = #data, sha256 = sha256(data) })
    end
    table.sort(list, function(a, b) return a.path < b.path end)
    return { version = version or "1.2.0", build = Updater.buildId(list, sha256), size = 0, files = list }
end

--- Snapshot of every file under dir: {relative path = bytes}.
local function snapshot(dir)
    local out = {}
    local p = io.popen("cd '" .. dir .. "' && find . -type f | sort")
    for line in p:lines() do
        local rel = line:gsub("^%./", "")
        out[rel] = read(dir .. "/" .. rel)
    end
    p:close()
    return out
end

local function deps(server_files, opts)
    opts = opts or {}
    local fetched = {}
    return {
        lfs = lfs,
        sha256 = sha256,
        fetch = function(path)
            table.insert(fetched, path)
            if opts.fail_on == path then return nil, { kind = "offline" } end
            if opts.wrong == path then return server_files[path] .. "x" end
            return server_files[path]
        end,
        rename = opts.rename,
        compile = opts.compile,
    }, fetched
end

local function runJob(job)
    local ok, code = job:prepare()
    if not ok then return nil, code end
    while true do
        local state, scode = job:step()
        if not state then return nil, scode end
        if state == "staged" then break end
    end
    return job:finish()
end

local OLD = { ["_meta.lua"] = 'return { version = "1.1.0" }', ["main.lua"] = "return 1", ["api.lua"] = "return {}" }
local NEW = { ["_meta.lua"] = 'return { version = "1.2.0" }', ["main.lua"] = "return 1", ["api.lua"] = "return { new = true }",
    ["ui/extra.lua"] = "return 'extra'" }

-- ---------------------------------------------------------------- paths

T.it("validPath accepts plain relative paths", function()
    for _, p in ipairs({ "main.lua", "_meta.lua", "sub/x.lua", "a/b/c-d_e.f.lua", "README", "x..y" }) do
        T.truthy(Updater.validPath(p), p)
    end
end)

T.it("validPath refuses every unsafe kind", function()
    local bad = {
        { "", "empty" }, { nil, "empty" }, { "/etc/passwd", "absolute" }, { "sub/", "trailing slash" },
        { "../x.lua", "dot segment" }, { "sub/../../x", "dot segment" }, { "./main.lua", "dot segment" },
        { "a//b", "empty segment" }, { "a\\b", "character" }, { "a b", "character" }, { "a%2F..", "character" },
        { "ma\0in", "character" }, { "caf\195\169.lua", "character" }, { "..", "dot segment" },
    }
    for _, c in ipairs(bad) do
        local ok, why = Updater.validPath(c[1])
        T.falsy(ok, tostring(c[1]))
        T.eq(why, c[2], tostring(c[1]))
    end
end)

T.it("validateManifest refuses unsafe, incomplete or oversized manifests", function()
    local good = manifest(NEW)
    T.truthy(Updater.validateManifest(good))
    local function with(fn)
        local m = manifest(NEW)
        fn(m)
        return Updater.validateManifest(m)
    end
    T.falsy(with(function(m) m.files[1].path = "../evil.lua" end))
    T.falsy(with(function(m) m.files[1].path = "/abs.lua" end))
    T.falsy(with(function(m) table.insert(m.files, { path = m.files[1].path, size = 1, sha256 = m.files[1].sha256 }) end), "duplicate")
    T.falsy(with(function(m) for i, f in ipairs(m.files) do if f.path == "main.lua" then table.remove(m.files, i) end end end), "no main.lua")
    T.falsy(with(function(m) for i, f in ipairs(m.files) do if f.path == "_meta.lua" then table.remove(m.files, i) end end end), "no _meta.lua")
    T.falsy(with(function(m) m.files[1].sha256 = "abc" end), "short checksum")
    T.falsy(with(function(m) m.files[1].size = -1 end), "negative size")
    T.falsy(with(function(m) m.files[1].size = Updater.MAX_BYTES + 1 end), "too big")
    T.falsy(with(function(m) m.build = nil end), "no build")
    T.falsy(with(function(m) table.insert(m.files, { path = "ui", size = 0, sha256 = string.rep("0", 64) }) end), "file and folder")
    T.falsy(with(function(m)
        for i = 1, Updater.MAX_FILES do table.insert(m.files, { path = "f" .. i, size = 0, sha256 = string.rep("0", 64) }) end
    end), "too many files")
    T.falsy(Updater.validateManifest(nil))
    T.falsy(Updater.validateManifest({ build = "ab", files = {} }))
end)

-- ---------------------------------------------------------------- build id

T.it("build id matches the vector shared with the PHP tests", function()
    local dir = plugin({ ["_meta.lua"] = "a", ["main.lua"] = "b", ["sub/x.lua"] = "c" })
    local m = Updater.localManifest({ lfs = lfs, sha256 = sha256 }, dir)
    T.eq(m.build, FIXTURE_BUILD)
    T.eq(#m.files, 3)
    T.eq(m.files[3].path, "sub/x.lua")
    T.eq(m.size, 3)
end)

T.it("local manifest skips junk, sorts in byte order and reads the version", function()
    local dir = plugin({ ["_meta.lua"] = 'version = "1.1.0"', ["main.lua"] = "b", ["Zeta.lua"] = "z",
        [".DS_Store"] = "x", ["._main.lua"] = "x", ["store.lua.tmp"] = "x", ["main.lua~"] = "x", ["sub/.hidden"] = "x" })
    local m = Updater.localManifest({ lfs = lfs, sha256 = sha256 }, dir)
    local paths = {}
    for _, f in ipairs(m.files) do table.insert(paths, f.path) end
    T.eq(paths, { "Zeta.lua", "_meta.lua", "main.lua" })
    T.eq(m.version, "1.1.0")
end)

T.it("parseVersion reads _meta.lua and the plugin's own _meta.lua", function()
    T.eq(Updater.parseVersion('return {\n    version = "1.2.3",\n}'), "1.2.3")
    T.eq(Updater.parseVersion("version='2.0'"), "2.0")
    T.eq(Updater.parseVersion("subversion = \"9\""), nil)
    T.eq(Updater.parseVersion(nil), nil)
    T.truthy((Updater.parseVersion(read(plugin_dir .. "/_meta.lua")) or ""):match("^%d+%.%d+%.%d+$"), "the plugin's own version")
end)

-- ---------------------------------------------------------------- decisions

T.it("update needed only when the server build differs", function()
    T.truthy(Updater.updateNeeded("aa", "bb"))
    T.falsy(Updater.updateNeeded("aa", "aa"))
    T.falsy(Updater.updateNeeded("aa", nil))
    T.falsy(Updater.updateNeeded("aa", ""))
end)

T.it("available text names both versions, or 'a newer build of' when equal", function()
    local tpl = function(s, a, b) return (s:gsub("%%1", tostring(a)):gsub("%%2", tostring(b))) end
    local id = function(s) return s end
    T.eq(Updater.availableText("1.1.0", "1.2.0", tpl, id), "Version 1.1.0 is installed. The server has a newer build (1.2.0).")
    T.eq(Updater.availableText("1.1.0", "1.1.0", tpl, id), "Version 1.1.0 is installed. The server has a newer build of 1.1.0.")
end)

T.it("automatic check at most once a day, only online, only when on", function()
    local day = Updater.CHECK_INTERVAL_MS
    T.truthy(Updater.autoCheckDue({ connected = true, now = 1000 }))
    T.falsy(Updater.autoCheckDue({ connected = false, now = 1000 }), "offline")
    T.falsy(Updater.autoCheckDue({ connected = true, enabled = false, now = 1000 }), "switched off")
    T.falsy(Updater.autoCheckDue({ connected = true, last_checked = 1000, now = 1000 + day - 1 }), "within 24 h")
    T.truthy(Updater.autoCheckDue({ connected = true, last_checked = 1000, now = 1000 + day }), "after 24 h")
    T.truthy(Updater.autoCheckDue({ connected = true, last_checked = 5000, now = 1000 }), "clock went back")
end)

-- ---------------------------------------------------------------- install

T.it("installs: unchanged files are copied, changed ones downloaded, folder replaced", function()
    local dir, plugins = plugin(OLD)
    local m = manifest(NEW)
    local d, fetched = deps(NEW)
    local installed = Updater.localManifest(d, dir)
    local job = Updater.newJob(d, dir, m, installed)
    T.truthy(runJob(job))
    T.eq(snapshot(dir), NEW)
    table.sort(fetched)
    T.eq(fetched, { "_meta.lua", "api.lua", "ui/extra.lua" }, "main.lua is unchanged: not downloaded")
    T.eq(job.reused, 1)
    T.eq(job.downloaded, 3)
    T.falsy(exists(plugins .. "/novarr.koplugin.new"), "no staging left")
    T.falsy(exists(plugins .. "/novarr.koplugin.old"), "no backup left")
    T.eq(Updater.localManifest(d, dir).build, m.build)
end)

local function unchangedAfter(opts, expect_code)
    local dir, plugins = plugin(OLD)
    local before = snapshot(dir)
    local d = deps(NEW, opts)
    local job = Updater.newJob(d, dir, manifest(NEW), Updater.localManifest(d, dir))
    local ok, code = runJob(job)
    T.falsy(ok)
    T.eq(code, expect_code)
    T.eq(snapshot(dir), before, "installed plugin untouched")
    T.falsy(exists(plugins .. "/novarr.koplugin.new"), "staging removed")
    T.falsy(exists(plugins .. "/novarr.koplugin.old"), "no backup")
end

T.it("a download failure half-way changes nothing", function()
    unchangedAfter({ fail_on = "api.lua" }, "download")
end)

T.it("a checksum mismatch changes nothing", function()
    unchangedAfter({ wrong = "ui/extra.lua" }, "verify")
end)

T.it("a truncated .lua file (matching its manifest) is caught by the compile check", function()
    local dir, plugins = plugin(OLD)
    local before = snapshot(dir)
    local broken = { ["_meta.lua"] = NEW["_meta.lua"], ["main.lua"] = "return 1", ["api.lua"] = "local x = {\n  a = " }
    local d = deps(broken)
    local job = Updater.newJob(d, dir, manifest(broken), Updater.localManifest(d, dir))
    local ok, code = runJob(job)
    T.falsy(ok)
    T.eq(code, "compile")
    T.eq(snapshot(dir), before)
    T.falsy(exists(plugins .. "/novarr.koplugin.new"))
end)

T.it("a refused manifest writes nothing at all", function()
    local dir, plugins = plugin(OLD)
    local before = snapshot(dir)
    local m = manifest(NEW)
    m.files[1].path = "../../escape.lua"
    local d, fetched = deps(NEW)
    local job = Updater.newJob(d, dir, m, nil)
    local ok, code = job:prepare()
    T.falsy(ok)
    T.eq(code, "invalid_manifest")
    T.eq(#fetched, 0)
    T.eq(snapshot(dir), before)
    T.falsy(exists(plugins .. "/novarr.koplugin.new"))
    T.falsy(exists(plugins .. "/escape.lua"))
    T.falsy(exists(plugins .. "/../escape.lua"))
end)

T.it("a leftover staging folder is wiped before staging", function()
    local dir, plugins = plugin(OLD)
    write(plugins .. "/novarr.koplugin.new/stale.lua", "stale")
    local d = deps(NEW)
    T.truthy(runJob(Updater.newJob(d, dir, manifest(NEW), Updater.localManifest(d, dir))))
    T.eq(snapshot(dir), NEW, "no stale file carried over")
end)

T.it("swap rolls back when the second rename fails", function()
    local dir, plugins = plugin(OLD)
    local before = snapshot(dir)
    local calls = 0
    local rename = function(a, b)
        calls = calls + 1
        if calls == 2 then return nil, "simulated failure" end
        return os.rename(a, b)
    end
    local d = deps(NEW, { rename = rename })
    local ok, code = runJob(Updater.newJob(d, dir, manifest(NEW), Updater.localManifest(d, dir)))
    T.falsy(ok)
    T.eq(code, "swap")
    T.eq(calls, 3, "installed -> .old, (fails), .old -> installed")
    T.eq(snapshot(dir), before, "old plugin back in place")
    T.falsy(exists(plugins .. "/novarr.koplugin.new"))
    T.falsy(exists(plugins .. "/novarr.koplugin.old"))
end)

T.it("swap fails cleanly when the installed folder can't be moved", function()
    local dir = plugin(OLD)
    local before = snapshot(dir)
    local d = deps(NEW, { rename = function() return nil, "busy" end })
    local ok, code = runJob(Updater.newJob(d, dir, manifest(NEW), Updater.localManifest(d, dir)))
    T.falsy(ok)
    T.eq(code, "swap")
    T.eq(snapshot(dir), before)
end)

T.it("cancel (abort) after some files removes the staging folder", function()
    local dir, plugins = plugin(OLD)
    local before = snapshot(dir)
    local d = deps(NEW)
    local job = Updater.newJob(d, dir, manifest(NEW), Updater.localManifest(d, dir))
    T.truthy(job:prepare())
    T.eq(job:step(), "more")
    T.truthy(exists(plugins .. "/novarr.koplugin.new"))
    job:abort()
    T.falsy(exists(plugins .. "/novarr.koplugin.new"))
    T.eq(snapshot(dir), before)
end)

T.it("start-up clean-up removes leftover .new and .old folders only", function()
    local dir, plugins = plugin(OLD)
    write(plugins .. "/novarr.koplugin.new/main.lua", "x")
    write(plugins .. "/novarr.koplugin.old/sub/main.lua", "x")
    write(plugins .. "/other.koplugin/main.lua", "keep")
    local removed = Updater.cleanupLeftovers({ lfs = lfs }, dir)
    T.eq(#removed, 2)
    T.falsy(exists(plugins .. "/novarr.koplugin.new"))
    T.falsy(exists(plugins .. "/novarr.koplugin.old"))
    T.eq(read(plugins .. "/other.koplugin/main.lua"), "keep")
    T.eq(snapshot(dir), OLD)
    T.eq(#Updater.cleanupLeftovers({ lfs = lfs }, dir), 0, "nothing the second time")
end)

T.it("staging and backup names are not loaded by KOReader's plugin loader", function()
    local new_dir, old_dir = Updater.siblings("/x/plugins/novarr.koplugin")
    -- pluginloader.lua: a plugin directory is one whose name ends in ".koplugin".
    T.falsy(new_dir:sub(-9) == ".koplugin")
    T.falsy(old_dir:sub(-9) == ".koplugin")
end)

T.it("removeTree does not follow links", function()
    local base = T.tmpdir()
    write(base .. "/outside/keep.txt", "keep")
    os.execute("mkdir -p '" .. base .. "/victim' && ln -s '" .. base .. "/outside' '" .. base .. "/victim/link'")
    T.truthy(Updater.removeTree(lfs, base .. "/victim"))
    T.falsy(exists(base .. "/victim"))
    T.eq(read(base .. "/outside/keep.txt"), "keep")
end)

-- ---------------------------------------------------------------- API

local function fakeTransport(answers)
    local seen = {}
    return function(req)
        table.insert(seen, req)
        local a = answers[req.url]
        if not a then return { status = 404, headers = {}, body = '{"message":"Not Found"}' } end
        return a
    end, seen
end

T.it("api: plugin manifest, 404 and raw file download", function()
    local transport, seen = fakeTransport({
        ["http://h/api/v1/koreader/plugin"] = { status = 200, headers = {}, body = '{"plugin":{"version":"1.2.0","build":"ab","size":1,"files":[]}}' },
        ["http://h/api/v1/koreader/plugin/files/sub/x.lua"] = { status = 200, headers = {}, body = "\0raw\r\n" },
        ["http://h/api/v1/koreader/plugin/files/boom.lua"] = { status = 500, headers = {}, body = "" },
    })
    local api = Api.new({ base_url = "http://h", transport = transport })
    local m = api:pluginManifest()
    T.eq(m.version, "1.2.0")
    T.eq(api:pluginFile("sub/x.lua"), "\0raw\r\n")
    T.eq(seen[2].headers["Accept"], "application/octet-stream")
    local _, e = api:pluginFile("nope.lua")
    T.eq(e.kind, "not_found")
    _, e = api:pluginFile("boom.lua")
    T.eq(e.kind, "server")
    local old = Api.new({ base_url = "http://old", transport = fakeTransport({}) })
    _, e = old:pluginManifest()
    T.eq(e.kind, "not_found", "a server without the endpoint")
    Api.simulate_offline = true
    _, e = api:pluginFile("sub/x.lua")
    Api.simulate_offline = false
    T.eq(e.kind, "offline")
end)

-- ---------------------------------------------------------------- automatic install

local function due(over)
    local o = { enabled = true, flagged = "b2", installed = false, linked = false, attempt = nil, today = "2026-11-16",
        home_top = true, reader_open = false, connected = true, idle_s = 45 }
    for k, v in pairs(over or {}) do o[k] = v end
    return { Updater.autoInstallDue(o) }
end

T.it("autoInstallDue: installs when on, flagged, Home on top, online, idle 30 s", function()
    T.eq(due(), { "install", "idle on Home" })
end)

T.it("autoInstallDue: never when off, nothing flagged, already installed or linked", function()
    T.eq(due({ enabled = false })[1], "skip")
    T.eq(Updater.autoInstallDue({ flagged = "b2", home_top = true, connected = true, idle_s = 99 }), "skip", "off by default (nil)")
    T.eq(due({ flagged = false })[1], "skip")
    T.eq(due({ installed = true })[1], "skip")
    T.eq(due({ linked = true })[1], "skip")
end)

T.it("autoInstallDue: one attempt per build per day", function()
    T.eq(due({ attempt = { build = "b2", day = "2026-11-16" } }), { "skip", "already tried today" })
    T.eq(due({ attempt = { build = "b2", day = "2026-11-15" } })[1], "install", "the next day")
    T.eq(due({ attempt = { build = "b1", day = "2026-11-16" } })[1], "install", "another build")
end)

T.it("autoInstallDue: waits while reading, off Home, offline (never switches Wi-Fi on), or right after a user action", function()
    T.eq(due({ reader_open = true }), { "wait", "a book is open", 30 })
    T.eq(due({ home_top = false }), { "wait", "Home is not on top", 30 })
    T.eq(due({ connected = false }), { "wait", "offline", 60 })
    T.eq(due({ idle_s = 12 }), { "wait", "recent user action", 18 })
    T.eq(due({ idle_s = 29.5 }), { "wait", "recent user action", 1 })
    T.eq(due({ idle_s = 5, idle_needed = 4 })[1], "install")
end)
