<?php

namespace App\Http\Middleware;

use App\Support\CurrentReader;
use Closure;
use Illuminate\Http\Middleware\SetCacheHeaders as BaseSetCacheHeaders;

/**
 * The framework's `cache.headers`, with the reader in the ETag.
 *
 * The conditional-GET routes (chapter lists, the library, OPDS, the palette)
 * carry the reader's read state, so a 304 must never answer one reader with
 * another's body: the ETag is a hash of the reader's id AND the body. Their
 * routes also say `private` (no shared cache may store them).
 */
class SetCacheHeaders extends BaseSetCacheHeaders
{
    public function handle($request, Closure $next, $options = [])
    {
        if (is_string($options)) {
            $options = $this->parseOptions($options);
        }

        if (($options['etag'] ?? null) === true) {
            $inner = $next;
            $next = function ($request) use ($inner) {
                $response = $inner($request);
                if ($response->getEtag() === null && $request->isMethodCacheable() && $response->isSuccessful()) {
                    $content = $response->getContent();
                    if (is_string($content) && $content !== '') {
                        $response->setEtag(hash('xxh128', static::readerKey() . '|' . $content));
                    }
                }

                return $response;
            };
        }

        return parent::handle($request, $next, $options);
    }

    /** The reader the response was built for ("guest" on a public route). */
    private static function readerKey(): string
    {
        $reader = app(CurrentReader::class);

        return $reader->isGuest() ? 'guest' : 'u' . $reader->id();
    }
}
