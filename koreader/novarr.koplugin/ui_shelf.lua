--[[--
Novarr Home as a Kindle-like cover shelf, built from KOReader's stock
widgets (TitleBar, ImageWidget, ProgressWidget, Button, groups).

    title bar
    [offline note: one small line]
    page 1: hero block (large cover; title, author, chapter, left, progress bar)
    grid of covers, 3 columns, as many rows as fit (title on up to two lines
        over the cell width + thin progress bar, a black badge with the
        number of new chapters)
    ‹ 2 / 5 ›           (only with more than one page)
    Library · Downloads · Sync · Settings   (always visible)

On page 2 and later the hero is not shown and the grid uses the full
height. With covers off, hero and grid become plain text rows.

It speaks the same small protocol as KOReader's Menu (item_table, page,
onMenuSelect/onMenuHold, onNextPage/onPrevPage, switchItemTable, onClose,
pageItemIndex), so UI.showMenu, the session and the autopilot drive it.

Item kinds (item.kind): "hero", "cell", "row", "button", "note".
Covers: only the current page has image widgets; they are freed on every
page change and on close. Missing covers are fetched one per scheduled
step when already connected, then the page is repainted once.
]]

local Blitbuffer = require("ffi/blitbuffer")
local Button = require("ui/widget/button")
local CenterContainer = require("ui/widget/container/centercontainer")
local Device = require("device")
local Font = require("ui/font")
local FrameContainer = require("ui/widget/container/framecontainer")
local Geom = require("ui/geometry")
local GestureRange = require("ui/gesturerange")
local HorizontalGroup = require("ui/widget/horizontalgroup")
local HorizontalSpan = require("ui/widget/horizontalspan")
local ImageWidget = require("ui/widget/imagewidget")
local InputContainer = require("ui/widget/container/inputcontainer")
local LeftContainer = require("ui/widget/container/leftcontainer")
local LineWidget = require("ui/widget/linewidget")
local OverlapGroup = require("ui/widget/overlapgroup")
local RightContainer = require("ui/widget/container/rightcontainer")
local Size = require("ui/size")
local TextBoxWidget = require("ui/widget/textboxwidget")
local TextWidget = require("ui/widget/textwidget")
local TitleBar = require("ui/widget/titlebar")
local UIManager = require("ui/uimanager")
local VerticalGroup = require("ui/widget/verticalgroup")
local VerticalSpan = require("ui/widget/verticalspan")
local _ = require("gettext")
local T = require("ffi/util").template
local Screen = Device.screen

local Covers = require("novarr.covers")
local Model = require("novarr.model")

-- A tappable area (tap = select, hold = hold).
local Tap = InputContainer:extend{ item = nil, shelf = nil, width = nil, height = nil }

function Tap:init()
    self.dimen = Geom:new{ x = 0, y = 0, w = self.width, h = self.height }
    self.ges_events = {
        TapSelect = { GestureRange:new{ ges = "tap", range = function() return self.dimen end } },
        HoldSelect = { GestureRange:new{ ges = "hold", range = function() return self.dimen end } },
    }
    self[1] = self.content
end

function Tap:onTapSelect()
    if self.item.select_enabled == false then return true end
    self.shelf:onMenuSelect(self.item)
    return true
end

function Tap:onHoldSelect()
    self.shelf:onMenuHold(self.item)
    return true
end

local Shelf = InputContainer:extend{
    name = "novarr_menu",
    title = "",
    item_table = nil,
    covers = nil,
    can_fetch = nil,
    fetch_cover = nil,
    covers_fullscreen = true,
}

function Shelf:init()
    self.item_table = self.item_table or {}
    self.page = 1
    self.image_widgets = {}
    self.dimen = Geom:new{ x = 0, y = 0, w = Screen:getWidth(), h = Screen:getHeight() }
    local dpi = Screen:getDPI()
    self.pad = Size.padding.default
    self.gap = math.max(self.pad, math.floor(dpi * 0.06))
    self.hero_h = math.floor(dpi * 1.25)
    self.grid_cover_h = math.floor(dpi * 0.95)
    self.text_row_h = math.floor(dpi * 0.32)
    self.title_face = Font:getFace("cfont", 22)
    self.cell_face = Font:getFace("cfont", 15)
    self.small_face = Font:getFace("cfont", 17)
    self.note_face = Font:getFace("cfont", 14)
    self.badge_face = Font:getFace("cfont", 14)
    self.title_bar = TitleBar:new{
        width = self.dimen.w,
        fullscreen = "true",
        align = "center",
        title = self.title,
        close_callback = function() self:onClose() end,
        show_parent = self,
    }
    self.ges_events = {
        Swipe = { GestureRange:new{ ges = "swipe", range = self.dimen } },
    }
    if Device:hasKeys() then
        self.key_events = {
            NextPage = { { Device.input.group.PgFwd } },
            PrevPage = { { Device.input.group.PgBack } },
            Close = { { Device.input.group.Back } },
        }
    end
    self.fetch_task = function() self:fetchStep() end
    self:updateItems()
end

-- ---------------------------------------------------------------- layout

function Shelf:split()
    local hero, grid, buttons, note = nil, {}, {}, nil
    for i, item in ipairs(self.item_table) do
        if item.kind == "hero" then hero = i
        elseif item.kind == "button" then table.insert(buttons, i)
        elseif item.kind == "note" then note = i
        else table.insert(grid, i) end
    end
    return hero, grid, buttons, note
end

function Shelf:freeImages()
    for _i, w in ipairs(self.image_widgets) do pcall(w.free, w) end
    self.image_widgets = {}
end

function Shelf:image(url, w, h)
    local path = self.covers and url and self.covers:cached(url)
    if not path then return nil end
    local ok, img = pcall(function()
        local widget = ImageWidget:new{ file = path, file_do_cache = false, width = w, height = h, scale_factor = 0 }
        widget:_render()
        return widget
    end)
    if not ok or not img then return nil end
    table.insert(self.image_widgets, img)
    return img
end

-- Bordered cover box (image or initials) with an optional new-chapters badge.
function Shelf:coverBox(url, name, w, h, badge)
    local iw, ih = w - 2 * Size.border.thin, h - 2 * Size.border.thin
    local img = self:image(url, iw, ih)
    local inner = CenterContainer:new{
        dimen = Geom:new{ w = iw, h = ih },
        img or TextWidget:new{ text = Covers.initials(name), face = Font:getFace("tfont", math.max(14, math.floor(w / 4))) },
    }
    local box = FrameContainer:new{
        bordersize = Size.border.thin, padding = 0, margin = 0,
        background = Blitbuffer.COLOR_WHITE,
        inner,
    }
    if not badge or badge <= 0 then return box end
    -- Black box, white bold number: legible on e-ink.
    local label = TextWidget:new{ text = tostring(badge), face = self.badge_face, bold = true, fgcolor = Blitbuffer.COLOR_WHITE }
    local mark = FrameContainer:new{
        background = Blitbuffer.COLOR_BLACK,
        bordersize = Size.border.thin,
        color = Blitbuffer.COLOR_WHITE,
        padding = math.floor(self.pad / 2),
        padding_left = self.pad,
        padding_right = self.pad,
        margin = 0,
        label,
        overlap_align = "right",
    }
    return OverlapGroup:new{ dimen = Geom:new{ w = w, h = h }, box, mark }
end

local function bar(width, fraction)
    local ok, ProgressWidget = pcall(require, "ui/widget/progresswidget")
    if not ok then return VerticalSpan:new{ width = 0 } end
    return ProgressWidget:new{
        width = width,
        height = Screen:scaleBySize(5),
        percentage = math.max(0, math.min(1, fraction or 0)),
        margin_h = 0, margin_v = 0, radius = 0,
        bordersize = Size.border.thin,
        fillcolor = Blitbuffer.COLOR_BLACK,
        bgcolor = Blitbuffer.COLOR_WHITE,
    }
end

function Shelf:buildHero(item, w, h)
    local cover_h = h - 2 * self.pad
    local cover_w = math.floor(cover_h * 2 / 3)
    local text_w = w - cover_w - 3 * self.gap
    local group = VerticalGroup:new{ align = "left" }
    local probe = TextBoxWidget:new{ text = "M", face = self.title_face, width = text_w, bold = true }
    local line_h = probe:getLineHeight()
    probe:free()
    local title = TextBoxWidget:new{ text = item.title or item.text or "", face = self.title_face, bold = true, width = text_w }
    if title:getSize().h > 2 * line_h then
        title:free()
        title = TextBoxWidget:new{ text = item.title or item.text or "", face = self.title_face, bold = true,
            width = text_w, height = 2 * line_h, height_overflow_show_ellipsis = true }
    end
    table.insert(group, title)
    for _i, line in ipairs(item.lines or {}) do
        table.insert(group, VerticalSpan:new{ width = math.floor(self.pad / 2) })
        table.insert(group, TextBoxWidget:new{ text = line, face = self.small_face, width = text_w,
            fgcolor = Blitbuffer.COLOR_DARK_GRAY })
    end
    if item.progress then
        table.insert(group, VerticalSpan:new{ width = self.pad })
        table.insert(group, bar(math.floor(text_w * 0.9), item.progress))
    end
    return HorizontalGroup:new{
        align = "center",
        HorizontalSpan:new{ width = self.gap },
        self:coverBox(item.url, item.title or item.text, cover_w, cover_h, item.badge),
        HorizontalSpan:new{ width = self.gap },
        LeftContainer:new{ dimen = Geom:new{ w = text_w, h = cover_h }, group },
    }
end

-- Height of one line of a cell's title (cell_face).
function Shelf:cellLineHeight()
    if not self.cell_line_h then
        local probe = TextBoxWidget:new{ text = "M", face = self.cell_face, width = 100 }
        self.cell_line_h = probe:getLineHeight()
        probe:free()
    end
    return self.cell_line_h
end

function Shelf:buildCell(item, cell_w, cover_w, cover_h)
    local group = VerticalGroup:new{ align = "center" }
    table.insert(group, self:coverBox(item.url, item.title or item.text, cover_w, cover_h, item.badge))
    table.insert(group, VerticalSpan:new{ width = math.floor(self.pad / 2) })
    -- The title over the whole cell width (about twice the cover's), on up to
    -- two lines ending with an ellipsis. Every cell reserves the two lines, so
    -- the covers and bars of a row stay aligned.
    table.insert(group, TextBoxWidget:new{
        text = item.title or item.text or "",
        face = self.cell_face,
        width = cell_w,
        alignment = "center",
        height = 2 * self:cellLineHeight(),
        height_overflow_show_ellipsis = true,
    })
    table.insert(group, VerticalSpan:new{ width = math.floor(self.pad / 2) })
    table.insert(group, bar(cover_w, item.progress))
    return CenterContainer:new{ dimen = Geom:new{ w = cell_w, h = group:getSize().h }, group }
end

function Shelf:buildRow(item, w)
    local h = self.text_row_h
    local mandatory = item.mandatory and TextWidget:new{ text = item.mandatory, face = self.note_face }
    local mand_w = mandatory and (mandatory:getSize().w + self.pad) or 0
    local text = TextWidget:new{
        text = item.text or "", face = self.small_face, bold = item.bold,
        fgcolor = item.dim and Blitbuffer.COLOR_DARK_GRAY or Blitbuffer.COLOR_BLACK,
        max_width = w - 2 * self.pad - mand_w,
    }
    local overlap = OverlapGroup:new{
        dimen = Geom:new{ w = w - 2 * self.pad, h = h },
        LeftContainer:new{ dimen = Geom:new{ w = w - 2 * self.pad, h = h }, text },
    }
    if mandatory then
        table.insert(overlap, RightContainer:new{ dimen = Geom:new{ w = w - 2 * self.pad, h = h }, mandatory })
    end
    return VerticalGroup:new{
        HorizontalGroup:new{ HorizontalSpan:new{ width = self.pad }, overlap },
        LineWidget:new{ dimen = Geom:new{ w = w, h = Size.line.thin }, background = Blitbuffer.COLOR_LIGHT_GRAY },
    }
end

function Shelf:updateItems()
    self:freeImages()
    UIManager:unschedule(self.fetch_task)
    local W, H = self.dimen.w, self.dimen.h
    local hero_i, grid, buttons, note_i = self:split()
    self.grid_indexes = grid

    -- Bottom: page indicator (when needed) and the button row.
    local button_row = HorizontalGroup:new{ align = "center" }
    local bw = math.floor((W - (#buttons + 1) * self.gap) / math.max(1, #buttons))
    for _i, index in ipairs(buttons) do
        local item = self.item_table[index]
        table.insert(button_row, HorizontalSpan:new{ width = self.gap })
        table.insert(button_row, Button:new{
            text = item.text,
            width = bw,
            max_width = bw,
            text_font_size = 18,
            bordersize = Size.border.thin,
            radius = Size.radius.button,
            padding = self.pad,
            callback = function() self:onMenuSelect(item) end,
            show_parent = self,
        })
    end
    local buttons_h = #buttons > 0 and (button_row:getSize().h + 2 * self.gap) or 0
    local probe = Button:new{ icon = "chevron.left", bordersize = 0, callback = function() end, show_parent = self }
    local pager_h = probe:getSize().h
    local title_h = self.title_bar:getHeight()
    local note
    if note_i then
        note = LeftContainer:new{
            dimen = Geom:new{ w = W, h = math.floor(self.text_row_h * 0.6) },
            HorizontalGroup:new{ HorizontalSpan:new{ width = self.gap },
                TextWidget:new{ text = self.item_table[note_i].text, face = self.note_face,
                    fgcolor = Blitbuffer.COLOR_DARK_GRAY, max_width = W - 2 * self.gap } },
        }
    end
    local note_h = note and note:getSize().h or 0

    -- Grid geometry.
    local covers_mode = not self.text_mode
    local cols = 3
    local cell_w = math.floor((W - (cols + 1) * self.gap) / cols)
    local cover_h = self.grid_cover_h
    local cover_w = math.floor(cover_h * 2 / 3)
    if cover_w > cell_w then cover_w = cell_w cover_h = math.floor(cell_w * 3 / 2) end
    -- Cover, two title lines (buildCell), progress bar, spacing.
    local cell_h = cover_h + 2 * self:cellLineHeight() + Screen:scaleBySize(5) + self.pad * 2 + self.gap
    local hero_h = (hero_i and covers_mode) and self.hero_h or (hero_i and self.text_row_h or 0)

    local function body_h(with_pager)
        return H - title_h - note_h - buttons_h - (with_pager and pager_h or 0)
    end
    local function capacity(avail)
        if covers_mode then return math.max(1, math.floor(avail / cell_h)) * cols end
        return math.max(1, math.floor(avail / self.text_row_h))
    end
    -- Assume a pager first; drop it when everything fits on one page.
    local pages = Model.shelfPages(#grid, capacity(body_h(true) - hero_h) , capacity(body_h(true)))
    if #pages > 1 or (#grid > 0 and capacity(body_h(false) - hero_h) < #grid) then
        self.with_pager = true
    else
        self.with_pager = false
        pages = Model.shelfPages(#grid, capacity(body_h(false) - hero_h), capacity(body_h(false)))
    end
    self.pages = pages
    self.page_num = #pages
    if self.page > self.page_num then self.page = self.page_num end
    if self.page < 1 then self.page = 1 end
    self.perpage = math.max(1, pages[1].last - pages[1].first + 1)

    local column = VerticalGroup:new{ align = "left", self.title_bar }
    if note then table.insert(column, note) end
    self.visible = {}
    if self.page == 1 and hero_i then
        local item = self.item_table[hero_i]
        local content = covers_mode and self:buildHero(item, W, hero_h) or self:buildRow(item, W)
        table.insert(column, Tap:new{ item = item, shelf = self, width = W, height = hero_h,
            content = LeftContainer:new{ dimen = Geom:new{ w = W, h = hero_h }, content } })
        table.insert(self.visible, hero_i)
    end
    local range = pages[self.page]
    -- Spread the grid rows over the height available on this page.
    local vgap = self.gap
    if covers_mode then
        local avail = body_h(self.with_pager) - ((self.page == 1 and hero_i) and hero_h or 0)
        local rows = math.max(1, math.floor(avail / cell_h))
        local used = math.ceil((range.last - range.first + 1) / cols)
        if used == rows then
            vgap = math.min(3 * self.gap, math.max(self.gap, math.floor((avail - rows * (cell_h - self.gap)) / (rows + 1))))
        end
    end
    local row
    for k = range.first, range.last do
        local index = grid[k]
        local item = self.item_table[index]
        table.insert(self.visible, index)
        if covers_mode then
            if not row or #row >= 2 * cols then
                row = HorizontalGroup:new{ align = "top" }
                table.insert(column, VerticalSpan:new{ width = vgap })
                table.insert(column, row)
            end
            table.insert(row, HorizontalSpan:new{ width = self.gap })
            local content = self:buildCell(item, cell_w, cover_w, cover_h)
            table.insert(row, Tap:new{ item = item, shelf = self, width = cell_w, height = content:getSize().h, content = content })
        else
            local content = self:buildRow(item, W)
            table.insert(column, Tap:new{ item = item, shelf = self, width = W, height = self.text_row_h, content = content })
        end
    end

    local bottom = VerticalGroup:new{ align = "center" }
    if self.with_pager then
        local function chev(icon, cb)
            return Button:new{ icon = icon, bordersize = 0, callback = cb, show_parent = self }
        end
        table.insert(bottom, HorizontalGroup:new{
            align = "center",
            chev("chevron.left", function() self:onPrevPage() end),
            HorizontalSpan:new{ width = self.gap * 2 },
            TextWidget:new{ text = T("%1 / %2", self.page, self.page_num), face = self.small_face },
            HorizontalSpan:new{ width = self.gap * 2 },
            chev("chevron.right", function() self:onNextPage() end),
        })
    end
    if #buttons > 0 then
        table.insert(bottom, VerticalSpan:new{ width = self.gap })
        table.insert(bottom, button_row)
        table.insert(bottom, VerticalSpan:new{ width = self.gap })
    end
    local bottom_h = bottom:getSize().h
    self[1] = FrameContainer:new{
        background = Blitbuffer.COLOR_WHITE,
        bordersize = 0, padding = 0, margin = 0,
        OverlapGroup:new{
            dimen = self.dimen:copy(),
            column,
            VerticalGroup:new{
                VerticalSpan:new{ width = H - bottom_h },
                CenterContainer:new{ dimen = Geom:new{ w = W, h = bottom_h }, bottom },
            },
        },
    }
    UIManager:setDirty(self, function() return "ui", self.dimen end)
    self:queueCovers()
end

-- ---------------------------------------------------------------- covers

function Shelf:queueCovers()
    self.fetch_queue = {}
    self.fetched = 0
    if self.text_mode or not self.covers or not self.fetch_cover or not (self.can_fetch and self.can_fetch()) then return end
    for _i, index in ipairs(self.visible or {}) do
        local item = self.item_table[index]
        if item.url and self.covers:wants(item.url) then table.insert(self.fetch_queue, item.url) end
    end
    if #self.fetch_queue > 0 then UIManager:scheduleIn(0.2, self.fetch_task) end
end

function Shelf:fetchStep()
    if self.closed or not self.fetch_queue then return end
    local url = table.remove(self.fetch_queue, 1)
    if url and self.can_fetch and self.can_fetch() then
        local ok, path = pcall(self.fetch_cover, url)
        if ok and path then self.fetched = self.fetched + 1 end
    else
        self.fetch_queue = {}
    end
    if #self.fetch_queue > 0 then
        UIManager:scheduleIn(0.1, self.fetch_task)
    elseif self.fetched > 0 then
        self.fetched = 0
        self:updateItems() -- one repaint for the batch
    end
end

-- ---------------------------------------------------------------- Menu protocol

--- Item index of the Nth tappable entry on the current page (hero first).
function Shelf:pageItemIndex(n)
    return self.visible and self.visible[n]
end

function Shelf:onMenuSelect(item)
    if self.onMenuChoice then self:onMenuChoice(item) elseif item.callback then item.callback() end
    return true
end

function Shelf:onMenuHold() return true end

function Shelf:onGotoPage(page)
    if not page or page < 1 or page > (self.page_num or 1) or page == self.page then return true end
    self.page = page
    self:updateItems()
    return true
end

function Shelf:onNextPage()
    if (self.page_num or 1) > 1 then
        self.page = self.page < self.page_num and self.page + 1 or 1
        self:updateItems()
    end
    return true
end

function Shelf:onPrevPage()
    if (self.page_num or 1) > 1 then
        self.page = self.page > 1 and self.page - 1 or self.page_num
        self:updateItems()
    end
    return true
end

function Shelf:onSwipe(_arg, ges)
    local dir = ges and ges.direction
    if dir == "west" then return self:onNextPage() end
    if dir == "east" then return self:onPrevPage() end
    return false
end

--- Replaces the items, keeping the page (or going to the page of itemnumber).
function Shelf:switchItemTable(title, items, itemnumber)
    if title then
        self.title = title
        if self.title_bar.setTitle then self.title_bar:setTitle(title) end
    end
    if items then self.item_table = items end
    self:updateItems()
    if itemnumber and itemnumber > 0 and self.grid_indexes then
        for k, index in ipairs(self.grid_indexes) do
            if index == itemnumber then
                for p, range in ipairs(self.pages) do
                    if k >= range.first and k <= range.last and p ~= self.page then
                        self.page = p
                        self:updateItems()
                    end
                end
                break
            end
        end
    end
end

function Shelf:onLeftButtonTap() end

function Shelf:onClose()
    UIManager:close(self)
    return true
end

function Shelf:onCloseWidget()
    self.closed = true
    UIManager:unschedule(self.fetch_task)
    self:freeImages()
    UIManager:setDirty(nil, "ui")
end

return Shelf
