local T = ...
local Doc = require("novarr.chapterdoc")

T.it("escapes HTML special characters", function()
    T.eq(Doc.escape([[<b>"Tom" & 'Jerry'</b>]]), "&lt;b&gt;&quot;Tom&quot; &amp; &#39;Jerry&#39;&lt;/b&gt;")
    T.eq(Doc.escape(nil), "")
    T.eq(Doc.escape("a\1b\0c\tok"), "abc\tok")
end)

T.it("builds a self-contained document from paragraphs", function()
    local html = Doc.build({
        novel_name = "Ascending <the> Nine",
        label = "Chapter 17 - A & B",
        chapter = 17,
        paragraphs = { "First <script>alert(1)</script> line.", "  ", "Second \"quoted\"." },
        html = "<p>SERVER HTML MUST NOT BE USED</p>",
    })
    T.contains(html, '<meta charset="utf-8"/>')
    T.contains(html, '<html lang="en">')
    T.contains(html, "<title>Ascending &lt;the&gt; Nine \226\128\148 Chapter 17 - A &amp; B</title>")
    T.contains(html, "<h2>Chapter 17 - A &amp; B</h2>")
    T.contains(html, "<p>First &lt;script&gt;alert(1)&lt;/script&gt; line.</p>")
    T.contains(html, "<p>Second &quot;quoted&quot;.</p>")
    T.notContains(html, "SERVER HTML")
    T.notContains(html, "<p>  </p>")
    T.notContains(html, "<script>")
end)

T.it("falls back to 'Chapter N' when the label is missing", function()
    local html = Doc.build({ novel_name = "N", chapter = 3, paragraphs = {} })
    T.contains(html, "<h2>Chapter 3</h2>")
    T.contains(html, "<title>N \226\128\148 Chapter 3</title>")
end)

T.it("language attribute is sanitised", function()
    T.contains(Doc.build({ label = "x", paragraphs = {} }, 'zh"><x'), '<html lang="zhx">')
end)

T.it("FAT-safe names", function()
    T.eq(Doc.fatSafe('Chapter 1: What? "Yes" <no> a|b *c* d/e\\f'), "Chapter 1 What Yes no a b c d e f")
    T.eq(Doc.fatSafe("  ...trailing dots... "), "trailing dots")
    T.eq(Doc.fatSafe("tab\there\nnewline"), "tab here newline")
    T.eq(Doc.fatSafe("???"), "")
    T.eq(Doc.fatSafe(nil), "")
    -- 60-byte cap that never splits a UTF-8 character (each CJK char = 3 bytes)
    local cjk = string.rep("\228\184\128", 30) -- 90 bytes
    local s = Doc.fatSafe(cjk, 61)
    T.eq(#s, 60)
    T.eq(Doc.fatSafe("abc. ", 4), "abc")
end)

T.it("file names encode the chapter id", function()
    T.eq(Doc.fileName(123, "Chapter 5: The End?"), "123_Chapter 5 The End.html")
    T.eq(Doc.fileName(9, "???"), "9.html")
    T.eq(Doc.idFromFileName("123_Chapter 5.html"), 123)
    T.eq(Doc.idFromFileName("9.html"), 9)
    T.eq(Doc.idFromFileName("index.json"), nil)
    T.eq(Doc.idFromFileName("12a.html"), nil)
    T.eq(Doc.idFromFileName("123_x.html.tmp"), nil)
end)

T.it("path <-> ids round trip", function()
    local root = "/mnt/us/koreader/novarr"
    local path = Doc.path(root, 7, Doc.fileName(4321, "Chapter 2"))
    T.eq(path, "/mnt/us/koreader/novarr/chapters/7/4321_Chapter 2.html")
    local n, c = Doc.idsFromPath(path, root)
    T.eq(n, 7)
    T.eq(c, 4321)
    T.eq(Doc.idsFromPath("/mnt/us/koreader/novarr/chapters/7/9.html", root), 7)
end)

T.it("paths outside the store never match", function()
    local root = "/mnt/us/koreader/novarr"
    T.eq(Doc.idsFromPath("/mnt/us/documents/chapters/7/9.html", root), nil)
    T.eq(Doc.idsFromPath("/mnt/us/koreader/novarr/chapters/7/sub/9.html", root), nil)
    T.eq(Doc.idsFromPath("/mnt/us/koreader/novarr/chapters/x/9.html", root), nil)
    T.eq(Doc.idsFromPath("/mnt/us/koreader/novarr/chapters/7/notes.html", root), nil)
    T.eq(Doc.idsFromPath("/mnt/us/koreader/novarr-other/chapters/7/9.html", root), nil)
    T.eq(Doc.idsFromPath("/mnt/us/koreader/novarr/chapters/7/9.epub", root), nil)
    T.eq(Doc.idsFromPath(nil, root), nil)
end)

T.it("EPUB file names are FAT-safe", function()
    T.eq(Doc.epubFileName("Ascending: The Nine? - Feng.epub"), "Ascending The Nine - Feng.epub")
    T.eq(Doc.epubFileName(nil), "novel.epub")
    T.eq(Doc.epubFileName("A/B|C"), "A B C.epub")
end)
