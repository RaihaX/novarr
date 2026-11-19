<?php

namespace Tests\Feature;

use App\Http\Middleware\HardenPublicAddress;
use App\Services\Credentials;
use App\Support\AndroidApp;
use App\Support\PublicAddress;
use App\Support\RouteAccess;
use App\UserInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The public address (NOVARR_PUBLIC_HOSTS, App\Support\PublicAddress): the
 * hostname Novarr is published on through a Cloudflare Tunnel. Requests come
 * in as cloudflared forwards them: plain http to nginx, the public Host
 * header, X-Forwarded-Proto: https, X-Forwarded-For.
 */
class PublicAddressTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const HOST = 'read.example.com';

    private const PUBLIC = 'http://' . self::HOST;

    private const LAN = 'http://novarr.lan:8080';

    /** cloudflared's LAN address (TRUSTED_PROXIES in production). */
    private const CONNECTOR = '192.168.1.50';

    private const PARAMS = [
        'id' => '1', 'page' => '1', 'filename' => 'laravel.log', 'file' => '1-empty_text-20260101_000000.html.gz',
        'command' => 'toc', 'jobId' => 'cmd_x', 'uuid' => 'x', 'path' => 'x', 'secret' => 'ab',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('manga');
        \Illuminate\Support\Facades\Bus::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->app->instance(\App\Scraping\Fetcher::class, new \Tests\Support\UnexpectedFetch());
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function configurePublic(?string $url = null): void
    {
        config(['novarr.public_hosts' => [self::HOST], 'novarr.public_url' => $url]);
    }

    /** As cloudflared forwards a request. */
    private function viaTunnel(string $visitorIp = '203.0.113.7'): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::CONNECTOR])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => $visitorIp, 'CF-Connecting-IP' => $visitorIp]);
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

    // ---- The helper -------------------------------------------------------------

    public function test_the_helper_matches_hosts_case_insensitively_and_ignores_the_port(): void
    {
        $this->assertFalse(PublicAddress::configured());
        $this->assertNull(PublicAddress::url());
        $this->assertFalse(PublicAddress::isPublicRequest(Request::create('http://read.example.com/')));

        $this->configurePublic();
        $this->assertTrue(PublicAddress::configured());
        $this->assertSame('https://read.example.com', PublicAddress::url());
        $this->assertTrue(PublicAddress::isPublicRequest(Request::create('http://READ.Example.com/')));
        $this->assertTrue(PublicAddress::isPublicRequest(Request::create('https://read.example.com:8443/x')));
        $this->assertFalse(PublicAddress::isPublicRequest(Request::create('http://novarr.lan/')));
        $this->assertFalse(PublicAddress::isPublicRequest(Request::create('http://evil-read.example.com/')));

        config(['novarr.public_url' => 'https://books.example.com/']);
        $this->assertSame('https://books.example.com', PublicAddress::url());
    }

    public function test_a_forwarded_host_cannot_turn_the_public_hostname_into_a_home_one(): void
    {
        $this->configurePublic();
        // TRUSTED_PROXIES=* (the default) honours X-Forwarded-Host, but the
        // raw Host header still counts.
        $request = Request::create('http://read.example.com/', 'GET', [], [], [], ['HTTP_X_FORWARDED_HOST' => 'novarr.lan', 'REMOTE_ADDR' => '10.0.0.1']);
        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_HOST);
        try {
            $this->assertSame('novarr.lan', $request->getHost());
            $this->assertTrue(PublicAddress::isPublicRequest($request));
        } finally {
            Request::setTrustedProxies([], -1);
        }
    }

    public function test_anything_straight_from_the_tunnel_connector_is_public_whatever_its_hostname(): void
    {
        $this->configurePublic();
        config(['novarr.public_proxies' => [self::CONNECTOR]]);

        // A hostname added to the tunnel but missing from NOVARR_PUBLIC_HOSTS.
        $viaConnector = Request::create('http://other.example.com/', 'GET', [], [], [], ['REMOTE_ADDR' => self::CONNECTOR]);
        $this->assertTrue(PublicAddress::isPublicRequest($viaConnector));

        // The same hostname from another LAN machine is a home request.
        $fromLan = Request::create('http://other.example.com/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.1.77']);
        $this->assertFalse(PublicAddress::isPublicRequest($fromLan));

        // End to end: admin pages are refused through the connector.
        $this->withServerVariables(['REMOTE_ADDR' => self::CONNECTOR])
            ->get('http://other.example.com/settings')->assertStatus(403);
    }

    public function test_the_env_list_is_normalised(): void
    {
        $config = (static function () {
            $_ENV['NOVARR_PUBLIC_HOSTS'] = $_SERVER['NOVARR_PUBLIC_HOSTS'] = ' https://Read.Example.com:443/ , books.example.com,, ';
            putenv('NOVARR_PUBLIC_HOSTS=' . $_ENV['NOVARR_PUBLIC_HOSTS']);
            try {
                return require base_path('config/novarr.php');
            } finally {
                unset($_ENV['NOVARR_PUBLIC_HOSTS'], $_SERVER['NOVARR_PUBLIC_HOSTS']);
                putenv('NOVARR_PUBLIC_HOSTS');
            }
        })();

        $this->assertSame(['read.example.com', 'books.example.com'], $config['public_hosts']);
        $this->assertSame('https://read.example.com', $config['public_url']);
    }

    // ---- Feature off -------------------------------------------------------------

    public function test_with_no_public_address_nothing_changes(): void
    {
        $this->novelWithChapters();
        $response = $this->viaTunnel()->get(self::PUBLIC . '/settings');
        $response->assertOk();
        foreach (['Strict-Transport-Security', 'X-Robots-Tag', 'Referrer-Policy', 'X-Frame-Options'] as $header) {
            $response->assertHeaderMissing($header);
        }
        $this->getJson(self::PUBLIC . '/api/v1/meta')->assertJsonPath('auth.required', false);
    }

    // ---- Admin features are unavailable -------------------------------------------

    public function test_every_admin_route_is_refused_to_the_admin_on_the_public_host(): void
    {
        $this->enableSignIn();
        $this->configurePublic();
        $token = $this->tokenFor($this->owner());

        $checked = 0;
        foreach ($this->routes() as $route) {
            if (RouteAccess::classify($route) !== RouteAccess::ADMIN) {
                continue;
            }
            $this->asToken($token)->viaTunnel();
            $response = $this->json($this->method($route), self::PUBLIC . $this->uri($route));
            $response->assertStatus(403);
            $response->assertExactJson(['message' => PublicAddress::ADMIN_MESSAGE, 'code' => 'forbidden']);
            $checked++;
        }
        $this->assertGreaterThan(50, $checked);

        // Guests too (not a sign-in prompt), and a browser gets the 403 page.
        $this->asNobody()->viaTunnel()->getJson(self::PUBLIC . '/settings')->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $cookies = $this->webSignIn($this->owner()->username, self::OWNER_PASSWORD);
        $this->withBrowser($cookies)->viaTunnel()->get(self::PUBLIC . '/settings', ['Accept' => 'text/html'])
            ->assertStatus(403)->assertSee(PublicAddress::ADMIN_MESSAGE)->assertSee(PublicAddress::TITLE);

        // The same admin on the home network: allowed.
        $this->asToken($token)->get(self::LAN . '/settings')->assertOk();
    }

    public function test_reader_routes_work_on_the_public_host_for_the_admin_and_a_reader(): void
    {
        $this->enableSignIn();
        $this->configurePublic();
        $this->novelWithChapters();
        $reader = $this->makeReader();

        foreach ([$this->owner(), $reader] as $user) {
            foreach ($this->routes() as $route) {
                if (RouteAccess::classify($route) !== RouteAccess::READER) {
                    continue;
                }
                $key = RouteAccess::key($route);
                $this->asToken($this->tokenFor($user))->viaTunnel();
                $body = $key === 'novels.bulk' ? ['action' => 'read', 'ids' => [1]] : [];
                $response = $this->json($this->method($route), self::PUBLIC . $this->uri($route), $body);
                $this->assertNotContains($response->getStatusCode(), [401, 403], "{$key} refused {$user->username}: " . $response->getContent());
                $this->assertLessThan(500, $response->getStatusCode(), "{$key}: " . $response->getContent());
            }
        }

        // The account's real role is still reported (clients hide admin UI themselves).
        $this->asToken($this->tokenFor($this->owner()))->viaTunnel()->getJson(self::PUBLIC . '/api/v1/auth/me')
            ->assertOk()->assertJsonPath('user.role', 'admin');
        // A per-action admin check inside a reader route is refused too.
        $this->asToken($this->tokenFor($this->owner()))->viaTunnel()
            ->postJson(self::PUBLIC . '/novels/bulk', ['action' => 'complete', 'ids' => [1]])->assertStatus(403);
    }

    public function test_reader_requests_on_the_public_host_search_and_request_but_never_decide(): void
    {
        $this->enableSignIn();
        $this->configurePublic();
        \Illuminate\Support\Facades\Cache::flush();
        \Tests\Support\FakeRequestSource::register();
        \Tests\Support\FakeRequestSource::$search[\Tests\Support\FakeNovelA::class] = [['name' => 'Far Away', 'url' => 'https://novel-a.test/far-away']];
        $reader = $this->tokenFor($this->makeReader());
        $owner = $this->tokenFor($this->owner());

        try {
            // Searching is a reader feature: allowed on the public address.
            $id = $this->asToken($reader)->viaTunnel()->postJson(self::PUBLIC . '/api/v1/requests/searches', ['q' => 'far', 'medium' => 'novel'])
                ->assertStatus(202)->json('search.id');
            (new \App\Jobs\RunRequestSearch($id))->handle(app(\App\Services\Requests\RequestSearch::class), app(\App\Services\Discover::class));
            $search = $this->asToken($reader)->viaTunnel()->getJson(self::PUBLIC . "/api/v1/requests/searches/{$id}")->assertOk()->json('search');
            $this->assertSame('Far Away', $search['results'][0]['title']);

            $request = $this->asToken($reader)->viaTunnel()->postJson(self::PUBLIC . '/api/v1/requests', ['search_id' => $id, 'key' => $search['results'][0]['key']])
                ->assertStatus(201)->json('request');
            $this->asToken($reader)->viaTunnel()->getJson(self::PUBLIC . '/api/v1/notifications')->assertOk();

            // Approving is the admin's, and admin features are home-network only — even for the admin.
            foreach (['approve', 'decline', 'rerank'] as $action) {
                $this->asToken($owner)->viaTunnel()->postJson(self::PUBLIC . "/api/v1/requests/{$request['id']}/{$action}")
                    ->assertStatus(403)->assertExactJson(['message' => PublicAddress::ADMIN_MESSAGE, 'code' => 'forbidden']);
            }
            // The admin reading on the public address gets the reader view: cannot decide.
            $this->asToken($owner)->viaTunnel()->getJson(self::PUBLIC . '/api/v1/requests?scope=all')->assertStatus(403);
            $this->asToken($owner)->viaTunnel()->getJson(self::PUBLIC . '/api/v1/requests')->assertOk()->assertJsonPath('can_decide', false);
            $this->assertTrue(\App\NovelRequest::find($request['id'])->isPending());

            // At home the admin decides.
            $this->asToken($owner)->getJson(self::LAN . "/api/v1/requests/{$request['id']}")->assertOk()->assertJsonPath('request.can_decide', true);
            $this->asToken($owner)->postJson(self::LAN . "/api/v1/requests/{$request['id']}/decline")->assertOk();
        } finally {
            \Tests\Support\FakeRequestSource::reset();
        }
    }

    public function test_admin_ui_is_absent_from_pages_for_the_admin_on_the_public_host(): void
    {
        $this->enableSignIn();
        $this->configurePublic();
        [$novel] = $this->novelWithChapters();

        $pages = [
            '/' => ['id="systemDropdown"', 'home-navstat'],
            '/library' => ['id="systemDropdown"', route('novels.discover', [], false)],
            '/novels/' . $novel->id => ['id="systemDropdown"', 'id="editTags"'],
        ];

        $cookies = $this->webSignIn($this->owner()->username, self::OWNER_PASSWORD);
        foreach ($pages as $uri => $markers) {
            // Home network: the admin's controls are there…
            $lan = $this->withBrowser($cookies)->get(self::LAN . $uri);
            $lan->assertOk();
            foreach ($markers as $marker) {
                $this->assertStringContainsString($marker, $lan->getContent(), "LAN {$uri}: {$marker}");
            }
            // …and gone on the public address.
            $public = $this->withBrowser($cookies)->viaTunnel()->get(self::PUBLIC . $uri);
            $public->assertOk();
            foreach ($markers as $marker) {
                $this->assertStringNotContainsString($marker, $public->getContent(), "public {$uri}: {$marker}");
            }
        }

        // The palette (in every page): admin pages and commands only at home.
        $lan = (string) $this->withBrowser($cookies)->get(self::LAN . '/')->getContent();
        $this->assertStringContainsString('"title":"Settings"', $lan);
        $this->assertStringContainsString('"commands":true', $lan);
        $public = (string) $this->withBrowser($cookies)->viaTunnel()->get(self::PUBLIC . '/')->getContent();
        $this->assertStringNotContainsString('"title":"Settings"', $public);
        $this->assertStringContainsString('"commands":false', $public);
    }

    // ---- Sign-in required -------------------------------------------------------

    public function test_with_sign_in_off_the_public_host_serves_only_public_routes(): void
    {
        $this->configurePublic();
        $this->novelWithChapters();

        foreach ($this->routes() as $route) {
            $key = RouteAccess::key($route);
            $this->asNobody()->viaTunnel();
            $response = $this->json($this->method($route), self::PUBLIC . $this->uri($route));
            if (RouteAccess::classify($route) === RouteAccess::PUBLIC) {
                $this->assertStringNotContainsString(PublicAddress::SIGN_IN_OFF_MESSAGE, (string) $response->getContent(), $key);
                continue;
            }
            $response->assertStatus(403);
            $response->assertExactJson(['message' => PublicAddress::SIGN_IN_OFF_MESSAGE, 'code' => 'forbidden']);
        }

        // The page version, with nobody shown as signed in.
        $this->asNobody()->viaTunnel()->get(self::PUBLIC . '/')->assertStatus(403)->assertSee(PublicAddress::SIGN_IN_OFF_MESSAGE);
        // The home network is unchanged: everyone is the owner.
        $this->asNobody()->get(self::LAN . '/settings')->assertOk();
        // Public routes behave normally.
        $this->asNobody()->viaTunnel()->getJson(self::PUBLIC . '/api/health')->assertOk();
    }

    public function test_meta_on_the_public_host_always_says_sign_in_is_required(): void
    {
        $this->configurePublic();
        $this->viaTunnel()->getJson(self::PUBLIC . '/api/v1/meta')->assertOk()->assertJsonPath('auth.required', true);
        $this->asNobody()->getJson(self::LAN . '/api/v1/meta')->assertOk()->assertJsonPath('auth.required', false);

        $this->enableSignIn();
        $this->asNobody()->viaTunnel()->getJson(self::PUBLIC . '/api/v1/meta')->assertJsonPath('auth.required', true);
        $this->asNobody()->getJson(self::LAN . '/api/v1/meta')->assertJsonPath('auth.required', true);
    }

    // ---- HTTPS, cookies, headers -------------------------------------------------

    public function test_public_host_cookies_are_secure_and_headers_are_set(): void
    {
        $this->enableSignIn();
        $this->configurePublic();

        $page = $this->asNobody()->viaTunnel()->get(self::PUBLIC . '/login');
        $page->assertOk();
        $this->assertNotEmpty($page->headers->getCookies());
        foreach ($page->headers->getCookies() as $cookie) {
            $this->assertTrue($cookie->isSecure(), $cookie->getName() . ' is not Secure');
        }
        $page->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $page->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $page->assertHeader('X-Content-Type-Options', 'nosniff');
        $page->assertHeader('Referrer-Policy', 'same-origin');
        $page->assertHeader('X-Frame-Options', 'DENY');

        // Signing in: the session and remember-me cookies are Secure.
        $login = $this->asNobody()->viaTunnel()->post(self::PUBLIC . '/login', ['username' => $this->owner()->username, 'password' => self::OWNER_PASSWORD]);
        $login->assertRedirect('https://' . self::HOST);
        $names = [];
        foreach ($login->headers->getCookies() as $cookie) {
            $names[] = $cookie->getName();
            $this->assertTrue($cookie->isSecure(), $cookie->getName() . ' is not Secure');
        }
        $this->assertNotEmpty(array_filter($names, fn($n) => str_starts_with($n, 'remember_web_')));
        $this->assertContains(config('session.cookie'), $names);

        // An error answer gets the headers too.
        $this->asNobody()->viaTunnel()->getJson(self::PUBLIC . '/settings')
            ->assertHeader('Strict-Transport-Security', HardenPublicAddress::HSTS)
            ->assertHeader('X-Robots-Tag', HardenPublicAddress::ROBOTS);

        // The home network (plain http): unchanged.
        $lan = $this->asNobody()->get(self::LAN . '/login');
        $lan->assertOk();
        foreach ($lan->headers->getCookies() as $cookie) {
            $this->assertFalse($cookie->isSecure(), $cookie->getName() . ' is Secure on the LAN');
        }
        foreach (['Strict-Transport-Security', 'X-Robots-Tag', 'Referrer-Policy', 'X-Frame-Options'] as $header) {
            $lan->assertHeaderMissing($header);
        }
    }

    public function test_urls_on_the_public_host_are_https_and_do_not_leak_into_the_next_request(): void
    {
        $this->enableSignIn();
        $this->configurePublic();

        // Even without X-Forwarded-Proto, links on the public host are https.
        $this->asNobody()->get(self::PUBLIC . '/')->assertRedirect('https://' . self::HOST . '/login');
        $this->asNobody()->viaTunnel()->get(self::PUBLIC . '/')->assertRedirect('https://' . self::HOST . '/login');
        // The next request on the home network builds its own URLs (Octane-safe).
        $this->asNobody()->get(self::LAN . '/')->assertRedirect(self::LAN . '/login');
    }

    public function test_robots_txt_disallows_everything(): void
    {
        $this->assertSame("User-agent: *\nDisallow: /\n", file_get_contents(public_path('robots.txt')));
    }

    // ---- Links -------------------------------------------------------------------

    public function test_invite_and_app_links_use_the_public_address_from_a_lan_request(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);
        $this->enableSignIn();
        $this->configurePublic();
        $apk = storage_path('framework/testing/public-address.apk');
        @mkdir(dirname($apk), 0777, true);
        file_put_contents($apk, 'apk');
        config(['novarr.android_apk_path' => $apk]);

        try {
            $cookies = $this->webSignIn($this->owner()->username, self::OWNER_PASSWORD);
            $response = $this->withBrowser($cookies)->post(self::LAN . '/settings/users/invites', ['name' => 'Sam Lee', 'email' => 'sam@example.test']);
            $response->assertRedirect();
            $link = session(\App\Http\Controllers\UserInviteController::FLASH)['link'];
            $this->assertStringStartsWith('https://read.example.com/invite/', $link);
            Mail::assertSent(\App\Mail\ReaderInvite::class, function ($mail) {
                $html = $mail->render();

                return str_contains($html, 'https://read.example.com/invite/') && str_contains($html, 'https://read.example.com/app/android');
            });

            $lan = Request::create(self::LAN . '/account');
            $this->assertSame('https://read.example.com/invite/abc', UserInvite::link($lan, 'abc'));
            $this->assertSame('https://read.example.com/app/android', AndroidApp::url($lan));

            // Settings → Users says where links go.
            $page = $this->withBrowser($cookies)->get(self::LAN . '/settings/users')->assertOk()->getContent();
            $this->assertStringContainsString('Links use https://read.example.com.', $page);
            $this->assertStringContainsString('Public address: <strong>https://read.example.com</strong>', $page);
            // The account page's app link.
            $this->assertStringContainsString('href="https://read.example.com/app/android"', $this->withBrowser($cookies)->get(self::LAN . '/account')->getContent());
        } finally {
            @unlink($apk);
        }
    }

    public function test_without_a_public_address_links_use_the_admins_origin(): void
    {
        $lan = Request::create(self::LAN . '/account');
        $this->assertSame(self::LAN . '/invite/abc', UserInvite::link($lan, 'abc'));
        $this->assertSame(self::LAN . '/app/android', AndroidApp::url($lan));

        $this->enableSignIn();
        $cookies = $this->webSignIn($this->owner()->username, self::OWNER_PASSWORD);
        $page = $this->withBrowser($cookies)->get(self::LAN . '/settings/users')->assertOk()->getContent();
        $this->assertStringContainsString('The link uses this address (' . self::LAN . ')', $page);
        $this->assertStringContainsString('Public address: none configured', $page);
    }

    // ---- Per-IP cap on credential attempts ---------------------------------------

    public function test_credential_attempts_are_capped_per_ip_on_the_public_host(): void
    {
        $this->enableSignIn();
        $this->configurePublic();
        $this->makeReader();
        $ip = '203.0.113.7';

        // 20 attempts from one address, spread over every entry point and
        // over different names (so the per-name limit never trips).
        for ($i = 0; $i < 5; $i++) {
            $this->asNobody()->viaTunnel($ip)->post(self::PUBLIC . '/login', ['username' => "web{$i}", 'password' => 'nope'])->assertRedirect();
            $this->asNobody()->viaTunnel($ip)->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => "api{$i}", 'password' => 'nope'])->assertStatus(422);
            $this->asNobody()->viaTunnel($ip)->get(self::PUBLIC . '/opds', ['Authorization' => 'Basic ' . base64_encode("basic{$i}:nope")])->assertStatus(401);
            $this->asNobody()->viaTunnel($ip)->post(self::PUBLIC . '/invite/' . str_repeat('a', 64), [])->assertNotFound();
        }
        $this->assertSame(20, RateLimiter::attempts(Credentials::publicCapKey($ip)));

        // The 21st, even with the right password: 429 with Retry-After.
        $web = $this->asNobody()->viaTunnel($ip)->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $web->assertStatus(429)->assertSee('Too many sign-in attempts.')->assertHeader('Retry-After');
        $api = $this->asNobody()->viaTunnel($ip)->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $api->assertStatus(429)->assertHeader('Retry-After');
        $this->assertStringStartsWith('Too many sign-in attempts. Try again in', $api->json('message'));
        $basic = $this->asNobody()->viaTunnel($ip)->get(self::PUBLIC . '/opds', ['Authorization' => 'Basic ' . base64_encode('sam:' . self::READER_PASSWORD)]);
        $basic->assertStatus(429)->assertHeader('Retry-After');
        $invite = $this->asNobody()->viaTunnel($ip)->post(self::PUBLIC . '/invite/' . str_repeat('b', 64), []);
        $invite->assertStatus(429)->assertHeader('Retry-After')->assertSee('Too many sign-in attempts.');
        $this->assertLessThanOrEqual(600, (int) $api->headers->get('Retry-After'));

        // Another visitor through the same connector is not affected.
        $this->asNobody()->viaTunnel('198.51.100.9')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertOk();
        // Nor is the home network (only the per-name limit there).
        $this->asNobody()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(self::LAN . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertOk();
    }

    public function test_the_home_network_keeps_todays_limits(): void
    {
        $this->enableSignIn();
        $this->configurePublic();
        $this->makeReader();

        for ($i = 0; $i < 25; $i++) {
            $this->asNobody()->withServerVariables(['REMOTE_ADDR' => '192.168.1.77'])
                ->postJson(self::LAN . '/api/v1/auth/token', ['username' => "nobody{$i}", 'password' => 'nope'])->assertStatus(422);
        }
        $this->assertSame(0, RateLimiter::attempts(Credentials::publicCapKey('192.168.1.77')));
        $this->asNobody()->withServerVariables(['REMOTE_ADDR' => '192.168.1.77'])
            ->postJson(self::LAN . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertOk();
    }

    // ---- Trusted proxies -----------------------------------------------------------

    public function test_forwarded_headers_are_used_only_from_the_trusted_connector(): void
    {
        // TRUSTED_PROXIES=192.168.1.50. The kernel applies bootstrap/app.php's
        // middleware config (TrustProxies::at(env…)) when it is first made:
        // make it first, then narrow the list.
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        TrustProxies::at([self::CONNECTOR]);
        $this->enableSignIn();
        $this->configurePublic();

        // From the trusted connector: the visitor's address (the last
        // X-Forwarded-For hop, the one Cloudflare appended) is counted.
        $this->asNobody()->withServerVariables(['REMOTE_ADDR' => self::CONNECTOR])
            ->withHeaders(['X-Forwarded-For' => '6.6.6.6, 203.0.113.7', 'X-Forwarded-Proto' => 'https', 'CF-Connecting-IP' => '9.9.9.9'])
            ->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'x', 'password' => 'nope'])->assertStatus(422);
        $this->assertSame(1, RateLimiter::attempts(Credentials::publicCapKey('203.0.113.7')));
        $this->assertSame(0, RateLimiter::attempts(Credentials::publicCapKey('6.6.6.6')));
        $this->assertSame(0, RateLimiter::attempts(Credentials::publicCapKey('9.9.9.9')));
        $this->assertSame(0, RateLimiter::attempts(Credentials::publicCapKey(self::CONNECTOR)));

        // From anyone else: forwarded headers (and CF-Connecting-IP) are ignored.
        $this->asNobody()->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.99', 'CF-Connecting-IP' => '203.0.113.98'])
            ->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'x', 'password' => 'nope'])->assertStatus(422);
        $this->assertSame(1, RateLimiter::attempts(Credentials::publicCapKey('198.51.100.40')));
        $this->assertSame(0, RateLimiter::attempts(Credentials::publicCapKey('203.0.113.99')));
        $this->assertSame(0, RateLimiter::attempts(Credentials::publicCapKey('203.0.113.98')));

        // X-Forwarded-Proto / -Host from an untrusted address do not change URLs (home network).
        $this->asNobody()->withServerVariables(['REMOTE_ADDR' => '192.168.1.77'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example'])
            ->get(self::LAN . '/')->assertRedirect(self::LAN . '/login');
        // From the trusted one they do.
        $this->asNobody()->withServerVariables(['REMOTE_ADDR' => self::CONNECTOR])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->get(self::LAN . '/')->assertRedirect('https://novarr.lan/login');
    }

    // ---- Artisan -----------------------------------------------------------------

    public function test_auth_status_shows_the_public_address_and_warns_while_sign_in_is_off(): void
    {
        $this->artisan('novarr:auth', ['action' => 'status'])
            ->expectsOutputToContain('Public address')
            ->expectsOutputToContain('not set')
            ->doesntExpectOutputToContain('refuses every page')
            ->assertExitCode(0);

        $this->configurePublic();
        $this->artisan('novarr:auth', ['action' => 'status'])
            ->expectsOutputToContain('https://read.example.com')
            ->expectsOutputToContain('the public address refuses every page until sign-in is on')
            ->assertExitCode(0);

        $this->enableSignIn();
        $this->artisan('novarr:auth', ['action' => 'status'])
            ->expectsOutputToContain('https://read.example.com')
            ->doesntExpectOutputToContain('refuses every page')
            ->assertExitCode(0);
    }
}
