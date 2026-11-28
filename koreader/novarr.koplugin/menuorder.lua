--[[--
Novarr first in KOReader's menu (setting menu_first, 1.15.0): the "novarr"
entry is put at the top of the first tab of the reader's menu (navi) and of
the file manager's (filemanager_settings), with a separator under it, by
editing KOReader's menu order tables (ui/elements/*_menu_order), which every
menu is sorted by when it is built. Off: the entry goes back to where its
sorting_hint puts it (Tools). Idempotent. Pure but for the require of the
order tables; place()/remove() take a table (unit tested).
]]

local MenuOrder = {}

MenuOrder.ID = "novarr"
-- KOReader's separator entry in an order table.
MenuOrder.SEPARATOR = "----------------------------"

-- order module => the tab Novarr goes first in.
MenuOrder.TABS = {
    ["ui/elements/reader_menu_order"] = "navi",
    ["ui/elements/filemanager_menu_order"] = "filemanager_settings",
}

--- Puts the entry (and a separator) first in tab; nothing when it is there already.
function MenuOrder.place(tab)
    if type(tab) ~= "table" or tab[1] == MenuOrder.ID then return end
    MenuOrder.remove(tab)
    table.insert(tab, 1, MenuOrder.ID)
    table.insert(tab, 2, MenuOrder.SEPARATOR)
end

--- Takes the entry (and the separator placed with it) out of tab.
function MenuOrder.remove(tab)
    if type(tab) ~= "table" then return end
    for i = #tab, 1, -1 do
        if tab[i] == MenuOrder.ID then
            table.remove(tab, i)
            if tab[i] == MenuOrder.SEPARATOR then table.remove(tab, i) end
        end
    end
end

--- Applies the setting to both menus' order tables (a menu built later follows it).
function MenuOrder.apply(first)
    for module, key in pairs(MenuOrder.TABS) do
        local ok, order = pcall(require, module)
        if ok and type(order) == "table" then
            if first then MenuOrder.place(order[key]) else MenuOrder.remove(order[key]) end
        end
    end
end

return MenuOrder
