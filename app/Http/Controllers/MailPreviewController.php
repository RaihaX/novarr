<?php

namespace App\Http\Controllers;

use App\Mail\MailPreviews;
use Illuminate\Http\Response;

/**
 * GET /mail-preview, /mail-preview/{name}[?part=text]: every email Novarr
 * sends, rendered with sample data (App\Mail\MailPreviews) so the owner can
 * look them over. Admin only (App\Support\RouteAccess: unlisted = admin,
 * listed there on purpose), so never on the public address. Nothing is
 * sent and nothing is stored.
 */
class MailPreviewController extends Controller
{
    public function index()
    {
        $mails = [];
        foreach (MailPreviews::all() as $name => $entry) {
            try {
                $subject = (string) (($entry['make'])()->build()->subject ?? '');
            } catch (\Throwable $e) {
                $subject = 'Could not be built: ' . $e->getMessage();
            }
            $mails[$entry['group']][] = ['name' => $name, 'label' => $entry['label'], 'subject' => $subject];
        }

        return response()->view('mail-preview.index', ['groups' => $mails])
            ->header('Cache-Control', 'no-store');
    }

    public function show(string $name)
    {
        $mail = MailPreviews::make($name);
        abort_if($mail === null, 404);

        if (request()->query('part') === 'text') {
            return new Response(MailPreviews::text($mail), 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        return new Response($mail->render(), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
