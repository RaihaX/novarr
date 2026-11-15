--[[
Tiny test runner for the Novarr KOReader plugin's pure logic.

Usage (from koreader/):  <luajit> spec/run.lua
Environment:
  KOREADER_DIR  folder of a KOReader install (the one holding common/ and
                libs/); provides rapidjson and lfs, as on the device.
]]

local spec_dir = (arg and arg[0] or "spec/run.lua"):match("^(.*)/[^/]*$") or "."
local root = spec_dir .. "/.."
local plugin_dir = root .. "/novarr.koplugin"
local ko = os.getenv("KOREADER_DIR")
if not ko or ko == "" then
    io.stderr:write("KOREADER_DIR is not set (needed for rapidjson and lfs)\n")
    os.exit(2)
end

-- ko/?.lua: KOReader's ffi/ helpers (ffi/sha2 for the updater's checksums).
package.path = spec_dir .. "/?.lua;" .. ko .. "/common/?.lua;" .. ko .. "/?.lua;" .. package.path
package.cpath = ko .. "/common/?.so;" .. ko .. "/libs/?.so;" .. package.cpath

-- The plugin requires its own modules as "novarr.<name>" (main.lua sets this
-- up the same way through package.preload).
for _, name in ipairs({ "json", "chapterdoc", "queue", "store", "model", "api", "sync", "readinginfo", "epubflow", "covers", "updater", "groups" }) do
    package.preload["novarr." .. name] = function()
        return dofile(plugin_dir .. "/" .. name .. ".lua")
    end
end

local T = require("testlib")

local files = { "test_api", "test_chapterdoc", "test_queue", "test_store", "test_model", "test_sync", "test_readinginfo", "test_epubflow", "test_covers", "test_dialect", "test_updater", "test_groups" }
for _, f in ipairs(files) do
    T.file(f)
    local fn = assert(loadfile(spec_dir .. "/" .. f .. ".lua"))
    fn(T, plugin_dir)
end

os.exit(T.report() and 0 or 1)
