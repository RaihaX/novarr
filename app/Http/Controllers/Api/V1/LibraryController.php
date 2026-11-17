<?php

namespace App\Http\Controllers\Api\V1;

use App\ChapterText;
use App\Console\Commands\ChapterScraper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NovelController;
use App\Novel;
use App\NovelChapter;
use App\Scraping\SourceUnavailable;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * Read endpoints of the native app's API (v1): server identity, the whole
 * library, Home, one novel and its chapter list. Thin: the figures come from
 * the same queries and caches as the web pages.
 */
class LibraryController extends Controller
{
    /** GET /api/v1/meta — lets the app verify a server URL. */
    public function meta(): JsonResponse
    {
        return response()->json([
            'app' => 'novarr',
            'api_version' => 1,
            'server_version' => static::serverVersion(),
        ]);
    }

    /**
     * GET /api/v1/novels — every novel, last read first, then name (the
     * Library's default "read" sort). A fixed number of statements whatever
     * the library size.
     */
    public function novels(\Illuminate\Http\Request $request): JsonResponse
    {
        $medium = Presenter::medium($request);
        $context = Presenter::context();
        $novels = Presenter::loadNovels()->filter(fn(Novel $n) => Presenter::inMedium($n->medium, $medium));
        $ordered = NovelController::sortLibraryIds($novels->values(), $context['aggregates'], 'read');

        return response()->json([
            'novels' => array_map(fn(int $id) => Presenter::summary($novels->get($id), $context), $ordered),
        ]);
    }

    /** GET /api/v1/home — the web Home's Continue and New chapters, plus totals. */
    public function home(\Illuminate\Http\Request $request, HomeController $home): JsonResponse
    {
        $medium = Presenter::medium($request);
        $continue = $home->continueShelf();
        $groups = $home->newChapterGroups($continue);

        // Manga only on request (?medium=manga|all): the shelves are the web
        // Home's, so they are filtered here.
        $candidateIds = array_values(array_unique(array_merge(
            array_map(fn($item) => (int) $item['novel']->id, $continue),
            array_map(fn($g) => (int) $g['novel_id'], $groups)
        )));
        $media = $candidateIds === [] ? collect() : Novel::withTrashed()->whereIn('id', $candidateIds)->pluck('medium', 'id');
        $keep = fn(int $id) => Presenter::inMedium($media->get($id), $medium);
        $continue = array_values(array_filter($continue, fn($item) => $keep((int) $item['novel']->id)));
        $groups = array_values(array_filter($groups, fn($g) => $keep((int) $g['novel_id'])));
        // The web page lists the novels you are reading first, then the rest.
        $groups = array_merge(
            array_values(array_filter($groups, fn($g) => $g['reading'])),
            array_values(array_filter($groups, fn($g) => !$g['reading']))
        );

        $context = Presenter::context();
        $ids = array_merge(
            array_map(fn($item) => (int) $item['novel']->id, $continue),
            array_map(fn($g) => (int) $g['novel_id'], $groups)
        );
        $novels = Presenter::loadNovels(array_values(array_unique($ids)));

        // The groups carry chapter ids only: their refs in one query.
        $firstIds = array_values(array_filter(array_map(fn($g) => $g['read_from_id'] ?? null, $groups)));
        $firsts = $firstIds === [] ? collect() : NovelChapter::whereIn('id', $firstIds)
            ->get(['id', 'chapter', 'book', 'label'])
            ->keyBy('id');

        $out = ['continue' => [], 'new_chapters' => []];
        foreach ($continue as $item) {
            if ($novel = $novels->get($item['novel']->id)) {
                $out['continue'][] = [
                    'novel' => Presenter::summary($novel, $context),
                    'chapter' => Presenter::chapterRef($item['next']),
                ];
            }
        }
        foreach ($groups as $g) {
            if ($novel = $novels->get($g['novel_id'])) {
                $first = isset($g['read_from_id']) ? $firsts->get($g['read_from_id']) : null;
                $out['new_chapters'][] = [
                    'novel' => Presenter::summary($novel, $context),
                    'count' => (int) $g['downloaded'],
                    'first_chapter' => $first ? Presenter::chapterRef($first) : null,
                ];
            }
        }

        $out['stats'] = [
            'novels' => $medium === 'all'
                ? count($context['reading'])
                : Novel::whereIn('id', array_keys($context['reading']))->where('medium', $medium)->count(),
            'chapters_downloaded' => (int) $context['totals']['downloaded'],
            'chapters_queued' => (int) $context['totals']['queued'],
        ];

        return response()->json($out);
    }

    /** GET /api/v1/novels/{id} — a summary plus synopsis, source and parked chapters. */
    public function show($id): JsonResponse
    {
        $novel = Presenter::loadNovels([(int) $id], ['description', 'translator_url', 'source_fail_streak', 'source_recheck_at'])
            ->get((int) $id);
        abort_if($novel === null, 404);

        $synopsis = NovelController::cleanSynopsis($novel->description, $novel->name);

        $unavailable = null;
        if ((int) $novel->source_fail_streak > 0) {
            $parked = SourceUnavailable::parkedCounts([(int) $novel->id])[(int) $novel->id] ?? null;
            if ($parked && $parked['count'] > 0) {
                $unavailable = [
                    'count' => $parked['count'],
                    'message' => ChapterScraper::sourceUnavailableMessage(
                        $parked['count'],
                        parse_url((string) $novel->translator_url, PHP_URL_HOST) ?: null,
                        Carbon::parse($novel->source_recheck_at ?? now())
                    ),
                ];
            }
        }

        return response()->json([
            'novel' => Presenter::summary($novel, Presenter::context()) + [
                'description' => $synopsis === null ? null : static::plainText($synopsis),
                'source_url' => $novel->translator_url ?: null,
                'source_unavailable' => $unavailable,
            ],
        ]);
    }

    /** GET /api/v1/novels/{id}/chapters — the whole list in reading order, blacklisted excluded. */
    public function chapters($id): JsonResponse
    {
        abort_unless(Novel::whereKey($id)->exists(), 404);

        $chapters = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->ordered()
            ->select(['id', 'chapter', 'book', 'label', 'status', 'read_at', 'read_progress', 'kind', 'download_date', 'last_failure_reason', 'page_count'])
            ->selectSub(
                ChapterText::query()->select('word_count')->whereColumn('chapter_texts.novel_chapter_id', 'novel_chapters.id'),
                'word_count'
            )
            ->get()
            ->map(function (NovelChapter $c) {
                $state = NovelController::chapterState($c);

                return [
                    'id' => (int) $c->id,
                    'chapter' => Presenter::number($c->chapter),
                    'book' => (int) $c->book,
                    'label' => Presenter::label($c),
                    'downloaded' => (bool) $c->status,
                    'read' => $c->read_at !== null,
                    'read_progress' => $c->read_progress === null ? null : (int) $c->read_progress,
                    'note' => $c->isNote(),
                    'state' => $state,
                    'reason' => $state === 'failed' ? NovelController::failureReasonText($c->last_failure_reason) : null,
                    'downloaded_at' => Presenter::timestamp($c->download_date),
                    'word_count' => $c->word_count === null ? null : (int) $c->word_count,
                    // Pages of a downloaded manga chapter; null for text.
                    'page_count' => $c->page_count === null ? null : (int) $c->page_count,
                ];
            });

        return response()->json(['chapters' => $chapters]);
    }

    /** Synopsis HTML as plain text: paragraphs separated by a blank line. */
    private static function plainText(string $html): string
    {
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|li|h[1-6]|blockquote)\s*>#i', "\n\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(fn($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $text));

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * config('app.version') when set, else the checked-out commit's short
     * sha read from .git (no process spawned), else null (a Docker image
     * ships without .git).
     */
    private static function serverVersion(): ?string
    {
        if ($version = config('app.version')) {
            return (string) $version;
        }

        $git = base_path('.git');
        $head = @file_get_contents($git . '/HEAD');
        if (!is_string($head)) {
            return null;
        }
        $head = trim($head);
        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $sha = @file_get_contents($git . '/' . $ref);
            if (!is_string($sha) && is_string($packed = @file_get_contents($git . '/packed-refs'))) {
                $sha = preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m) ? $m[1] : null;
            }
            $head = is_string($sha) ? trim($sha) : '';
        }

        return preg_match('/^[0-9a-f]{7,}$/', $head) ? substr($head, 0, 7) : null;
    }
}
