<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateePub;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Web fixes round: ePub download only once built, error pages, the reader
 * account's novel page, confirmations, the manga look-up flag, and small
 * copy / navigation fixes.
 */
class WebFixesRoundTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private ?string $epub = null;

    private ?string $apk = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('dashboard_attention', [], 900);
        config(['novarr.android_apk_path' => sys_get_temp_dir() . '/novarr-test-missing-' . uniqid() . '.apk']);
    }

    protected function tearDown(): void
    {
        foreach ([$this->epub, $this->apk, $this->apk ? $this->apk . '.json' : null] as $file) {
            if ($file !== null && is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    private function novel(string $name = 'Fixes Novel', array $attrs = []): Novel
    {
        return Novel::create(array_merge(['name' => $name, 'author' => 'Someone', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 0], $attrs));
    }

    private function chapter(Novel $novel, float $n, array $attrs = []): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0,
            'label' => "Chapter {$n}", 'url' => "https://example.test/{$novel->id}/c-{$n}",
        ]);
        $chapter->forceFill(array_merge(['status' => 1, 'download_date' => now()->subDay()], $attrs))->saveQuietly();

        return $chapter;
    }

    // ---- 2. Download ePub ---------------------------------------------------

    public function test_download_epub_is_listed_only_once_built_with_its_date(): void
    {
        $novel = $this->novel('Epub Novel');
        $this->chapter($novel, 1);

        $html = $this->get(route('novels.show', $novel->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<li id="epubDownloadItem" class="d-none">#', $html);
        $this->assertStringContainsString('data-command="epub"', $html, 'the generate item stays');

        File::ensureDirectoryExists(storage_path('app/ePub'));
        $this->epub = GenerateePub::epubPath($novel);
        File::put($this->epub, 'epub-bytes');
        $novel->forceFill(['epub_generated' => now()->setDate(2026, 3, 4)])->save();

        $html = $this->get(route('novels.show', $novel->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<li id="epubDownloadItem" class="">#', $html);
        $this->assertStringContainsString('<span class="item-suffix">4 Mar</span>', $html);
    }

    public function test_a_missing_epub_redirects_a_browser_back_and_404s_for_clients(): void
    {
        $novel = $this->novel('No Epub Yet');
        $page = route('novels.show', $novel->id);

        // A click in the browser (HTTPS: Sec-Fetch-Mode) goes back with a message.
        $this->get(route('novels.download_epub', $novel->id), ['Sec-Fetch-Mode' => 'navigate', 'Referer' => $page])
            ->assertRedirect($page)
            ->assertSessionHas('error', fn($m) => str_contains($m, 'No ePub has been generated'));
        // Plain http (no Sec-Fetch-*): an HTML request from one of our pages.
        $this->get(route('novels.download_epub', $novel->id), ['Accept' => 'text/html', 'Referer' => $page])
            ->assertRedirect($page);

        // OPDS readers, scripts and JSON clients keep the 404.
        $this->get(route('novels.download_epub', $novel->id))->assertNotFound();
        $this->get(route('novels.download_epub', $novel->id), ['Sec-Fetch-Mode' => 'cors', 'Referer' => $page])->assertNotFound();
        $this->getJson(route('novels.download_epub', $novel->id))->assertNotFound();
    }

    // ---- 3. Error pages -------------------------------------------------------

    public function test_error_pages_use_the_app_look_and_show_only_app_messages(): void
    {
        Route::get('/_test/abort/{code}', fn($code) => abort((int) $code, $code === '403' ? 'Only the admin can do that.' : ''));
        Route::get('/_test/boom', fn() => throw new \RuntimeException('secret internals'));

        $this->get('/no-such-page-' . uniqid())->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('This page does not exist')
            ->assertDontSee('could not be found')
            ->assertSee('class="card auth-card"', false);
        $this->get(route('novels.show', 999999))->assertNotFound()->assertDontSee('No query results');

        $this->get('/_test/abort/403')->assertForbidden()->assertSee('Only the admin can do that.')->assertSee('>Home</a>', false);
        $this->get('/_test/abort/419')->assertStatus(419)->assertSee('Page expired');
        $this->get('/_test/abort/429')->assertStatus(429)->assertSee('Too many requests');
        $this->get('/_test/abort/503')->assertStatus(503)->assertSee('Back soon');

        config(['app.debug' => false]);
        $this->get('/_test/boom')->assertStatus(500)->assertSee('Something went wrong')->assertDontSee('secret internals');
    }

    public function test_error_pages_render_signed_out_with_sign_in_on(): void
    {
        $this->enableSignIn();
        $this->asNobody()->get('/no-such-page-' . uniqid())->assertNotFound()->assertSee('Page not found');
    }

    // ---- 4. A reader's novel page ---------------------------------------------

    private function messyNovel(): Novel
    {
        $novel = $this->novel('Messy');
        $this->chapter($novel, 1);
        $this->chapter($novel, 1);                                                  // duplicate
        $this->chapter($novel, 4);                                                  // gaps 2, 3
        $this->chapter($novel, 5, ['status' => 0, 'download_date' => null]);        // queued
        $this->chapter($novel, 6, ['status' => 0, 'download_date' => null, 'attempts' => 9, 'last_failure_reason' => 'cloudflare']);

        return $novel;
    }

    public function test_a_reader_sees_one_quiet_line_and_not_downloaded_yet(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $novel = $this->messyNovel();

        $html = $this->withBrowser($this->webSignIn('sam', self::READER_PASSWORD))
            ->get(route('novels.show', $novel->id))->assertOk()->getContent();

        $this->assertStringContainsString('2 chapters still downloading', $html);
        foreach (['nv-issues', '</span> failed', 'missing from the numbering', 'duplicated', 'need review', 'Needs review', 'class="ch-reason"', 'data-state-filter="failed"', 'data-state-filter="queued"'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $this->assertStringContainsString('Not downloaded yet', $html);
        $this->assertStringContainsString('data-state-filter="pending"', $html);
        $this->assertStringContainsString('data-reasons="0"', $html);
        // Check for new chapters: a visible button, not in the overflow menu.
        $this->assertMatchesRegularExpression('#<button type="button" class="btn btn-secondary[^"]*reader-action-btn" data-reader-action="check"#', $html);
        $this->assertStringNotContainsString('dropdown-item reader-action-btn" data-reader-action="check"', $html);
    }

    public function test_the_admin_still_sees_every_figure(): void
    {
        $novel = $this->messyNovel();
        $html = $this->get(route('novels.show', $novel->id))->assertOk()->getContent();

        $this->assertStringContainsString('missing from the numbering', $html);
        $this->assertStringContainsString('Needs review', $html);
        $this->assertStringContainsString('class="ch-reason"', $html);
        $this->assertStringContainsString('data-reasons="1"', $html);
        $this->assertStringNotContainsString('still downloading', $html);
        $this->assertStringNotContainsString('data-reader-action="check"', $html);
    }

    // ---- 5. Confirmations -------------------------------------------------------

    public function test_revoke_and_turn_off_ask_first(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $this->tokenFor($owner, 'Kindle');
        $browser = $this->webSignIn($owner->username, self::OWNER_PASSWORD);

        $this->withBrowser($browser)->get(route('account'))->assertOk()
            ->assertSee('data-confirm="Sign this device out? A Kindle will need a new code to link again."', false);

        $users = $this->withBrowser($browser)->get(route('settings.users'))->assertOk()->getContent();
        $this->assertStringContainsString('data-confirm="Turn off Sam?', $users);
        $this->assertStringContainsString('data-confirm-text="Turn off"', $users);

        // Turning back on does not ask.
        $reader->forceFill(['disabled_at' => now()])->save();
        $users = $this->withBrowser($browser)->get(route('settings.users'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-confirm="Turn off Sam?', $users);

        // The handler is app-wide now (resources/js/confirm.js), not per page.
        $this->assertStringContainsString('export function initConfirmForms', file_get_contents(resource_path('js/confirm.js')));
        $this->assertStringContainsString('initConfirmForms();', file_get_contents(resource_path('js/app.js')));
    }

    public function test_the_devices_empty_state_names_android_and_kindle(): void
    {
        $owner = $this->enableSignIn();
        $this->withBrowser($this->webSignIn($owner->username, self::OWNER_PASSWORD))->get(route('account'))->assertOk()
            ->assertSee('The Android and iOS apps sign in with your name and password; a Kindle is linked with a code.');
    }

    // ---- 6. Manga look-up -------------------------------------------------------

    public function test_the_manga_view_passes_the_lookup_flag(): void
    {
        $manga = $this->novel('Lookup Manga', ['medium' => Novel::MEDIUM_MANGA]);
        $chapter = $this->chapter($manga, 1);

        $this->get(route('chapters.show', $chapter->id) . '?lookup=1')->assertOk()->assertSee('data-lookup="1"', false);
        $html = $this->get(route('chapters.show', $chapter->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-lookup=""', $html);
        $this->assertStringContainsString('id="mrPrevChapter"', $html);
        $this->assertStringContainsString('id="mrNextChapter"', $html);
        // The admin (sign-in off) saves the series default: no browser reset.
        $this->assertStringNotContainsString('id="mrModeReset"', $html);
    }

    // ---- 7. Copy and navigation -------------------------------------------------

    public function test_manga_library_has_no_offline_chip(): void
    {
        $this->get(route('novels.index', ['medium' => 'manga']))->assertOk()->assertDontSee('filter=offline', false);
        $this->get(route('novels.index', ['medium' => 'novel']))->assertOk()->assertSee('filter=offline', false);
    }

    public function test_offline_page_and_sign_in_copy(): void
    {
        $this->get(route('offline'))->assertOk()->assertSee('Open Offline')->assertDontSee('Downloads');

        $this->enableSignIn();
        $this->asNobody()->get('/login')->assertOk()
            ->assertSee('Forgot your password? Ask the person who runs this server to set a new one.');
    }

    public function test_apps_entry_appears_for_everyone_once_an_apk_is_published(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('app.android', [], false), $html);

        $this->apk = sys_get_temp_dir() . '/novarr-test-' . uniqid() . '.apk';
        file_put_contents($this->apk, 'apk');
        file_put_contents($this->apk . '.json', json_encode(['version' => '1.2', 'build' => 3]));
        config(['novarr.android_apk_path' => $this->apk]);

        // Sign-in off: no Account page, so the More menu and Settings carry it.
        $this->get(route('account'))->assertNotFound();
        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<a href="[^"]*/app/android" class="nv-sheet-item[^"]*"[^>]*download#', $html);
        $this->assertStringContainsString('<span>Apps</span>', $html);
        $this->assertStringContainsString('Android 1.2', $html);
        $this->get(route('settings.index'))->assertOk()->assertSee('id="appsCard"', false);

        // A reader (sign-in on) gets the More entry too.
        $this->enableSignIn();
        $this->makeReader();
        $this->withBrowser($this->webSignIn('sam', self::READER_PASSWORD))->get(route('home'))->assertOk()->assertSee('<span>Apps</span>', false);
    }
}
