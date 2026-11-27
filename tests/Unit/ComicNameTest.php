<?php

namespace Tests\Unit;

use App\Comics\ComicInfo;
use App\Comics\ComicName;
use App\Comics\ShellPageExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** App\Comics\ComicName (scene names), ComicInfo.xml and the extractor's safe paths. Pure. */
class ComicNameTest extends TestCase
{
    public static function names(): array
    {
        // name => [series, volume, issue, year, title, tpb, pack]
        return [
            ['Saga v01 (2012) (Digital) (F) (Zone-Empire).cbr', 'Saga', 1.0, null, 2012, null, false, null],
            ['Batman 001 (2016) (Digital).cbz', 'Batman', null, 1.0, 2016, null, false, null],
            ['Batman (2016) 001.cbz', 'Batman', null, 1.0, 2016, null, false, null],
            ['Watchmen (1987) TPB.pdf', 'Watchmen', null, null, 1987, null, true, null],
            ['Saga #54', 'Saga', null, 54.0, null, null, false, null],
            ['The Walking Dead Vol. 3 - Safety Behind Bars (2005).cbz', 'The Walking Dead', 3.0, null, 2005, 'Safety Behind Bars', false, null],
            ['Spider-Man 2099 001 (1992)', 'Spider-Man 2099', null, 1.0, 1992, null, false, null],
            ['Spider-Man 2099 (1992)', 'Spider-Man 2099', null, null, 1992, null, false, null],
            ['Saga Vol 1-9 (2012-2018)', 'Saga', null, null, 2012, null, false, ['kind' => 'volume', 'from' => 1.0, 'to' => 9.0]],
            ['Invincible #1-144 (2003-2018) Complete', 'Invincible', null, null, 2003, null, false, ['kind' => 'issue', 'from' => 1.0, 'to' => 144.0]],
            ['Saga.v01.2012.Digital.cbz', 'Saga', 1.0, null, 2012, null, false, null],
            ['Saga - Volume 2 (2013)', 'Saga', 2.0, null, 2013, null, false, null],
            ['Batman - The Killing Joke (1988).cbr', 'Batman - The Killing Joke', null, null, 1988, null, false, null],
            ['X-Men 097 (1997)', 'X-Men', null, 97.0, 1997, null, false, null],
            ['Something 1.5 (2019)', 'Something', null, 1.5, 2019, null, false, null],
            ['2000 AD 2100 (2018)', '2000 AD 2100', null, null, 2018, null, false, null],
            ['Maus', 'Maus', null, null, null, null, false, null],
        ];
    }

    #[DataProvider('names')]
    public function test_names_are_read_the_way_the_scene_writes_them(string $name, string $series, ?float $volume, ?float $issue, ?int $year, ?string $title, bool $tpb, ?array $pack): void
    {
        $parsed = ComicName::parse($name);

        $this->assertSame($series, $parsed['series'], 'series');
        $this->assertSame($volume, $parsed['volume'], 'volume');
        $this->assertSame($issue, $parsed['issue'], 'issue');
        $this->assertSame($year, $parsed['year'], 'year');
        $this->assertSame($title, $parsed['title'], 'title');
        $this->assertSame($tpb, $parsed['tpb'], 'tpb');
        $this->assertSame($pack, $parsed['pack'], 'pack');
    }

    public function test_flags_and_labels(): void
    {
        $this->assertTrue(ComicName::parse('Saga v01 (2012) (Digital)')['digital']);
        $this->assertTrue(ComicName::parse('Sandman Omnibus Vol. 1 (2013)')['omnibus']);
        $this->assertSame('Sandman', ComicName::parse('Sandman Omnibus Vol. 1 (2013)')['series']);
        $this->assertSame('Volume 3 – Safety Behind Bars', ComicName::label(3.0, null, 'Safety Behind Bars', 'x'));
        $this->assertSame('#1.5', ComicName::label(null, 1.5, null, 'x'));
        $this->assertSame('Maus', ComicName::label(null, null, null, 'Maus'));
    }

    public function test_comicinfo_is_read_and_its_volume_year_is_the_start_year(): void
    {
        $info = ComicInfo::parse('<?xml version="1.0"?><ComicInfo><Series>Saga</Series><Number>#003</Number><Volume>2012</Volume>'
            . '<Title>Three</Title><Writer>Brian K. Vaughan</Writer><Penciller>Fiona Staples; Fiona Staples</Penciller>'
            . '<Publisher>Image</Publisher><Year>2012</Year><Summary>&lt;b&gt;Bold&lt;/b&gt; plot</Summary>'
            . '<Manga>YesAndRightToLeft</Manga><LanguageISO>en-US</LanguageISO><Count>54</Count><Genre>Sci-Fi, Fantasy</Genre></ComicInfo>');

        $this->assertSame('Saga', $info['series']);
        $this->assertSame(3.0, $info['number']);
        $this->assertNull($info['volume']);
        $this->assertSame(2012, $info['start_year']);
        $this->assertSame(['Brian K. Vaughan'], $info['writers']);
        $this->assertSame(['Fiona Staples'], $info['artists']);
        $this->assertSame('Bold plot', $info['summary']);
        $this->assertTrue($info['rtl']);
        $this->assertSame('en', $info['language']);
        $this->assertSame(54, $info['count']);
        $this->assertSame(['Sci-Fi', 'Fantasy'], $info['genres']);

        $this->assertSame(2.0, ComicInfo::parse('<ComicInfo><Volume>2</Volume><Manga>Yes</Manga></ComicInfo>')['volume']);
        $this->assertFalse(ComicInfo::parse('<ComicInfo><Manga>Yes</Manga></ComicInfo>')['rtl'], '"Yes" says manga, not the direction');
        $this->assertNull(ComicInfo::parse('not xml'));
        $this->assertNull(ComicInfo::parse('<ComicInfo><Series>x</Serie'));
    }

    public function test_archive_paths_never_leave_the_folder(): void
    {
        $this->assertSame('Saga/001.jpg', ShellPageExtractor::safeRelative('Saga/./001.jpg'));
        $this->assertSame('a/b.jpg', ShellPageExtractor::safeRelative('a\\b.jpg'));
        $this->assertNull(ShellPageExtractor::safeRelative('../escape.jpg'));
        $this->assertNull(ShellPageExtractor::safeRelative('Saga/../../x.jpg'));
        $this->assertNull(ShellPageExtractor::safeRelative('/etc/passwd'));
        $this->assertNull(ShellPageExtractor::safeRelative('C:/x.jpg'));
    }

    public function test_the_format_is_told_by_the_bytes_not_the_name(): void
    {
        $dir = sys_get_temp_dir() . '/novarr-kind-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/a.cbr", "PK\x03\x04rest");
        file_put_contents("$dir/b.cbz", "Rar!\x1A\x07\x00");
        file_put_contents("$dir/c.cb7", "7z\xBC\xAF\x27\x1Cxx");
        file_put_contents("$dir/d.pdf", "%PDF-1.7");
        file_put_contents("$dir/e.cbz", 'nothing');
        $this->assertSame('zip', ShellPageExtractor::kind("$dir/a.cbr"));
        $this->assertSame('rar', ShellPageExtractor::kind("$dir/b.cbz"));
        $this->assertSame('7z', ShellPageExtractor::kind("$dir/c.cb7"));
        $this->assertSame('pdf', ShellPageExtractor::kind("$dir/d.pdf"));
        $this->assertNull(ShellPageExtractor::kind("$dir/e.cbz"));
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
