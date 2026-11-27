<?php

namespace App\Support;

use Illuminate\Routing\Route;

/**
 * Who may call each route when "Require sign-in" is on — the ONE list, read
 * by App\Http\Middleware\IdentifyReader (enforcement) and by
 * tests/Feature/RouteAccessTest (which walks every registered route and fails
 * on any that is not listed here).
 *
 *   public — no credentials (sign-in itself, accepting an invite, the
 *            daily summary's signed "Stop these emails" link, health
 *            checks, discovery, the KOReader plugin's self-update, the
 *            Android app download, the offline shell)
 *   reader — any signed-in account
 *   admin  — the owner only. DEFAULT: a route that is not listed below is
 *            admin-only (default-deny), so a new route is never accidentally
 *            open to readers.
 *
 * Routes are listed by name; a route without a name by "uri:<uri>".
 * A route listed as reader may still check per action in its controller
 * (Gate 'admin'): novels.bulk (read / unread = reader, delete/complete = admin).
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
        // The second step after the password, and "Sign in with a passkey"
        'login.second_step',
        'login.second_step.verify',
        'login.second_step.email',
        'login.second_step.passkey_options',
        'login.passkey.options',
        'login.passkey.verify',
        // Accepting an invite (the link is the credential; throttled)
        'invite.show',
        'invite.accept',
        // "Stop these emails" in a reader's daily summary (the signed link is the credential; throttled)
        'summary.unsubscribe',
        'summary.unsubscribe.stop',
        // The Android app download (before or right after accepting an invite)
        'app.android',
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
        'api.v1.auth.token.email_code',
        'api.v1.auth.token.second_step',
        'api.v1.auth.pairings.store',
        'api.v1.auth.pairings.show',
        // Old KOReader plugins must be able to update themselves
        'api.v1.koreader.plugin',
        'api.v1.koreader.plugin.file',
        // Laravel's local-disk file routes: they check their own temporary
        // signature (Illuminate\Filesystem\ServeFile / ReceiveFile).
        'storage.local',
        'storage.local.upload',
        // The books email address: the Cloudflare Email Worker, by its shared secret (App\Books\BookInbox)
        'api.v1.books.inbox',
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
        'account.browsers.sign_out_others',
        'account.browsers.sign_out',
        'account.summary_email',
        'account.send_to',
        // Own sign-in steps and catalogue passwords (each change needs a recent confirmation)
        'account.confirm',
        'account.confirm.email',
        'account.confirm.passkey_options',
        'account.passkeys.options',
        'account.passkeys.store',
        'account.passkeys.destroy',
        'account.authenticator.start',
        'account.authenticator.store',
        'account.authenticator.destroy',
        'account.recovery_codes',
        'account.email_codes',
        'account.catalogue.store',
        'account.catalogue.destroy',
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
        'api.v1.novels.send.show',
        'api.v1.novels.send',
        'api.v1.novels.audiobook_ask',
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
        // Own reading lists (App\Services\ReadingLists)
        'api.v1.lists',
        'api.v1.lists.store',
        'api.v1.lists.update',
        'api.v1.lists.destroy',
        'api.v1.lists.novels.add',
        'api.v1.lists.novels.remove',
        'api.v1.lists.order',
        'api.v1.lists.memberships',
        // Whole series read / unread (own reading state)
        'api.v1.novels.bulk_read',
        // Own "Want to read" (the built-in reading list)
        'want_to_read',
        'api.v1.want_to_read',
        'api.v1.novels.want_to_read.add',
        'api.v1.novels.want_to_read.remove',
        // Own "Favourites" (the second built-in reading list)
        'favourites',
        'api.v1.favourites',
        'api.v1.novels.favourite.add',
        'api.v1.novels.favourite.remove',
        // Reader requests: the page, search, request, cancel own, the notification feed
        'requests.index',
        'api.v1.requests.searches.store',
        'api.v1.requests.searches.show',
        'api.v1.requests.browse',
        'api.v1.requests',
        'api.v1.requests.show',
        'api.v1.requests.store',
        'api.v1.requests.destroy', // own pending only, per RequestController::destroy()
        'api.v1.requests.categories', // own or joined only, per RequestController::categories()
        'api.v1.notifications',
        'api.v1.updates', // new chapters to read (App\Services\Updates\NewChapterUpdates)
        // Covers in request results: only through a URL the server signed
        'requests.cover',
        // Uploading a book for the owner to approve (App\Books\BookUploads)
        'books.upload',
        'books.upload.store',
        'api.v1.books.uploads.store', // the Android app's "Share to Novarr"
        // Audiobooks: the shelf, the player, listening (own progress and bookmarks)
        'audiobooks.index',
        'audiobooks.show',
        'api.v1.audiobooks',
        'api.v1.audiobooks.continue',
        'api.v1.audiobooks.show',
        'api.v1.audiobooks.parts.show', // also by a signed URL (SIGNED_ROUTES)
        'api.v1.audiobooks.progress',
        'api.v1.audiobooks.bookmarks.store',
        'api.v1.audiobooks.bookmarks.destroy',
        // Asking for a book's audiobook (App\Downloads\WantedList; the owner is told)
        'novels.audiobook_ask',
        // Uploading an audiobook for the owner to approve (App\Audiobooks\AudiobookUploads):
        // own uploads only, per AudiobookUploadController (anyone else's is 404)
        'audiobooks.upload',
        'api.v1.audiobook_uploads.store',
        'api.v1.audiobook_uploads.show',
        'api.v1.audiobook_uploads.chunk',
        'api.v1.audiobook_uploads.complete',
        'api.v1.audiobook_uploads.destroy',
    ];

    /**
     * Admin-only routes, listed so the route walk can tell "classified admin"
     * from "forgotten" (both are admin at runtime).
     */
    public const ADMIN_ROUTES = [
        'activity.index', 'activity.downloads',
        'readers.index', 'readers.now', 'readers.history', 'readers.show',
        'commands.index', 'commands.execute', 'commands.execute-async', 'commands.status', 'commands.form',
        'discover.cover',
        'health.index', 'health.job', 'health.retry', 'health.retry_all', 'health.forget', 'health.flush',
        'health.sources',
        'health.storage',
        'logs.index', 'logs.tail', 'logs.clear', 'logs.download', 'logs.destroy', 'logs.show',
        'novels.create', 'novels.discover', 'novels.discover.browse', 'novels.store', 'novels.edit', 'novels.update',
        'novels.destroy', 'novels.toggle_pause', 'novels.toggle_frequent', 'novels.toggle_complete',
        'novels.attention_snooze', 'novels.sync_tags', 'tags.store', 'novels.remove_duplicates', 'novels.undo_conversion',
        'novels.metadata_candidates', 'novels.metadata_choose', 'novels.snapshots', 'novels.reading_mode',
        'novels.covers', 'novels.covers.choose', 'novels.covers.generate', 'novels.covers.generate_status',
        'settings.index', 'settings.update', 'settings.test_email', 'settings.test_flaresolverr',
        'settings.test_notification', 'settings.tailscale_status', 'settings.tailscale_serve', 'settings.tailscale_funnel',
        'settings.users', 'settings.users.store', 'settings.users.update', 'settings.users.password',
        'settings.users.disable', 'settings.users.destroy', 'settings.users.auth', 'settings.users.second_step.reset',
        'settings.users.invites.store', 'settings.users.invites.resend', 'settings.users.invites.destroy',
        'settings.users.history',
        'mail_preview.index', 'mail_preview.show',
        'api.v1.discover', 'api.v1.discover.browse', 'api.v1.discover.searches.store', 'api.v1.discover.searches.show',
        'api.v1.novels.store',
        'api.v1.requests.approve', 'api.v1.requests.decline', 'api.v1.requests.rerank',
        'books.review', 'books.review.search', 'books.review.approve', 'books.review.decline', 'books.review.upload', 'books.review.use_existing', 'downloads.seed_forever',
        'books.calibre', 'books.calibre.import', 'books.calibre.cover',
        // Audiobooks: the inbox, matching, "Scan now"
        'audiobooks.manage', 'audiobooks.manage.scan', 'audiobooks.manage.retry', 'audiobooks.manage.match', 'audiobooks.manage.lookup',
        'api.v1.audiobooks.inbox', 'api.v1.audiobooks.match', 'api.v1.audiobooks.scan',
        // Reviewing readers' audiobook uploads (the sample streams the pending file)
        'audiobooks.uploads.review', 'audiobooks.uploads.approve', 'audiobooks.uploads.use_existing',
        'audiobooks.uploads.decline', 'audiobooks.uploads.sample',
        // Searching Usenet & torrents, grabbing, the Downloads page (App\Downloads)
        'downloads.index', 'downloads.search', 'downloads.grab', 'downloads.poll', 'downloads.settings',
        'downloads.grabs.show', 'downloads.grabs.fail', 'downloads.grabs.import',
        'downloads.wanted.search', 'downloads.wanted.unmonitor', 'downloads.wanted.monitor',
        'downloads.blocklist.destroy', 'novels.audiobook_monitor',
    ];

    /**
     * Session-less asset routes (no cookies) that serve private content:
     * authorised by a signed URL that names the reader (App\Support\SignedUrls),
     * so <img> tags and the iOS app load them without headers. An
     * audiobook part also takes a bearer token or the session (it is a
     * reader route); its signed URL expires after a day.
     */
    public const SIGNED_ROUTES = ['chapters.page', 'discover.cover', 'requests.cover', 'api.v1.audiobooks.parts.show'];

    /** Routes that also accept HTTP Basic (username + password): ereader OPDS clients. */
    public const BASIC_ROUTES = ['opds.index', 'novels.download_epub'];

    /**
     * Public routes that, on the public address, answer only while sign-in
     * is on (403 "only available once sign-in is turned on" otherwise):
     * they make tokens or accounts that would work once it is.
     */
    public const SIGN_IN_ON_ROUTES = [
        'api.v1.auth.token.store',
        'api.v1.auth.token.email_code',
        'api.v1.auth.token.second_step',
        'invite.accept',
    ];

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

    public static function needsSignInOn(?Route $route): bool
    {
        return $route !== null && in_array($route->getName(), self::SIGN_IN_ON_ROUTES, true);
    }

    /** Bearer tokens are read on the API and the Basic routes (the ePub download) only. */
    public static function acceptsBearer(\Illuminate\Http\Request $request, ?Route $route): bool
    {
        return $request->is('api/*') || static::acceptsBasic($route);
    }

    public static function acceptsBasic(?Route $route): bool
    {
        return $route !== null && in_array($route->getName(), self::BASIC_ROUTES, true);
    }
}
