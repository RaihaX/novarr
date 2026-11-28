local T = ...
local MenuOrder = require("novarr.menuorder")

local SEP = MenuOrder.SEPARATOR

T.it("menu order: Novarr first with a separator, once", function()
    local tab = { "table_of_contents", "bookmarks", SEP, "go_to" }
    MenuOrder.place(tab)
    T.eq(tab, { "novarr", SEP, "table_of_contents", "bookmarks", SEP, "go_to" })
    MenuOrder.place(tab)
    T.eq(tab, { "novarr", SEP, "table_of_contents", "bookmarks", SEP, "go_to" }, "idempotent")
end)

T.it("menu order: off puts the tab back as it was", function()
    local tab = { "filemanager_display_mode", "start_with" }
    MenuOrder.place(tab)
    MenuOrder.remove(tab)
    T.eq(tab, { "filemanager_display_mode", "start_with" })
    MenuOrder.remove(tab)
    T.eq(tab, { "filemanager_display_mode", "start_with" }, "nothing to remove")
end)

T.it("menu order: an entry placed elsewhere moves to the top", function()
    local tab = { "a", "novarr", "b" }
    MenuOrder.place(tab)
    T.eq(tab, { "novarr", SEP, "a", "b" })
end)

T.it("menu order: no tab is no error", function()
    MenuOrder.place(nil)
    MenuOrder.remove(nil)
    T.eq(true, true)
end)
