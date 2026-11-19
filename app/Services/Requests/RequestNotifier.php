<?php

namespace App\Services\Requests;

use App\Http\Controllers\RequestCoverController;
use App\Http\Controllers\UserInviteController;
use App\Mail\RequestAvailable;
use App\Mail\RequestCreated;
use App\Mail\RequestDeclined;
use App\NovelRequest;
use App\RequestNotification;
use App\Support\CurrentReader;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Who hears about a request, and how. Every channel is best effort: a
 * notification that fails is logged and never fails the request.
 *
 *   new       → the owner: webhook "<reader> requested <title> (manga)",
 *               an email (the owner's address, else the summary address),
 *               and a request_new feed item for the admins.
 *   approved  → the requester: a request_approved feed item (no email;
 *               "available" follows).
 *   declined  → the requester: a request_declined feed item and an email.
 *   available → the requester: a request_available feed item and an email.
 *
 * Emails need mail set up as the other mails do (not the "log" mailer)
 * and a recipient address. A request the admin made (always, with sign-in
 * off) tells nobody that it was made; decisions on one's own request
 * are not announced to oneself.
 */
class RequestNotifier
{
    public function created(NovelRequest $request): void
    {
        $requester = $request->requester;
        if ($requester === null || $requester->isAdmin()) {
            return;
        }
        $name = static::nameOf($requester);
        $what = "{$request->title} ({$request->mediumLabel()})";

        $this->feed(null, RequestNotification::KIND_NEW, 'New request', "{$name} requested {$what}.", $request);
        $this->webhook("{$name} requested {$what}");

        $to = static::ownerEmail();
        if ($to !== null) {
            $ownerId = $this->ownerId();
            $this->mail($to, new RequestCreated(
                $name,
                (string) $request->title,
                $request->mediumLabel(),
                RequestCoverController::url($request->cover_url, $ownerId, static::adminBase()),
                $request->note ?: null,
                static::requestsPageUrl(),
            ));
        }
    }

    public function approved(NovelRequest $request): void
    {
        if ($this->decidedOwn($request)) {
            return;
        }
        $this->feed((int) $request->user_id, RequestNotification::KIND_APPROVED, 'Request approved',
            "{$request->title} was approved and is being added to the library.", $request);
    }

    public function declined(NovelRequest $request): void
    {
        if ($this->decidedOwn($request)) {
            return;
        }
        $reason = $request->decline_reason ?: null;
        $this->feed((int) $request->user_id, RequestNotification::KIND_DECLINED, 'Request declined',
            "{$request->title} was declined" . ($reason !== null ? ": {$reason}" : '.'), $request);

        $requester = $request->requester;
        if ($requester !== null && static::hasEmail($requester)) {
            $this->mail((string) $requester->email, new RequestDeclined(
                static::nameOf($requester),
                (string) $request->title,
                $request->mediumLabel(),
                RequestCoverController::url($request->cover_url, (int) $requester->id, static::readerBase()),
                $reason,
                static::readerBase() . '/requests',
            ));
        }
    }

    public function available(NovelRequest $request): void
    {
        $this->feed((int) $request->user_id, RequestNotification::KIND_AVAILABLE, 'Ready to read',
            "{$request->title} is in the library and ready to read.", $request);

        $requester = $request->requester;
        if ($requester !== null && static::hasEmail($requester)) {
            $this->mail((string) $requester->email, new RequestAvailable(
                static::nameOf($requester),
                (string) $request->title,
                $request->mediumLabel(),
                RequestCoverController::url($request->cover_url, (int) $requester->id, static::readerBase()),
                static::readerBase() . ($request->novel_id ? '/novels/' . (int) $request->novel_id : '/requests'),
            ));
        }
        $request->forceFill(['requester_notified_at' => now()])->save();
    }

    /** The web Requests page (config novarr.requests.page_url, else APP_URL/requests). */
    public static function requestsPageUrl(): string
    {
        $url = trim((string) config('novarr.requests.page_url', ''));

        return $url !== '' ? $url : static::adminBase() . '/requests';
    }

    /** Where the owner reads and decides: the home address (APP_URL). */
    public static function adminBase(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** Where readers read: the public address when there is one, else APP_URL. */
    public static function readerBase(): string
    {
        return PublicAddress::url() ?? static::adminBase();
    }

    /** The owner's address: their account's email, else the daily summary's. */
    public static function ownerEmail(): ?string
    {
        try {
            $owner = User::find(CurrentReader::ownerId());
        } catch (\Throwable) {
            $owner = null;
        }
        if ($owner !== null && static::hasEmail($owner)) {
            return (string) $owner->email;
        }
        $summary = trim((string) setting('summary_email', config('mail.summary_email')));

        return filter_var($summary, FILTER_VALIDATE_EMAIL) ? $summary : null;
    }

    public static function nameOf(?User $user): string
    {
        if ($user === null) {
            return 'Someone';
        }

        return trim((string) ($user->name ?: $user->username)) ?: 'Someone';
    }

    private static function hasEmail(User $user): bool
    {
        return filter_var(trim((string) $user->email), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function decidedOwn(NovelRequest $request): bool
    {
        return $request->decided_by !== null && (int) $request->decided_by === (int) $request->user_id;
    }

    private function ownerId(): ?int
    {
        try {
            return CurrentReader::ownerId();
        } catch (\Throwable) {
            return null;
        }
    }

    /** A feed item (App\Services\Requests\RequestFeed); null recipient = the admins. */
    protected function feed(?int $userId, string $kind, string $title, string $body, NovelRequest $request): void
    {
        try {
            RequestNotification::create([
                'user_id' => $userId,
                'novel_request_id' => $request->id,
                'kind' => $kind,
                'title' => mb_substr($title, 0, 255),
                'body' => mb_substr($body, 0, 1000),
                'novel_id' => $request->novel_id,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Request {$request->id}: feed item {$kind} not stored: " . $e->getMessage());
        }
    }

    /** The notification webhook (notify_webhook()); overridable for tests. */
    protected function webhook(string $message): void
    {
        try {
            notify_webhook($message);
        } catch (\Throwable $e) {
            Log::warning('Request webhook failed: ' . $e->getMessage());
        }
    }

    protected function mail(string $to, Mailable $mail): void
    {
        if (UserInviteController::mailIsOff()) {
            return;
        }
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            Log::warning("Request email to {$to} failed: " . $e->getMessage());
        }
    }
}
