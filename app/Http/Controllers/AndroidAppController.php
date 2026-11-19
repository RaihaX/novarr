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
}
