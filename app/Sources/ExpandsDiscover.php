<?php

namespace App\Sources;

/**
 * A source that appears on the Discover page as several picker entries
 * instead of one — e.g. SuwayomiSource, one entry per source installed on
 * the user's Suwayomi-Server. SourceResolver::discoverable() replaces the
 * registered instance with these entries (same tiering rules) under the
 * keys "{registry key}:{entry id}", and SourceResolver::get() resolves such
 * a key back to its scoped instance, so a key from a request
 * ("suwayomi:8448310129093543312") keeps working end to end.
 *
 * The registered (unscoped) instance is what URL resolution uses; it lists
 * nothing itself (discoverLists() = []).
 */
interface ExpandsDiscover
{
    /**
     * The Discover entries keyed by entry id (letters, digits, '-' and '_'
     * only; the resolver prefixes the registry key). [] when there is
     * nothing to list (not configured, or the listing failed).
     *
     * @return array<string, Source>
     */
    public function discoverSources(): array;
}
