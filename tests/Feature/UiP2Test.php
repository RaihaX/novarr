<?php

namespace Tests\Feature;

use App\Enums\NovelState;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * UI audit P2: novel header + maintenance disclosure, reader settings tabs /
 * playback bar / chapter drawer, renames, breadcrumb, status enum + component,
 * icon set, Health owning Needs-attention, /continue shortcut.
 */
class UiP2Test extends TestCase
{
    use RefreshDatabase;

    private function novel(array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => 'P2 Novel',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 10,
        ], $attrs));
    }

    private function chapter(Novel $novel, int $n, bool $downloaded = true, ?string $label = null): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => $label ?? "Chapter {$n} - The Road",
            'url' => "https://example.test/p2/chapter-{$n}",
        ]);
        if ($downloaded) {
            $chapter->description = '<p>Body of chapter ' . $n . '.</p>';
            $chapter->status = 1;
            $chapter->download_date = now();
            $chapter->save();
        }

        return $chapter;
    }

    // ---- Item 18: NovelState enum + <x-status> ---------------------------

    public function test_novel_state_maps_onto_the_status_triad(): void
    {
        $expected = [
            // state      => [tone,      badge,               progress,              bar]
            'downloaded' => ['success', 'badge-downloaded', 'progress-downloaded', 'bar-success'],
            'queued'     => ['pending', 'badge-queued',     'progress-queued',     'bar-pending'],
            'attention'  => ['warning', 'badge-attention',  'progress-attention',  'bar-warning'],
            'failed'     => ['danger',  'badge-failed',     'progress-failed',     'bar-danger'],
            'paused'     => ['muted',   'badge-paused',     'progress-paused',     'bar-muted'],
            'completed'  => ['success', 'badge-completed',  'progress-downloaded', 'bar-success'],
            'reading'    => ['reading', 'badge-reading',    'progress-reading',    'bar-reading'],
            'active'     => ['success', 'badge-active',     'progress-downloaded', 'bar-success'],
        ];

        $this->assertCount(count($expected), NovelState::cases());
        foreach ($expected as $value => [$tone, $badge, $progress, $bar]) {
            $state = NovelState::from($value);
            $this->assertSame($tone, $state->tone(), $value);
            $this->assertSame($badge, $state->badgeClass(), $value);
            $this->assertSame($progress, $state->progressClass(), $value);
            $this->assertSame($bar, $state->barClass(), $value);
            $this->assertSame('panel-' . $tone, $state->panelClass(), $value);
            $this->assertSame('tone-' . $tone, $state->textClass(), $value);
        }

        $this->assertSame('Needs attention', NovelState::Attention->label());
        $this->assertSame(NovelState::Paused, NovelState::resolve('Paused'));
        $this->assertSame(NovelState::Queued, NovelState::resolve('queued'));
        $this->assertNull(NovelState::resolve('nonsense'));

        $this->assertSame(NovelState::Completed, NovelState::forNovel((object) ['status' => 1, 'paused_at' => now()]));
        $this->assertSame(NovelState::Paused, NovelState::forNovel((object) ['status' => 0, 'paused_at' => now()]));
        $this->assertSame(NovelState::Attention, NovelState::forNovel((object) ['status' => 0, 'paused_at' => null], true));
        $this->assertSame(NovelState::Active, NovelState::forNovel((object) ['status' => 0, 'paused_at' => null]));
        $this->assertSame(NovelState::Downloaded, NovelState::forChapter((object) ['status' => 1]));
        $this->assertSame(NovelState::Queued, NovelState::forChapter((object) ['status' => 0]));
    }

    public function test_status_component_renders_triad_classes(): void
    {
        $badge = Blade::render('<x-status state="paused" id="b1" />');
        $this->assertStringContainsString('class="badge badge-paused"', $badge);
        $this->assertStringContainsString('id="b1"', $badge);
        $this->assertStringContainsString('>Paused</span>', $badge);

        $text = Blade::render('<x-status :state="$s" as="text">42</x-status>', ['s' => NovelState::Failed]);
        $this->assertStringContainsString('class="tone-danger"', $text);
        $this->assertStringContainsString('>42</span>', $text);
    }

    // ---- Item 19: icon set -------------------------------------------------

    public function test_icon_component_knows_the_p2_icon_set(): void
    {
        $names = ['check', 'x', 'more-horizontal', 'download', 'list', 'type', 'maximize-2', 'play', 'pause',
            'pencil', 'trash-2', 'refresh-cw', 'external-link', 'filter', 'bookmark', 'highlighter', 'settings',
            'activity', 'library', 'compass', 'wifi-off', 'sun', 'moon', 'chevron-down', 'headphones'];

        foreach ($names as $name) {
            $svg = Blade::render('<x-icon :name="$n" :size="14" />', ['n' => $name]);
            $this->assertStringContainsString('<svg', $svg, "icon {$name} should render");
        }
        $this->assertStringNotContainsString('<svg', Blade::render('<x-icon name="no-such-icon" />'));
    }

    // ---- Item 11: novel page header ---------------------------------------

    public function test_novel_header_has_three_actions_and_a_maintenance_disclosure(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1);

        $html = $this->get(route('novels.show', $novel->id))->assertOk()->getContent();

        // Download menu carries offline ranges + ePub + Kindle.
        $this->assertStringContainsString('id="offlineBtn"', $html);
        foreach (['data-scope="unread-next"', 'data-scope="range"', 'data-command="epub"', 'data-command="send_to_kindle"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        // Overflow menu: edit, pause, refresh metadata, delete.
        $this->assertStringContainsString('aria-label="More actions"', $html);
        $this->assertStringContainsString('id="pauseToggle"', $html);
        $this->assertStringContainsString('id="deleteNovel"', $html);

        // Refresh metadata exists exactly once (it used to be duplicated).
        $this->assertSame(1, substr_count($html, 'data-command="metadata"'));

        // Quick actions collapsed into a Maintenance <details>, dry-run wiring intact.
        $this->assertStringNotContainsString('Quick actions', $html);
        $this->assertMatchesRegularExpression('/<details class="card maintenance-panel[^"]*" id="maintenancePanel">/', $html);
        $this->assertSame(4, substr_count($html, 'data-dry-run-first title='));
        $this->assertStringContainsString('id="maintenanceButtons"', $html);

        // Hourly checks is a switch posting to the existing endpoint.
        $this->assertMatchesRegularExpression('/<input class="form-check-input" type="checkbox" role="switch" id="frequentToggle"/', $html);
    }

    public function test_hourly_toggle_still_answers_json(): void
    {
        $novel = $this->novel();

        $this->postJson(route('novels.toggle_frequent', $novel->id))
            ->assertOk()->assertJson(['success' => true, 'frequent' => true]);
        $this->postJson(route('novels.toggle_frequent', $novel->id))
            ->assertOk()->assertJson(['frequent' => false]);
    }

    // ---- Items 12 + 13: reader ---------------------------------------------

    public function test_reader_settings_have_tabs_and_playback_has_its_own_control(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1);
        $chapter = $this->chapter($novel, 2);
        $this->chapter($novel, 3);

        $html = $this->get(route('chapters.show', $chapter->id))->assertOk()->getContent();

        foreach (['rsTab-text', 'rsTab-layout', 'rsTab-playback'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html);
        }
        // Every existing preference control is still present.
        foreach (['data-font="+"', 'data-measure="-"', 'data-theme="sepia"', 'data-family="legible"',
                     'data-lineheight="2.1"', 'data-margin="l"', 'data-justify="1"', 'data-autonext="1"',
                     'data-ttsrate="1.5"', 'id="perNovelPrefs"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        // Listen + auto-scroll live in the docked playback bar.
        $this->assertStringContainsString('id="playbackBtn"', $html);
        $this->assertMatchesRegularExpression('/id="readerPlayback"[\s\S]*id="ttsPlayPause"[\s\S]*id="autoScrollToggle"/', $html);
        $this->assertStringContainsString('id="readerBackdrop"', $html);

        // Drawer: unread filter + jump to current.
        $this->assertStringContainsString('id="tocUnread"', $html);
        $this->assertStringContainsString('id="tocJump"', $html);
        $this->assertStringContainsString('id="readerIconCheck"', $html);

        // Prev/next labels don't repeat the chapter number.
        $this->assertStringContainsString('Chapter 1 - The Road', $html);
        $this->assertStringNotContainsString('Ch. 1 · Chapter 1', $html);
    }

    public function test_reader_nav_prefixes_the_number_when_the_label_lacks_it(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1, true, 'The Beginning');
        $chapter = $this->chapter($novel, 2);

        $this->get(route('chapters.show', $chapter->id))
            ->assertOk()
            ->assertSee('Ch. 1 · The Beginning');
    }

    // ---- Item 14: renames + breadcrumb -------------------------------------

    public function test_nav_uses_the_new_names_and_routes_are_unchanged(): void
    {
        $html = $this->get(route('novels.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#href="' . preg_quote(route('novels.index'), '#') . '">Library</a>#', $html);
        $this->assertStringContainsString('>Downloads</a>', $html);
        $this->assertMatchesRegularExpression('#href="' . preg_quote(route('bookmarks.index'), '#') . '">Highlights</a>#', $html);
        $this->assertStringContainsString('<title>Library · ', $html);
        $this->assertSame('/library', parse_url(route('library'), PHP_URL_PATH));
        $this->assertSame('/bookmarks', parse_url(route('bookmarks.index'), PHP_URL_PATH));

        $this->get(route('bookmarks.index'))->assertOk()->assertSee('<title>Highlights · ', false);
        $this->get(route('library'))->assertOk()->assertSee('<title>Downloads · ', false);
    }

    public function test_system_pages_show_a_breadcrumb_and_mark_the_active_child(): void
    {
        $html = $this->get(route('commands.index'))->assertOk()->getContent();

        $this->assertStringContainsString('class="page-breadcrumb"', $html);
        $this->assertMatchesRegularExpression('#<li\s*>\s*<span>System</span>#', $html);
        $this->assertMatchesRegularExpression('#<li\s+aria-current="page"\s*>\s*<span>Commands</span>#', $html);
        $this->assertMatchesRegularExpression('#class="dropdown-item active"\s+aria-current="page"\s+href="' . preg_quote(route('commands.index'), '#') . '"#', $html);
    }

    // ---- Item 17: Health owns Needs-attention -------------------------------

    private function failingNovel(): Novel
    {
        $novel = $this->novel(['name' => 'Failing P2 Novel']);
        $novel->scrape_failures = 12;
        $novel->save();
        $this->chapter($novel, 1, false);

        return $novel;
    }

    public function test_dashboard_shows_a_summary_line_and_health_has_the_panel(): void
    {
        $this->failingNovel();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="attentionSummary"', false)
            ->assertSee('1 novel</strong> needs attention', false)
            ->assertSee(route('health.index') . '#attentionPanel', false)
            ->assertDontSee('id="attentionPanel"', false);

        $this->get(route('health.index'))
            ->assertOk()
            ->assertSee('id="attentionPanel"', false)
            ->assertSee('Failing P2 Novel')
            ->assertSee('Snooze 7 days')
            ->assertDontSee('toggle-pause', false);
    }

    public function test_snoozed_note_renders_on_both_pages(): void
    {
        $novel = $this->failingNovel();
        $novel->attention_ignored_until = now()->addDays(3);
        $novel->save();

        foreach (['home', 'health.index'] as $route) {
            $this->get(route($route))->assertOk()
                ->assertSee('attention-snoozed', false)
                ->assertSee('Failing P2 Novel');
        }
    }

    // ---- Item 20: /continue + manifest shortcuts ---------------------------

    public function test_continue_redirects_to_the_resume_point_or_home(): void
    {
        $this->get('/continue')->assertRedirect(route('home'));

        $novel = $this->novel();
        $c1 = $this->chapter($novel, 1);
        $c2 = $this->chapter($novel, 2);
        $c1->read_at = now();
        $c1->save();

        $this->get('/continue')->assertRedirect(route('chapters.show', $c2->id));
    }

    public function test_manifest_shortcuts_point_at_continue_with_distinct_icons(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertIsArray($manifest);

        $shortcuts = collect($manifest['shortcuts']);
        $this->assertSame('/continue', $shortcuts->firstWhere('name', 'Continue Reading')['url']);
        $this->assertNotNull($shortcuts->firstWhere('name', 'Downloads'));

        $icons = $shortcuts->map(fn ($s) => $s['icons'][0]['src']);
        $this->assertCount(4, $icons->unique());
        foreach ($icons as $src) {
            $path = public_path(ltrim($src, '/'));
            $this->assertFileExists($path);
            [$w, $h] = getimagesize($path);
            $this->assertSame([96, 96], [$w, $h], $src);
        }
    }
}
