<?php

namespace App\Http\Controllers;

use App\Novel;
use App\NovelChapter;
use App\Scraping\FailureSnapshot;

/**
 * Failure snapshots for a novel (see App\Scraping\FailureSnapshot): the
 * pages the scraper fetched but could not use, for diagnosing markup
 * changes. GET /novels/{id}/snapshots lists them; with a file name it
 * serves that snapshot's HTML (gunzipped) as plain text.
 */
class SnapshotController extends Controller
{
    public function show($id, ?string $file = null)
    {
        $novel = Novel::findOrFail($id);

        if ($file !== null) {
            return $this->download($novel, $file);
        }

        $snapshots = FailureSnapshot::files((int) $novel->id);

        $chapterIds = array_values(array_filter(array_unique(array_column($snapshots, 'chapter_id'))));
        $labels = $chapterIds
            ? NovelChapter::whereIn('id', $chapterIds)->pluck('label', 'id')->all()
            : [];

        foreach ($snapshots as &$snapshot) {
            $snapshot['label'] = $snapshot['chapter_id'] === 0
                ? 'Table of contents'
                : ($labels[$snapshot['chapter_id']] ?? "Chapter #{$snapshot['chapter_id']} (deleted)");
        }
        unset($snapshot);

        return view('novels.snapshots', [
            'novel' => $novel,
            'snapshots' => $snapshots,
            'enabled' => FailureSnapshot::enabled(),
            'keep' => (int) config('novarr.snapshots.keep_per_novel', 5),
            'days' => (int) config('novarr.snapshots.days', 14),
        ]);
    }

    private function download(Novel $novel, string $file)
    {
        // Name is validated against the snapshot pattern and resolved inside
        // the novel's own directory — no traversal, no other files.
        if (!FailureSnapshot::validName($file)) {
            abort(404);
        }

        $html = FailureSnapshot::read((int) $novel->id, $file);
        if ($html === null) {
            abort(404);
        }

        return response($html, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="' . substr($file, 0, -3) . '.txt"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
