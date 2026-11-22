<?php

namespace Tests\Support;

use App\ApiToken;
use App\Novel;
use App\NovelChapter;
use App\Support\AuthMode;
use App\User;
use Illuminate\Support\Facades\Hash;

/**
 * Sign-in helpers for feature tests: real credentials through real HTTP
 * (web session cookies carried by hand, bearer tokens), never actingAs —
 * App\Http\Middleware\IdentifyReader starts every request from scratch.
 */
trait UsesAccounts
{
    public const OWNER_PASSWORD = 'owner-pass-1';

    public const READER_PASSWORD = 'reader-pass-1';

    /** The owner with a known password, and "Require sign-in" on. */
    protected function enableSignIn(): User
    {
        $owner = $this->owner();
        $owner->setNewPassword(self::OWNER_PASSWORD);
        AuthMode::store(true);

        return $owner->fresh();
    }

    protected function makeReader(string $username = 'sam', string $name = 'Sam'): User
    {
        return $this->makeReaderAccount($username, $name);
    }

    protected function makeReaderAccount(string $username = 'sam', string $name = 'Sam'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $name,
            'username' => $username,
            'role' => User::ROLE_READER,
            'password' => Hash::make(self::READER_PASSWORD),
            'password_set_at' => now(),
        ])->save();

        return $user;
    }

    protected function tokenFor(User $user, string $name = 'Test device'): string
    {
        return ApiToken::issue($user, $name)[1];
    }

    /** Requests from here on carry this bearer token (and no cookies). */
    protected function asToken(string $token): static
    {
        $this->asNobody();

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * Requests from here on are $user's signed-in browser on $host (signed
     * in through the real form; on the public address with the second
     * step). Web pages need this: bearer tokens open only the API and the
     * Basic routes. $prepare adds headers / server variables to the sign-in
     * requests too (the tunnel's).
     */
    protected function asSignedIn(User $user, string $host = '', ?callable $prepare = null): static
    {
        $password = collect([self::READER_PASSWORD, self::OWNER_PASSWORD])
            ->first(fn($p) => Hash::check($p, (string) $user->fresh()->password));
        if ($password === null) {
            $password = 'signed-in-for-the-test';
            $user->forceFill(['password' => Hash::make($password), 'password_set_at' => $user->password_set_at ?? now()])->save();
        }
        $public = $host !== '' && \App\Support\PublicAddress::isPublicRequest(\Illuminate\Http\Request::create($host . '/'));
        if ($public) {
            $cookies = $this->cookiesOf($this->publicWebSignIn((string) $user->username, $password, $host, $prepare));
        } else {
            $this->asNobody();
            $prepare && $prepare($this);
            $response = $this->post($host . '/login', ['username' => $user->username, 'password' => $password]);
            $response->assertRedirect();
            $cookies = $this->cookiesOf($response);
        }
        $this->withBrowser($cookies);
        $prepare && $prepare($this);

        return $this;
    }

    /** Requests from here on carry no credentials. */
    protected function asNobody(): static
    {
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withCredentials = false;
        // The test app reuses one session store object across requests and
        // its attributes would leak into the next request (a browser gets a
        // fresh one from its cookie). Saved sessions stay in the handler.
        $this->app['session.store']->flush();
        // The cookie jar is one object for the whole test too: a cookie
        // queued for one response (the remember-me cookie) would be added to
        // the next ones. Octane flushes it per request (FlushQueuedCookies).
        $this->app['cookie']->flushQueuedCookies();

        return $this;
    }

    /**
     * Sign in through POST /login; returns the response's cookies (already
     * encrypted, as a browser holds them).
     *
     * @return array<string, string>
     */
    protected function webSignIn(string $username, string $password): array
    {
        $this->asNobody();
        $response = $this->post('/login', ['username' => $username, 'password' => $password]);
        $response->assertRedirect();

        return $this->cookiesOf($response);
    }

    /** A known authenticator secret for $user (set up, nothing used yet). Returns the secret. */
    protected function giveAuthenticator(User $user, string $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'): string
    {
        app(\App\Services\Authenticator::class)->enable($user, $secret, 0);
        $user->forceFill(['totp_last_step' => null])->save();

        return $secret;
    }

    /** The authenticator code of $secret now (+ $steps time steps). */
    protected function totpCode(string $secret, int $steps = 0): string
    {
        $step = intdiv(now()->getTimestamp(), \App\Services\Authenticator::PERIOD) + $steps;

        return \OTPHP\TOTP::createFromSecret($secret, new \App\Support\AppClock())->at($step * \App\Services\Authenticator::PERIOD);
    }

    /**
     * Sign in on the public address the whole way: the password, then the
     * authenticator code (set up for the account when it has none; its used
     * step is forgotten first, so a test can sign in many times a second).
     * $prepare adds headers / server variables to both requests. Returns the
     * last response (the redirect after signing in).
     */
    protected function publicWebSignIn(string $username, string $password, string $host = 'http://read.example.com', ?callable $prepare = null, array $fields = [])
    {
        $user = User::findForSignIn($username);
        $secret = app(\App\Services\Authenticator::class)->secretOf($user) ?? $this->giveAuthenticator($user);
        $user->forceFill(['totp_last_step' => null])->save();

        $this->asNobody();
        $prepare && $prepare($this);
        $first = $this->post($host . '/login', ['username' => $username, 'password' => $password] + $fields);
        $this->assertStringEndsWith('/login/second-step', (string) $first->headers->get('Location'), 'The password should lead to the second step.');

        $this->withBrowser($this->cookiesOf($first));
        $prepare && $prepare($this);

        return $this->post($host . '/login/second-step', ['method' => 'totp', 'code' => $this->totpCode($secret)]);
    }

    /** @return array<string, string> */
    protected function cookiesOf($response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            // Expiry by the app's clock: Cookie::isCleared() asks the real
            // one, which drops every cookie of a test that travels back.
            $expires = $cookie->getExpiresTime();
            if ($cookie->getValue() !== null && ($expires === 0 || $expires > now()->getTimestamp())) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $cookies;
    }

    /** Requests from here on carry these browser cookies (and no token). */
    protected function withBrowser(array $cookies): static
    {
        $this->asNobody();
        $this->unencryptedCookies = $cookies;
        // JSON requests (getJson / postJson) send cookies only with this on.
        $this->withCredentials = true;

        return $this;
    }

    /** A downloaded novel with $count chapters. @return array{0: Novel, 1: NovelChapter[]} */
    protected function novelWithChapters(string $name = 'Account Novel', int $count = 3, string $medium = Novel::MEDIUM_NOVEL): array
    {
        $novel = Novel::create(['name' => $name, 'status' => 0, 'group_id' => 1, 'no_of_chapters' => $count, 'medium' => $medium]);
        $chapters = [];
        foreach (range(1, $count) as $n) {
            $chapter = NovelChapter::create([
                'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0,
                'label' => "Chapter {$n}", 'url' => "https://example.test/" . md5($name) . "/{$n}",
            ]);
            $chapter->description = "<p>Words of chapter {$n}.</p>";
            $chapter->forceFill(['status' => 1, 'download_date' => now()->subDays(2)])->save();
            $chapters[$n] = $chapter;
        }

        return [$novel, $chapters];
    }
}
