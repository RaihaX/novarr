<?php

namespace Tests\Feature;

use App\ApiToken;
use App\AuthEvent;
use App\Http\Controllers\AccountSecurityController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Catalogue (OPDS) passwords: made on the Account page (shown once, stored
 * as SHA-256), the HTTP Basic password of /opds and the ePub download only.
 * On the public address Basic never takes (nor even checks) the account
 * password; at home both work.
 */
class CataloguePasswordsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const PUBLIC = 'http://read.example.com';

    private const LAN = 'http://novarr.lan';

    protected function setUp(): void
    {
        parent::setUp();
        config(['novarr.public_hosts' => ['read.example.com']]);
    }

    private function basic(string $name, string $password): static
    {
        $this->asNobody();

        return $this->withHeader('Authorization', 'Basic ' . base64_encode($name . ':' . $password));
    }

    private function make(array $cookies, string $name = 'KOReader'): string
    {
        $response = $this->withBrowser($cookies)->post(route('account.catalogue.store'), ['name' => $name]);
        $response->assertRedirect(route('account') . '#catalogue');

        return session(AccountSecurityController::FLASH_CATALOGUE_PASSWORD)['password'];
    }

    public function test_a_catalogue_password_is_made_shown_once_and_stored_hashed(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $plain = $this->make($cookies);

        $this->assertMatchesRegularExpression('/^nvr_[A-Za-z0-9]{60}$/', $plain);
        $token = ApiToken::sole();
        $this->assertSame(ApiToken::KIND_CATALOGUE, $token->kind);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertSame('KOReader', $token->name);
        $this->assertSame('KOReader', AuthEvent::where('event', AuthEvent::CATALOGUE_PASSWORD_CREATED)->sole()->detail['name']);

        // Shown on the next page only.
        $this->withBrowser($cookies)->withSession([AccountSecurityController::FLASH_CATALOGUE_PASSWORD => ['name' => 'KOReader', 'password' => $plain]])
            ->get(route('account'))->assertSee($plain);
        $page = $this->withBrowser($cookies)->get(route('account'))->getContent();
        $this->assertStringNotContainsString($plain, $page);
        preg_match('#id="catalogueList">(.*?)</ul>#s', $page, $m);
        $this->assertStringContainsString('KOReader', $m[1]);
        $this->assertStringContainsString('Not used yet', $m[1]);
        // Not listed with the apps and devices, nor counted as one.
        preg_match('#id="devicesTitle">.*?</section>#s', $page, $devices);
        $this->assertStringNotContainsString('KOReader', $devices[0]);
        $this->assertSame($sam->id, $token->user_id);
    }

    public function test_on_the_public_address_basic_takes_a_catalogue_password_never_the_account_password(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $sam->forceFill(['email' => 'sam@example.test'])->save();
        $plain = $this->make($this->webSignIn('sam', self::READER_PASSWORD));

        $this->basic('sam', $plain)->get(self::PUBLIC . '/opds')->assertOk();
        $this->basic('sam@example.test', $plain)->get(self::PUBLIC . '/opds')->assertOk();
        $this->assertNotNull(ApiToken::sole()->last_used_at);

        $this->makeReader('kim', 'Kim');
        // The account password: the Basic challenge, without even checking it.
        Hash::spy();
        $refused = $this->basic('sam', self::READER_PASSWORD)->get(self::PUBLIC . '/opds');
        $refused->assertStatus(401);
        $this->assertStringContainsString('Basic realm="Novarr"', (string) $refused->headers->get('WWW-Authenticate'));
        $this->assertStringContainsString('catalogue password', $refused->json('message'));
        Hash::shouldNotHaveReceived('check');

        // Someone else's name with this password, or a wrong one: refused.
        $this->basic('kim', $plain)->get(self::PUBLIC . '/opds')->assertStatus(401);
        $this->basic('sam', 'nvr_' . str_repeat('z', 60))->get(self::PUBLIC . '/opds')->assertStatus(401);
    }

    public function test_at_home_both_the_account_password_and_a_catalogue_password_work(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $plain = $this->make($this->webSignIn('sam', self::READER_PASSWORD));
        $this->basic('sam', self::READER_PASSWORD)->get(self::LAN . '/opds')->assertOk();
        $this->basic('sam', $plain)->get(self::LAN . '/opds')->assertOk();
    }

    public function test_a_catalogue_password_is_no_bearer_token_and_works_on_opds_and_epubs_only(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        [$novel] = $this->novelWithChapters();
        $plain = $this->make($this->webSignIn('sam', self::READER_PASSWORD));

        $this->asToken($plain)->getJson(self::LAN . '/api/v1/auth/me')->assertStatus(401);
        $this->asToken($plain)->getJson(self::PUBLIC . '/api/v1/novels')->assertStatus(401);
        // The ePub download takes it (404/other: no ePub built here, but not refused).
        $epub = $this->basic('sam', $plain)->get(self::PUBLIC . '/novels/' . $novel->id . '/epub');
        $this->assertNotContains($epub->getStatusCode(), [401, 403]);
        // Other pages do not.
        $this->basic('sam', $plain)->getJson(self::PUBLIC . '/stats')->assertStatus(401);
    }

    public function test_revoking_one_stops_it(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $plain = $this->make($cookies);
        $token = ApiToken::sole();

        $this->withBrowser($cookies)->delete(route('account.catalogue.destroy', $token->id))->assertRedirect(route('account') . '#catalogue');
        $this->basic('sam', $plain)->get(self::PUBLIC . '/opds')->assertStatus(401);
        $this->assertSame('Catalogue password revoked: KOReader', AuthEvent::where('event', AuthEvent::CATALOGUE_PASSWORD_REVOKED)->sole()->label());
        // An app token cannot be revoked through it (nor the other way round).
        $app = ApiToken::issue(\App\User::findByUsername('sam'), 'Phone')[0];
        $this->withBrowser($cookies)->delete(self::LAN . '/account/catalogue-passwords/' . $app->id)->assertNotFound();
    }
}
