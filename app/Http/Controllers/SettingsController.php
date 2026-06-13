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
            'notification_webhook_url' => [
                'label' => 'Notification webhook',
                'help' => 'Optional Discord webhook or ntfy topic URL — pinged when a novel completes or a source starts failing.',
                'type' => 'url',
                'default' => env('NOTIFICATION_WEBHOOK_URL'),
            ],
            'scrape_min_delay' => [
                'label' => 'Min delay between chapters (s)',
                'help' => 'Lower bound of the polite random pause between chapter downloads.',
                'type' => 'number',
                'default' => '30',
            ],
            'scrape_max_delay' => [
                'label' => 'Max delay between chapters (s)',
                'help' => 'Upper bound of the random pause. Higher = gentler on the source site.',
                'type' => 'number',
                'default' => '90',
            ],
            'auto_kindle' => [
                'label' => 'Auto-send completed novels to Kindle',
                'help' => 'When a novel is marked complete, email its ePub to your Kindle automatically.',
                'type' => 'checkbox',
                'default' => '1',
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
        $data = $request->validate([
            'kindle_email' => 'nullable|email',
            'summary_email' => 'nullable|email',
            'flaresolverr_url' => 'nullable|url',
            'summary_time' => 'nullable|date_format:H:i',
            'notification_webhook_url' => 'nullable|url',
            'scrape_min_delay' => 'nullable|integer|min:0|max:600',
            'scrape_max_delay' => 'nullable|integer|min:0|max:600',
            'auto_kindle' => 'nullable',
        ]);

        foreach ($this->fields() as $key => $meta) {
            if (($meta['type'] ?? null) === 'checkbox') {
                // Unchecked boxes aren't posted — store explicit 0/1.
                Setting::put($key, $request->boolean($key) ? '1' : '0');
            } else {
                Setting::put($key, $data[$key] ?? null);
            }
        }

        return redirect()->route('settings.index')->with('status', 'Settings saved.');
    }

    /**
     * Send a test message to the configured notification webhook.
     */
    public function testNotification(Request $request)
    {
        $url = $request->input('notification_webhook_url')
            ?: Setting::get('notification_webhook_url', env('NOTIFICATION_WEBHOOK_URL'));

        if (empty($url)) {
            return response()->json(['success' => false, 'message' => 'No webhook URL configured.'], 422);
        }

        // notify_webhook reads the saved setting; temporarily honour the
        // posted URL so you can test before saving.
        Setting::put('notification_webhook_url', $url);
        $ok = notify_webhook('🔔 Test notification from Novarr — your webhook is working.');

        return $ok
            ? response()->json(['success' => true, 'message' => 'Test notification sent.'])
            : response()->json(['success' => false, 'message' => 'Webhook post failed — check the URL.'], 502);
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
