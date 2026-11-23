--[[--
The quick menu shown while reading a Novarr chapter (hold the status bar, or
the "Novarr: quick menu" gesture): its title and its rows of buttons. Pure
Lua (unit tested); ui_quickmenu.lua turns them into a KOReader ButtonDialog.
]]

-- gettext inside KOReader; identity in the unit tests.
local has_gettext, gettext = pcall(require, "gettext")
local _ = has_gettext and gettext or function(text) return text end

local QuickMenu = {}

--- "Novel name\nChapter label\nstatus", missing parts left out (nil when all are).
function QuickMenu.title(state)
    state = state or {}
    local lines = {}
    for _i, key in ipairs({ "novel_name", "chapter_label", "status" }) do
        local v = state[key]
        if type(v) == "string" and v ~= "" then table.insert(lines, v) end
    end
    if #lines == 0 then return nil end
    return table.concat(lines, "\n")
end

--[[--
The buttons, as rows of {id, text, enabled}. state = {has_prev, has_next,
pending (writes waiting), checking (a check for new chapters is running),
can_check (the account may ask for one)}.
]]
function QuickMenu.rows(state)
    state = state or {}
    local pending = tonumber(state.pending) or 0
    local sync_text = pending > 0 and string.format(_("Sync now (%d)"), pending) or _("Sync now")
    local check
    if state.checking then
        check = { id = "check", text = _("Checking…"), enabled = false }
    else
        check = { id = "check", text = _("Check for new chapters"), enabled = state.can_check ~= false }
    end
    return {
        { { id = "chapters", text = _("Chapter list"), enabled = true },
          { id = "novel", text = _("Novel page"), enabled = true } },
        { { id = "prev", text = _("Previous chapter"), enabled = state.has_prev and true or false },
          { id = "next", text = _("Next chapter"), enabled = state.has_next and true or false } },
        { { id = "sync", text = sync_text, enabled = true }, check },
        { { id = "home", text = _("Novarr home"), enabled = true },
          { id = "close", text = _("Close"), enabled = true } },
    }
end

return QuickMenu
