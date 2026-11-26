<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\V1\Presenter;
use App\Support\AuthMode;
use App\Support\SignedUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * GET /requests/cover?u=…&reader=…&signature=… — a cover in reader-request
 * search results and requests, for readers (also on the public address).
 *
 * Discover's cover route (discover.cover) is the admin's: readers are
 * refused it. This route serves the same covers (DiscoverCoverController:
 * only proxied() hosts, cached a day) to any reader, but ONLY through a URL
 * the server signed: the signature covers the cover URL `u` and, with
 * sign-in on, the reader it was signed for (App\Support\SignedUrls). So a
 * reader can load the covers the server put in their results and nothing
 * else — no unsigned image proxy. Without a valid signature: 404.
 */
class RequestCoverController extends DiscoverCoverController
{
    /**
     * The cover URL to hand a client for a source cover: the raw URL when
     * the browser may load it directly, else this route signed for the
     * current reader (or $userId, e.g. an email's recipient), absolute on
     * $base (default: the request's origin). Null for no cover.
     */
    public static function url(?string $cover, ?int $userId = null, ?string $base = null): ?string
    {
        $cover = trim((string) $cover);
        if ($cover === '') {
            return null;
        }
        // A cover Novarr stores itself (an uploaded book's): on the address
        // the reader is using, not the one the URL was saved with.
        $own = rtrim((string) config('filesystems.disks.public.url'), '/') . '/';
        if (str_starts_with($cover, $own) || str_starts_with($cover, '/storage/')) {
            $path = '/storage/' . ltrim(substr($cover, str_starts_with($cover, $own) ? strlen($own) : strlen('/storage/')), '/');

            return $base !== null ? rtrim($base, '/') . $path : Presenter::url($path);
        }
        if (!static::proxied($cover)) {
            return preg_match('#^https?://#i', $cover) ? $cover : null;
        }

        $parameters = ['u' => $cover];
        if (AuthMode::required() && $userId !== null) {
            $parameters[SignedUrls::READER_PARAM] = $userId;
            $relative = URL::signedRoute('requests.cover', $parameters, null, false);
        } elseif (AuthMode::required()) {
            $relative = SignedUrls::route('requests.cover', $parameters);
        } else {
            // Sign-in off: still signed, so the route is never an open proxy.
            $relative = URL::signedRoute('requests.cover', $parameters, null, false);
        }

        return $base !== null ? rtrim($base, '/') . $relative : Presenter::url($relative);
    }

    public function cover(Request $request)
    {
        abort_unless($request->query->has('signature') && $request->hasValidRelativeSignature(), 404);

        return $this->respond($request, (string) $request->query('u', ''));
    }
}
