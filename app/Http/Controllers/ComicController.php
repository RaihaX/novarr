<?php

namespace App\Http\Controllers;

use App\Comics\ComicInbox;
use App\Comics\ComicLibrary;
use App\Comics\ComicMetadata;
use App\Comics\ComicVine;
use App\Jobs\ScanComics;
use App\Novel;
use App\NovelRequest;
use Illuminate\Http\Request;

/**
 * The owner's Manage comics page (App\Comics): the comics inbox (waiting /
 * not imported + Retry), "Scan now", the comic requests waiting to arrive,
 * the recent series, a Usenet & torrents search for a comic, and "Look up
 * details" (Comic Vine / the catalogues) for one series.
 */
class ComicController extends Controller
{
    public function manage(ComicInbox $inbox)
    {
        return view('comics.manage', [
            'status' => $inbox->status(),
            'recent' => ComicLibrary::series()->with('file')->orderByDesc('updated_at')->limit(30)->get(),
            'totals' => [
                'series' => ComicLibrary::series()->count(),
                'issues' => \App\NovelChapter::whereIn('novel_id', ComicLibrary::series()->select('id'))->count(),
            ],
            'waiting' => NovelRequest::where('medium', Novel::MEDIUM_COMIC)->where('status', NovelRequest::STATUS_PENDING)->orderBy('id')->limit(50)->get(),
            'comicVine' => ComicVine::enabled(),
        ]);
    }

    public function scan()
    {
        ScanComics::dispatch();

        return redirect()->route('comics.manage')
            ->with('success', 'Scan queued: settled entries are imported within a minute or two. Refresh this page to see them.');
    }

    public function retry(Request $request, ComicInbox $inbox)
    {
        $name = (string) $request->validate(['name' => 'required|string|max:255'])['name'];
        if (($error = $inbox->retry($name)) !== null) {
            return redirect()->route('comics.manage')->with('error', $error);
        }

        return redirect()->route('comics.manage')->with('success', "“{$name}” is back in the inbox: the next scan tries it again.");
    }

    /** "Look up details": Comic Vine (asked again) and the catalogues, filling only what is missing. */
    public function lookup(ComicMetadata $metadata, int $id)
    {
        $novel = ComicLibrary::series()->findOrFail($id);
        $first = \App\ChapterPage::whereIn('novel_chapter_id', $novel->chapters()->orderBy('chapter')->limit(1)->pluck('id'))->orderBy('page')->value('path');
        $done = $metadata->fill($novel, null, $first !== null ? \App\ChapterPage::disk()->path($first) : null, false, null, true);
        \App\Http\Helpers\CacheHelper::clearNovelCache((int) $novel->id);

        $message = $done === []
            ? 'Nothing new was found' . (ComicVine::enabled() ? '' : ' (Comic Vine is off: set NOVARR_COMICVINE_API_KEY)') . '.'
            : 'Filled: ' . implode(', ', array_map(fn($k) => str_replace('_', ' ', (string) $k), array_keys($done))) . '.';

        return redirect()->route('novels.show', $novel->id)->with('success', $message);
    }
}
