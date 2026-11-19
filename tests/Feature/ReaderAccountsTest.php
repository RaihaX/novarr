<?php

namespace Tests\Feature;

use App\ApiToken;
use App\Bookmark;
use App\ChapterPage;
use App\ChapterRead;
use App\Novel;
use App\ReadingPosition;
use App\Support\AuthMode;
use App\Support\CurrentReader;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Reader accounts end to end, sign-in on: each account's own read state
 * through real requests, per-reader caching, signed asset URLs, OPDS Basic,
 * account management rules, what readers see, and the artisan escape hatch.
 */
class ReaderAccountsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    // ---- Read state per account -------------------------------------------

    public function test_two_signed_in_users_see_their_own_read_state(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        [$novel, $chapters] = $this->novelWithChapters('Shared Novel', 3);

        $ownerBrowser = $this->webSignIn($owner->username, self::OWNER_PASSWORD);
        $readerBrowser = $this->webSignIn('sam', self::READER_PASSWORD);

        // The reader opens chapter 1 (marks it read) and marks 2 read via the API-style write.
        $this->withBrowser($readerBrowser)->get(route('chapters.show', $chapters[1]->id))->assertOk();
        $this->withBrowser($readerBrowser)->postJson(route('chapters.progress', $chapters[2]->id), ['read' => true])->assertOk();
        // The owner marks chapter 3 through a token.
        $this->asToken($this->tokenFor($owner))->postJson("/api/v1/chapters/{$chapters[3]->id}/read", ['read' => true])->assertOk();

        $readFlags = fn(array $cookies) => collect($this->withBrowser($cookies)->getJson(route('novels.chapters_json', $novel->id))
            ->assertOk()->json('chapters'))->pluck('read', 'id')->all();

        $this->assertSame([$chapters[1]->id => true, $chapters[2]->id => true, $chapters[3]->id => false], $readFlags($readerBrowser));
        $this->assertSame([$chapters[1]->id => false, $chapters[2]->id => false, $chapters[3]->id => true], $readFlags($ownerBrowser));

        $this->assertSame(2, ChapterRead::where('user_id', $reader->id)->whereNotNull('read_at')->count());
        $this->assertSame(1, ChapterRead::where('user_id', $owner->id)->whereNotNull('read_at')->count());

        // The library's reading figures too (the HTML page).
        $this->withBrowser($readerBrowser)->get(route('novels.show', $novel->id))->assertOk()->assertSee('2 of 3 read');
        $this->withBrowser($ownerBrowser)->get(route('novels.show', $novel->id))->assertOk()->assertSee('1 of 3 read');
    }

    public function test_conditional_get_etags_include_the_reader(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        [$novel] = $this->novelWithChapters('Etag Novel', 2);
        $ownerToken = $this->tokenFor($owner);
        $readerToken = $this->tokenFor($reader);

        // Same read state, same body — still different ETags per reader.
        $ownerRes = $this->asToken($ownerToken)->getJson("/api/v1/novels/{$novel->id}/chapters")->assertOk();
        $readerRes = $this->asToken($readerToken)->getJson("/api/v1/novels/{$novel->id}/chapters")->assertOk();
        $this->assertSame($ownerRes->getContent(), $readerRes->getContent());
        $this->assertNotSame($ownerRes->headers->get('ETag'), $readerRes->headers->get('ETag'));
        $this->assertStringContainsString('private', $readerRes->headers->get('Cache-Control'));

        // One reader's ETag never earns another a 304.
        $this->asToken($readerToken)->getJson("/api/v1/novels/{$novel->id}/chapters", ['If-None-Match' => $ownerRes->headers->get('ETag')])->assertOk();
        $this->asToken($readerToken)->getJson("/api/v1/novels/{$novel->id}/chapters", ['If-None-Match' => $readerRes->headers->get('ETag')])->assertStatus(304);
        // The web routes' conditional GET too.
        $web = $this->asToken($readerToken)->getJson(route('novels.chapters_json', $novel->id))->assertOk();
        $this->asToken($ownerToken)->getJson(route('novels.chapters_json', $novel->id), ['If-None-Match' => $web->headers->get('ETag')])->assertOk();
    }

    // ---- Signed asset URLs --------------------------------------------------

    private function mangaWithPage(): array
    {
        Storage::fake('manga');
        [$manga, $chapters] = $this->novelWithChapters('A Manga', 1, Novel::MEDIUM_MANGA);
        $chapter = $chapters[1];
        $path = "{$manga->id}/{$chapter->id}/" . ChapterPage::fileName(1, 'png');
        Storage::disk('manga')->put($path, file_get_contents(base_path('tests/fixtures/mangadex/page.png')));
        ChapterPage::create(['novel_chapter_id' => $chapter->id, 'page' => 1, 'path' => $path, 'mime' => 'image/png', 'bytes' => Storage::disk('manga')->size($path)]);
        $chapter->forceFill(['page_count' => 1])->save();

        return [$manga, $chapter];
    }

    public function test_page_image_urls_are_signed_and_load_without_a_session_on_any_host(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        [, $chapter] = $this->mangaWithPage();

        $url = $this->asToken($this->tokenFor($reader))->getJson("/api/v1/chapters/{$chapter->id}/pages")
            ->assertOk()->json('pages.0.url');
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('reader=' . $reader->id, $url);
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        // No header, no cookie — as an <img> or the iOS app loads it.
        $this->asNobody()->get($path)->assertOk()->assertHeader('Content-Type', 'image/png');
        // The signature covers path + query only: any hostname works.
        foreach (['http://192.168.1.50:8080', 'https://novarr.tail1234.ts.net', 'http://localhost'] as $origin) {
            $this->asNobody()->get($origin . $path)->assertOk();
        }

        // Tampered (another page, another reader, no signature) → refused.
        $this->asNobody()->get(str_replace('/pages/1?', '/pages/2?', $path))->assertStatus(401);
        $this->asNobody()->get(str_replace('reader=' . $reader->id, 'reader=' . $this->owner()->id, $path))->assertStatus(401);
        $this->asNobody()->get(route('chapters.page', ['id' => $chapter->id, 'page' => 1], false))->assertStatus(401);

        // A disabled reader's URLs stop working.
        $reader->forceFill(['disabled_at' => now()])->save();
        $this->asNobody()->get($path)->assertStatus(401);
    }

    public function test_the_web_reader_and_its_no_script_fallback_carry_signed_urls(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        [, $chapter] = $this->mangaWithPage();
        $browser = $this->webSignIn('sam', self::READER_PASSWORD);

        $json = $this->withBrowser($browser)->getJson(route('chapters.pages', $chapter->id))->assertOk()->json('pages.0.url');
        $this->assertStringContainsString('signature=', $json);
        $html = $this->withBrowser($browser)->get(route('chapters.show', $chapter->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<img src="/chapters/' . $chapter->id . '/pages/1\?reader=\d+&amp;signature=[0-9a-f]+"#', $html);
    }

    public function test_with_sign_in_off_page_urls_are_unsigned_as_before(): void
    {
        [, $chapter] = $this->mangaWithPage();
        $url = $this->getJson("/api/v1/chapters/{$chapter->id}/pages")->assertOk()->json('pages.0.url');
        $this->assertSame(route('chapters.page', ['id' => $chapter->id, 'page' => 1]), $url);
        $this->get($url)->assertOk();
    }

    public function test_discover_cover_urls_are_signed_for_the_admin_and_refused_to_readers(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();

        $this->app->instance(CurrentReader::class, CurrentReader::for($owner));
        $ownerUrl = \App\Support\SignedUrls::route('discover.cover', ['u' => 'https://uploads.mangadex.org/covers/x.jpg']);
        $this->app->instance(CurrentReader::class, CurrentReader::for($reader));
        $readerUrl = \App\Support\SignedUrls::route('discover.cover', ['u' => 'https://uploads.mangadex.org/covers/x.jpg']);
        $this->assertStringStartsWith('/discover/cover?', $ownerUrl);

        // Signed for the reader, but Discover is the admin's.
        $this->asNobody()->get($readerUrl)->assertStatus(403);
        // The admin's signature gets past sign-in (then the controller decides: a
        // host outside the proxy list is a 404, no fetch).
        config(['novarr.discover_cover_proxy_hosts' => []]);
        $this->assertNotContains($this->asNobody()->get($ownerUrl)->getStatusCode(), [401, 403]);
        $this->asNobody()->get(str_replace('x.jpg', 'y.jpg', $ownerUrl))->assertStatus(401);
    }

    // ---- OPDS ----------------------------------------------------------------

    public function test_opds_accepts_http_basic_and_challenges_without_it(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $this->novelWithChapters('Opds Novel', 1);

        $this->asNobody()->get('/opds')->assertStatus(401)->assertHeader('WWW-Authenticate', 'Basic realm="Novarr", charset="UTF-8"');
        $this->asNobody()->get('/opds', ['Authorization' => 'Basic ' . base64_encode('SAM:' . self::READER_PASSWORD)])
            ->assertOk()->assertSee('<feed', false);
        $this->asNobody()->get('/opds', ['Authorization' => 'Basic ' . base64_encode('sam:wrong')])
            ->assertStatus(401)->assertHeader('WWW-Authenticate');
        // The ePub links of the feed take the same credentials (404: none built).
        $this->asNobody()->get(route('novels.download_epub', 1), ['Authorization' => 'Basic ' . base64_encode('sam:' . self::READER_PASSWORD)])
            ->assertNotFound();
        // Basic is only for OPDS: not a way into the rest.
        $this->asNobody()->getJson('/api/v1/novels', ['Authorization' => 'Basic ' . base64_encode('sam:' . self::READER_PASSWORD)])->assertStatus(401);
        // The session works too.
        $this->withBrowser($this->webSignIn('sam', self::READER_PASSWORD))->get('/opds')->assertOk();
    }

    // ---- Settings → Users ------------------------------------------------------

    public function test_users_are_added_and_managed_but_the_owner_is_protected(): void
    {
        $owner = $this->owner();

        // Sign-in off: the page works (accounts are prepared ahead).
        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'Kim.Lee', 'password' => 'short'])
            ->assertSessionHasErrors(['password'], null, 'add');
        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'Kim.Lee', 'password' => 'kim-pass-1'])
            ->assertRedirect(route('settings.users'));
        $kim = User::where('username', 'kim.lee')->sole();
        $this->assertSame(User::ROLE_READER, $kim->role);
        $this->assertNotNull($kim->password_set_at);
        $this->post(route('settings.users.store'), ['name' => 'Kim 2', 'username' => 'KIM.LEE', 'password' => 'kim-pass-1'])
            ->assertSessionHasErrors(['username'], null, 'add');

        // Rename, reset password.
        $this->put(route('settings.users.update', $kim->id), ['name' => 'Kim L', 'username' => 'kim'])->assertRedirect();
        $this->assertSame('kim', $kim->fresh()->username);
        $this->post(route('settings.users.password', $kim->id), ['password' => 'new-kim-pass'])->assertRedirect();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-kim-pass', $kim->fresh()->password));

        // The owner's own name and password (how they get one while off).
        $this->put(route('settings.users.update', $owner->id), ['name' => 'Reyhan', 'username' => 'reyhan'])->assertRedirect();
        $this->post(route('settings.users.password', $owner->id), ['password' => self::OWNER_PASSWORD])->assertRedirect();
        $this->assertNotNull($owner->fresh()->password_set_at);

        // The owner cannot be disabled or deleted; nobody's role changes.
        $this->post(route('settings.users.disable', $owner->id))->assertSessionHas('error');
        $this->delete(route('settings.users.destroy', $owner->id))->assertSessionHas('error');
        $this->assertNull($owner->fresh()->disabled_at);
        $this->assertNotNull($owner->fresh());
        $this->put(route('settings.users.update', $kim->id), ['name' => 'Kim', 'username' => 'kim', 'role' => 'admin']);
        $this->assertSame(User::ROLE_READER, $kim->fresh()->role);
        $this->assertSame(User::ROLE_ADMIN, $owner->fresh()->role);

        // Disable / enable a reader.
        $this->post(route('settings.users.disable', $kim->id))->assertSessionHas('status');
        $this->assertNotNull($kim->fresh()->disabled_at);
        $this->post(route('settings.users.disable', $kim->id));
        $this->assertNull($kim->fresh()->disabled_at);
    }

    public function test_deleting_a_reader_deletes_their_read_state_and_credentials(): void
    {
        $owner = $this->owner();
        $reader = $this->makeReader();
        [$novel, $chapters] = $this->novelWithChapters();
        $this->markRead($chapters[1], $reader);
        $this->markRead($chapters[1], $owner);
        ReadingPosition::create(['user_id' => $reader->id, 'novel_id' => $novel->id, 'novel_chapter_id' => $chapters[1]->id, 'position_at' => now()]);
        Bookmark::create(['user_id' => $reader->id, 'novel_id' => $novel->id, 'novel_chapter_id' => $chapters[1]->id, 'excerpt' => 'x']);
        $this->tokenFor($reader);

        $this->delete(route('settings.users.destroy', $reader->id))->assertRedirect(route('settings.users'));

        $this->assertNull(User::find($reader->id));
        $this->assertSame(0, ChapterRead::where('user_id', $reader->id)->count());
        $this->assertSame(0, ReadingPosition::where('user_id', $reader->id)->count());
        $this->assertSame(0, Bookmark::where('user_id', $reader->id)->count());
        $this->assertSame(0, ApiToken::where('user_id', $reader->id)->count());
        $this->assertSame(1, ChapterRead::where('user_id', $owner->id)->count(), "the owner's state stays");
    }

    public function test_turning_an_account_off_and_on_again_does_not_bring_old_sign_ins_back(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $login = $this->asNobody()->post('/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $browser = $this->cookiesOf($login);
        $remember = collect($browser)->filter(fn($v, $k) => str_starts_with($k, 'remember_web_'))->all();
        $token = $this->tokenFor($reader);
        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);

        // Off and straight back on, while the reader's browser made no request.
        $this->withBrowser($admin)->post(route('settings.users.disable', $reader->id))->assertSessionHas('status');
        $this->withBrowser($admin)->post(route('settings.users.disable', $reader->id));
        $this->assertNull($reader->fresh()->disabled_at);

        $this->withBrowser($browser)->get('/')->assertRedirect(route('login'));
        $this->withBrowser($remember)->get('/')->assertRedirect(route('login'));
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
        // A new sign-in works.
        $this->withBrowser($this->webSignIn('sam', self::READER_PASSWORD))->get('/')->assertOk();
    }

    public function test_a_password_reset_signs_the_account_out_everywhere(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $browser = $this->webSignIn('sam', self::READER_PASSWORD);
        $token = $this->tokenFor($reader);
        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);

        $this->withBrowser($admin)->post(route('settings.users.password', $reader->id), ['password' => 'reset-by-admin'])->assertRedirect();

        $this->withBrowser($browser)->get('/')->assertRedirect(route('login'));
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
        // The admin is still in.
        $this->withBrowser($admin)->get(route('settings.users'))->assertOk();
    }

    public function test_changing_your_own_password_keeps_this_browser_and_your_devices(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $other = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->travel(2)->seconds();
        $here = $this->webSignIn('sam', self::READER_PASSWORD);
        $token = $this->tokenFor($reader);

        $change = $this->withBrowser($here)->post(route('account.password'), [
            'current_password' => self::READER_PASSWORD, 'password' => 'brand-new-2', 'password_confirmation' => 'brand-new-2',
        ])->assertRedirect(route('account'));

        $this->withBrowser($this->cookiesOf($change) + $here)->get(route('account'))->assertOk();
        $this->withBrowser($other)->get('/')->assertRedirect(route('login'));
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_pairing_links_to_one_account_only(): void
    {
        $first = $this->makeReader('first', 'First');
        $second = $this->makeReader('second', 'Second');
        [$pairing] = \App\DevicePairing::begin('k', 'Kindle');

        $this->assertNotNull($pairing->link($first));
        $this->assertNull(\App\DevicePairing::find($pairing->id)->link($second));
        $this->assertSame($first->id, $pairing->fresh()->user_id);
        $this->assertSame(1, ApiToken::count());
    }

    public function test_readers_cannot_manage_users(): void
    {
        $this->enableSignIn();
        $other = $this->makeReader('other', 'Other');
        $token = $this->tokenFor($this->makeReader());

        $this->asToken($token)->get(route('settings.users'))->assertStatus(403);
        $this->asToken($token)->delete(route('settings.users.destroy', $other->id))->assertStatus(403);
        $this->asToken($token)->post(route('settings.users.auth'), ['required' => 0])->assertStatus(403);
        $this->assertNotNull($other->fresh());
        $this->assertTrue(AuthMode::required());
    }

    // ---- Account page ------------------------------------------------------------

    public function test_account_page_changes_name_and_password_and_revokes_devices(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $browser = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->tokenFor($reader, 'Sam’s iPhone');

        $page = $this->withBrowser($browser)->get(route('account'))->assertOk()->getContent();
        $this->assertStringContainsString('Sam’s iPhone', $page);
        $this->assertStringContainsString('Link a device', $page);

        $this->withBrowser($browser)->post(route('account.profile'), ['name' => 'Samantha'])->assertRedirect(route('account'));
        $this->assertSame('Samantha', $reader->fresh()->name);

        $this->withBrowser($browser)->post(route('account.password'), ['current_password' => 'wrong', 'password' => 'brand-new-1', 'password_confirmation' => 'brand-new-1'])
            ->assertSessionHasErrors(['current_password'], null, 'password');
        $changed = $this->withBrowser($browser)->post(route('account.password'), ['current_password' => self::READER_PASSWORD, 'password' => 'brand-new-1', 'password_confirmation' => 'brand-new-1'])
            ->assertRedirect(route('account'));
        // This browser carries on with the cookies the change handed it.
        $browser = $this->cookiesOf($changed) + $browser;
        $this->asNobody()->post('/login', ['username' => 'sam', 'password' => 'brand-new-1'])->assertRedirect(route('home'));

        $token = ApiToken::where('user_id', $reader->id)->sole();
        $this->withBrowser($browser)->delete(route('account.tokens.revoke', $token->id))->assertRedirect(route('account'));
        $this->assertNotNull($token->fresh()->revoked_at);

        // Someone else's token cannot be revoked from here.
        $ownerToken = ApiToken::issue($this->owner(), 'Owner phone')[0];
        $this->withBrowser($browser)->delete(route('account.tokens.revoke', $ownerToken->id))->assertNotFound();
        $this->assertNull($ownerToken->fresh()->revoked_at);
    }

    public function test_there_is_no_account_page_while_sign_in_is_off(): void
    {
        $this->get(route('account'))->assertNotFound();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('accountMenuBtn', $html);
        $this->assertStringNotContainsString(route('account'), $html);
    }

    // ---- What a reader sees ------------------------------------------------------

    public function test_readers_see_no_admin_controls_on_home_library_series_page_and_nav(): void
    {
        $owner = $this->enableSignIn();
        $this->makeReader();
        [$novel, $chapters] = $this->novelWithChapters('Visible Novel', 3);
        $this->markRead($chapters[1], User::where('username', 'sam')->sole());
        $reader = $this->webSignIn('sam', self::READER_PASSWORD);
        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);

        $pages = [
            'home' => '/',
            'library' => route('novels.index'),
            'library-table' => route('novels.index', ['view' => 'table']),
            'series' => route('novels.show', $novel->id),
        ];
        $adminOnly = [
            'nav' => ['id="systemDropdown"', 'href="' . route('settings.index') . '"', 'href="' . route('activity.index') . '"', 'id="funnelBanner"'],
            'home' => ['id="statusCard"', 'home-navstat', 'novels/discover'],
            'library' => ['id="bulkDelete"', 'id="bulkComplete"', '+ Add'],
            'library-table' => ['novel-delete-btn" data-id', 'novel-complete-btn" data-id'],
            'series' => ['Edit details', 'id="deleteNovel"', 'id="pauseToggle"', 'id="maintenancePanel"', 'id="editTags"',
                'data-command="epub"', 'send_to_kindle', 'id="frequentToggle"', 'href="' . route('novels.edit', $novel->id) . '"'],
        ];

        foreach ($pages as $page => $url) {
            $readerHtml = $this->withBrowser($reader)->get($url)->assertOk()->getContent();
            $adminHtml = $this->withBrowser($admin)->get($url)->assertOk()->getContent();
            foreach (array_merge($adminOnly['nav'], $adminOnly[$page]) as $needle) {
                $this->assertStringNotContainsString($needle, $readerHtml, "reader {$page} shows {$needle}");
                $this->assertStringContainsString($needle, $adminHtml, "admin {$page} lacks {$needle}");
            }
            // Both see who they are and how to sign out.
            $this->assertStringContainsString('Signed in as <strong>sam</strong>', $readerHtml);
            $this->assertStringContainsString('action="' . route('logout') . '"', $readerHtml);
        }

        // The reader's own actions on the series page.
        $series = $this->withBrowser($reader)->get(route('novels.show', $novel->id))->getContent();
        $this->assertStringContainsString('data-reader-action="check"', $series);
        $this->assertStringContainsString('data-reader-action="epub"', $series);
        $this->assertStringContainsString('Download ePub', $series);
        $library = $this->withBrowser($reader)->get(route('novels.index'))->getContent();
        $this->assertStringContainsString('id="bulkRead"', $library, 'mark read stays: it is their own state');

        // The palette's server-side index: no admin pages, no commands.
        $readerHome = $this->withBrowser($reader)->get('/')->getContent();
        preg_match('#<script type="application/json" id="paletteNav">(.*?)</script>#s', $readerHome, $m);
        $nav = json_decode($m[1], true);
        $this->assertFalse($nav['commands']);
        $titles = array_column($nav['nav'], 'title');
        foreach (['Discover', 'Add a novel', 'Add a manga', 'Activity', 'Commands', 'Logs', 'Health', 'Settings'] as $title) {
            $this->assertNotContains($title, $titles);
        }
        $this->assertContains('Account', $titles);
        $this->assertContains('Stats', $titles);
    }

    public function test_an_empty_library_invites_the_admin_to_add_but_not_a_reader(): void
    {
        $owner = $this->enableSignIn();
        $this->makeReader();

        $reader = $this->webSignIn('sam', self::READER_PASSWORD);
        $readerHtml = $this->withBrowser($reader)->get(route('novels.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Nothing here yet', $readerHtml);
        $this->assertStringNotContainsString('Add your first', $readerHtml);
        $home = $this->withBrowser($reader)->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Discover novels', $home);

        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);
        $this->withBrowser($admin)->get(route('novels.index'))->assertOk()->assertSee('Add your first');
    }

    public function test_the_manga_reader_hides_the_reading_mode_from_readers(): void
    {
        $owner = $this->enableSignIn();
        $this->makeReader();
        [, $chapter] = $this->mangaWithPage();

        $readerHtml = $this->withBrowser($this->webSignIn('sam', self::READER_PASSWORD))->get(route('chapters.show', $chapter->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-mode-url', $readerHtml);
        $this->assertStringNotContainsString('mr-modes', $readerHtml);
        $adminHtml = $this->withBrowser($this->webSignIn($owner->username, self::OWNER_PASSWORD))->get(route('chapters.show', $chapter->id))->getContent();
        $this->assertStringContainsString('data-mode-url', $adminHtml);
    }

    public function test_the_page_names_its_reader_for_the_offline_queue(): void
    {
        $owner = $this->owner();
        $this->assertStringContainsString('<meta name="novarr-reader" content="' . $owner->id . '" data-owner="1">', $this->get('/')->getContent());

        $this->enableSignIn();
        $reader = $this->makeReader();
        $html = $this->withBrowser($this->webSignIn('sam', self::READER_PASSWORD))->get('/')->getContent();
        $this->assertStringContainsString('<meta name="novarr-reader" content="' . $reader->id . '" data-owner="0">', $html);
        $this->assertStringNotContainsString('novarr-reader', $this->asNobody()->get('/login')->getContent());
    }

    public function test_reader_triggered_checks_are_throttled_but_the_admins_are_not(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $owner = $this->enableSignIn();
        [$novel] = $this->novelWithChapters();
        $readerToken = $this->tokenFor($this->makeReader());

        for ($i = 0; $i < 6; $i++) {
            $this->asToken($readerToken)->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202);
        }
        $this->asToken($readerToken)->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(429);
        $this->asToken($this->tokenFor($owner))->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202);
    }

    // ---- Artisan escape hatch ----------------------------------------------------

    public function test_artisan_auth_and_password_commands(): void
    {
        $owner = $this->owner();

        $this->artisan('novarr:auth', ['action' => 'on'])->expectsOutputToContain('Set a password for the owner account first')->assertExitCode(1);
        $this->assertFalse(AuthMode::required());

        $this->artisan('novarr:password', ['--password' => 'short'])->assertExitCode(1);
        $this->artisan('novarr:password')
            ->expectsQuestion("New password for {$owner->name} ({$owner->username})", 'console-pass-1')
            ->expectsQuestion('Same password again', 'console-pass-1')
            ->assertExitCode(0);
        $this->assertNotNull($owner->fresh()->password_set_at);

        $this->artisan('novarr:auth', ['action' => 'on'])->expectsOutputToContain('Sign-in is on.')->assertExitCode(0);
        $this->assertTrue(AuthMode::required());
        $this->asNobody()->get('/')->assertRedirect(route('login'));
        $this->post('/login', ['username' => strtoupper($owner->username), 'password' => 'console-pass-1'])->assertRedirect(route('home'));

        $this->artisan('novarr:auth', ['action' => 'status'])->expectsOutputToContain('In effect')->assertExitCode(0);
        $this->artisan('novarr:auth', ['action' => 'off'])->assertExitCode(0);
        $this->assertFalse(AuthMode::required());
        $this->artisan('novarr:auth', ['action' => 'sideways'])->assertExitCode(2);

        $reader = $this->makeReader();
        $this->artisan('novarr:password', ['username' => 'SAM', '--password' => 'sam-new-pass'])->assertExitCode(0);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('sam-new-pass', $reader->fresh()->password));
        $this->artisan('novarr:password', ['username' => 'nobody', '--password' => 'whatever-1'])->assertExitCode(1);
        $this->artisan('novarr:password')
            ->expectsQuestion("New password for {$owner->name} ({$owner->username})", 'one-password')
            ->expectsQuestion('Same password again', 'another-one')
            ->assertExitCode(1);
    }

    public function test_console_and_queue_read_as_the_owner(): void
    {
        $this->enableSignIn();
        $this->assertSame($this->owner()->id, app(CurrentReader::class)->id());
    }
}
