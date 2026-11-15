--[[--
A full-screen list with cover thumbnails, built from KOReader's stock
widgets (TitleBar, ImageWidget, TextBoxWidget, groups, InputContainer). It
speaks the same small protocol as KOReader's Menu that the rest of the
plugin uses (item_table, page, onMenuSelect/onMenuHold, onNextPage/
onPrevPage, switchItemTable, onLeftButtonTap, onClose), so screens switch
between it and the plain text Menu with the "Show covers" setting.

Row kinds (fields of an item):
    cover = {url, name, author, info}   a thumbnail row (2:3 cover, title up to 2 lines)
    hero  = {url, name, lines}          a tall row with the large cover (Novel screen)
    toolbar = true                      a button in the bar under the title (not paged)
    anything else                       a plain text row (text, mandatory, dim, bold)

Covers: only the rows on the current page get image widgets; they are
freed on every page change and when the screen closes. Missing covers are
fetched one per scheduled step (only when already connected), and the page
is repainted once when the batch is done.
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
local logger = require("logger")
local _ = require("gettext")
local T = require("ffi/util").template
local Screen = Device.screen

local Covers = require("novarr.covers")

-- ---------------------------------------------------------------- rows

local Row = InputContainer:extend{
    item = nil,
    menu = nil,
    width = nil,
    height = nil,
}

function Row:init()
    self.dimen = Geom:new{ x = 0, y = 0, w = self.width, h = self.height }
    self.ges_events = {
        TapSelect = { GestureRange:new{ ges = "tap", range = function() return self.dimen end } },
        HoldSelect = { GestureRange:new{ ges = "hold", range = function() return self.dimen end } },
    }
    self[1] = self.content
end

function Row:onTapSelect()
    if self.item.select_enabled == false then return true end
    self.menu:onMenuSelect(self.item)
    return true
end

function Row:onHoldSelect()
    self.menu:onMenuHold(self.item)
    return true
end

-- ---------------------------------------------------------------- list

local CoverList = InputContainer:extend{
    name = "novarr_menu",
    title = "",
    item_table = nil,
    title_bar_left_icon = nil,
    covers = nil,          -- Covers cache (novarr.covers)
    can_fetch = nil,       -- function() -> bool: connected, covers may be fetched
    fetch_cover = nil,     -- function(url) -> path|nil (one blocking download)
    covers_fullscreen = true,
}

function CoverList:init()
    self.item_table = self.item_table or {}
    self.page = 1
    self.image_widgets = {}
    self.dimen = Geom:new{ x = 0, y = 0, w = Screen:getWidth(), h = Screen:getHeight() }
    local dpi = Screen:getDPI()
    self.pad = Size.padding.default
    -- Covers about 0.68 inch tall (about 5 rows on a 6-inch screen), 2:3.
    self.cover_h = math.floor(dpi * 0.68)
    self.cover_w = math.floor(self.cover_h * 2 / 3)
    self.cover_row_h = self.cover_h + 2 * self.pad
    self.hero_h = math.floor(dpi * 1.6)
    self.hero_w = math.floor(self.hero_h * 2 / 3)
    self.hero_row_h = self.hero_h + 2 * self.pad
    self.text_row_h = math.floor(dpi * 0.28)
    self.title_face = Font:getFace("cfont", 20)
    self.small_face = Font:getFace("cfont", 16)
    self.info_face = Font:getFace("infont", 15)
    local probe = TextBoxWidget:new{ text = "M", face = self.title_face, width = 100 }
    self.title_line_h = probe:getLineHeight()
    probe:free()

    self.title_bar = TitleBar:new{
        width = self.dimen.w,
        fullscreen = "true",
        align = "center",
        title = self.title,
        left_icon = self.title_bar_left_icon,
        left_icon_tap_callback = function() self:onLeftButtonTap() end,
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

function CoverList:toolbarItems()
    local out = {}
    for _i, item in ipairs(self.item_table) do
        if item.toolbar then table.insert(out, item) end
    end
    return out
end

function CoverList:rowHeight(item)
    if item.hero then return self.hero_row_h end
    if item.cover then return self.cover_row_h end
    return self.text_row_h
end

-- Splits the (non-toolbar) items into pages that fit the available height.
function CoverList:paginate(avail)
    self.pages = { {} }
    local used = 0
    for i, item in ipairs(self.item_table) do
        if not item.toolbar then
            local h = self:rowHeight(item)
            local page = self.pages[#self.pages]
            if #page > 0 and used + h > avail then
                page = {}
                table.insert(self.pages, page)
                used = 0
            end
            table.insert(page, i)
            used = used + h
        end
    end
    self.page_num = #self.pages
    -- Rough per-page count, used only by generic helpers.
    self.perpage = math.max(1, #self.pages[1])
end

--- Item index of the Nth row on the current page (for the autopilot).
function CoverList:pageItemIndex(n)
    local page = self.pages and self.pages[self.page]
    return page and page[n]
end

function CoverList:getPageNumber(index)
    for p, list in ipairs(self.pages or {}) do
        for _i, idx in ipairs(list) do
            if idx >= index then return p end
        end
    end
    return self.page_num or 1
end

function CoverList:freeImages()
    for _i, w in ipairs(self.image_widgets) do
        pcall(w.free, w)
    end
    self.image_widgets = {}
end

-- Cover box: the cached image scaled to fit, or a bordered placeholder with initials.
function CoverList:coverBox(url, name, w, h, badge)
    local box = self:plainCoverBox(url, name, w, h)
    if not badge or badge <= 0 then return box end
    -- New chapters: a black box with a white bold number (legible on e-ink).
    local mark = FrameContainer:new{
        background = Blitbuffer.COLOR_BLACK,
        bordersize = Size.border.thin,
        color = Blitbuffer.COLOR_WHITE,
        padding = math.floor(self.pad / 2),
        padding_left = self.pad,
        padding_right = self.pad,
        margin = 0,
        TextWidget:new{ text = tostring(badge), face = Font:getFace("cfont", 14), bold = true, fgcolor = Blitbuffer.COLOR_WHITE },
        overlap_align = "right",
    }
    return OverlapGroup:new{ dimen = Geom:new{ w = w, h = h }, box, mark }
end

function CoverList:plainCoverBox(url, name, w, h)
    local path = self.covers and url and self.covers:cached(url)
    local inner
    if path then
        local ok, img = pcall(function()
            local widget = ImageWidget:new{
                file = path,
                file_do_cache = false,
                width = w - 2 * Size.border.thin,
                height = h - 2 * Size.border.thin,
                scale_factor = 0,
            }
            widget:_render() -- decode now, so a bad file falls back to the placeholder
            return widget
        end)
        if ok and img then
            table.insert(self.image_widgets, img)
            inner = CenterContainer:new{ dimen = Geom:new{ w = w - 2 * Size.border.thin, h = h - 2 * Size.border.thin }, img }
        else
            logger.info("Novarr covers: cannot show", path)
        end
    end
    if not inner then
        inner = CenterContainer:new{
            dimen = Geom:new{ w = w - 2 * Size.border.thin, h = h - 2 * Size.border.thin },
            TextWidget:new{ text = Covers.initials(name), face = Font:getFace("tfont", math.max(12, math.floor(w / 5))) },
        }
    end
    return FrameContainer:new{
        bordersize = Size.border.thin,
        padding = 0,
        margin = 0,
        background = Blitbuffer.COLOR_WHITE,
        inner,
    }
end

function CoverList:buildCoverRow(item, width, hero)
    local c = item.cover or item.hero
    local cw, ch = self.cover_w, self.cover_h
    if hero then cw, ch = self.hero_w, self.hero_h end
    local text_w = width - cw - 3 * self.pad
    local group = VerticalGroup:new{ align = "left" }
    local title = TextBoxWidget:new{
        text = c.name or item.text or "",
        face = self.title_face,
        bold = true,
        width = text_w,
    }
    if not hero and title:getSize().h > 2 * self.title_line_h then
        -- At most two lines, ending with an ellipsis.
        title:free()
        title = TextBoxWidget:new{
            text = c.name or item.text or "",
            face = self.title_face,
            bold = true,
            width = text_w,
            height = 2 * self.title_line_h,
            height_overflow_show_ellipsis = true,
        }
    end
    table.insert(group, title)
    local lines = c.lines or {}
    if not hero then
        lines = {}
        if c.author then table.insert(lines, c.author) end
        if c.info then table.insert(lines, c.info) end
    end
    for _i, line in ipairs(lines) do
        table.insert(group, VerticalSpan:new{ width = math.floor(self.pad / 2) })
        table.insert(group, TextBoxWidget:new{
            text = line,
            face = self.small_face,
            width = text_w,
            fgcolor = Blitbuffer.COLOR_DARK_GRAY,
        })
    end
    local content = HorizontalGroup:new{
        align = "center",
        HorizontalSpan:new{ width = self.pad },
        self:coverBox(c.url, c.name or item.text, cw, ch, c.badge),
        HorizontalSpan:new{ width = self.pad },
        LeftContainer:new{ dimen = Geom:new{ w = text_w, h = ch }, group },
    }
    return content
end

function CoverList:buildTextRow(item, width)
    local h = self.text_row_h
    local mandatory = item.mandatory and TextWidget:new{ text = item.mandatory, face = self.info_face }
    local mand_w = mandatory and (mandatory:getSize().w + self.pad) or 0
    local text = TextWidget:new{
        text = item.text or "",
        face = self.title_face,
        bold = item.bold,
        fgcolor = item.dim and Blitbuffer.COLOR_DARK_GRAY or Blitbuffer.COLOR_BLACK,
        max_width = width - 2 * self.pad - mand_w,
    }
    local overlap = OverlapGroup:new{
        dimen = Geom:new{ w = width - 2 * self.pad, h = h },
        LeftContainer:new{ dimen = Geom:new{ w = width - 2 * self.pad, h = h }, text },
    }
    if mandatory then
        table.insert(overlap, RightContainer:new{ dimen = Geom:new{ w = width - 2 * self.pad, h = h }, mandatory })
    end
    return HorizontalGroup:new{ HorizontalSpan:new{ width = self.pad }, overlap }
end

function CoverList:updateItems()
    self:freeImages()
    UIManager:unschedule(self.fetch_task)
    local width = self.dimen.w
    local toolbar = self:toolbarItems()
    local toolbar_widget
    if #toolbar > 0 then
        toolbar_widget = HorizontalGroup:new{ align = "center" }
        local bw = math.floor((width - 2 * self.pad) / #toolbar)
        for _i, item in ipairs(toolbar) do
            table.insert(toolbar_widget, Button:new{
                text = item.text,
                width = bw - self.pad,
                max_width = bw - self.pad,
                bordersize = Size.border.thin,
                radius = Size.radius.button,
                text_font_size = 16,
                callback = function() self:onMenuSelect(item) end,
                show_parent = self,
            })
            table.insert(toolbar_widget, HorizontalSpan:new{ width = self.pad })
        end
    end
    local function chev(icon, cb)
        return Button:new{ icon = icon, bordersize = 0, callback = cb, show_parent = self }
    end
    local probe = chev("chevron.left", function() end)
    local footer_h = probe:getSize().h + self.pad
    local title_h = self.title_bar:getHeight()
    local toolbar_h = toolbar_widget and (toolbar_widget:getSize().h + 2 * self.pad) or 0
    local avail = self.dimen.h - title_h - toolbar_h - footer_h
    self:paginate(avail)
    if self.page > self.page_num then self.page = self.page_num end
    if self.page < 1 then self.page = 1 end
    -- Footer: page info and chevrons.
    self.page_text = TextWidget:new{ text = T(_("Page %1 of %2"), self.page, self.page_num), face = Font:getFace("cfont", 18) }
    local footer = HorizontalGroup:new{
        chev("chevron.first", function() self:onGotoPage(1) end),
        HorizontalSpan:new{ width = self.pad * 3 },
        chev("chevron.left", function() self:onPrevPage() end),
        HorizontalSpan:new{ width = self.pad * 3 },
        self.page_text,
        HorizontalSpan:new{ width = self.pad * 3 },
        chev("chevron.right", function() self:onNextPage() end),
        HorizontalSpan:new{ width = self.pad * 3 },
        chev("chevron.last", function() self:onGotoPage(self.page_num) end),
    }

    local body = VerticalGroup:new{ align = "left" }
    self.rows = {}
    for _i, index in ipairs(self.pages[self.page] or {}) do
        local item = self.item_table[index]
        local h = self:rowHeight(item)
        local content
        if item.hero then
            content = self:buildCoverRow(item, width, true)
        elseif item.cover then
            content = self:buildCoverRow(item, width, false)
        else
            content = self:buildTextRow(item, width)
        end
        local row = Row:new{
            item = item, menu = self, width = width, height = h,
            content = VerticalGroup:new{
                align = "left",
                CenterContainer:new{ dimen = Geom:new{ w = width, h = h - Size.line.thin }, LeftContainer:new{ dimen = Geom:new{ w = width, h = h - Size.line.thin }, content } },
                LineWidget:new{ dimen = Geom:new{ w = width, h = Size.line.thin }, background = Blitbuffer.COLOR_LIGHT_GRAY },
            },
        }
        table.insert(self.rows, row)
        table.insert(body, row)
    end
    local column = VerticalGroup:new{ align = "center", self.title_bar }
    if toolbar_widget then
        table.insert(column, VerticalSpan:new{ width = self.pad })
        table.insert(column, CenterContainer:new{ dimen = Geom:new{ w = width, h = toolbar_widget:getSize().h }, toolbar_widget })
        table.insert(column, VerticalSpan:new{ width = self.pad })
    end
    table.insert(column, body)
    self[1] = FrameContainer:new{
        background = Blitbuffer.COLOR_WHITE,
        bordersize = 0,
        padding = 0,
        margin = 0,
        OverlapGroup:new{
            dimen = self.dimen:copy(),
            column,
            -- Footer pinned to the bottom; a single page needs none.
            self.page_num > 1 and VerticalGroup:new{
                VerticalSpan:new{ width = self.dimen.h - footer_h },
                CenterContainer:new{ dimen = Geom:new{ w = width, h = footer:getSize().h }, footer },
            } or VerticalSpan:new{ width = 0 },
        },
    }
    UIManager:setDirty(self, function() return "ui", self.dimen end)
    self:queueCovers()
end

-- ---------------------------------------------------------------- cover fetching

function CoverList:queueCovers()
    self.fetch_queue = {}
    self.fetched = 0
    if not self.covers or not self.fetch_cover or not (self.can_fetch and self.can_fetch()) then return end
    for _i, index in ipairs(self.pages[self.page] or {}) do
        local item = self.item_table[index]
        local c = item.cover or item.hero
        if c and c.url and self.covers:wants(c.url) then
            table.insert(self.fetch_queue, c.url)
        end
    end
    if #self.fetch_queue > 0 then
        UIManager:scheduleIn(0.2, self.fetch_task)
    end
end

function CoverList:fetchStep()
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
        -- One repaint for the whole batch.
        self.fetched = 0
        self:updateItems()
    end
end

-- ---------------------------------------------------------------- Menu protocol

function CoverList:onMenuSelect(item)
    if self.onMenuChoice then self:onMenuChoice(item) elseif item.callback then item.callback() end
    return true
end

function CoverList:onMenuHold(item)
    return true
end

function CoverList:onGotoPage(page)
    if not page or page < 1 or page > (self.page_num or 1) or page == self.page then return true end
    self.page = page
    self:updateItems()
    return true
end

function CoverList:onNextPage()
    local page = self.page < self.page_num and self.page + 1 or 1
    if self.page_num > 1 then
        self.page = page
        self:updateItems()
    end
    return true
end

function CoverList:onPrevPage()
    local page = self.page > 1 and self.page - 1 or self.page_num
    if self.page_num > 1 then
        self.page = page
        self:updateItems()
    end
    return true
end

function CoverList:onSwipe(_arg, ges)
    local dir = ges and ges.direction
    if dir == "west" then return self:onNextPage() end
    if dir == "east" then return self:onPrevPage() end
    return false
end

--- Same signature as Menu:switchItemTable (title, items, itemnumber).
function CoverList:switchItemTable(title, items, itemnumber)
    if title then
        self.title = title
        if self.title_bar.setTitle then self.title_bar:setTitle(title) end
    end
    if items then self.item_table = items end
    -- Work out the page of itemnumber after re-paginating.
    self:updateItems()
    if itemnumber and itemnumber > 0 then
        local p = self:getPageNumber(itemnumber)
        if p ~= self.page then
            self.page = p
            self:updateItems()
        end
    end
end

function CoverList:onLeftButtonTap() end

function CoverList:onClose()
    UIManager:close(self)
    return true
end

function CoverList:onCloseWidget()
    self.closed = true
    UIManager:unschedule(self.fetch_task)
    self:freeImages()
    UIManager:setDirty(nil, "ui")
end

return CoverList
