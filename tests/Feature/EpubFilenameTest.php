<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateePub;
use App\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The generator, the download action and OPDS must agree on the ePub path —
 * reserved characters and a missing author used to make downloads 404.
 */
class EpubFilenameTest extends TestCase
{
    use RefreshDatabase;

    private ?string $written = null;

    protected function tearDown(): void
    {
        if ($this->written && is_file($this->written)) {
            File::delete($this->written);
        }
        parent::tearDown();
    }

    public function test_sanitised_name_and_missing_author_resolve_to_the_same_file(): void
    {
        $novel = Novel::create([
            'name' => 'Re:Zero / Test',
            'status' => 1,
            'group_id' => 1,
            'no_of_chapters' => 1,
        ]);
        $this->assertNull($novel->author);

        $this->assertSame('ReZero Test - Unknown.epub', GenerateePub::epubFilename($novel));
        $this->assertSame(storage_path('app/ePub/ReZero Test - Unknown.epub'), GenerateePub::epubPath($novel));

        // Not generated yet → the download action 404s.
        $this->get(route('novels.download_epub', $novel->id))->assertNotFound();

        // Simulate the generator's output at its path; the download (which is
        // also the OPDS acquisition link) must now find it.
        File::ensureDirectoryExists(storage_path('app/ePub'));
        $this->written = GenerateePub::epubPath($novel);
        File::put($this->written, 'epub-bytes');

        $this->get(route('novels.download_epub', $novel->id))
            ->assertOk()
            ->assertDownload('ReZero Test - Unknown.epub');
    }
}
