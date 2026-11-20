<?php

namespace App\Services;

use App\Http\Controllers\UserInviteController;
use App\Mail\AdminAlertMail;
use App\Services\Requests\RequestNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * A message for the owner through the channels they already set up for
 * admin news: the notification webhook (Settings → notification webhook,
 * notify_webhook()) and an email to the owner's address (their account's
 * email, else the daily summary's — RequestNotifier::ownerEmail(), the
 * address a new reader request is mailed to). Email only when mail is set
 * up (not the "log" mailer), as for the other mails. Best effort: a channel
 * that fails is logged and never throws.
 */
class AdminAlert
{
    /** @return array{webhook: bool, email: bool} which channels took the message */
    public function send(string $subject, string $message, ?string $url = null): array
    {
        $text = $message . ($url !== null ? " {$url}" : '');

        return [
            'webhook' => $this->webhook("Novarr: {$text}"),
            'email' => $this->email($subject, $message, $url),
        ];
    }

    protected function webhook(string $message): bool
    {
        try {
            return notify_webhook($message);
        } catch (\Throwable $e) {
            Log::warning('Admin alert webhook failed: ' . $e->getMessage());

            return false;
        }
    }

    protected function email(string $subject, string $message, ?string $url): bool
    {
        if (UserInviteController::mailIsOff()) {
            return false;
        }
        $to = RequestNotifier::ownerEmail();
        if ($to === null) {
            return false;
        }

        try {
            Mail::to($to)->send(new AdminAlertMail($subject, $message, $url));

            return true;
        } catch (\Throwable $e) {
            Log::warning("Admin alert email to {$to} failed: " . $e->getMessage());

            return false;
        }
    }
}
