<?php

namespace Tests\Feature;

use App\Http\Controllers\DownloadController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The grouped top nav (partials/navbar-menu): Home · Library ▾ · Requests ·
 * Discover · Manage ▾ · System ▾. Readers get Home, Library and Requests; the
 * group of the current page is marked active; counts sit on the toggles.
 */
class NavGroupsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    /** The <li> of one nav group, or null. */
    private function group(string $html, string $name): ?string
    {
        return preg_match('#<li class="nav-item dropdown nav-group" data-nav-group="' . $name . '">.*?</ul>\s*</li>#s', $html, $m) ? $m[0] : null;
    }

    private function toggleIsActive(string $group): bool
    {
        return (bool) preg_match('#<button type="button" class="nav-link dropdown-toggle active"[^>]*aria-current="true"#', $group);
    }

    public function test_readers_see_library_and_requests_but_not_manage_or_system(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $html = $this->withBrowser($this->webSignIn('sam', self::READER_PASSWORD))->get('/')->assertOk()->getContent();

        $library = $this->group($html, 'library');
        $this->assertNotNull($library);
        foreach (['Web Novels', 'Manga', 'Books', 'Audiobooks', 'Want to read', 'Highlights', 'Stats', 'Offline', 'Upload a book'] as $label) {
            $this->assertStringContainsString('>' . $label . '</a>', $library, "reader Library lacks {$label}");
        }
        $this->assertStringContainsString('id="navRequests"', $html);
        $this->assertNull($this->group($html, 'manage'));
        $this->assertNull($this->group($html, 'system'));
        foreach (['id="manageDropdown"', 'id="systemDropdown"', 'id="navDiscover"', 'href="' . route('downloads.index') . '"', 'href="' . route('settings.index') . '"', 'href="' . route('health.storage') . '"'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "reader sees {$needle}");
        }
        // The account menu: Account, Link a device, Sign out.
        $this->assertStringContainsString('href="' . route('account') . '#link">Link a device</a>', $html);
        $this->assertStringContainsString('action="' . route('logout') . '"', $html);
    }

    public function test_the_owner_sees_every_group(): void
    {
        $owner = $this->enableSignIn();
        $html = $this->withBrowser($this->webSignIn($owner->username, self::OWNER_PASSWORD))->get('/')->assertOk()->getContent();

        $manage = $this->group($html, 'manage');
        $system = $this->group($html, 'system');
        $this->assertNotNull($manage);
        $this->assertNotNull($system);
        foreach ([route('downloads.index'), route('audiobooks.manage'), route('books.calibre'), route('books.upload'), route('novels.create')] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $manage);
        }
        foreach (['health.index', 'health.storage', 'health.sources', 'readers.index', 'activity.index', 'logs.index', 'commands.index', 'settings.index'] as $name) {
            $this->assertStringContainsString('href="' . route($name) . '"', $system, "System lacks {$name}");
        }
        $this->assertStringContainsString('id="navDiscover"', $html);
        // The owner's upload is under Manage, not in Library.
        $this->assertStringNotContainsString('Upload a book', $this->group($html, 'library'));
        // Nothing marked on Home but Home.
        $this->assertFalse($this->toggleIsActive($this->group($html, 'library')));
        $this->assertFalse($this->toggleIsActive($manage));
        $this->assertFalse($this->toggleIsActive($system));
    }

    public function test_the_group_of_the_current_page_is_marked_active(): void
    {
        $cases = [
            route('want_to_read') => ['library', route('want_to_read')],
            route('audiobooks.index') => ['library', route('audiobooks.index')],
            route('stats.index') => ['library', route('stats.index')],
            route('audiobooks.manage') => ['manage', route('audiobooks.manage')],
            route('books.calibre') => ['manage', route('books.calibre')],
            route('health.storage') => ['system', route('health.storage')],
            route('settings.index') => ['system', route('settings.index')],
        ];
        foreach ($cases as $url => [$groupName, $childHref]) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach (['library', 'manage', 'system'] as $name) {
                $this->assertSame($name === $groupName, $this->toggleIsActive($this->group($html, $name)), "{$url}: {$name}");
            }
            $this->assertMatchesRegularExpression('#class="dropdown-item active"\s+aria-current="page"\s+href="' . preg_quote($childHref, '#') . '"#', $this->group($html, $groupName), $url);
        }
        // Health's own page marks Health, not Storage or Sources.
        $system = $this->group($this->get(route('health.index'))->getContent(), 'system');
        $this->assertSame(1, substr_count($system, 'dropdown-item active'));
        // Discover is its own item: no group is active there.
        $html = $this->get(route('novels.discover'))->getContent();
        $this->assertMatchesRegularExpression('#class="nav-link active"\s+aria-current="page"\s+href="' . preg_quote(route('novels.discover'), '#') . '"#', $html);
        $this->assertFalse($this->toggleIsActive($this->group($html, 'library')));
    }

    public function test_the_nav_count_is_the_needs_you_tab_count_in_one_query(): void
    {
        $this->assertSame(0, DownloadController::attentionTotal());

        \App\Audiobook::create(['title' => 'Mystery Tape', 'status' => \App\Audiobook::STATUS_UNMATCHED, 'dir' => 'x', 'fingerprint' => 'fp1']);
        $grab = fn(array $a) => \App\DownloadGrab::create(array_merge([
            'kind' => 'ebook', 'protocol' => 'usenet', 'client' => 'nzbget', 'client_ref' => '101', 'title' => 'T', 'size' => 1,
            'indexer' => 'X', 'status' => 'queued', 'guid' => uniqid('g'), 'user_id' => $this->owner()->id, 'progress_at' => now(),
        ], $a));
        $grab(['status' => \App\DownloadGrab::STATUS_FAILED]);
        $grab(['status' => \App\DownloadGrab::STATUS_FAILED]);
        $old = $grab(['status' => \App\DownloadGrab::STATUS_COMPLETED]);
        \App\DownloadGrab::whereKey($old->id)->toBase()->update(['updated_at' => now()->subHour()]);
        $grab(['status' => \App\DownloadGrab::STATUS_COMPLETED]); // just finished: not stuck yet
        $grab([]); // queued: nothing to do

        $expected = DownloadController::attentionCount(DownloadController::attention());
        $this->assertSame(4, $expected);
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->assertSame($expected, DownloadController::attentionTotal());
        $this->assertCount(1, \Illuminate\Support\Facades\DB::getQueryLog());
    }

    public function test_counts_show_on_the_group_toggle_and_the_collapsed_toggler(): void
    {
        Cache::put(DownloadController::NAV_ATTENTION_CACHE, 3, 60);
        $html = $this->get(route('stats.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#id="manageDropdown"[^>]*>Manage<span class="nav-count[^"]*"><span class="visually-hidden">, </span>3<#', $html);
        $this->assertMatchesRegularExpression('#id="navDownloads">Downloads<span class="nav-count#', $html);
        $this->assertMatchesRegularExpression('#aria-label="Menu, 3 waiting">\s*<span class="navbar-toggler-icon"></span>\s*<span class="nav-count[^"]*navbar-toggler-count"[^>]*>3</span>#', $html);

        Cache::put(DownloadController::NAV_ATTENTION_CACHE, 0, 60);
        $html = $this->get(route('stats.index'))->getContent();
        $this->assertStringNotContainsString('navbar-toggler-count', $html);
        $this->assertMatchesRegularExpression('#id="manageDropdown"[^>]*>Manage</button>#', $html);
    }
}
