--[[--
Small JSON wrapper around KOReader's bundled rapidjson.

* decode() never throws: it returns nil plus an error message on bad input.
* JSON null becomes nil (rapidjson's null sentinel is stripped), so the rest
  of the plugin can use plain `if x then` checks.
* array() marks a Lua table so that it encodes as [] even when empty.
]]

local rapidjson = require("rapidjson")

local NULL = rapidjson.null

local Json = {}

-- Encodes as JSON null (for the rare request that must say "no value").
Json.null = NULL

local function denull(value)
    if value == NULL then return nil end
    if type(value) ~= "table" then return value end
    for k, v in pairs(value) do
        if v == NULL then
            value[k] = nil
        elseif type(v) == "table" then
            denull(v)
        end
    end
    return value
end

--- Decodes a JSON string. Returns value or nil, error message.
function Json.decode(text)
    if type(text) ~= "string" or text == "" then
        return nil, "empty"
    end
    local ok, value, err = pcall(rapidjson.decode, text)
    if not ok then
        return nil, tostring(value)
    end
    if value == nil then
        return nil, err or "invalid JSON"
    end
    return denull(value)
end

--- Encodes a Lua value. Returns string or nil, error message.
function Json.encode(value)
    local ok, text = pcall(rapidjson.encode, value)
    if not ok or type(text) ~= "string" then
        return nil, tostring(text)
    end
    return text
end

--- Marks a table as a JSON array (so {} encodes as []).
function Json.array(t)
    return rapidjson.array(t or {})
end

return Json
