<?php

namespace Tests\Feature;

use App\Http\Middleware\IdentifyReader;
use App\Support\RouteAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Every registered route is classified (App\Support\RouteAccess) and, with
 * sign-in on, enforced: guests are sent to sign in, readers are refused
 * every admin route and let through every reader route, the admin gets
 * everything. Walks the whole route list, so a new route that nobody
 * classified fails here.
 */
class RouteAccessTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    /** Values for route parameters (satisfying their where() patterns). */
    private const PARAMS = [
        'id' => '1', 'page' => '1', 'filename' => 'laravel.log', 'file' => '1-empty_text-20260101_000000.html.gz',
        'command' => 'toc', 'jobId' => 'cmd_x', 'uuid' => 'x', 'path' => 'x', 'secret' => 'ab',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('manga');
        // Nothing a walked route starts may reach the network or a queue worker.
        \Illuminate\Support\Facades\Bus::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->app->instance(\App\Scraping\Fetcher::class, new \Tests\Support\UnexpectedFetch());
    }

    /** @return Route[] */
    private function routes(): array
    {
        return array_values(RouteFacade::getRoutes()->getRoutes());
    }

    private function uri(Route $route): string
    {
        $uri = preg_replace_callback('/\{(\w+)\??\}/', fn($m) => self::PARAMS[$m[1]] ?? '1', $route->uri());

        return '/' . ltrim($uri, '/');
    }

    private function method(Route $route): string
    {
        return collect($route->methods())->first(fn($m) => $m !== 'HEAD');
    }

    public function test_every_route_is_classified_and_guarded(): void
    {
        // The HTTP kernel hands the middleware groups to the router.
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        $unlisted = [];
        $unguarded = [];
        foreach ($this->routes() as $route) {
            $key = RouteAccess::key($route);
            if (!RouteAccess::isListed($key)) {
                $unlisted[] = $key . ' (' . $route->uri() . ')';
            }
            if (RouteAccess::classify($route) !== RouteAccess::PUBLIC
                && !in_array(IdentifyReader::class, RouteFacade::gatherRouteMiddleware($route), true)) {
                $unguarded[] = $key;
            }
        }

        $this->assertSame([], $unlisted, 'Routes missing from App\Support\RouteAccess (unlisted routes are admin-only, but list them on purpose).');
        $this->assertSame([], $unguarded, 'Non-public routes that IdentifyReader does not run on.');
    }

    public function test_the_lists_name_only_routes_that_exist_and_do_not_overlap(): void
    {
        $keys = array_map(fn(Route $r) => RouteAccess::key($r), $this->routes());
        $lists = [RouteAccess::PUBLIC_ROUTES, RouteAccess::READER_ROUTES, RouteAccess::ADMIN_ROUTES];
        $all = array_merge(...$lists);

        $this->assertSame([], array_values(array_diff($all, $keys)), 'Listed routes that do not exist.');
        $this->assertSame(count($all), count(array_unique($all)), 'A route is listed twice.');
        $this->assertSame([], array_values(array_diff(RouteAccess::SIGNED_ROUTES, $keys)));
        $this->assertSame([], array_values(array_diff(RouteAccess::BASIC_ROUTES, RouteAccess::READER_ROUTES)));
    }

    public function test_an_unlisted_route_is_admin_only(): void
    {
        $this->assertSame(RouteAccess::ADMIN, RouteAccess::classifyKey('some.new.route'));
        $this->assertSame(RouteAccess::ADMIN, RouteAccess::classifyKey(null));
        $this->assertSame(RouteAccess::READER, RouteAccess::classifyKey('home'));
        $this->assertSame(RouteAccess::PUBLIC, RouteAccess::classifyKey('uri:up'));
    }

    public function test_with_sign_in_on_a_guest_reaches_only_public_routes(): void
    {
        $this->enableSignIn();
        $this->asNobody();

        foreach ($this->routes() as $route) {
            if (RouteAccess::classify($route) === RouteAccess::PUBLIC) {
                continue;
            }
            $key = RouteAccess::key($route);
            $isApi = str_starts_with($route->uri(), 'api/');

            // As a browser page…
            $page = $this->call($this->method($route), $this->uri($route), [], [], [], ['HTTP_ACCEPT' => 'text/html']);
            $this->assertContains($page->getStatusCode(), [302, 401], "{$key}: a guest must not get in");
            if ($page->getStatusCode() === 302) {
                $this->assertStringEndsWith('/login', (string) $page->headers->get('Location'), $key);
            }
            // …and as a JSON request: always the contract's 401.
            $json = $this->json($this->method($route), $this->uri($route));
            $json->assertStatus(401);
            $json->assertExactJson(['message' => 'Sign in to continue.', 'code' => 'unauthenticated']);
            if ($isApi) {
                $page->assertStatus(401);
            }
        }
    }

    /**
     * Requests from here on are $user's, the way that route takes them: a
     * bearer token on the API and the Basic routes, a signed-in browser
     * elsewhere (bearer tokens are ignored there).
     */
    private function asUserOn(Route $route, \App\User $user, string $host = '', ?callable $prepare = null): static
    {
        if (RouteAccess::acceptsBearer(\Illuminate\Http\Request::create($host . $this->uri($route)), $route)) {
            $this->asToken($this->tokenFor($user));
            $prepare && $prepare($this);

            return $this;
        }

        return $this->asSignedIn($user, $host, $prepare);
    }

    public function test_bearer_tokens_open_the_api_and_the_basic_routes_only(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        [$novel] = $this->novelWithChapters();
        $token = $this->tokenFor($reader);

        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->asToken($token)->get('/opds')->assertOk();
        $epub = $this->asToken($token)->get('/novels/' . $novel->id . '/epub');
        $this->assertNotContains($epub->getStatusCode(), [401, 403]);
        // Web pages: the bearer is ignored (not signed in).
        foreach (['/', '/account', '/stats', '/library'] as $uri) {
            $this->asToken($token)->getJson($uri)->assertStatus(401);
        }
        $this->asToken($token)->post('/account/confirm', ['password' => self::READER_PASSWORD])->assertRedirect(route('login'));
        $this->asToken($token)->post('/account/catalogue-passwords', ['name' => 'x'])->assertRedirect(route('login'));
        $this->assertSame(0, \App\ApiToken::where('kind', \App\ApiToken::KIND_CATALOGUE)->count());
        // With a browser's session too, the bearer adds nothing: the session decides.
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser($cookies)->withHeader('Authorization', 'Bearer ' . $this->tokenFor($this->owner()))
            ->getJson('/settings')->assertStatus(403);
    }

    public function test_with_sign_in_on_a_reader_is_refused_every_admin_route(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();

        $checked = 0;
        foreach ($this->routes() as $route) {
            if (RouteAccess::classify($route) !== RouteAccess::ADMIN) {
                continue;
            }
            $this->asUserOn($route, $reader);
            $response = $this->json($this->method($route), $this->uri($route));
            if (RouteAccess::acceptsSignature($route)) {
                // Session-less, opened by a signed URL only: nobody is signed in there.
                $response->assertStatus(401);
                continue;
            }
            $response->assertStatus(403);
            $response->assertExactJson(['message' => 'Only the admin can do that.', 'code' => 'forbidden']);
            $checked++;
        }
        $this->assertGreaterThan(50, $checked);
    }

    public function test_with_sign_in_on_a_reader_is_let_through_every_reader_route(): void
    {
        $this->enableSignIn();
        [$novel] = $this->novelWithChapters();
        // The walk uses id 1: the fixture's on sqlite; on MariaDB (ids are not
        // reset between tests) a missing series answers 404, still not 401/403.
        $reader = $this->makeReader();

        foreach ($this->routes() as $route) {
            if (RouteAccess::classify($route) !== RouteAccess::READER) {
                continue;
            }
            $key = RouteAccess::key($route);
            // A fresh token / sign-in each time: DELETE /api/v1/auth/token
            // revokes its own token, the browser routes may sign out.
            if (RouteAccess::acceptsSignature($route)) {
                continue; // signed URLs only (covered by their own tests)
            }
            $this->asUserOn($route, $reader);
            // novels.bulk is authorised per action: "read" is the reader's.
            $body = $key === 'novels.bulk' ? ['action' => 'read', 'ids' => [1]] : [];
            $response = $this->json($this->method($route), $this->uri($route), $body);
            $this->assertNotContains($response->getStatusCode(), [401, 403], "{$key} refused a reader: " . $response->getContent());
            $this->assertLessThan(500, $response->getStatusCode(), "{$key}: " . $response->getContent());
        }
    }

    public function test_library_bulk_actions_are_authorised_per_action(): void
    {
        $this->enableSignIn();
        [$novel] = $this->novelWithChapters();
        $this->asSignedIn($this->makeReader());

        foreach (['delete', 'complete'] as $action) {
            $this->postJson('/novels/bulk', ['action' => $action, 'ids' => [$novel->id]])
                ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        }
        $this->assertNotSoftDeleted($novel);
        $this->postJson('/novels/bulk', ['action' => 'read', 'ids' => [$novel->id]])->assertOk()->assertJsonPath('chapters', 3);

        $this->asSignedIn($this->owner());
        $this->postJson('/novels/bulk', ['action' => 'complete', 'ids' => [$novel->id]])->assertOk();
        $this->assertSame(1, (int) $novel->fresh()->status);
    }

    public function test_with_sign_in_on_the_admin_is_let_through_admin_routes(): void
    {
        $this->enableSignIn();
        $this->novelWithChapters();
        $token = $this->tokenFor($this->owner());

        foreach (['settings.index', 'settings.users', 'activity.index', 'commands.index', 'health.index', 'logs.index',
                     'novels.create', 'novels.edit', 'api.v1.discover'] as $name) {
            $route = RouteFacade::getRoutes()->getByName($name);
            $this->asUserOn($route, $this->owner());
            $response = $this->get($this->uri($route));
            $this->assertNotContains($response->getStatusCode(), [401, 403], $name);
            $this->assertLessThan(500, $response->getStatusCode(), $name);
        }
    }

    public function test_with_sign_in_off_nothing_is_refused_and_credentials_are_ignored(): void
    {
        $reader = $this->makeReader();
        $token = $this->tokenFor($reader);

        foreach (['/', '/settings', '/activity', '/commands', '/novels/create'] as $uri) {
            $this->asNobody()->get($uri)->assertOk();
            // A reader's token presented anyway: still the owner, still allowed.
            $this->asToken($token)->get($uri)->assertOk();
        }
        $this->asToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('user.id', $this->owner()->id)->assertJsonPath('user.role', 'admin');
    }
}
