<?php

namespace Tests\Unit;

use App\Services\Requests\SearchRelevance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether a reader-request search hit resembles the query
 * (App\Services\Requests\SearchRelevance). Pure: no app, no network.
 */
class SearchRelevanceTest extends TestCase
{
    public function test_normalisation_lowercases_strips_accents_and_punctuation(): void
    {
        $this->assertSame('re zero kara hajimeru', SearchRelevance::normalize('  Re:Zéro — kara   Hajimeru! '));
        $this->assertSame('heros journey', SearchRelevance::normalize('Hero’s Journey'));
        $this->assertSame('pokemon', SearchRelevance::normalize('Pokémon'));
        $this->assertSame('tom jerry', SearchRelevance::normalize('Tom &amp; Jerry'));
        $this->assertSame('', SearchRelevance::normalize(' ?! '));
    }

    public static function kept(): array
    {
        return [
            'exact' => ['Solo Leveling', 'solo leveling'],
            'every word, any case and punctuation' => ['Solo Leveling: Ragnarok', 'ragnarok solo'],
            'a word prefix' => ['Solo Leveling', 'solo lev'],
            'stop words ignored' => ['Lord Mysteries', 'Lord of the Mysteries'],
            'one-letter words ignored' => ['Re Zero', 'Re: Zero x'],
            'accents on either side' => ['Pokémon Adventures', 'pokemon adventures'],
            'near-equal (a typo)' => ['Solo Leveling', 'solo levelling'],
            'title wholly in the query' => ['Solo Leveling', 'solo leveling manhwa season 2'],
            'a short title inside the query' => ['Learning', 'mother of learning'],
            'near-equal beats a missing word' => ['Isolo Leveling', 'solo leveling'],
            'numbers count as words' => ['86 Eighty-Six', '86 eighty'],
            'cyrillic word prefix' => ['Поднятие уровня в одиночку', 'поднятие уровня'],
            'cyrillic exact, other case' => ['ВЕДЬМАК', 'ведьмак'],
        ];
    }

    #[DataProvider('kept')]
    public function test_a_title_that_resembles_the_query_is_kept(string $title, string $query): void
    {
        $this->assertTrue(SearchRelevance::keeps(['name' => $title], $query), "{$title} for {$query}");
    }

    public static function dropped(): array
    {
        return [
            'loosely related' => ['The Wandering Inn', 'mother of learning'],
            'one word missing' => ['Mother of Dragons', 'mother of learning'],
            'a word only inside another' => ['The Isolo Chronicles', 'solo leveling'],
            'the query only partly in the title' => ['Learning Magic Academy', 'mother of learning'],
            'cyrillic unrelated' => ['Война и мир', 'поднятие уровня'],
        ];
    }

    #[DataProvider('dropped')]
    public function test_a_title_that_does_not_resemble_the_query_is_dropped(string $title, string $query): void
    {
        $this->assertFalse(SearchRelevance::keeps(['name' => $title], $query), "{$title} for {$query}");
    }

    public function test_an_alternative_title_is_enough(): void
    {
        $item = ['name' => 'Solo Leveling', 'alt_titles' => ['Na Honjaman Level Up', '나 혼자만 레벨업', null, ['nested']]];

        $this->assertTrue(SearchRelevance::keeps($item, 'na honjaman level up'));
        $this->assertFalse(SearchRelevance::keeps($item, 'tower of god'));
    }

    public function test_mangadex_items_carry_their_alternative_titles(): void
    {
        $attributes = [
            'title' => ['en' => 'Solo Leveling', 'ko' => '나 혼자만 레벨업'],
            'altTitles' => [['ko-ro' => 'Na Honjaman Level Up'], ['en' => 'Solo Leveling'], ['ja' => '俺だけレベルアップな件'], ['x' => 7], ['ko-ro' => 'Na Honjaman Level Up']],
        ];

        $alt = \App\Sources\MangaDexSource::altTitles($attributes, 'Solo Leveling');

        $this->assertSame(['나 혼자만 레벨업', 'Na Honjaman Level Up', '俺だけレベルアップな件'], $alt);
        $this->assertTrue(SearchRelevance::keeps(['name' => 'Solo Leveling', 'alt_titles' => $alt], 'Na Honjaman Level Up'));
    }

    public function test_short_queries_and_queries_without_a_significant_word_filter_nothing(): void
    {
        foreach (['x', 'ab', ' A ', 'the of', 'a b c', '!?'] as $query) {
            $this->assertFalse(SearchRelevance::filters($query), $query);
            $this->assertTrue(SearchRelevance::keeps(['name' => 'Anything Else'], $query), $query);
        }
        $this->assertTrue(SearchRelevance::filters('abc'));
    }

    public function test_cjk_queries_pass_every_hit_through(): void
    {
        foreach (['俺だけレベルアップな件', '斗破苍穹', '나 혼자만 레벨업', 'solo 레벨업'] as $query) {
            $this->assertFalse(SearchRelevance::filters($query), $query);
            $this->assertTrue(SearchRelevance::keeps(['name' => 'Battle Through the Heavens'], $query), $query);
        }
        $this->assertSame(
            [['name' => 'A'], ['name' => 'B']],
            SearchRelevance::filter([['name' => 'A'], ['name' => 'B']], '重生之斗破苍穹'),
        );
    }

    public function test_filter_keeps_order_and_drops_non_items(): void
    {
        $items = [
            ['name' => 'Mother of Learning'],
            ['name' => 'The Wandering Inn'],
            'junk',
            ['name' => 'Mother of Learning: Arc 2'],
            ['name' => ''],
            ['url' => 'https://example.test/no-name'],
        ];

        $this->assertSame(
            [['name' => 'Mother of Learning'], ['name' => 'Mother of Learning: Arc 2']],
            SearchRelevance::filter($items, 'Mother of Learning'),
        );
    }
}
