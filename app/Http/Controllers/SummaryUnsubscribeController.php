<?php

namespace App\Http\Controllers;

use App\User;
use Illuminate\Http\Request;

/**
 * /summary/stop/{id} — "Stop these emails" in a reader's daily summary
 * (App\Console\Commands\EmailSummary::unsubscribeUrl()). Public
 * (App\Support\RouteAccess): the signed link is the credential. The
 * signature covers the path only (made in the console under APP_URL, opened
 * on the public address) and never expires.
 *
 * GET only shows the confirmation (mail scanners open links); the button
 * POSTs to the same signed URL and turns the summary off
 * (users.summary_email_off_at). It is switched back on in Account.
 */
class SummaryUnsubscribeController extends Controller
{
    public function show(Request $request, $id)
    {
        $user = $this->userFor($request, $id);

        return $this->page('auth.summary-unsubscribe', [
            'user' => $user,
            'done' => false,
            // The same signed path and query, on whatever host this is.
            'action' => $request->getRequestUri(),
        ]);
    }

    public function stop(Request $request, $id)
    {
        $user = $this->userFor($request, $id);
        if ($user->summary_email_off_at === null) {
            $user->forceFill(['summary_email_off_at' => now()])->save();
        }

        return $this->page('auth.summary-unsubscribe', ['user' => $user, 'done' => true, 'action' => null]);
    }

    /** The account the link names; 403 for a bad signature, 404 for an account that is gone. */
    private function userFor(Request $request, $id): User
    {
        abort_unless($request->hasValidSignature(false), 403, 'This link is not valid.');

        return User::findOrFail((int) $id);
    }

    /** Never cached (the page carries a CSRF token and the account's name). */
    private function page(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'no-store, private');
    }
}
