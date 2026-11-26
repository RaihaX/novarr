<?php

namespace Tests\Support;

use ZipArchive;

/**
 * Small ePubs for the books tests, written to a temporary file.
 *
 *   EpubBuilder::epub3()  nav contents, a part page, a file split by
 *                         fragments, a PNG cover, an ISBN, Calibre series
 *   EpubBuilder::epub2()  NCX contents with nested points, no cover
 */
class EpubBuilder
{
    /** @var string[] files written, removed by cleanup() */
    private static array $written = [];

    public static function words(int $count, string $word = 'word'): string
    {
        return trim(str_repeat("{$word} ", $count));
    }

    public static function epub3(array $overrides = []): string
    {
        $o = array_merge([
            'title' => 'The Quiet Orchard',
            'author' => 'Ada Wren',
            'isbn' => '9780306406157',
            'language' => 'en-GB',
            'description' => '&lt;p&gt;A &lt;b&gt;quiet&lt;/b&gt; story.&lt;/p&gt;&lt;p&gt;Second line.&lt;/p&gt;',
            'subjects' => ['Fantasy', 'England--Fiction', 'Coming of age (Fiction)'],
            'cover' => true,
            'nav' => true,
        ], $overrides);

        $w = self::words(40);
        $files = [
            'mimetype' => 'application/epub+zip',
            'META-INF/container.xml' => '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
            'OEBPS/text/title.xhtml' => self::xhtml('Title', '<h1>The Quiet Orchard</h1><p>by Ada Wren</p>'),
            'OEBPS/text/part1.xhtml' => self::xhtml('Part One', '<h1>Part One</h1>'),
            'OEBPS/text/ch1.xhtml' => self::xhtml('One', "<section id=\"c1\"><h2>1</h2><p>First <i>chapter</i> {$w}</p><p>More<br/>lines {$w}</p><img src=\"../img/x.png\" alt=\"x\"/><script>alert(1)</script></section>"),
            'OEBPS/text/ch2-3.xhtml' => self::xhtml('Two and three', "<div><h2 id=\"c2\">Two</h2><p>Second {$w}</p><a id=\"c3\"></a><h2>Three</h2><p>Third {$w}</p></div>"),
            'OEBPS/text/notes.xhtml' => self::xhtml('Notes', "<p>Unlisted note {$w}</p>"),
        ];

        $manifest = '<item id="title" href="text/title.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="part1" href="text/part1.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="ch1" href="text/ch1.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="ch23" href="text/ch2-3.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="notes" href="text/notes.xhtml" media-type="application/xhtml+xml"/>';
        $meta = '';
        if ($o['cover']) {
            $files['OEBPS/img/cover.png'] = self::png();
            $manifest .= '<item id="cover-img" href="img/cover.png" media-type="image/png" properties="cover-image"/>';
            $meta .= '<meta name="cover" content="cover-img"/>';
        }
        if ($o['nav']) {
            $files['OEBPS/nav.xhtml'] = '<?xml version="1.0" encoding="UTF-8"?><html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>Contents</title></head><body>'
                . '<nav epub:type="toc"><ol>'
                . '<li><a href="text/title.xhtml">Title Page</a></li>'
                . '<li><a href="text/part1.xhtml">Part One</a><ol>'
                . '<li><a href="text/ch1.xhtml#c1">1</a></li>'
                . '<li><a href="text/ch2-3.xhtml#c2">Two</a></li>'
                . '<li><a href="text/ch2-3.xhtml#c3">Three</a></li>'
                . '</ol></li></ol></nav>'
                . '<nav epub:type="landmarks"><ol><li><a href="text/ch1.xhtml">Start</a></li></ol></nav></body></html>';
            $manifest .= '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
        }

        $subjects = implode('', array_map(fn($s) => "<dc:subject>{$s}</dc:subject>", $o['subjects']));
        $isbn = $o['isbn'] ? "<dc:identifier id=\"isbn\">urn:isbn:{$o['isbn']}</dc:identifier>" : '';
        $files['OEBPS/content.opf'] = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:identifier id="uid">urn:uuid:12345678-1234-1234-1234-123456789012</dc:identifier>' . $isbn
            . "<dc:title>{$o['title']}</dc:title>"
            . ($o['author'] !== null ? "<dc:creator id=\"a1\">{$o['author']}</dc:creator><meta refines=\"#a1\" property=\"role\">aut</meta>" : '')
            . '<dc:creator id="ill">Ian Illustrator</dc:creator><meta refines="#ill" property="role">ill</meta>'
            . "<dc:language>{$o['language']}</dc:language>"
            . "<dc:description>{$o['description']}</dc:description>"
            . '<dc:date>2019-04-01</dc:date><dc:publisher>Small Press</dc:publisher>' . $subjects
            . '<meta name="calibre:series" content="Orchard Cycle"/><meta name="calibre:series_index" content="2.0"/>'
            . $meta
            . '</metadata>'
            . "<manifest>{$manifest}</manifest>"
            . '<spine><itemref idref="title"/><itemref idref="part1"/><itemref idref="ch1"/><itemref idref="ch23"/><itemref idref="notes" linear="no"/></spine>'
            . '</package>';

        return self::zip($files);
    }

    public static function epub2(): string
    {
        $w = self::words(30);
        $files = [
            'mimetype' => 'application/epub+zip',
            'META-INF/container.xml' => '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
            'a.html' => self::xhtml('A', "<h1>Prologue</h1><p>Opening {$w}</p>"),
            'b.html' => self::xhtml('B', "<h1>Chapter 1</h1><p>Begin {$w}</p>"),
            'toc.ncx' => '<?xml version="1.0"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><navMap>'
                . '<navPoint id="p1" playOrder="1"><navLabel><text>Prologue</text></navLabel><content src="a.html"/></navPoint>'
                . '<navPoint id="p2" playOrder="2"><navLabel><text>Book I</text></navLabel><content src="b.html"/>'
                . '<navPoint id="p3" playOrder="3"><navLabel><text>2</text></navLabel><content src="b.html#nowhere"/></navPoint>'
                . '</navPoint></navMap></ncx>',
            'content.opf' => '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="2.0"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">'
                . '<dc:title>Old Format</dc:title><dc:creator opf:role="aut">Bo Brown</dc:creator><dc:creator opf:role="edt">Ed Editor</dc:creator><dc:language>eng</dc:language>'
                . '<dc:identifier opf:scheme="ISBN">0-306-40615-2</dc:identifier>'
                . '</metadata><manifest><item id="a" href="a.html" media-type="application/xhtml+xml"/><item id="b" href="b.html" media-type="application/xhtml+xml"/><item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/></manifest>'
                . '<spine toc="ncx"><itemref idref="a"/><itemref idref="b"/></spine></package>',
        ];

        return self::zip($files);
    }

    /** A zip that is not an ePub (no package document). */
    public static function notAnEpub(): string
    {
        return self::zip(['readme.txt' => 'hello']);
    }

    public static function cleanup(): void
    {
        foreach (self::$written as $file) {
            @unlink($file);
        }
        self::$written = [];
    }

    private static function xhtml(string $title, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml"><head><title>' . $title . '</title><style>p{}</style></head><body>' . $body . '</body></html>';
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(60, 90);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 60));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @param array<string, string> $files */
    private static function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'novarr-epub-') . '.epub';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        self::$written[] = $path;

        return $path;
    }
}
