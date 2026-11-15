-- Minimal assertion helpers (no external test framework on the device or Mac).
local T = { passed = 0, failed = 0, failures = {}, current_file = "" }

function T.file(name)
    T.current_file = name
    io.write("\n" .. name .. "\n")
end

local function describe(v)
    if type(v) == "string" then return string.format("%q", v) end
    if type(v) ~= "table" then return tostring(v) end
    local parts = {}
    local n = 0
    for k, val in pairs(v) do
        n = n + 1
        if n > 12 then table.insert(parts, "...") break end
        table.insert(parts, tostring(k) .. "=" .. describe(val))
    end
    return "{" .. table.concat(parts, ", ") .. "}"
end
T.describe = describe

local function deepEqual(a, b)
    if a == b then return true end
    if type(a) ~= "table" or type(b) ~= "table" then return false end
    for k, v in pairs(a) do
        if not deepEqual(v, b[k]) then return false end
    end
    for k in pairs(b) do
        if a[k] == nil then return false end
    end
    return true
end
T.deepEqual = deepEqual

function T.it(name, fn)
    local ok, err = xpcall(fn, debug.traceback)
    if ok then
        T.passed = T.passed + 1
        io.write("  ok   " .. name .. "\n")
    else
        T.failed = T.failed + 1
        table.insert(T.failures, T.current_file .. ": " .. name .. "\n" .. tostring(err))
        io.write("  FAIL " .. name .. "\n")
    end
end

function T.eq(actual, expected, msg)
    if not deepEqual(actual, expected) then
        error((msg and (msg .. ": ") or "") .. "expected " .. describe(expected) .. ", got " .. describe(actual), 2)
    end
end

function T.truthy(v, msg)
    if not v then error((msg or "expected a truthy value") .. ", got " .. describe(v), 2) end
end

function T.falsy(v, msg)
    if v then error((msg or "expected a falsy value") .. ", got " .. describe(v), 2) end
end

function T.contains(haystack, needle, msg)
    if type(haystack) ~= "string" or not haystack:find(needle, 1, true) then
        error((msg and (msg .. ": ") or "") .. "expected to find " .. describe(needle) .. " in " .. describe(haystack), 2)
    end
end

function T.notContains(haystack, needle, msg)
    if type(haystack) == "string" and haystack:find(needle, 1, true) then
        error((msg and (msg .. ": ") or "") .. "did not expect " .. describe(needle) .. " in " .. describe(haystack), 2)
    end
end

-- Fresh temporary directory for store tests.
local counter = 0
function T.tmpdir()
    counter = counter + 1
    local base = os.getenv("NOVARR_TEST_TMP") or os.getenv("TMPDIR") or "/tmp"
    base = base:gsub("/+$", "")
    local dir = string.format("%s/novarr-spec-%d-%d", base, os.time(), counter)
    os.execute("rm -rf '" .. dir .. "' && mkdir -p '" .. dir .. "'")
    return dir
end

function T.report()
    io.write(string.format("\n%d passed, %d failed\n", T.passed, T.failed))
    for _, f in ipairs(T.failures) do
        io.write("\n" .. f .. "\n")
    end
    return T.failed == 0
end

return T
