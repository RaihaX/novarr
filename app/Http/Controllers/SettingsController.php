<?php

namespace App\Http\Controllers;

use App\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpClient\HttpClient;

class SettingsController extends Controller
{
    /**
     * Settings exposed in the UI: key => [label, help, default-from-config].
     */
    protected function fields(): array
    {
        return [
            'kindle_email' => [
                'label' => 'Kindle email',
                'help' => 'Your Send-to-Kindle address (e.g. yourname_xxx@kindle.com). The sender must be in your Amazon approved list.',
                'type' => 'email',
                'default' => config('mail.kindle_email'),
            ],
            'summary_email' => [
                'label' => 'Daily summary recipient',
                'help' => 'Where the daily new-chapters summary is emailed.',
                'type' => 'email',
                'default' => config('mail.summary_email'),
            ],
            'flaresolverr_url' => [
                'label' => 'FlareSolverr URL',
                'help' => 'Endpoint used to bypass Cloudflare when scraping (e.g. http://192.168.1.41:8191/v1).',
                'type' => 'url',
                'default' => env('FLARESOLVERR_URL', 'http://192.168.1.41:8191/v1'),
            ],
            'summary_time' => [
                'label' => 'Daily summary time',
                'help' => 'Time of day the summary email is sent (24h, app timezone).',
                'type' => 'time',
                'default' => '08:00',
            ],
        ];
    }

    public function index()
    {
        $fields = [];
        foreach ($this->fields() as $key => $meta) {
            $meta['value'] = Setting::get($key, $meta['default']);
            $fields[$key] = $meta;
        }

        return view('settings.index', ['fields' => $fields]);
    }

    public function update(Request $request)
    {
        $rules = [
            'kindle_email' => 'nullable|email',
            'summary_email' => 'nullable|email',
            'flaresolverr_url' => 'nullable|url',
            'summary_time' => 'nullable|date_format:H:i',
        ];

        $data = $request->validate($rules);

        foreach (array_keys($this->fields()) as $key) {
            Setting::put($key, $data[$key] ?? null);
        }

        return redirect()->route('settings.index')->with('status', 'Settings saved.');
    }

    /**
     * Send a test email to the configured summary recipient to verify mail
     * delivery (Resend / SMTP) end to end.
     */
    public function testEmail()
    {
        $to = Setting::get('summary_email', config('mail.summary_email'));

        if (empty($to)) {
            return response()->json(['success' => false, 'message' => 'No summary recipient configured.'], 422);
        }

        try {
            Mail::raw('This is a test email from Novarr — your mail settings are working.', function ($m) use ($to) {
                $m->to($to)->subject('Novarr test email');
            });

            return response()->json(['success' => true, 'message' => "Test email sent to {$to}."]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Send failed: ' . $e->getMessage()], 502);
        }
    }

    /**
     * Check that the configured FlareSolverr endpoint is reachable.
     */
    public function testFlareSolverr(Request $request)
    {
        $url = $request->input('flaresolverr_url')
            ?: Setting::get('flaresolverr_url', env('FLARESOLVERR_URL', 'http://192.168.1.41:8191/v1'));

        if (empty($url)) {
            return response()->json(['success' => false, 'message' => 'No FlareSolverr URL configured.'], 422);
        }

        try {
            $response = HttpClient::create(['timeout' => 10])
                ->request('POST', $url, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => ['cmd' => 'sessions.list'],
                ]);

            $ok = $response->getStatusCode() === 200
                && ($response->toArray(false)['status'] ?? null) === 'ok';

            return $ok
                ? response()->json(['success' => true, 'message' => "FlareSolverr is reachable at {$url}."])
                : response()->json(['success' => false, 'message' => "Reached {$url} but it did not respond as expected (HTTP {$response->getStatusCode()})."], 502);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()], 502);
        }
    }
}
