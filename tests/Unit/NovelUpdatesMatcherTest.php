<?php

namespace Tests\Unit;

use App\Console\Commands\VerifyCompletion;
use App\Group;
use App\Http\Controllers\MetadataController;
use App\Jobs\RunNovelCommand;
use App\Novel;
use App\NovelChapter;
use App\Scraping\NovelUpdatesMatcher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * NovelUpdates identity scoring (audit F28/F29, A14): the matcher itself,
 * the completion guard that relies on it and the edit-page endpoints.
 */
class NovelUpdatesMatcherTest extends TestCase
{
    use RefreshDatabase;

    private static function candidate(string $title, array $associated = [], ?string $author = null, string $slug = 'x'): array
    {
        return ['title' => $title, 'url' => "https://www.novelupdates.com/series/{$slug}/", 'associated' => $associated, 'author' => $author];
    }

    // ---- normalizeTitle -------------------------------------------------

    public function testNormalizeTitle(): void
    {
        $this->assertSame('rebirth cafe', NovelUpdatesMatcher::normalizeTitle('Rébirth: Café'));
        $this->assertSame('kings avatar', NovelUpdatesMatcher::normalizeTitle('The King’s Avatar'));
        $this->assertSame('shadow slave', NovelUpdatesMatcher::normalizeTitle('Shadow Slave (WN)'));
        $this->assertSame('shadow slave', NovelUpdatesMatcher::normalizeTitle('Shadow  Slave - Novel'));
        $this->assertSame('lord of mysteries', NovelUpdatesMatcher::normalizeTitle('Lord of the Mysteries Web Novel'));
        $this->assertSame('overgeared', NovelUpdatesMatcher::normalizeTitle('Overgeared (LN)'));
        $this->assertSame('a', NovelUpdatesMatcher::normalizeTitle('A'), 'never normalises to nothing');
    }

    // ---- score / pick ---------------------------------------------------

    public function testExactTitleScoresOne(): void
    {
        $this->assertSame(1.0, NovelUpdatesMatcher::score('Shadow Slave', null, self::candidate('Shadow Slave')));
    }

    public function testAccentAndPunctuationVariantsMatch(): void
    {
        $this->assertSame(1.0, NovelUpdatesMatcher::score('Rebirth Cafe', null, self::candidate('Rébirth: Café')));
        $this->assertSame(1.0, NovelUpdatesMatcher::score("The King's Avatar", null, self::candidate('King’s Avatar (WN)')));
        $this->assertGreaterThanOrEqual(0.85, NovelUpdatesMatcher::score('Release That Witch!', null, self::candidate('Release that Witch')));
    }

    public function testAssociatedNameMatches(): void
    {
        $candidate = self::candidate('Beyond the Timescape', ['超脱', 'Outside of Time', 'Chao Tuo']);

        $this->assertLessThan(0.6, NovelUpdatesMatcher::score('Outside of Time', null, ['title' => 'Beyond the Timescape']));
        $this->assertSame(1.0, NovelUpdatesMatcher::score('Outside of Time', null, $candidate));
    }

    public function testAuthorBonusAndClamp(): void
    {
        $title = NovelUpdatesMatcher::score('Shadow Slave', null, self::candidate('Shadow Hack'));
        $withAuthor = NovelUpdatesMatcher::score('Shadow Slave', 'Guiltythree', self::candidate('Shadow Hack', [], 'GuiltyThree'));

        $this->assertLessThan(0.9, $title);
        $this->assertEqualsWithDelta($title + 0.1, $withAuthor, 0.0011);
        // Clamped at 1.
        $this->assertSame(1.0, NovelUpdatesMatcher::score('Shadow Slave', 'Guiltythree', self::candidate('Shadow Slave', [], 'Guiltythree')));
        // Different author: no bonus.
        $this->assertSame($title, NovelUpdatesMatcher::score('Shadow Slave', 'Someone Else', self::candidate('Shadow Hack', [], 'Guiltythree')));
    }

    public function testWrongSeriesIsRejected(): void
    {
        $this->assertLessThan(0.85, NovelUpdatesMatcher::score('Shadow Slave', null, self::candidate('Shadow Hack')));
        $this->assertNull(NovelUpdatesMatcher::pick([self::candidate('Shadow Hack')], 'Shadow Slave', null));
        $this->assertNull(NovelUpdatesMatcher::pick([], 'Shadow Slave', null));
    }

    public function testPickReturnsTheBestCandidateWithItsScore(): void
    {
        $candidates = [
            self::candidate('Shadow Hack', [], null, 'shadow-hack'),
            self::candidate('Shadow Slave', [], null, 'shadow-slave'),
            self::candidate('Slave of the Shadow', [], null, 'slave-of-the-shadow'),
        ];

        $best = NovelUpdatesMatcher::pick($candidates, 'Shadow Slave', null);
        $this->assertSame('https://www.novelupdates.com/series/shadow-slave/', $best['url']);
        $this->assertSame(1.0, $best['score']);

        $ranked = NovelUpdatesMatcher::rank($candidates, 'Shadow Slave', null);
        $this->assertSame('shadow-slave', basename($ranked[0]['url']));
        $this->assertGreaterThanOrEqual($ranked[1]['score'], $ranked[0]['score']);
        $this->assertGreaterThanOrEqual($ranked[2]['score'], $ranked[1]['score']);
    }

    public function testParseSearchResultsReturnsEverySeries(): void
    {
        $html = '<ul>'
            . '<li class="search_li_results"><a href="https://www.novelupdates.com/series/shadow-slave/" class="a_search">'
            . '<img src="x.jpg"><span>Shadow <span class="search_hl">Slave</span></span></a></li>'
            . '<li class="search_li_results"><a href="https://www.novelupdates.com/series/shadow-hack" class="a_search"><span>Shadow Hack</span></a></li>'
            . '<li><a href="https://www.novelupdates.com/series/shadow-slave/">dup</a></li>'
            . '<li><a href="https://www.novelupdates.com/viewlist/">not a series</a></li>'
            . '</ul>';

        $results = NovelUpdatesMatcher::parseSearchResults($html);

        $this->assertSame(['Shadow Slave', 'Shadow Hack'], array_column($results, 'title'));
        $this->assertSame([
            'https://www.novelupdates.com/series/shadow-slave/',
            'https://www.novelupdates.com/series/shadow-hack/',
        ], array_column($results, 'url'));
        $this->assertSame([], NovelUpdatesMatcher::parseSearchResults(''));
    }

    public function testNovelSlugTransliterates(): void
    {
        $this->assertSame('rebirth-cafe', novelSlug('Rébirth: Café'));
        $this->assertSame('the-kings-avatar', novelSlug('The King’s Avatar'));
        $this->assertSame('re-zero', novelSlug('Re:Zero'));
    }

    // ---- novel:verify-completion guard ----------------------------------

    private function completeLookingNovel($score): Novel
    {
        $group = new Group();
        $group->label = 'Example';
        $group->url = 'https://example.test';
        $group->save();

        $novel = Novel::create(['name' => 'Shadow Slave', 'status' => 0, 'group_id' => $group->id, 'no_of_chapters' => 3]);
        $novel->forceFill([
            'novelupdates_url' => 'https://www.novelupdates.com/series/shadow-slave/',
            'novelupdates_match_score' => $score,
        ])->saveQuietly();

        foreach ([1, 2, 3] as $n) {
            $chapter = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => $n, 'label' => "Chapter {$n}", 'url' => "https://example.test/c-{$n}"]);
            $chapter->forceFill(['status' => 1])->saveQuietly();
        }

        // NovelUpdates says: complete, fully translated, 3 chapters.
        $this->app[Kernel::class]->registerCommand(new class extends VerifyCompletion {
            protected function fetchMetadata(Novel $novel): array
            {
                return [
                    'description' => 'x', 'author' => '', 'no_of_chapters' => 3, 'image' => '',
                    'status_text' => '3 Chapters (Completed)', 'completed' => true,
                    'fully_translated' => true, 'genres' => [], 'title' => 'Shadow Slave', 'associated' => [],
                ];
            }
        });

        return $novel;
    }

    public function testVerifyCompletionRefusesAnUnscoredMatch(): void
    {
        $novel = $this->completeLookingNovel(null);

        Artisan::call('novel:verify-completion', ['novel' => $novel->id, '--dry-run' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('NovelUpdates match is unverified', $output);
        $this->assertStringNotContainsString('Would mark complete', $output);
    }

    public function testVerifyCompletionRefusesALowScore(): void
    {
        $novel = $this->completeLookingNovel(0.7);

        Artisan::call('novel:verify-completion', ['novel' => $novel->id, '--dry-run' => true]);

        $this->assertStringContainsString('match is uncertain (score 0.700', Artisan::output());
        $this->assertSame(0, (int) $novel->fresh()->status);
    }

    public function testVerifyCompletionAcceptsAConfidentMatch(): void
    {
        $novel = $this->completeLookingNovel(0.92);

        Artisan::call('novel:verify-completion', ['novel' => $novel->id, '--dry-run' => true]);

        $this->assertStringContainsString('Would mark complete: Shadow Slave', Artisan::output());
    }

    public function testMatchRefusalReason(): void
    {
        $this->assertNotNull(VerifyCompletion::matchRefusalReason(null));
        $this->assertNotNull(VerifyCompletion::matchRefusalReason('0.849'));
        $this->assertNull(VerifyCompletion::matchRefusalReason('0.850'));
        $this->assertNull(VerifyCompletion::matchRefusalReason(1.0));
    }

    // ---- metadata endpoints ---------------------------------------------

    /**
     * The search itself (resolveNovelUpdatesUrl, network) is stubbed by
     * binding a controller subclass; the ranking is the real matcher's.
     */
    public function testCandidatesEndpointReturnsTopFiveScored(): void
    {
        $novel = Novel::create(['name' => 'Shadow Slave', 'status' => 0]);

        $this->app->bind(MetadataController::class, fn() => new class extends MetadataController {
            protected function searchCandidates(Novel $novel): array
            {
                $raw = [];
                foreach (['Shadow Hack', 'Shadow Slave', 'Slave Shadow', 'Shadows', 'Dark Slave', 'Unrelated Title'] as $i => $title) {
                    $raw[] = ['title' => $title, 'url' => "https://www.novelupdates.com/series/s-{$i}/", 'associated' => [], 'author' => null];
                }
                return NovelUpdatesMatcher::rank($raw, $novel->name, $novel->author);
            }
        });

        $response = $this->getJson(route('novels.metadata_candidates', $novel->id));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('novel_id', $novel->id)
            ->assertJsonPath('threshold', 0.85)
            ->assertJsonPath('current.url', null)
            ->assertJsonPath('current.score', null)
            ->assertJsonCount(5, 'candidates')
            ->assertJsonStructure(['candidates' => [['title', 'url', 'score', 'associated']]])
            ->assertJsonPath('candidates.0.title', 'Shadow Slave')
            ->assertJsonPath('candidates.0.score', 1);

        $scores = array_column($response->json('candidates'), 'score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    public function testChooseSetsTheUrlAndQueuesTheMetadataRefresh(): void
    {
        Bus::fake();
        $novel = Novel::create(['name' => 'Outside of Time', 'status' => 0]);

        $this->postJson(route('novels.metadata_choose', $novel->id), ['url' => 'https://www.novelupdates.com/series/beyond-the-timescape'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('url', 'https://www.novelupdates.com/series/beyond-the-timescape/');

        $fresh = $novel->fresh();
        $this->assertSame('https://www.novelupdates.com/series/beyond-the-timescape/', $fresh->novelupdates_url);
        $this->assertEqualsWithDelta(1.0, (float) $fresh->novelupdates_match_score, 0.0001);

        Bus::assertDispatched(RunNovelCommand::class, fn($job) => $job->artisanCommand === 'novel:metadata'
            && $job->params === ['novel' => $novel->id]);
    }

    public function testChooseRejectsANonSeriesUrl(): void
    {
        Bus::fake();
        $novel = Novel::create(['name' => 'X', 'status' => 0]);

        $this->postJson(route('novels.metadata_choose', $novel->id), ['url' => 'https://evil.example/series/x/'])
            ->assertStatus(422);

        $this->assertNull($novel->fresh()->novelupdates_url);
        Bus::assertNotDispatched(RunNovelCommand::class);
    }

    public function testUpdateMetadataForcedUrlNeedsANovelAndASeriesUrl(): void
    {
        $this->assertSame(1, Artisan::call('novel:metadata', ['--novelupdates-url' => 'https://www.novelupdates.com/series/x/']));

        $novel = Novel::create(['name' => 'X', 'status' => 0]);
        $this->assertSame(1, Artisan::call('novel:metadata', ['novel' => $novel->id, '--novelupdates-url' => 'https://example.com/x']));
        $this->assertNull($novel->fresh()->novelupdates_url);
    }

    /** A sequel is a different series even when the letters nearly match. */
    public function testSequelMarkersPullTheScoreUnderTheThreshold(): void
    {
        $cand = ['title' => 'Reverend Insanity II', 'url' => 'https://www.novelupdates.com/series/reverend-insanity-ii/', 'associated' => []];
        $this->assertLessThan(NovelUpdatesMatcher::THRESHOLD, NovelUpdatesMatcher::score('Reverend Insanity', null, $cand));

        $same = ['title' => 'Reverend Insanity', 'url' => 'https://www.novelupdates.com/series/reverend-insanity/', 'associated' => []];
        $this->assertGreaterThanOrEqual(NovelUpdatesMatcher::THRESHOLD, NovelUpdatesMatcher::score('Reverend Insanity', null, $same));

        // Both sides carry the same marker: no penalty.
        $this->assertGreaterThanOrEqual(NovelUpdatesMatcher::THRESHOLD, NovelUpdatesMatcher::score('Reverend Insanity II', null, $cand));

        $this->assertSame('2', NovelUpdatesMatcher::sequelMarker('Mushoku Tensei Season 2'));
        $this->assertSame('2', NovelUpdatesMatcher::sequelMarker('Overlord II'));
        $this->assertSame('', NovelUpdatesMatcher::sequelMarker('Catch-22 Stories'));
        $this->assertSame('', NovelUpdatesMatcher::sequelMarker('Shadow Slave'));
    }
}
