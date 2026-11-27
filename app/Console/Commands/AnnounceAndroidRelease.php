<?php

namespace App\Console\Commands;

use App\ApiToken;
use App\AuthEvent;
use App\Http\Controllers\UserInviteController;
use App\Mail\AndroidReleaseMail;
use App\Mail\NewChapters;
use App\Services\SecondStep;
use App\Setting;
use App\Support\AndroidApp;
use App\Support\AuthMode;
use App\Support\CurrentReader;
use App\Support\DeviceDescription;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * "Novarr 1.17 is out": emails the published Android version, with its
 * release notes, once per build. Run by `make release-android` over ssh
 * right after the APK and its sidecar are copied (App\Support\AndroidApp).
 *
 * Recipients (recipients()), each once:
 *   - the owner, unless disabled or turned off;
 *   - when sign-in is required, every other account that uses the Android
 *     app: not disabled, with a live (not revoked) app token, and seen on
 *     Android — a chapter read, reading position or audiobook position
 *     stamped "Novarr app on Android" (App\Support\ReadingClient), or an
 *     app sign-in (auth_events token_issued) whose User-Agent says so;
 * and only with a usable email (SecondStep::emailAddress(); for the owner
 * the summary address as a fallback) and app update emails on
 * (users.app_update_emails_off_at null; Account, or the email's link).
 *
 * The build sent is kept in the setting android_announced_build: a build at
 * or below it is not sent again (--force sends anyway). Sends one by one
 * with a short pause; a failure is logged and the next recipient carries
 * on. The build is recorded unless every send failed (a rerun then tries
 * them all again).
 */
class AnnounceAndroidRelease extends Command
{
    public const ANNOUNCED_KEY = 'android_announced_build';

    /** Pause between two emails (ms), so a mail server's rate limit is not hit. */
    public const PAUSE_MS = 400;

    protected $signature = 'app:announce-android
        {--dry-run : List who would get the email; send and record nothing}
        {--force : Send even when this build was already announced}';

    protected $description = 'Email the published Android app version and its release notes to the owner and the readers who use the app (once per build)';

    public function handle(): int
    {
        if (!AndroidApp::available()) {
            $this->error('No Android app is published (no APK at ' . AndroidApp::path() . '): nothing to announce.');
            return 1;
        }
        $version = AndroidApp::versionName();
        $build = AndroidApp::build();
        if ($version === null || $build === null) {
            $this->error('The APK has no version and build in ' . AndroidApp::path() . '.json: nothing to announce.');
            return 1;
        }

        $announced = (int) Setting::get(self::ANNOUNCED_KEY, 0);
        if ($build <= $announced && !$this->option('force')) {
            $this->info("Novarr {$version} (build {$build}) was already announced (last announced build: {$announced}). Pass --force to send it again.");
            return 0;
        }
        if (UserInviteController::mailIsOff()) {
            $this->warn('Mail is not set up (MAIL_MAILER is log): no announcement sent.');
            return 0;
        }

        $notes = AndroidApp::notes();
        $recipients = static::recipients();
        $dry = (bool) $this->option('dry-run');

        $this->info(($dry ? 'Dry run: would announce' : 'Announcing') . " Novarr {$version} (build {$build}) to {$recipients->count()} " . ($recipients->count() === 1 ? 'person' : 'people') . ($notes === [] ? ' (no release notes).' : '.'));
        if ($dry) {
            foreach ($recipients as $r) {
                $this->line("  {$r['user']->username} <{$r['email']}>" . ($r['owner'] ? ' (owner)' : ''));
            }
            return 0;
        }

        $sent = 0;
        $failed = 0;
        $downloadUrl = AndroidApp::pageUrl();
        $homeUrl = NewChapters::readerUrl('home');
        foreach ($recipients->values() as $i => $r) {
            if ($i > 0) {
                usleep(max(0, (int) config('novarr.announce_pause_ms', self::PAUSE_MS)) * 1000);
            }
            /** @var User $user */
            $user = $r['user'];
            try {
                Mail::to($r['email'])->send(new AndroidReleaseMail(
                    $version,
                    $notes,
                    $downloadUrl,
                    (string) $user->name,
                    static::unsubscribeUrl($user),
                    $homeUrl,
                ));
                $sent++;
                $this->line("  sent to {$user->username} <{$r['email']}>");
            } catch (\Throwable $e) {
                $failed++;
                Log::error("Failed to send the Android {$version} announcement to {$r['email']} ({$user->username}): " . $e->getMessage());
                $this->error("  failed for {$user->username} <{$r['email']}>: " . $e->getMessage());
            }
        }

        $recorded = !($sent === 0 && $failed > 0);
        if ($recorded) {
            Setting::put(self::ANNOUNCED_KEY, (string) max($build, $announced));
        }

        $summary = "Android {$version} (build {$build}) announcement: {$sent} sent, {$failed} failed" . ($recorded ? '.' : '; not recorded, run it again to retry.');
        Log::info($summary);
        $failed > 0 ? $this->warn($summary) : $this->info($summary);

        return $recorded ? 0 : 1;
    }

    /**
     * Who gets the email, owner first (see the class comment for the rule).
     *
     * @return Collection<int, array{user: User, email: string, owner: bool}>
     */
    public static function recipients(): Collection
    {
        $ownerId = CurrentReader::ownerId();
        $out = collect();

        $owner = User::find($ownerId);
        if ($owner !== null && $owner->disabled_at === null && $owner->app_update_emails_off_at === null) {
            $email = SecondStep::emailAddress($owner) ?? static::ownerFallbackEmail();
            if ($email !== null) {
                $out->push(['user' => $owner, 'email' => $email, 'owner' => true]);
            }
        }

        if (!AuthMode::required()) {
            return $out;
        }

        $androidIds = static::androidUserIds();
        if ($androidIds === []) {
            return $out;
        }

        $readers = User::whereKeyNot($ownerId)
            ->whereIn('id', $androidIds)
            ->whereNull('disabled_at')
            ->whereNull('app_update_emails_off_at')
            ->whereExists(fn($q) => $q->select(DB::raw(1))->from('api_tokens')
                ->whereColumn('api_tokens.user_id', 'users.id')
                ->where('api_tokens.kind', ApiToken::KIND_APP)
                ->whereNull('api_tokens.revoked_at'))
            ->orderBy('id')
            ->get();

        $seen = $out->pluck('email')->all();
        foreach ($readers as $user) {
            $email = SecondStep::emailAddress($user);
            if ($email === null || in_array($email, $seen, true)) {
                continue;
            }
            $seen[] = $email;
            $out->push(['user' => $user, 'email' => $email, 'owner' => false]);
        }

        return $out;
    }

    /**
     * Accounts seen using the Android app: reads, reading and listening
     * positions stamped with its client name, and app sign-ins whose
     * User-Agent DeviceDescription reads as the Android app.
     *
     * @return list<int>
     */
    public static function androidUserIds(): array
    {
        $client = 'Novarr app on Android';
        $ids = [];
        foreach (['chapter_reads', 'reading_positions', 'audiobook_progress'] as $table) {
            try {
                $ids = array_merge($ids, DB::table($table)->where('client', $client)->distinct()->pluck('user_id')->all());
            } catch (\Throwable $e) {
                // A table or column from a later migration that has not run: skip it.
                Log::warning("app:announce-android could not read {$table}: " . $e->getMessage());
            }
        }

        AuthEvent::query()
            ->where('event', AuthEvent::TOKEN_ISSUED)
            ->whereNotNull('user_id')
            ->where(fn($q) => $q->where('user_agent', 'like', '%okhttp%')->orWhere('user_agent', 'like', '%Android%'))
            ->select(['user_id', 'user_agent'])
            ->orderBy('id')
            ->each(function (AuthEvent $event) use (&$ids, $client) {
                if (DeviceDescription::from($event->user_agent) === $client) {
                    $ids[] = $event->user_id;
                }
            });

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * "Stop app update emails": signed over the path only (made here under
     * APP_URL, opened on the public address), no expiry.
     */
    public static function unsubscribeUrl(User $user): string
    {
        $relative = URL::signedRoute('app_updates.unsubscribe', ['id' => $user->id], null, false);

        return (PublicAddress::url() ?? rtrim(url('/'), '/')) . $relative;
    }

    /** The owner's daily summary address when their account has no email. */
    private static function ownerFallbackEmail(): ?string
    {
        $email = strtolower(trim((string) setting('summary_email', config('mail.summary_email'))));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
