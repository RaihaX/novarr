<?php

namespace Tests\Feature;

use App\Console\Commands\VerifyCompletion;
use App\Group;
use App\Novel;
use App\NovelChapter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * novel:verify-completion end to end with the NovelUpdates fetch faked: a
 * novel that local stats already rule out is never fetched (nor paused for).
 */
class VerifyCompletionTest extends TestCase
{
    use RefreshDatabase;

    /** @var VerifyCompletion&object{fetched: array, pauses: array} */
    private $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new class extends VerifyCompletion {
            public array $fetched = [];
            public array $pauses = [];

            protected function fetchMetadata(Novel $novel): array
            {
                $this->fetched[] = $novel->id;

                return [
                    'completed' => false,
                    'status_text' => 'Ongoing',
                    'fully_translated' => false,
                    'no_of_chapters' => 2,
                ];
            }

            protected function pause(int $seconds): void
            {
                $this->pauses[] = $seconds;
            }
        };
        $this->app[Kernel::class]->registerCommand($this->command);
    }

    private function novel(string $name, array $chapterStatuses): Novel
    {
        $group = Group::first();
        if (!$group) {
            $group = new Group();
            $group->label = 'Example';
            $group->url = 'https://example.test';
            $group->save();
        }
        $novel = Novel::create(['name' => $name, 'status' => 0, 'group_id' => $group->id, 'no_of_chapters' => count($chapterStatuses)]);

        foreach ($chapterStatuses as $i => $status) {
            $chapter = NovelChapter::create([
                'novel_id' => $novel->id,
                'chapter' => $i + 1,
                'label' => 'Chapter ' . ($i + 1),
                'url' => "https://example.test/{$novel->id}/" . ($i + 1),
            ]);
            $chapter->status = $status;
            $chapter->save();
        }

        return $novel;
    }

    public function testPendingChaptersSkipTheNovelUpdatesFetch(): void
    {
        $pending = $this->novel('A Pending', [1, 0]);
        $ready = $this->novel('B Ready', [1, 1]);

        Artisan::call('novel:verify-completion');
        $output = Artisan::output();

        $this->assertSame([$ready->id], $this->command->fetched, 'only the locally complete novel is fetched');
        $this->assertCount(1, $this->command->pauses, 'no polite pause for the skipped novel');
        $this->assertStringContainsString('1 chapter(s) still pending download', $output);
        $this->assertStringContainsString('NU: not checked', $output);
        $this->assertSame(0, (int) $pending->fresh()->status);
        $this->assertSame(0, (int) $ready->fresh()->status, 'NovelUpdates says ongoing');
    }

    public function testMissingChaptersSkipTheFetch(): void
    {
        $novel = $this->novel('Gappy', [1, 1]);
        NovelChapter::where('novel_id', $novel->id)->where('chapter', 2)->update(['chapter' => 5]);

        Artisan::call('novel:verify-completion', ['novel' => $novel->id]);

        $this->assertSame([], $this->command->fetched);
        $this->assertStringContainsString('chapter number(s) missing from the sequence', Artisan::output());
    }

    public function testLocalReasons(): void
    {
        $this->assertSame([], VerifyCompletion::localReasons(['pending' => 0, 'missing' => 0, 'missing_list' => []]));
        $this->assertSame(
            ['2 chapter(s) still pending download', '1 chapter number(s) missing from the sequence (e.g. 4)'],
            VerifyCompletion::localReasons(['pending' => 2, 'missing' => 1, 'missing_list' => [4]])
        );
    }
}
