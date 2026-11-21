<?php

namespace App\Http\Middleware;

use App\ApiToken;
use App\Services\Credentials;
use App\Support\AuthMode;
use App\Support\CurrentReader;
use App\Support\PublicAddress;
use App\Support\RouteAccess;
use App\Support\SignedUrls;
use App\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who is calling, and may they call this route (App\Support\RouteAccess).
 * Appended to the `web` and `api` middleware groups, so it runs on every
 * route of the app (the route walk test checks that).
 *
 * Sign-in off: does nothing — every request is the owner (CurrentReader's
 * default), whatever credentials it carries.
 *
 * Sign-in on: identifies the user from, in order,
 *   1. a bearer token (Authorization: Bearer …) — the native clients; a
 *      token that is present but not valid means "not signed in";
 *   2. a signed URL naming the reader — session-less assets only
 *      (RouteAccess::SIGNED_ROUTES);
 *   3. HTTP Basic — OPDS and its ePub links only (RouteAccess::BASIC_ROUTES);
 *   4. the web session (incl. the remember-me cookie) — web pages, and the
 *      web UI's own same-origin /api/v1 calls (App\Http\Middleware\ApiSession).
 * Disabled users count as not signed in (their session is ended). The user
 * becomes the request's CurrentReader and the `web` guard's user (so
 * auth()->user(), @can and the gate see it). Then public routes pass,
 * the rest need a user, admin routes an admin.
 *
 * On the public address (App\Support\PublicAddress::isPublicRequest): with
 * sign-in off only public routes answer (403 otherwise, never "everyone is
 * the owner"); with sign-in on admin routes answer 403 for everyone,
 * including the admin (who reads there like a reader).
 *
 * Not signed in → 401 JSON {message, code: unauthenticated} for the API and
 * JSON requests, else a redirect to /login (the intended URL remembered).
 * Not allowed → 403 JSON {message, code: forbidden}, else a plain 403 page.
 */
class IdentifyReader
{
    public const BASIC_REALM = 'Novarr';

    /** Request attribute holding the ApiToken a request authenticated with. */
    public const TOKEN_ATTRIBUTE = 'novarr.api_token';

    public function __construct(private Credentials $credentials)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $access = RouteAccess::classify($route);
        // The public address (App\Support\PublicAddress): public routes only
        // while sign-in is off, and no admin routes for anyone.
        $publicHost = PublicAddress::isPublicRequest($request);

        if (!AuthMode::required()) {
            if ($publicHost && $access !== RouteAccess::PUBLIC) {
                // Nobody is the owner here: the refusal page shows no one.
                app()->instance(CurrentReader::class, CurrentReader::guest());

                return static::forbidden($request, PublicAddress::SIGN_IN_OFF_MESSAGE, PublicAddress::TITLE);
            }

            return $next($request);
        }

        // Guards cache their user per instance: start every request from
        // scratch (what Octane does too; the test client reuses the app).
        Auth::forgetGuards();

        $user = $this->identify($request, $route, $basicFailure);
        if ($user !== null) {
            Auth::guard('web')->setUser($user);
            app()->instance(CurrentReader::class, CurrentReader::for($user));
            $user->markSeen();
        } else {
            app()->instance(CurrentReader::class, CurrentReader::guest());
        }

        if ($access === RouteAccess::PUBLIC) {
            return $next($request);
        }
        // Signed in or not, admin or not: no admin routes on the public address.
        if ($publicHost && $access === RouteAccess::ADMIN) {
            return static::forbidden($request, PublicAddress::ADMIN_MESSAGE, PublicAddress::TITLE);
        }
        if ($user === null) {
            return $basicFailure ?? static::unauthenticated($request, $route);
        }
        if ($access === RouteAccess::ADMIN && !$user->isAdmin()) {
            return static::forbidden($request);
        }

        return $next($request);
    }

    private function identify(Request $request, ?Route $route, ?Response &$basicFailure = null): ?User
    {
        $basicFailure = null;

        $bearer = $request->bearerToken();
        if ($bearer !== null) {
            $token = ApiToken::findActive($bearer);
            if ($token === null) {
                return null;
            }
            $token->touchUsed();
            $request->attributes->set(self::TOKEN_ATTRIBUTE, $token);

            return $token->user;
        }

        if (RouteAccess::acceptsSignature($route) && $request->query->has('signature')) {
            return SignedUrls::userFor($request);
        }

        if (RouteAccess::acceptsBasic($route) && $request->getUser() !== null) {
            $result = $this->credentials->attempt($request->getUser(), $request->getPassword(), $request->ip(), PublicAddress::isPublicRequest($request), $request, 'basic');
            if ($result instanceof User) {
                return $result;
            }
            $basicFailure = static::basicChallenge($result['error'], $result['status'] === 429 ? 429 : 401, $result['retry_after'] ?? null);

            return null;
        }

        if ($request->hasSession()) {
            $guard = Auth::guard('web');
            $user = $guard->user();
            if (!$user instanceof User) {
                return null;
            }

            $session = $request->session();
            // Signed in just now by the remember-me cookie (its token was
            // still current): this session starts now.
            if ($guard->viaRemember()) {
                $session->put(User::SESSION_SIGNED_IN_AT, $user->signInStamp());
            }
            $signedInAt = $session->get(User::SESSION_SIGNED_IN_AT);

            // Turned off, or signed out everywhere (password reset, turned
            // off) after this session signed in. Only this browser is signed
            // out: logout() would also cycle the remember token and sign out
            // the account's newer browsers.
            if ($user->isDisabled() || !$user->sessionStillValid(is_numeric($signedInAt) ? (int) $signedInAt : null)) {
                $guard->logoutCurrentDevice();

                return null;
            }

            return $user;
        }

        return null;
    }

    /** API / JSON requests get JSON; the rest a page. */
    public static function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function unauthenticated(Request $request, ?Route $route = null): Response
    {
        $route ??= $request->route();

        // OPDS catalog: always the Basic challenge (ereaders send any Accept);
        // its ePub links: a browser page goes to sign in instead.
        if ($route?->getName() === 'opds.index' || (RouteAccess::acceptsBasic($route) && !static::isBrowserPage($request))) {
            return static::basicChallenge('Sign in to continue.', 401);
        }
        if (static::wantsJson($request) || !$request->hasSession()) {
            return new JsonResponse(['message' => 'Sign in to continue.', 'code' => 'unauthenticated'], 401);
        }

        // Only a page the person asked for is where sign-in returns to: the
        // service worker's background fetches (it precaches "/" and
        // "/library") must not replace it. Browsers label the kind of fetch;
        // clients that don't (curl, old browsers) count as navigating.
        $mode = $request->header('Sec-Fetch-Mode');
        $navigating = $mode === null || $mode === 'navigate';

        return $navigating && ($request->isMethod('GET') || $request->isMethod('HEAD'))
            ? redirect()->guest(route('login'))
            : redirect()->route('login');
    }

    public static function forbidden(Request $request, ?string $message = null, ?string $title = null): Response
    {
        $message ??= 'Only the admin can do that.';
        if (static::wantsJson($request) || !$request->hasSession()) {
            return new JsonResponse(['message' => $message, 'code' => 'forbidden'], 403);
        }

        return response()->view('auth.forbidden', ['message' => $message, 'title' => $title], 403);
    }

    /** A browser asking for a page (not an ereader / OPDS client). */
    private static function isBrowserPage(Request $request): bool
    {
        return $request->hasSession() && str_contains((string) $request->header('Accept'), 'text/html');
    }

    private static function basicChallenge(string $message, int $status, ?int $retryAfter = null): Response
    {
        $headers = $status === 401 ? ['WWW-Authenticate' => 'Basic realm="' . self::BASIC_REALM . '", charset="UTF-8"'] : [];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return new JsonResponse(['message' => $message, 'code' => 'unauthenticated'], $status, $headers);
    }
}
