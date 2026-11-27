<?php

namespace App\Support;

use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * URLs of private, session-less assets (manga page images, Discover covers:
 * RouteAccess::SIGNED_ROUTES) that <img> tags and the iOS app load without
 * headers.
 *
 * With sign-in on, the URL carries the reader's id (`reader`) and a signature
 * over the PATH + QUERY only (a relative signed route), so the same URL works
 * whichever hostname the app is reached by (LAN IP, Tailscale name,
 * localhost, a proxy). No expiry: a page image's URL stays the same per
 * reader, so browser / service-worker caches keep working; it stops working
 * when the reader is disabled or deleted. With sign-in off the URL is the
 * plain route, exactly as before.
 */
final class SignedUrls
{
    public const READER_PARAM = 'reader';

    /**
     * The route's URL for the current reader. Absolute (on APP_URL) like
     * route() when sign-in is off; relative (/path?…) when signed — callers
     * that need an absolute URL on the request's origin wrap it in
     * Api\V1\Presenter::url() or url().
     */
    public static function route(string $name, array $parameters = []): string
    {
        if (!AuthMode::required()) {
            return route($name, $parameters);
        }

        $reader = app(CurrentReader::class);
        $parameters[self::READER_PARAM] = $reader->id();

        return URL::signedRoute($name, $parameters, null, false);
    }

    /**
     * route() with an expiry: the URL stops working at $expires (an
     * audiobook part's URL, valid a day, for <audio> and ExoPlayer). The
     * expiry is rounded up to the hour, so the URL stays the same for an
     * hour (players and caches see one URL per part, not one per request).
     * With sign-in off the plain route, as route().
     */
    public static function temporaryRoute(string $name, array $parameters = [], int $hours = 24): string
    {
        if (!AuthMode::required()) {
            return route($name, $parameters);
        }

        $parameters[self::READER_PARAM] = app(CurrentReader::class)->id();
        $expires = now()->startOfHour()->addHours($hours + 1);

        return URL::temporarySignedRoute($name, $expires, $parameters, false);
    }

    /** The enabled user a signed request names, or null when the signature or user is not valid. */
    public static function userFor(Request $request): ?User
    {
        if (!$request->query->has('signature') || !$request->hasValidRelativeSignature()) {
            return null;
        }

        $id = (int) $request->query(self::READER_PARAM, 0);
        $user = $id > 0 ? User::find($id) : null;

        return $user !== null && !$user->isDisabled() ? $user : null;
    }
}
