<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Accessibility pass: landmark + skip target, modal dialog semantics in the
 * reader, captioned / card-responsive tables, no click handlers on divs.
 */
class A11yTest extends TestCase
{
    use RefreshDatabase;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = storage_path('logs/a11y-test-' . Str::random(6) . '.log');
        File::put($this->logFile, '[2026-10-03 10:00:00] testing.ERROR: ' . str_repeat('very-long-unbroken-token-', 20) . PHP_EOL
            . '[2026-10-03 10:00:01] testing.INFO: second entry' . PHP_EOL);
    }

    protected function tearDown(): void
    {
        File::delete($this->logFile);
        parent::tearDown();
    }

    private function novelWithChapters(int $count = 3): array
    {
        $novel = Novel::create(['name' => 'A11y Novel', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => $count]);
        $chapters = [];
        for ($n = 1; $n <= $count; $n++) {
            $c = NovelChapter::create([
                'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0,
                'label' => "Chapter {$n}", 'url' => "https://example.test/a11y/{$n}",
            ]);
            $c->description = "<p>Body {$n}.</p>";
            $c->status = 1;
            $c->download_date = now();
            $c->save();
            $chapters[] = $c;
        }

        return [$novel, $chapters];
    }

    /** Key pages render exactly one <main> landmark that the skip link targets. */
    public function test_key_pages_have_a_single_main_landmark_the_skip_link_targets(): void
    {
        [$novel, $chapters] = $this->novelWithChapters();

        $urls = [
            route('health.index'), route('stats.index'), route('logs.index'),
            route('logs.show', basename($this->logFile)), route('commands.index'),
            route('settings.index'), route('search.index', ['q' => 'sword']),
            route('bookmarks.index'), route('chapters.show', $chapters[1]->id),
        ];

        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            // The layout owns the landmark (id="main-content"); views must not add a second one.
            $this->assertSame(1, preg_match_all('/<main\b/i', $html), "one <main> on {$url}");
            $this->assertMatchesRegularExpression('/<main\b[^>]*\bid="main-content"/', $html, "main#main-content on {$url}");
            $this->assertStringContainsString('href="#main-content"', $html, "skip link on {$url}");
        }
    }

    public function test_reader_dialogs_are_labelled_modal_dialogs(): void
    {
        [, $chapters] = $this->novelWithChapters();
        $html = $this->get(route('chapters.show', $chapters[1]->id))->assertOk()->getContent();

        // Aa sheet, chapter drawer and "?" overlay are modal.
        foreach (['readerSettings' => 'readerSettingsTitle', 'tocPanel' => 'tocPanelLabel', 'readerKeys' => 'readerKeysTitle'] as $id => $label) {
            $this->assertMatchesRegularExpression('/<div[^>]*id="' . $id . '"[^>]*>/', $html);
            preg_match('/<div[^>]*id="' . $id . '"[^>]*>/', $html, $m);
            $this->assertStringContainsString('role="dialog"', $m[0], $id);
            $this->assertStringContainsString('aria-modal="true"', $m[0], $id);
            $this->assertStringContainsString('aria-labelledby="' . $label . '"', $m[0], $id);
            $this->assertStringContainsString('id="' . $label . '"', $html, "{$id} label target");
        }

        // Playback is a labelled, deliberately non-modal dialog.
        preg_match('/<div[^>]*id="readerPlayback"[^>]*>/', $html, $m);
        $this->assertStringContainsString('role="dialog"', $m[0]);
        $this->assertStringContainsString('aria-modal="false"', $m[0]);
        $this->assertStringContainsString('aria-labelledby="readerPlaybackTitle"', $m[0]);

        // Focus-trap / inert plumbing and the shell hook are wired in.
        $this->assertStringContainsString('window.Novarr', $html);
        $this->assertStringContainsString('focusTrap', $html);
        $this->assertStringContainsString('.inert = true', $html);

        // The shortcut list documents only bound keys.
        $this->assertStringContainsString("e.key === 'ArrowLeft'", $html);
        $this->assertStringContainsString("e.key !== '?'", $html);
    }

    public function test_reader_icon_only_controls_have_accessible_names(): void
    {
        [, $chapters] = $this->novelWithChapters();
        $html = $this->get(route('chapters.show', $chapters[1]->id))->assertOk()->getContent();

        foreach (['readerSettingsBtn', 'playbackBtn', 'tocBtn', 'focusBtn', 'ttsStop'] as $id) {
            $this->assertMatchesRegularExpression('/id="' . $id . '"[^>]*aria-label="[^"]+"|aria-label="[^"]+"[^>]*id="' . $id . '"/', $html, $id);
        }
        $this->assertStringContainsString('aria-label="Close chapter list"', $html);
        $this->assertStringContainsString('aria-label="Close keyboard shortcuts"', $html);
    }

    public function test_tables_have_captions_and_card_labels(): void
    {
        [$novel, $chapters] = $this->novelWithChapters();
        $chapters[0]->forceFill(['read_at' => now()])->save();
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'RuntimeException: boom', 'failed_at' => now(),
        ]);

        $pages = [
            route('stats.index') => ['Most-read novels', 'data-label="Chapters"', 'data-label="Last read"'],
            route('health.index') => ['Failed queue jobs', 'data-label="Queue"', 'data-label="Error"'],
            route('logs.index') => ['Log files', 'data-label="Size"', 'data-label="Modified"'],
            route('logs.show', basename($this->logFile)) => ['Entries in', 'data-label="Timestamp"', 'data-label="Level"'],
        ];

        foreach ($pages as $url => $needles) {
            $html = $this->get($url)->assertOk()->getContent();
            $tables = preg_match_all('/<table\b/i', $html);
            $captions = preg_match_all('/<caption class="visually-hidden">/', $html);
            $this->assertGreaterThan(0, $tables, $url);
            $this->assertSame($tables, $captions, "every table on {$url} has a caption");
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $html, $url);
            }
        }

        // Phones: these tables stack as cards; the log viewer wraps.
        $this->assertStringContainsString('table-responsive-cards', $this->get(route('stats.index'))->getContent());
        $this->assertStringContainsString('table-responsive-cards', $this->get(route('health.index'))->getContent());
        $this->assertStringContainsString('table-responsive-cards', $this->get(route('logs.index'))->getContent());
        $scss = File::get(resource_path('css/_tables.scss'));
        $this->assertStringContainsString('.table-responsive-cards', $scss);
        $this->assertStringContainsString('white-space: pre-wrap', $scss);
        $this->assertStringContainsString('attr(data-label)', $scss);
    }

    public function test_owned_views_have_no_click_handlers_on_divs(): void
    {
        $files = array_merge(
            [
                'chapters/show.blade.php', 'logs/show.blade.php', 'logs/index.blade.php',
                'stats/index.blade.php', 'health/index.blade.php', 'settings/index.blade.php',
                'search/index.blade.php', 'bookmarks/index.blade.php',
            ],
            array_map(fn ($f) => 'commands/' . basename($f), glob(resource_path('views/commands/*.blade.php'))),
        );

        foreach ($files as $file) {
            $src = File::get(resource_path('views/' . $file));
            $this->assertDoesNotMatchRegularExpression('/<(div|span|li|tr|td|section)\b[^>]*\sonclick=/i', $src, "{$file}: onclick on a non-interactive element");
            $this->assertDoesNotMatchRegularExpression('/\sonclick=/i', $src, "{$file}: inline onclick handler");
        }
    }

    public function test_a11y_stylesheet_covers_focus_rings_and_reduced_motion(): void
    {
        $scss = File::get(resource_path('css/_a11y.scss'));
        $this->assertStringContainsString(':focus-visible', $scss);
        $this->assertStringContainsString('outline: 2px solid $accent', $scss);
        $this->assertStringContainsString('outline-offset: 2px', $scss);
        $this->assertStringContainsString('.toc-row:focus-visible', $scss);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $scss);
        foreach (['.reader-chrome', '.reader-pop', '.toast'] as $sel) {
            $this->assertStringContainsString($sel, $scss);
        }
    }
}
