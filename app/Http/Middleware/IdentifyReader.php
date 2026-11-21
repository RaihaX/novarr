<?php

namespace App\Http\Middleware;

use App\ApiToken;
use App\Auth\BrowserGuard;
use App\Services\BrowserSessions;
use App\Services\Credentials;
use App\Services\SecondStep;
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
 *   3. HTTP Basic — OPDS and its ePub links only (RouteAccess::BASIC_ROUTES):
 *      a catalogue password, or (home network only) the account password;
 *   4. the web session (incl. the per-browser remember-me cookie,
 *      App\Auth\BrowserGuard) — web pages, and the web UI's own same-origin
 *      /api/v1 calls (App\Http\Middleware\ApiSession). On the public address
 *      only a sign-in that passed the second step counts
 *      (App\Services\SecondStep).
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

    public const CATALOGUE_PASSWORD_NEEDED = 'On this address the catalogue needs a catalogue password: make one on your Account page and use it with your sign-in name.';

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
            return $this->basic($request, $basicFailure);
        }

        if ($request->hasSession()) {
            $guard = Auth::guard('web');
            $user = $guard->user();
            if (!$user instanceof User) {
                return null;
            }

            $session = $request->session();
            // Signed in just now by the remember-me cookie (its row was
            // still live): this session starts now, with the row's second step.
            if ($guard->viaRemember()) {
                $session->put(User::SESSION_SIGNED_IN_AT, $stamp = $user->signInStamp());
                $row = $guard instanceof BrowserGuard ? $guard->recalledRow() : null;
                if ($row !== null && $row->second_step_passed_at !== null) {
                    $session->put(User::SESSION_SECOND_STEP_AT, $stamp);
                }
                app(BrowserSessions::class)->start($request, $user, $stamp);
            }
            $signedInAt = $session->get(User::SESSION_SIGNED_IN_AT);

            // Turned off, or signed out everywhere (password reset, turned
            // off) after this session signed in. Only this browser is signed
            // out (its own remember-me row goes; the others stay).
            if ($user->isDisabled() || !$user->sessionStillValid(is_numeric($signedInAt) ? (int) $signedInAt : null)) {
                app(BrowserSessions::class)->end($request);
                $guard->logoutCurrentDevice();

                return null;
            }

            // The public address only counts a sign-in that passed the
            // second step: a session from the home network (same hostname
            // there), or from before the second step existed, is not signed
            // in here. It is left as it is (it may still be valid at home).
            if (SecondStep::requiredFor($request) && !$session->has(User::SESSION_SECOND_STEP_AT)) {
                if ($guard instanceof BrowserGuard) {
                    $guard->forgetForThisRequest();
                }

                return null;
            }

            // Signed out from another browser's Account page ("Sign out" on its row).
            if (!app(BrowserSessions::class)->touch($request, $user)) {
                $guard->logoutCurrentDevice();

                return null;
            }

            return $user;
        }

        return null;
    }

    /**
     * HTTP Basic (OPDS and its ePub links). The password is a catalogue
     * password (App\ApiToken kind catalogue) or, on the home network only,
     * the account password. On the public address the account password is
     * never even checked: 401 with the challenge.
     */
    private function basic(Request $request, ?Response &$basicFailure): ?User
    {
        $name = (string) $request->getUser();
        $password = $request->getPassword();
        $publicHost = PublicAddress::isPublicRequest($request);

        if (ApiToken::looksLikeToken($password)) {
            if (($refusal = Credentials::refusal($name, $request->ip(), $publicHost)) !== null) {
                $basicFailure = static::basicChallenge($refusal['error'], 429, $refusal['retry_after'] ?? null);

                return null;
            }
            $user = User::findForSignIn($name);
            $token = ApiToken::findActive($password, ApiToken::KIND_CATALOGUE);
            if ($user !== null && $token !== null && (int) $token->user_id === (int) $user->id && !$user->isDisabled()) {
                $token->touchUsed();

                return $user;
            }
            Credentials::recordFailure($name, $request->ip(), $publicHost);
            Credentials::recordFailed($request, 'basic', $user, 'wrong');
            $basicFailure = static::basicChallenge(Credentials::WRONG, 401);

            return null;
        }

        if ($publicHost) {
            $basicFailure = static::basicChallenge(self::CATALOGUE_PASSWORD_NEEDED, 401);

            return null;
        }

        $result = $this->credentials->attempt($name, $password, $request->ip(), false, $request, 'basic');
        if ($result instanceof User) {
            return $result;
        }
        $basicFailure = static::basicChallenge($result['error'], $result['status'] === 429 ? 429 : 401, $result['retry_after'] ?? null);

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
