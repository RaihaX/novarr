<?php

namespace Tests\Unit;

use App\Sources\InstallIds;
use Tests\TestCase;

/**
 * The install-specific ids moved from code literals to config: defaults
 * equal the original literals, env lists parse, and the readers follow the
 * config.
 */
class InstallIdsTest extends TestCase
{
    public function testDefaultsMatchTheOriginalLiterals(): void
    {
        $this->assertSame([1, 3, 6], InstallIds::alternativeUrlGroupIds());
        $this->assertSame([72], InstallIds::dottedChapterUrlNovelIds());
        $this->assertSame([1], InstallIds::novelArrowApiGroupIds());
        $this->assertSame([6], InstallIds::webnovelUniqueIdGroupIds());
    }

    public function testEnvListsParse(): void
    {
        $load = function (array $env): array {
            foreach ($env as $key => $value) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
            try {
                return require base_path('config/novarr.php');
            } finally {
                foreach (array_keys($env) as $key) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                }
            }
        };

        $config = $load(['NOVARR_ALTERNATIVE_URL_GROUP_IDS' => ' 2, 9 ,,', 'NOVARR_NOVELARROW_API_GROUP_IDS' => '']);
        $this->assertSame([2, 9], $config['alternative_url_group_ids']);
        $this->assertSame([], $config['novelarrow_api_group_ids']);
        $this->assertSame([72], $config['dotted_chapter_url_novel_ids']);
        $this->assertSame([6], $config['webnovel_unique_id_group_ids']);

        // A value that is not a list of whole numbers keeps the default: a
        // typo must not switch the behaviour off, nor match "no group" (0).
        foreach (['2, x', 'abc', 'true', '1.9', '0', '-3'] as $bad) {
            $config = $load(['NOVARR_ALTERNATIVE_URL_GROUP_IDS' => $bad]);
            $this->assertSame([1, 3, 6], $config['alternative_url_group_ids'], $bad);
        }
    }

    public function testChapterSourceUrlFollowsTheConfig(): void
    {
        $chapter = (object) [
            'url' => '/c/12',
            'chapter' => '12.5',
            'novel' => (object) ['id' => 5, 'alternative_url' => 'https://alt.test/n/', 'group' => (object) ['id' => 3, 'url' => 'https://g.test']],
        ];

        $this->assertSame('https://alt.test/n/12', chapterSourceUrl($chapter));

        config(['novarr.dotted_chapter_url_novel_ids' => [5]]);
        $this->assertSame('https://alt.test/n/12-5', chapterSourceUrl($chapter));

        config(['novarr.alternative_url_group_ids' => []]);
        $this->assertSame('https://g.test/c/12', chapterSourceUrl($chapter));
    }

    public function testRegistryIgnoresEntriesThatAreNotSources(): void
    {
        config(['novarr.sources' => [
            'ghost' => 'App\\Sources\\DoesNotExist',
            'wrong' => \stdClass::class,
            'novelfull' => \App\Sources\NovelFullSource::class,
        ]]);
        $this->assertSame(['novelfull' => \App\Sources\NovelFullSource::class], \App\Sources\SourceResolver::classes());

        // Nothing usable left: the built-in three, never an empty registry.
        config(['novarr.sources' => ['ghost' => 'App\\Sources\\DoesNotExist']]);
        $this->assertSame(\App\Sources\SourceResolver::DEFAULT_SOURCES, \App\Sources\SourceResolver::classes());
    }
}
