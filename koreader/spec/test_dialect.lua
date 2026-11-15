-- Guards the Lua 5.1 / LuaJIT dialect: every plugin file must compile under
-- the running interpreter (KOReader's LuaJIT when run via `make test`) and
-- must not use 5.2+ only syntax or library functions.
local T, plugin_dir = ...
local lfs = require("libkoreader-lfs")

local files = {}
for entry in lfs.dir(plugin_dir) do
    if entry:match("%.lua$") then table.insert(files, entry) end
end
table.sort(files)

local forbidden = {
    { "//", "integer division operator" },
    { "goto%s", "goto" },
    { "::[%a_]+::", "goto label" },
    { "table%.unpack", "table.unpack (5.2+)" },
    { "table%.move", "table.move (5.3+)" },
    { "utf8%.", "utf8 library (5.3+)" },
    { "math%.tointeger", "math.tointeger (5.3+)" },
    { "string%.pack", "string.pack (5.3+)" },
    { "<const>", "attribs (5.4+)" },
    { "<close>", "attribs (5.4+)" },
    { "[^%w_]setglobal", "globals" },
}

-- Strips comments and string literals well enough for the checks above.
local function code_only(src)
    src = src:gsub("%-%-%[(=*)%[.-%]%1%]", "")
    src = src:gsub("%[(=*)%[.-%]%1%]", '""')
    src = src:gsub("%-%-[^\n]*", "")
    src = src:gsub('"[^"\n]*"', '""')
    src = src:gsub("'[^'\n]*'", "''")
    return src
end

for _, f in ipairs(files) do
    T.it("compiles: " .. f, function()
        local fn, err = loadfile(plugin_dir .. "/" .. f)
        T.truthy(fn, err)
    end)
    T.it("5.1 dialect: " .. f, function()
        local fh = assert(io.open(plugin_dir .. "/" .. f, "rb"))
        local src = code_only(fh:read("*a"))
        fh:close()
        for _, rule in ipairs(forbidden) do
            if src:find(rule[1]) then
                error(f .. " uses " .. rule[2])
            end
        end
    end)
end

T.it("gettext's _ is never shadowed", function()
    for _i, f in ipairs(files) do
        local fh = assert(io.open(plugin_dir .. "/" .. f, "rb"))
        local src = code_only(fh:read("*a"))
        fh:close()
        if src:find('local _ = require%(""%)') or src:find("require%(\"gettext\"%)") then
            for _j, pat in ipairs({ "for%s+_%s*,", "function%s*%(%s*_%s*[,%)]", "local%s+_%s*,", "\n%s*local%s+_%s*=%s*[^r%s]" }) do
                if src:find(pat) then error(f .. " shadows _ (" .. pat .. ")") end
            end
        end
    end
end)

T.it("no accidental globals in plugin modules", function()
    -- Load each logic module in a sandbox that records global writes.
    for _, name in ipairs({ "json", "chapterdoc", "queue", "store", "model", "api", "sync" }) do
        local fn = assert(loadfile(plugin_dir .. "/" .. name .. ".lua"))
        local writes = {}
        local env = setmetatable({}, {
            __index = _G,
            __newindex = function(t, k, v) table.insert(writes, k) rawset(t, k, v) end,
        })
        setfenv(fn, env)
        fn()
        T.eq(writes, {}, name .. " wrote globals")
    end
end)

-- Every global a plugin file reads or writes must be a standard Lua 5.1 one
-- (catches typos and missing `local` declarations). Uses LuaJIT's bytecode
-- listing, so it only runs under LuaJIT (as `make test` does).
local ALLOWED = {}
for name in ([[assert collectgarbage debug error getmetatable io ipairs jit loadfile loadstring math
    next os package pairs pcall print rawget rawset require select setfenv setmetatable string table
    tonumber tostring type unpack xpcall _G G_reader_settings]]):gmatch("%S+") do ALLOWED[name] = true end

local ko = os.getenv("KOREADER_DIR")
if jit and ko then
    for _i, f in ipairs(files) do
        T.it("only standard globals: " .. f, function()
            local abs = plugin_dir .. "/" .. f
            if abs:sub(1, 1) ~= "/" then abs = (os.getenv("PWD") or ".") .. "/" .. abs end
            local p = assert(io.popen("cd '" .. ko .. "' && ./luajit -bl '" .. abs .. "' 2>&1"))
            local listing = p:read("*a")
            p:close()
            T.truthy(listing:find("KGC", 1, true) or listing:find("RET", 1, true), "no bytecode listing: " .. listing:sub(1, 200))
            for op, name in listing:gmatch("(G[GS]ET)%s+[^\n]-\"([^\"]+)\"") do
                if not ALLOWED[name] then error(f .. ": " .. op .. " " .. name) end
            end
        end)
    end
end
