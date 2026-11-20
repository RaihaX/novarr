<?php

namespace Tests\Unit;

use App\Scraping\OriginInference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OriginInference: pure "translated or original English?" rules — the
 * NovelUpdates mapping and the weak-signal inference for unmatched novels.
 */
class OriginInferenceTest extends TestCase
{
    public static function inferCases(): array
    {
        return [
            // name => [author, host, chapter texts, toc labels, NU match, NU score, origin, language]
            'Han author' => ['爱潜水的乌贼', 'novelfull.com', [], [], false, null, 'translated', 'zh'],
            'Hangul author' => ['싱숑', 'novelfull.com', [], [], false, null, 'translated', 'ko'],
            'Kana + Han author' => ['伏瀬 (ふせ)', null, [], [], false, null, 'translated', 'ja'],
            'Latin author, no signals' => ['Guiltythree', 'novelping.com', ['<p>Sunny woke up in the dark.</p>'], ['Chapter 1 Nightmare Begins'], false, null, 'original', 'en'],
            'TurtleMe' => ['TurtleMe', 'novelfull.com', [], [], false, null, 'original', 'en'],
            'translator speaker line' => ['Some Author', 'novelfull.com', ["<p>The sect master laughed.</p><p>TL: sorry for the delay!</p>"], [], false, null, 'translated', null],
            'TN with full-width colon' => ['Some Author', null, ["TN： cultivation term"], [], false, null, 'translated', null],
            'translator speaker + Hangul in text' => ['Some Author', null, ["Translator: 오빠 means older brother"], [], false, null, 'translated', 'ko'],
            "translator's note phrase" => ['Some Author', null, ["(Translator's note: a pun in the raws)"], [], false, null, 'translated', null],
            'MTL in TOC labels' => ['Some Author', null, [], ['Chapter 1', 'Chapter 2 (MTL)'], false, null, 'translated', null],
            'raws in a chapter' => ['Some Author', null, ['The raws are delayed this week.'], [], false, null, 'translated', null],
            'raw meat is not raws' => ['Some Author', null, ['He ate the raw meat.'], [], false, null, 'original', 'en'],
            'speaker beyond the first 400 chars is ignored' => ['Some Author', null, [str_repeat('word ', 100) . "\nTL: hello"], [], false, null, 'original', 'en'],
            'only three chapters are sampled' => ['Some Author', null, ['a', 'b', 'c', 'TL: hi'], [], false, null, 'original', 'en'],
            'Latin author with a NovelUpdates match' => ['Some Author', null, [], [], true, 0.95, 'unknown', null],
            'NovelUpdates near miss' => ['Some Author', null, [], [], false, 0.7, 'unknown', null],
            'NovelUpdates far miss' => ['Some Author', null, [], [], false, 0.3, 'original', 'en'],
            'pinyin author' => ['Er Gen', null, [], [], false, null, 'translated', 'zh'],
            'pinyin author (joined syllables)' => ['Feng Qingyang', null, [], [], false, null, 'translated', 'zh'],
            'pinyin author (three words)' => ['Tang Jia San Shao', null, [], [], false, null, 'translated', 'zh'],
            'mixed: pinyin author + translator line' => ['Er Gen', null, ['TL: hi'], [], false, null, 'translated', null],
            'mixed: Latin author + Han in TOC + MTL' => ['Mad Snail', null, [], ['第一章 (MTL)'], false, null, 'translated', 'zh'],
            'Royal Road host wins over a pinyin author' => ['Er Gen', 'www.royalroad.com', [], [], false, null, 'original', 'en'],
            'Royal Road host, unknown author' => ['', 'www.royalroad.com', [], [], false, null, 'original', 'en'],
            'no author, no signals' => ['', 'novelfull.com', [], [], false, null, 'unknown', null],
            'accented Latin author' => ['José Ramírez', null, [], [], false, null, 'original', 'en'],
        ];
    }

    #[DataProvider('inferCases')]
    public function test_infer(string $author, ?string $host, array $texts, array $labels, bool $nuMatch, ?float $nuScore, string $origin, ?string $language): void
    {
        $result = OriginInference::infer($author, $host, $texts, $labels, $nuMatch, $nuScore);

        $this->assertSame($origin, $result['origin'], $result['reason']);
        $this->assertSame($language, $result['origin_language'], $result['reason']);
        $this->assertNotSame('', $result['reason']);
    }

    public static function novelUpdatesCases(): array
    {
        return [
            'Chinese web novel' => [['type' => 'Web Novel (CN)', 'original_language' => 'Chinese'], 'translated', 'zh', 'Web Novel (CN)'],
            'Korean, raw whitespace' => [['type' => "Web Novel\n (KR)", 'original_language' => " Korean\n"], 'translated', 'ko', 'Web Novel (KR)'],
            'Japanese light novel' => [['type' => 'Light Novel (JP)', 'original_language' => 'Japanese'], 'translated', 'ja', 'Light Novel (JP)'],
            'English original' => [['type' => 'Web Novel', 'original_language' => 'English'], 'original', 'en', 'Web Novel'],
            'Filipino' => [['type' => 'Web Novel', 'original_language' => 'Filipino'], 'translated', 'tl', 'Web Novel'],
            'unmapped language keeps the word' => [['type' => 'Web Novel (MY)', 'original_language' => 'Malaysian'], 'translated', null, 'Web Novel (MY) · Malaysian'],
            'language only' => [['type' => '', 'original_language' => 'Malaysian'], 'translated', null, 'Malaysian'],
            'type suffix when no language' => [['type' => 'Web Novel (KR)', 'original_language' => ''], 'translated', 'ko', 'Web Novel (KR)'],
        ];
    }

    #[DataProvider('novelUpdatesCases')]
    public function test_from_novel_updates(array $metadata, string $origin, ?string $language, ?string $type): void
    {
        $this->assertSame(
            ['origin' => $origin, 'origin_language' => $language, 'origin_type' => $type],
            OriginInference::fromNovelUpdates($metadata)
        );
    }

    // ---- What the text reads like ------------------------------------------------

    private const CHINESE = 'Zhou Yuan stood before the sect gate as the morning bell rang. Elder Liu Feng had told him that a cultivator '
        . 'without spirit stones would never reach Foundation Establishment, and Senior Brother Chen Hao agreed. "Young Master," the servant '
        . 'said, bowing, "the sect master is waiting." Zhou Yuan gathered the qi in his dantian and walked on. Behind him Lin Xue and Wang Lei '
        . 'whispered about the outer sect trial. Three hundred taels had changed hands, they said, and still nobody dared to challenge Zhao Min. ';

    private const KOREAN = 'Kim Min-Jun checked the gate rank on his phone while the subway crossed the river into Gangnam. "Hyung, are you '
        . 'really going in alone?" Park Ji-Ho asked. The guild had offered him ten million won for the raid, and Director Choi-nim wanted an '
        . 'answer by noon. In Seoul every hunter knew the rule. Lee Seo-Yeon, his sunbae, had cleared the same gate last winter. "Noona said it '
        . 'was easy," Ji-Ho said, and Min-Jun laughed for the first time that morning. ';

    private const JAPANESE = 'Tanaka-san put the lunch boxes on the desk before the bell. "Sensei is late again," Yui-chan said, looking out '
        . 'at the school gate. Haruto-kun had spent his last five hundred yen on bread, so he only shrugged. After class they would take the '
        . 'train to Tokyo, where Sato-senpai worked at a small bookshop. "Hurry up," Aoi-san called from the corridor, and the three of them ran. ';

    private const ENGLISH = 'Marcus dropped the crate on the dock and wiped his hands. The harbour master, a broad woman named Eleanor Voss, '
        . 'was already shouting at the crew of the Sparrow. "Level four," the blue window in front of him read, and under it a new skill '
        . 'waited to be chosen. He glanced at Thomas, who was pretending not to see it. The guild hall in Westbrook paid well for river '
        . 'pearls, and winter was coming. Sarah had said the dungeon below the mill reset every ten days. That gave them a week. ';

    public static function textCases(): array
    {
        return [
            // author, texts, genres => origin, language, confidence
            'Chinese story, pen-name in English words' => ['Quiet River Lantern', [str_repeat(self::CHINESE, 4)], [], 'translated', 'zh', 'high'],
            'Chinese story, handle-like author' => ['MoonTide', [str_repeat(self::CHINESE, 4)], [], 'translated', 'zh', 'high'],
            'Korean hunter story' => ['Sing Shong', [str_repeat(self::KOREAN, 4)], [], 'translated', 'ko', 'high'],
            'Japanese school story' => ['Some Author', [str_repeat(self::JAPANESE, 4)], [], 'translated', 'ja', 'high'],
            'English original, handle author' => ['river_otter42', [str_repeat(self::ENGLISH, 16)], [], 'original', 'en', 'high'],
            'English original, plain name: a guess only' => ['Trent Maddox', [str_repeat(self::ENGLISH, 16)], [], 'original', 'en', 'low'],
            'English text too short to judge' => ['Trent Maddox', [self::ENGLISH], [], 'original', 'en', 'low'],
            'genre tag settles a quiet text' => ['Some Author', [str_repeat(self::ENGLISH, 16)], ['Action', 'Xianxia'], 'translated', 'zh', 'medium'],
            'genre and text agree' => ['Some Author', [str_repeat(self::KOREAN, 4)], ['Murim'], 'translated', 'ko', 'high'],
        ];
    }

    #[DataProvider('textCases')]
    public function test_infer_reads_the_story(string $author, array $texts, array $genres, string $origin, ?string $language, string $confidence): void
    {
        $result = OriginInference::infer($author, 'novelping.com', $texts, [], false, null, $genres);

        $this->assertSame([$origin, $language, $confidence], [$result['origin'], $result['origin_language'], $result['confidence']], $result['reason']);
    }

    public function test_english_words_that_spell_pinyin_are_not_chinese_names(): void
    {
        $text = str_repeat('King Long ran to the Sun Gang hall. He and She sang a Song. Dean Kane began the Line. ', 30);

        $this->assertNull(OriginInference::classifyText([$text])['language']);
    }

    public function test_handles(): void
    {
        foreach (['joyce_4070', 'SleepDeprivedSloth', 'Exlor', 'reader99'] as $author) {
            $this->assertTrue(OriginInference::looksLikeHandle($author), $author);
        }
        foreach (['Trent Maddox', 'Quiet River Lantern', ''] as $author) {
            $this->assertFalse(OriginInference::looksLikeHandle($author), $author);
        }
    }

    public function test_from_novel_updates_is_null_without_type_or_language(): void
    {
        $this->assertNull(OriginInference::fromNovelUpdates(['type' => '', 'original_language' => '']));
        $this->assertNull(OriginInference::fromNovelUpdates([]));
    }

    public function test_language_table(): void
    {
        $this->assertSame('zh', OriginInference::languageCode('Chinese'));
        $this->assertSame('es', OriginInference::languageCode(' spanish '));
        $this->assertNull(OriginInference::languageCode('Klingon'));
        $this->assertNull(OriginInference::languageCode(null));
        $this->assertSame('Korean', OriginInference::languageName('ko'));
        $this->assertNull(OriginInference::languageName(null));
    }
}
