<?php

namespace Tests\Feature;

use App\AuthEvent;
use App\Services\BreachedPasswords;
use App\Services\BrowserSessions;
use App\Services\Credentials;
use App\Services\KnownDevices;
use App\Services\Turnstile;
use App\Support\DeviceDescription;
use App\Support\RouteAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The sign-in hardening keeps no per-request state (Octane reuses one
 * application for many requests), and with sign-in off nothing changes:
 * everyone is the owner, no browsers or devices are tracked, the new
 * account pages answer 404 like the existing ones.
 */
class AuthHardeningStateTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    public function test_the_new_services_hold_no_static_or_shared_state(): void
    {
        foreach ([BreachedPasswords::class, Turnstile::class, KnownDevices::class, BrowserSessions::class, Credentials::class, DeviceDescription::class] as $class) {
            $reflection = new \ReflectionClass($class);
            $statics = array_filter($reflection->getProperties(\ReflectionProperty::IS_STATIC), fn($p) => $p->getDeclaringClass()->getName() === $class);
            $this->assertSame([], array_map(fn($p) => $p->getName(), $statics), "{$class} has static properties");
            $instance = array_filter($reflection->getProperties(), fn($p) => !$p->isStatic() && $p->getDeclaringClass()->getName() === $class);
            $this->assertSame([], array_map(fn($p) => $p->getName(), $instance), "{$class} keeps state on the object");
            $this->assertFalse($this->app->isShared($class), "{$class} is bound as a singleton");
        }
        $own = array_filter((new \ReflectionClass(AuthEvent::class))->getProperties(\ReflectionProperty::IS_STATIC), fn($p) => $p->getDeclaringClass()->getName() === AuthEvent::class);
        $this->assertSame([], $own);
    }

    public function test_one_application_serving_public_then_home_requests_does_not_carry_anything_over(): void
    {
        config([
            'novarr.public_hosts' => ['read.example.com'],
            'services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret',
        ]);
        $this->enableSignIn();
        $this->makeReader();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->asNobody()->get('http://read.example.com/login')->assertSee('cf-turnstile', false);
        $public = $this->asNobody()->post('http://read.example.com/login', ['username' => 'sam', 'password' => self::READER_PASSWORD, Turnstile::FIELD => 'ok']);
        $public->assertRedirect();

        // The next request on the same application, on the home network.
        $this->asNobody()->get('http://novarr.lan/login')->assertDontSee('cf-turnstile', false);
        $home = $this->asNobody()->post('http://novarr.lan/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $home->assertRedirect(route('home'));
        foreach ($home->headers->getCookies() as $cookie) {
            $this->assertFalse($cookie->isSecure(), $cookie->getName() . ' is Secure on the home network');
        }
        $this->assertSame([true, false], AuthEvent::where('event', AuthEvent::SIGN_IN)->orderBy('id')->pluck('public')->all());
        $this->assertSame([1, 0], DB::table('browser_sessions')->orderBy('id')->pluck('public')->map(fn($v) => (int) $v)->all());
    }

    public function test_with_sign_in_off_nothing_is_tracked_and_everyone_is_the_owner(): void
    {
        $owner = $this->owner();
        $owner->setNewPassword(self::OWNER_PASSWORD);

        $this->get('/')->assertOk();
        $page = $this->get(route('settings.users'))->assertOk();
        $this->assertSame(0, DB::table('browser_sessions')->count());
        $this->assertSame(0, DB::table('known_devices')->count());
        $this->assertSame(0, AuthEvent::count());
        foreach ($page->headers->getCookies() as $cookie) {
            $this->assertNotSame(KnownDevices::COOKIE, $cookie->getName());
        }

        // /login has nothing to sign in to; the account pages are 404.
        $this->post('/login', ['username' => $owner->username, 'password' => self::OWNER_PASSWORD])->assertRedirect(route('home'));
        $this->assertSame(0, AuthEvent::count());
        $this->get(route('account'))->assertNotFound();
        $this->post(route('account.browsers.sign_out_others'))->assertNotFound();
    }

    public function test_the_new_routes_are_classified(): void
    {
        $this->assertSame(RouteAccess::ADMIN, RouteAccess::classifyKey('settings.users.history'));
        $this->assertTrue(RouteAccess::isListed('settings.users.history'));
        $this->assertSame(RouteAccess::READER, RouteAccess::classifyKey('account.browsers.sign_out_others'));
    }
}
