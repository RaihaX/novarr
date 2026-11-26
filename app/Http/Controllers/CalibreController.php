<?php

namespace App\Http\Controllers;

use App\Books\CalibreImport;
use App\Books\CalibreWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Admin: browse the owner's Calibre-Web (newest first, or a search) and
 * import a book into the Books library (App\Books\CalibreImport). Calibre's
 * covers need its login, so they come through cover().
 */
class CalibreController extends Controller
{
    /** How long a listed book is remembered for its Import button and cover. */
    private const ENTRY_TTL = 7200;

    public function index(Request $request, CalibreWeb $calibre): View
    {
        $query = trim((string) $request->query('q', ''));
        $offset = max(0, (int) $request->query('offset', 0));
        $page = ['entries' => [], 'next' => null];
        $error = null;

        if (!CalibreWeb::enabled()) {
            $error = 'Calibre-Web is not set up. Set NOVARR_CALIBRE_WEB_URL, _USERNAME and _PASSWORD in .env.';
        } else {
            try {
                $page = $query !== '' ? $calibre->search($query, $offset) : $calibre->recent($offset);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        foreach ($page['entries'] as $entry) {
            if (!empty($entry['id'])) {
                Cache::put(self::key((int) $entry['id']), $entry, self::ENTRY_TTL);
            }
        }

        return view('books.calibre', [
            'entries' => $page['entries'],
            'next' => $page['next'],
            'offset' => $offset,
            'query' => $query,
            'error' => $error,
            'imported' => CalibreImport::importedUuids(withDeleted: false),
            'mark' => \App\Setting::get(CalibreImport::MARK),
            'sync' => (bool) config('novarr.calibre_web.sync', true),
        ]);
    }

    public function import(Request $request, CalibreImport $import): RedirectResponse
    {
        $data = $request->validate(['id' => ['required', 'integer', 'min:1'], 'force' => ['nullable', 'boolean']]);
        $entry = Cache::get(self::key((int) $data['id']));
        if ($entry === null) {
            return back()->with('error', 'That list is out of date: search or reload, then import again.');
        }

        $outcome = $import->import($entry, (bool) ($data['force'] ?? false));
        if ($outcome['result'] === CalibreImport::IMPORTED) {
            return back()->with('success', "“{$entry['title']}” is in the library.");
        }

        return back()->with('error', "“{$entry['title']}”: {$outcome['message']}.");
    }

    public function cover(int $id, CalibreWeb $calibre)
    {
        $entry = Cache::get(self::key($id));
        $bytes = $entry !== null ? Cache::remember("calibre_cover_{$id}", self::ENTRY_TTL, fn() => $calibre->cover($entry)) : null;
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => getimagesizefromstring($bytes)['mime'] ?? 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private static function key(int $id): string
    {
        return "calibre_entry_{$id}";
    }
}
