<?php

namespace App\Support;

use Illuminate\Routing\Route;

/**
 * Who may call each route when "Require sign-in" is on — the ONE list, read
 * by App\Http\Middleware\IdentifyReader (enforcement) and by
 * tests/Feature/RouteAccessTest (which walks every registered route and fails
 * on any that is not listed here).
 *
 *   public — no credentials (sign-in itself, health checks, discovery, the
 *            KOReader plugin's self-update, the offline shell)
 *   reader — any signed-in account
 *   admin  — the owner only. DEFAULT: a route that is not listed below is
 *            admin-only (default-deny), so a new route is never accidentally
 *            open to readers.
 *
 * Routes are listed by name; a route without a name by "uri:<uri>".
 * A route listed as reader may still check per action in its controller
 * (Gate 'admin'): novels.bulk (read = reader, delete/complete = admin).
 */
final class RouteAccess
{
    public const PUBLIC = 'public';

    public const READER = 'reader';

    public const ADMIN = 'admin';

    /** No credentials needed. */
    public const PUBLIC_ROUTES = [
        // Signing in / out
        'login',
        'login.store',
        'logout',
        // The PWA's offline fallback page (no reader data on it)
        'offline',
        // Health checks (Docker, monitoring)
        'uri:up',
        'api.health',
        'api.health.db',
        'api.health.cache',
        'api.ping',
        // Native clients: discovery, password sign-in, device pairing
        'api.v1.meta',
        'api.v1.auth.token.store',
        'api.v1.auth.pairings.store',
        'api.v1.auth.pairings.show',
        // Old KOReader plugins must be able to update themselves
        'api.v1.koreader.plugin',
        'api.v1.koreader.plugin.file',
        // Laravel's local-disk file routes: they check their own temporary
        // signature (Illuminate\Filesystem\ServeFile / ReceiveFile).
        'storage.local',
        'storage.local.upload',
    ];

    /** Any signed-in account. */
    public const READER_ROUTES = [
        // Home, library, series, reading
        'home',
        'continue',
        'library',
        'novels.index',
        'novels.show',
        'novels.jump_chapter',
        'novels.offline_manifest',
        'novels.chapters_json',
        'novels.bulk', // per action in NovelController::bulk()
        'chapters.show',
        'chapters.pages',
        'chapters.page',
        // Own read state
        'chapters.toggle_read',
        'chapters.read_through',
        'chapters.progress',
        'chapters.bulk_read',
        // ePub download, OPDS
        'novels.download_epub',
        'opds.index',
        // Search, palette, own stats and highlights
        'search.index',
        'palette',
        'stats.index',
        'bookmarks.index',
        'bookmarks.store',
        'bookmarks.destroy',
        // Own account
        'account',
        'account.profile',
        'account.password',
        'account.tokens.revoke',
        'account.link',
        'account.link.confirm',
        // Native app API
        'api.v1.auth.me',
        'api.v1.auth.token.destroy',
        'api.v1.novels',
        'api.v1.home',
        'api.v1.novels.show',
        'api.v1.novels.chapters',
        'api.v1.novels.position',
        'api.v1.novels.epub',
        'api.v1.novels.epub.build',
        'api.v1.novels.check',
        'api.v1.novels.check.start',
        'api.v1.chapters.show',
        'api.v1.chapters.pages',
        'api.v1.chapters.progress',
        'api.v1.chapters.read',
        'api.v1.chapters.bulk_read',
        'api.v1.highlights',
        'api.v1.highlights.store',
        'api.v1.highlights.destroy',
    ];

    /**
     * Admin-only routes, listed so the route walk can tell "classified admin"
     * from "forgotten" (both are admin at runtime).
     */
    public const ADMIN_ROUTES = [
        'activity.index', 'activity.downloads',
        'commands.index', 'commands.execute', 'commands.execute-async', 'commands.status', 'commands.form',
        'discover.cover',
        'health.index', 'health.job', 'health.retry', 'health.retry_all', 'health.forget', 'health.flush',
        'logs.index', 'logs.tail', 'logs.clear', 'logs.download', 'logs.destroy', 'logs.show',
        'novels.create', 'novels.discover', 'novels.discover.browse', 'novels.store', 'novels.edit', 'novels.update',
        'novels.destroy', 'novels.toggle_pause', 'novels.toggle_frequent', 'novels.toggle_complete',
        'novels.attention_snooze', 'novels.sync_tags', 'tags.store', 'novels.remove_duplicates',
        'novels.metadata_candidates', 'novels.metadata_choose', 'novels.snapshots', 'novels.reading_mode',
        'settings.index', 'settings.update', 'settings.test_email', 'settings.test_flaresolverr',
        'settings.test_notification', 'settings.tailscale_status', 'settings.tailscale_serve', 'settings.tailscale_funnel',
        'settings.users', 'settings.users.store', 'settings.users.update', 'settings.users.password',
        'settings.users.disable', 'settings.users.destroy', 'settings.users.auth',
        'api.v1.discover', 'api.v1.discover.browse', 'api.v1.discover.searches.store', 'api.v1.discover.searches.show',
        'api.v1.novels.store',
    ];

    /**
     * Session-less asset routes (no cookies) that serve private content:
     * authorised by a signed URL that names the reader (App\Support\SignedUrls),
     * so <img> tags and the iOS app load them without headers.
     */
    public const SIGNED_ROUTES = ['chapters.page', 'discover.cover'];

    /** Routes that also accept HTTP Basic (username + password): ereader OPDS clients. */
    public const BASIC_ROUTES = ['opds.index', 'novels.download_epub'];

    /** The key a route is listed under. */
    public static function key(?Route $route): ?string
    {
        if ($route === null) {
            return null;
        }

        return $route->getName() ?? 'uri:' . $route->uri();
    }

    /** public | reader | admin. Unlisted (and unmatched) routes are admin. */
    public static function classify(?Route $route): string
    {
        return static::classifyKey(static::key($route));
    }

    public static function classifyKey(?string $key): string
    {
        if ($key !== null && in_array($key, self::PUBLIC_ROUTES, true)) {
            return self::PUBLIC;
        }
        if ($key !== null && in_array($key, self::READER_ROUTES, true)) {
            return self::READER;
        }

        return self::ADMIN;
    }

    /** Whether the route is explicitly listed (any class): the route walk's check. */
    public static function isListed(string $key): bool
    {
        return in_array($key, self::PUBLIC_ROUTES, true)
            || in_array($key, self::READER_ROUTES, true)
            || in_array($key, self::ADMIN_ROUTES, true);
    }

    public static function acceptsSignature(?Route $route): bool
    {
        return $route !== null && in_array($route->getName(), self::SIGNED_ROUTES, true);
    }

    public static function acceptsBasic(?Route $route): bool
    {
        return $route !== null && in_array($route->getName(), self::BASIC_ROUTES, true);
    }
}
