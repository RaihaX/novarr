<?php

namespace App\Books;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Writing to the owner's Calibre-Web (the other half of App\Books\CalibreWeb,
 * which only reads OPDS): add a book (POST /upload) and change its details
 * (the edit form, POST /admin/book/{id}). Calibre-Web 0.6.x.
 *
 * Needs, in Calibre-Web: uploads switched on (Basic Configuration) and the
 * Novarr account given Upload and Edit rights. Writes go through a signed-in
 * session, not the OPDS basic auth: Calibre-Web allows 3 sign-ins a minute
 * and 40 a day per account, so the session cookie is kept (cache, a week,
 * "remember me") and Novarr signs in again only when it has run out.
 *
 * Every POST carries the form's csrf_token (Flask-WTF). The edit posts the
 * whole form back as Calibre-Web drew it, with only Novarr's fields changed,
 * so languages, identifiers, ratings and custom columns stay as they were.
 */
class CalibreWebWriter
{
    private const COOKIES = 'calibre_web_session_cookies';

    private ?CookieJar $jar = null;

    /**
     * Add an ePub to Calibre: its new book id.
     *
     * @throws \RuntimeException
     */
    public function upload(string $path, string $filename): int
    {
        $csrf = $this->csrf();
        $response = $this->http()
            ->attach('btn-upload', fopen($path, 'r'), $filename, ['Content-Type' => 'application/epub+zip'])
            ->withHeaders(['X-CSRFToken' => $csrf, 'Accept' => 'application/json'])
            ->post(CalibreWeb::base() . '/upload', ['csrf_token' => $csrf]);
        $this->keep();

        if ($response->status() === 403) {
            throw new \RuntimeException('Calibre-Web refused the upload: switch uploads on, and give the Novarr account Upload and Edit rights.');
        }
        $location = (string) ($response->json('location') ?? '');
        if (!$response->successful() || !preg_match('#/(?:admin/)?book/(\d+)#', $location, $m)) {
            throw new \RuntimeException('Calibre-Web did not add the book (answered ' . $response->status() . ($location !== '' ? ", {$location}" : '') . ').');
        }

        return (int) $m[1];
    }

    /**
     * Change a book's details. $fields: title, authors (string[]),
     * description (plain text), tags (string[]), series, series_index,
     * publisher. Only the keys given change. $coverFile: an image to upload
     * as the cover.
     *
     * @throws \RuntimeException
     */
    public function update(int $bookId, array $fields, ?string $coverFile = null): void
    {
        $url = CalibreWeb::base() . "/admin/book/{$bookId}";
        $page = $this->authed(fn() => $this->http()->get($url));
        if (!$page->successful()) {
            throw new \RuntimeException("Calibre-Web has no book {$bookId} to edit (answered {$page->status()}).");
        }
        $form = self::formValues($page->body());
        if (!isset($form['csrf_token'])) {
            throw new \RuntimeException('Calibre-Web did not show the edit form: give the Novarr account Edit rights.');
        }

        foreach (self::formFields($fields) as $name => $value) {
            $form[$name] = $value;
        }
        $form['detail_view'] = 'on';

        $request = $this->http()->withHeaders(['X-CSRFToken' => $form['csrf_token']]);
        if ($coverFile !== null && is_file($coverFile)) {
            $request = $request->attach('btn-upload-cover', fopen($coverFile, 'r'), basename($coverFile));
        } else {
            $request = $request->asMultipart();
        }
        $response = $request->post($url, $form);
        $this->keep();

        if ($response->status() >= 400) {
            throw new \RuntimeException("Calibre-Web did not save the details of book {$bookId} (answered {$response->status()}).");
        }
    }

    /** Novarr's fields as Calibre-Web's edit form names them. */
    public static function formFields(array $fields): array
    {
        $out = [];
        if (array_key_exists('title', $fields) && trim((string) $fields['title']) !== '') {
            $out['title'] = trim((string) $fields['title']);
        }
        if (!empty($fields['authors'])) {
            // Calibre separates authors with "&"; a comma inside a name is its own.
            $out['authors'] = implode(' & ', array_map(fn($a) => str_replace('&', 'and', trim((string) $a)), (array) $fields['authors']));
        }
        if (array_key_exists('description', $fields)) {
            $text = trim((string) $fields['description']);
            $out['comments'] = $text === '' ? '' : implode('', array_map(
                fn($p) => '<p>' . htmlspecialchars($p, ENT_QUOTES | ENT_HTML5) . '</p>',
                preg_split('/\n\s*\n|\n/u', $text) ?: [$text],
            ));
        }
        if (array_key_exists('tags', $fields)) {
            $out['tags'] = implode(', ', array_map(fn($t) => str_replace(',', ' ', trim((string) $t)), (array) $fields['tags']));
        }
        if (array_key_exists('series', $fields)) {
            $out['series'] = trim((string) $fields['series']);
            $index = $fields['series_index'] ?? null;
            $out['series_index'] = $out['series'] !== '' && $index !== null && $index !== '' ? rtrim(rtrim(number_format((float) $index, 2, '.', ''), '0'), '.') : '1';
        }
        if (array_key_exists('publisher', $fields) && $fields['publisher'] !== null) {
            $out['publisher'] = trim((string) $fields['publisher']);
        }

        return $out;
    }

    /**
     * The values a browser would send for Calibre-Web's edit form: inputs
     * (not files or buttons; checkboxes only when checked), textareas and
     * selects (their selected option, several for a multiple select).
     *
     * @return array<string, string|array>
     */
    public static function formValues(string $html): array
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $x = new DOMXPath($doc);
        $form = $x->query("//form[@id='book_edit_frm']")->item(0) ?? $x->query('//form[.//input[@name="csrf_token"]]')->item(0);
        if ($form === null) {
            return [];
        }

        $values = [];
        foreach ($x->query('.//input[@name]|.//textarea[@name]|.//select[@name]', $form) as $node) {
            /** @var DOMElement $node */
            $name = $node->getAttribute('name');
            $tag = strtolower($node->tagName);
            if ($tag === 'input') {
                $type = strtolower($node->getAttribute('type') ?: 'text');
                if (in_array($type, ['file', 'submit', 'button', 'image', 'reset'], true)) {
                    continue;
                }
                if (in_array($type, ['checkbox', 'radio'], true) && !$node->hasAttribute('checked')) {
                    continue;
                }
                $values[$name] = $node->getAttribute('value') !== '' || $type !== 'checkbox' ? $node->getAttribute('value') : 'on';
            } elseif ($tag === 'textarea') {
                $values[$name] = $node->textContent;
            } else {
                $selected = [];
                foreach ($x->query('.//option', $node) as $option) {
                    /** @var DOMElement $option */
                    if ($option->hasAttribute('selected')) {
                        $selected[] = $option->getAttribute('value');
                    }
                }
                if ($selected !== []) {
                    $values[$name] = $node->hasAttribute('multiple') ? $selected : $selected[0];
                }
            }
        }

        return $values;
    }

    /** A csrf_token from a signed-in page. */
    private function csrf(): string
    {
        $page = $this->authed(fn() => $this->http()->get(CalibreWeb::base() . '/me'));
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page->body(), $m)) {
            return $m[1];
        }

        throw new \RuntimeException('Calibre-Web gave no form token.');
    }

    /** A GET that signs in first when the session has run out (redirected to /login). */
    private function authed(callable $get)
    {
        $response = $get();
        if (self::isLoginPage($response)) {
            $this->login();
            $response = $get();
            if (self::isLoginPage($response)) {
                throw new \RuntimeException('Calibre-Web did not accept Novarr’s sign-in.');
            }
        }
        $this->keep();

        return $response;
    }

    private static function isLoginPage($response): bool
    {
        $final = (string) ($response->effectiveUri() ?? '');

        // The sign-in form (its "remember me" box); /me also has a password field.
        return str_contains($final, '/login') || str_contains($response->body(), 'name="remember_me"');
    }

    private function login(): void
    {
        $this->jar = new CookieJar();
        $page = $this->http()->get(CalibreWeb::base() . '/login');
        if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page->body(), $m)) {
            throw new \RuntimeException('Calibre-Web’s sign-in page has no form token.');
        }
        $response = $this->http()->asForm()->post(CalibreWeb::base() . '/login', [
            'next' => '/',
            'csrf_token' => $m[1],
            'username' => (string) config('novarr.calibre_web.username', ''),
            'password' => (string) config('novarr.calibre_web.password', ''),
            'remember_me' => 'on',
            'submit' => '',
        ]);
        if ($response->status() === 429) {
            throw new \RuntimeException('Calibre-Web is limiting sign-ins for the Novarr account: try again in a few minutes.');
        }
        $this->keep();
    }

    private function http()
    {
        return Http::timeout(max(30, (int) config('novarr.calibre_web.timeout', 30) * 4))
            ->withUserAgent('Novarr (self-hosted reading server)')
            ->withOptions(['cookies' => $this->jar()]);
    }

    private function jar(): CookieJar
    {
        if ($this->jar === null) {
            $saved = Cache::get(self::COOKIES);
            $this->jar = new CookieJar(false, is_array($saved) ? array_map(fn($c) => new SetCookie($c), $saved) : []);
        }

        return $this->jar;
    }

    /** Keep the session cookies for the next run. */
    private function keep(): void
    {
        Cache::put(self::COOKIES, $this->jar()->toArray(), now()->addDays(7));
    }

    public static function forgetSession(): void
    {
        Cache::forget(self::COOKIES);
    }
}
