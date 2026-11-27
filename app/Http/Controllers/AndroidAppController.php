<?php

namespace App\Http\Controllers;

use App\Support\AndroidApp;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * GET /app/android — the Android app's APK (App\Support\AndroidApp). Public
 * (people download it before or right after accepting an invite), throttled
 * per address. The service worker leaves /app/ alone (public/sw.js), so the
 * file is never stored as a page.
 */
class AndroidAppController extends Controller
{
    public function download(Request $request)
    {
        if (!AndroidApp::available()) {
            return response()->view('apps.android-missing', [], 404)
                ->header('Cache-Control', 'no-store, private');
        }

        $response = new BinaryFileResponse(AndroidApp::path(), 200, [
            'Content-Type' => AndroidApp::MIME,
            // Revalidated every time (ETag / Last-Modified): a new APK is picked up at once.
            'Cache-Control' => 'no-cache, private',
            'X-Content-Type-Options' => 'nosniff',
        ], false, ResponseHeaderBag::DISPOSITION_ATTACHMENT, true, true);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, AndroidApp::FILENAME);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * GET /app/android/whats-new — the app's page: "What's new in 1.17"
     * (the sidecar's release notes) and the download button. Public like
     * the download; the new version email links here (a download link in
     * a mail opened on a computer would just save an APK).
     */
    public function page(Request $request)
    {
        if (!AndroidApp::available()) {
            return response()->view('apps.android-missing', [], 404)
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->view('apps.android', [
            'version' => AndroidApp::versionName(),
            'notes' => AndroidApp::notes(),
            'downloadUrl' => AndroidApp::url($request),
            'linkText' => AndroidApp::linkText(),
        ])->header('Cache-Control', 'no-cache, private');
    }
}
