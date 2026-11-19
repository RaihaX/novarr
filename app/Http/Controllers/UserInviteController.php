<?php

namespace App\Http\Controllers;

use App\Mail\ReaderInvite;
use App\Support\CurrentReader;
use App\User;
use App\UserInvite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Settings → Users → Invite a reader (admin): make, resend and cancel
 * invites (App\UserInvite). The person accepts at /invite/{token}
 * (App\Http\Controllers\Auth\InviteController).
 *
 * The link is built on the origin the admin is browsing (UserInvite::link)
 * and shown once, right after it is made or resent (only its hash is
 * stored), with a Copy button — so it can be sent another way when the
 * email cannot be sent. A failed email never loses the invite.
 */
class UserInviteController extends Controller
{
    /** Session flash: the result panel shown above the forms. */
    public const FLASH = 'invite_result';

    /** Session flash: the pending invite a refused duplicate refers to (offers Resend). */
    public const FLASH_DUPLICATE = 'invite_duplicate_id';

    public function store(Request $request, CurrentReader $reader)
    {
        $data = $request->validateWithBag('invite', [
            'name' => 'required|string|max:80',
            'email' => 'required|string|email|max:255',
        ], [
            'name.required' => 'Enter their name.',
            'email.required' => 'Enter their email address.',
            'email.email' => 'That does not look like an email address.',
        ]);
        $email = UserInvite::normaliseEmail($data['email']);

        if (User::findByEmail($email) !== null) {
            return back()->withInput()->withErrors(['email' => 'That email already belongs to an account.'], 'invite');
        }

        $existing = UserInvite::unaccepted()->forEmail($email)->orderByDesc('id')->first();
        if ($existing !== null && !$existing->isExpired()) {
            return back()->withInput()
                ->withErrors(['email' => "{$existing->name} already has an invite waiting at that address. Resend it to make a new link."], 'invite')
                ->with(self::FLASH_DUPLICATE, $existing->id);
        }
        // An expired invite for the same address is replaced by the new one.
        UserInvite::unaccepted()->forEmail($email)->delete();

        [$invite, $token] = UserInvite::issue($data['name'], $email, $reader->user());

        return $this->sent($request, $invite, $token, 'Invite made for');
    }

    /** A new link (new token, new expiry); the old link stops working. */
    public function resend(Request $request, $id)
    {
        $invite = UserInvite::unaccepted()->findOrFail($id);
        $token = $invite->renew();

        return $this->sent($request, $invite, $token, 'New invite made for');
    }

    public function destroy($id)
    {
        $invite = UserInvite::unaccepted()->findOrFail($id);
        $invite->delete();

        return redirect()->route('settings.users')->withFragment('invites')
            ->with('status', "Cancelled the invite for {$invite->name}. Its link no longer works.");
    }

    /** Email the link and show it (with Copy) whether or not the email went. */
    private function sent(Request $request, UserInvite $invite, string $token, string $verb)
    {
        $link = UserInvite::link($request, $token);
        $error = $this->mail($invite, $link, $request);

        return redirect()->route('settings.users')->withFragment('invite')->with(self::FLASH, [
            'name' => $invite->name,
            'email' => $invite->email,
            'link' => $link,
            'sent' => $error === null,
            'error' => $error,
            'verb' => $verb,
        ]);
    }

    /** Send the invite; null when sent, else why not (a sentence for the page). */
    private function mail(UserInvite $invite, string $link, Request $request): ?string
    {
        if (static::mailIsOff()) {
            return 'Email is not set up on this server (MAIL_MAILER=' . config('mail.default') . ').';
        }

        try {
            Mail::to($invite->email)->send(new ReaderInvite(
                $invite->name,
                $link,
                $invite->expires_at,
                (string) ($invite->inviter?->name ?: 'The owner of this Novarr'),
                \App\Support\AndroidApp::available() ? \App\Support\AndroidApp::url($request) : null,
            ));

            return null;
        } catch (\Throwable $e) {
            Log::warning("Invite email to {$invite->email} failed: " . $e->getMessage());

            return 'The email could not be sent (' . \Illuminate\Support\Str::limit($e->getMessage(), 140) . ').';
        }
    }

    /**
     * Mail goes nowhere a person reads it: the "log" mailer (the framework
     * default when nothing is configured) writes it to the log file.
     */
    public static function mailIsOff(): bool
    {
        return in_array(config('mail.default'), ['log', null, ''], true);
    }
}
