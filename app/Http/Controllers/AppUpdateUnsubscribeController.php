<?php

namespace App\Http\Controllers;

use App\User;
use Illuminate\Http\Request;

/**
 * /app-updates/stop/{id} — "Stop app update emails" in the new Android
 * version email (App\Console\Commands\AnnounceAndroidRelease::unsubscribeUrl()).
 * Public (App\Support\RouteAccess): the signed link is the credential,
 * signed over the path only (made in the console under APP_URL, opened on
 * the public address), no expiry. Like the daily summary's link
 * (SummaryUnsubscribeController): GET only shows the confirmation (mail
 * scanners open links); the button POSTs to the same signed URL and sets
 * users.app_update_emails_off_at. Switched back on in Account.
 */
class AppUpdateUnsubscribeController extends Controller
{
    public function show(Request $request, $id)
    {
        $user = $this->userFor($request, $id);

        return $this->page(['user' => $user, 'done' => false, 'action' => $request->getRequestUri()]);
    }

    public function stop(Request $request, $id)
    {
        $user = $this->userFor($request, $id);
        if ($user->app_update_emails_off_at === null) {
            $user->forceFill(['app_update_emails_off_at' => now()])->save();
        }

        return $this->page(['user' => $user, 'done' => true, 'action' => null]);
    }

    /** The account the link names; 403 for a bad signature, 404 for an account that is gone. */
    private function userFor(Request $request, $id): User
    {
        abort_unless($request->hasValidSignature(false), 403, 'This link is not valid.');

        return User::findOrFail((int) $id);
    }

    /** Never cached (the page carries a CSRF token and the account's name). */
    private function page(array $data)
    {
        return response()->view('auth.app-updates-unsubscribe', $data)->header('Cache-Control', 'no-store, private');
    }
}
