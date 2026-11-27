<?php

namespace App\Downloads;

use App\DownloadGrab;
use App\Novel;
use App\NovelRequest;
use App\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a search result (App\Downloads\Prowlarr) to its download client and
 * records the App\DownloadGrab that `downloads:poll` follows.
 *
 *   usenet   → NZBGet `append` with the result's download URL (a Prowlarr
 *              proxy link, or the indexer's own when Prowlarr's redirect is
 *              on: NZBGet fetches either), in the kind's category
 *              (novarr.nzbget.category_books / category_audiobooks).
 *   torrent  → Transmission `torrent-add` in the kind's download-dir
 *              (novarr.transmission.dir_books / dir_audiobooks): the magnet
 *              link when the result has one; else Novarr fetches the
 *              .torrent link itself (Prowlarr's link may redirect to a
 *              magnet, which Transmission cannot follow) and hands over the
 *              magnet or the file; when that fails, the link as it is.
 *
 * Targets: a reader's wanted book (NovelRequest::isWanted(), an ebook), a
 * book of the library (an audiobook for it), or none (a standalone search:
 * an ebook lands in the review queue, an audiobook is matched as usual).
 */
class Grabber
{
    public function __construct(private NzbGet $nzbget, private Transmission $transmission)
    {
    }

    /**
     * @throws DownloadFailed  not set up, a wrong target, the client refused
     */
    public function grab(array $result, string $kind, ?NovelRequest $request = null, ?Novel $novel = null, ?User $user = null, bool $auto = false, ?\App\DownloadWanted $wanted = null): DownloadGrab
    {
        if (!in_array($kind, DownloadGrab::KINDS, true)) {
            throw new DownloadFailed('Grab an ebook or an audiobook.');
        }
        if ($request !== null && ($kind !== DownloadGrab::KIND_EBOOK || !$request->isPending() || !$request->isWanted())) {
            throw new DownloadFailed('This request already has its ePub, or is no longer waiting.');
        }
        if ($novel !== null && ($kind !== DownloadGrab::KIND_AUDIOBOOK || !$novel->isBook())) {
            throw new DownloadFailed('Only an audiobook can be grabbed for a book of the library.');
        }
        $protocol = ($result['protocol'] ?? null) === DownloadGrab::TORRENT ? DownloadGrab::TORRENT : DownloadGrab::USENET;
        if (($missing = Downloads::clientMissing($protocol)) !== null) {
            throw new DownloadFailed($missing);
        }
        $title = trim((string) ($result['title'] ?? ''));
        if ($title === '') {
            throw new DownloadFailed('This result has no name.');
        }
        $busy = DownloadGrab::whereIn('status', DownloadGrab::ACTIVE)->where('title', mb_substr($title, 0, 500))->first();
        if ($busy !== null) {
            throw new DownloadFailed('This one is already downloading (' . Downloads::statusLine($busy) . ').');
        }

        if ($protocol === DownloadGrab::USENET) {
            $url = (string) ($result['download_url'] ?? '');
            if ($url === '') {
                throw new DownloadFailed('This result has no download link.');
            }
            $category = (string) config($kind === DownloadGrab::KIND_AUDIOBOOK ? 'novarr.nzbget.category_audiobooks' : 'novarr.nzbget.category_books');
            $ref = (string) $this->nzbget->append($title, $url, $category);
            // NZBGet names the download after the NZB (the queue's NZBName); the poll keeps it current.
            $name = substr(NzbGet::nzbName($title), 0, -4);
            $client = DownloadGrab::NZBGET;
        } else {
            $dir = (string) config($kind === DownloadGrab::KIND_AUDIOBOOK ? 'novarr.transmission.dir_audiobooks' : 'novarr.transmission.dir_books');
            $added = $this->transmission->add($this->torrentSource($result), $dir);
            $ref = $added['hash'];
            $name = $added['name'];
            $client = DownloadGrab::TRANSMISSION;
        }

        $grab = DownloadGrab::create([
            'kind' => $kind,
            'protocol' => $protocol,
            'client' => $client,
            'client_ref' => $ref,
            'title' => mb_substr($title, 0, 500),
            'size' => max(0, (int) ($result['size'] ?? 0)),
            'indexer' => isset($result['indexer']) ? mb_substr((string) $result['indexer'], 0, 120) : null,
            'info_url' => isset($result['info_url']) ? mb_substr((string) $result['info_url'], 0, 1000) : null,
            'novel_request_id' => $request?->id,
            'novel_id' => $novel?->id,
            'status' => DownloadGrab::STATUS_QUEUED,
            'progress' => 0,
            'completed_path' => $name !== '' ? mb_substr(basename($name), 0, 500) : null,
            'user_id' => $user?->id,
            'guid' => isset($result['guid']) ? mb_substr((string) $result['guid'], 0, 1000) : null,
            'auto' => $auto,
            'progress_at' => now(),
        ]);
        $wanted ??= WantedList::forGrab($grab);
        if ($wanted !== null) {
            $grab->forceFill(['wanted_id' => $wanted->id])->save();
            // The owner chose (or Novarr did): no longer waiting for a choice.
            $wanted->forceFill(['needs_choice' => false])->save();
        }
        \App\DownloadEvent::log($auto ? \App\DownloadEvent::AUTO_GRABBED : \App\DownloadEvent::GRABBED, $title,
            implode(' · ', array_filter([$result['why'] ?? null, Downloads::clientName($client)])), $grab, $wanted);
        Log::info('Download grabbed', ['grab' => $grab->id, 'kind' => $kind, 'client' => $client, 'title' => $title, 'auto' => $auto]);

        return $grab;
    }

    /**
     * What Transmission is given for a torrent result: its magnet link; or,
     * fetching the .torrent link (a few redirects, by hand), the magnet it
     * redirects to or the .torrent file; else the link itself.
     *
     * @return array{magnet?: string, url?: string, metainfo?: string}
     */
    public function torrentSource(array $result): array
    {
        $magnet = (string) ($result['magnet_url'] ?? '');
        if (str_starts_with($magnet, 'magnet:')) {
            return ['magnet' => $magnet];
        }
        $url = (string) (($result['download_url'] ?? null) ?: $magnet);
        if ($url === '') {
            throw new DownloadFailed('This torrent has no magnet link or download link.');
        }

        $next = $url;
        try {
            for ($hop = 0; $hop < 4; $hop++) {
                $response = Http::withOptions(['allow_redirects' => false])->timeout(30)->get($next);
                if ($response->redirect()) {
                    $location = (string) $response->header('Location');
                    if (str_starts_with($location, 'magnet:')) {
                        return ['magnet' => $location];
                    }
                    if (!preg_match('#^https?://#i', $location)) {
                        break;
                    }
                    $next = $location;

                    continue;
                }
                $body = $response->body();
                if ($response->successful() && str_starts_with($body, 'd') && str_contains(substr($body, 0, 4096), '4:info')) {
                    return ['metainfo' => $body];
                }
                if ($response->successful() && str_starts_with(trim($body), 'magnet:')) {
                    return ['magnet' => trim($body)];
                }
                break;
            }
        } catch (\Throwable $e) {
            Log::info('Fetching a .torrent for Transmission failed, passing the link: ' . $e->getMessage());
        }

        return ['url' => $url];
    }
}
