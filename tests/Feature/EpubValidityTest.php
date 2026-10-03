<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateePub;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use ZipArchive;

/**
 * novel:epub must (M1) process every eligible novel, (M2) emit well-formed
 * XHTML Kindle accepts, (M3) build in a throwaway directory and zip only the
 * manifest, and (M4) exit non-zero when any novel fails.
 */
class EpubValidityTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                File::delete($path);
            }
        }
        parent::tearDown();
    }

    private function seedNovel(string $name, int $chapters = 1, array $bodies = []): Novel
    {
        $novel = Novel::create([
            'name' => $name,
            'author' => 'Tester',
            'status' => 1,
            'group_id' => 1,
            'no_of_chapters' => $chapters,
        ]);

        for ($i = 1; $i <= $chapters; $i++) {
            $chapter = new NovelChapter([
                'novel_id' => $novel->id,
                'chapter' => $i,
                'book' => 0,
                'label' => "Chapter {$i} & <Friends>",
                'url' => "https://example.test/{$novel->id}/chapter-{$i}",
            ]);
            $chapter->status = 1;
            $chapter->blacklist = 0;
            $chapter->description = $bodies[$i - 1] ?? "<p>Body {$i}</p>";
            $chapter->save();
        }

        return $novel;
    }

    /** A GenerateePub whose per-novel work is stubbed (and optionally fails). */
    private function stubCommand(array $failIds = []): GenerateePub
    {
        $command = new class extends GenerateePub {
            public array $seen = [];
            public array $failIds = [];

            protected function generateEpubForNovel(Novel $novel, bool $forceRegenerate = false): void
            {
                $this->seen[] = $novel->id;
                if (in_array($novel->id, $this->failIds, true)) {
                    throw new \RuntimeException('boom');
                }
                // Same mutation the real generator makes — this is what made
                // offset-based chunk() skip half of the eligible novels.
                $novel->epub_generated = now();
                $novel->save();
            }

            public function sanitize(?string $html): string
            {
                return $this->sanitizeHtmlContent($html);
            }
        };
        $command->failIds = $failIds;
        $command->setLaravel($this->app);

        return $command;
    }

    private function runCommand(GenerateePub $command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new BufferedOutput());
    }

    public function test_every_eligible_novel_is_processed(): void
    {
        $ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $ids[] = $this->seedNovel("Novel {$i}")->id;
        }

        $command = $this->stubCommand();
        $exit = $this->runCommand($command);

        $this->assertSame(GenerateePub::SUCCESS, $exit);
        sort($command->seen);
        $this->assertSame($ids, $command->seen);
        $this->assertSame(0, Novel::whereNull('epub_generated')->count());
    }

    public function test_a_failed_novel_makes_the_command_exit_non_zero(): void
    {
        $a = $this->seedNovel('Fine');
        $b = $this->seedNovel('Broken');
        $c = $this->seedNovel('Also fine');

        $command = $this->stubCommand([$b->id]);
        $exit = $this->runCommand($command);

        $this->assertSame(GenerateePub::FAILURE, $exit);
        // The failure doesn't stop the remaining novels.
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $command->seen);
    }

    public static function bodies(): array
    {
        return [
            'stray less-than' => ['I <3 you'],
            'mixed ampersands' => ['A & B &amp; C'],
            'html void element' => ['<p>x</p><br>'],
            'named html entities' => ['<p>&nbsp;caf&eacute; &mdash; &hellip; &bogus;</p>'],
            'unclosed and nested' => ['<p><b>bold <i>both</p> after'],
            'attributes and scripts' => ['<p onclick="x()" style="a">ok</p><script>if (a < b && c) {}</script><!-- c -- c -->'],
            'control characters' => ["<p>a\x01b\x0Bc</p>"],
            'empty paragraph' => ['<p></p><hr>'],
            'cdata-like' => ['<![CDATA[ x ]]> y'],
        ];
    }

    /** @dataProvider bodies */
    public function test_chapter_bodies_are_well_formed_xhtml(string $body): void
    {
        $xhtml = $this->stubCommand()->sanitize($body);

        $wrapped = '<section xmlns="http://www.w3.org/1999/xhtml">' . $xhtml . '</section>';
        libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($wrapped);
        libxml_clear_errors();

        $this->assertNotFalse($parsed, "Not well-formed: {$xhtml}");
        $this->assertStringNotContainsString('<script', $xhtml);
        $this->assertStringNotContainsString('onclick', $xhtml);
    }

    public function test_text_survives_sanitising(): void
    {
        $command = $this->stubCommand();

        $this->assertStringContainsString('I &lt;3 you', $command->sanitize('I <3 you'));
        $this->assertSame('A &amp; B &amp; C', $command->sanitize('A & B &amp; C'));
        $this->assertSame('<p>x</p><br/>', $command->sanitize('<p>x</p><br>'));
        $this->assertSame('<p></p>', $command->sanitize(''));
    }

    public function test_generated_epub_parts_are_well_formed_and_only_manifest_files_are_zipped(): void
    {
        $novel = $this->seedNovel('Validity Check', 3, [
            '<p>A &amp; B &lt;3 C</p><br>',
            'Plain text with &nbsp; and &mdash; entities',
            '<p>Unclosed <b>bold',
        ]);
        $this->written[] = GenerateePub::epubPath($novel);

        // A stale file in the legacy fixed build dir must not end up in the book.
        $legacy = storage_path("app/Novel/{$novel->id}/OEBPS/Text");
        File::ensureDirectoryExists($legacy);
        File::put("{$legacy}/chapter_99999.xhtml", '<not xml');

        $this->artisan('novel:epub', ['novel' => $novel->id])->assertExitCode(0);

        $this->assertDirectoryDoesNotExist(storage_path("app/Novel/{$novel->id}"));
        $this->assertSame([], glob(storage_path("app/epub-build/{$novel->id}-*")) ?: []);
        $this->assertNotNull($novel->fresh()->epub_generated);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open(GenerateePub::epubPath($novel)) === true);
        $this->assertSame('mimetype', $zip->statIndex(0)['name']);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $opf = $zip->getFromName('OEBPS/content.opf');
        preg_match_all('/<item [^>]*href="([^"]+)"/', $opf, $m);
        $expected = array_merge(
            ['mimetype', 'META-INF/container.xml', 'OEBPS/content.opf'],
            array_map(fn ($href) => "OEBPS/{$href}", $m[1])
        );
        $this->assertEqualsCanonicalizing($expected, $names);

        $chapterIds = NovelChapter::where('novel_id', $novel->id)->pluck('id');
        foreach ($chapterIds as $id) {
            $this->assertContains(sprintf('OEBPS/Text/chapter_%05d.xhtml', $id), $names);
        }

        libxml_use_internal_errors(true);
        foreach ($names as $name) {
            if (!preg_match('/\.(xhtml|opf|ncx|xml)$/', $name)) {
                continue;
            }
            $this->assertNotFalse(
                simplexml_load_string($zip->getFromName($name)),
                "{$name} is not well-formed XML"
            );
        }
        libxml_clear_errors();
        $zip->close();
    }
}
