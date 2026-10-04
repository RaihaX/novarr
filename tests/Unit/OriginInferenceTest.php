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
            'pinyin author' => ['Er Gen', null, [], [], false, null, 'unknown', null],
            'pinyin author (joined syllables)' => ['Feng Qingyang', null, [], [], false, null, 'unknown', null],
            'pinyin author (three words)' => ['Tang Jia San Shao', null, [], [], false, null, 'unknown', null],
            'mixed: pinyin author + translator line' => ['Er Gen', null, ['TL: hi'], [], false, null, 'translated', null],
            'mixed: Latin author + Han in TOC + MTL' => ['Mad Snail', null, [], ['第一章 (MTL)'], false, null, 'translated', 'zh'],
            'Royal Road host' => ['Er Gen', 'www.royalroad.com', [], [], false, null, 'unknown', null],
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
