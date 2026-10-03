<?php

namespace App\Http\Controllers;

use App\Scraping\NovelUpdatesMatcher;
use App\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpClient\HttpClient;

class SettingsController extends Controller
{
    /**
     * Settings exposed in the UI: key => [label, help, type, group, default]
     * plus optional min/max/step for number inputs and `secret` for masking.
     */
    protected function fields(): array
    {
        return [
            'kindle_email' => [
                'label' => 'Kindle email',
                'help' => 'Your Send-to-Kindle address (e.g. yourname_xxx@kindle.com). The sender must be in your Amazon approved list.',
                'type' => 'email',
                'group' => 'Email & Kindle',
                'default' => config('mail.kindle_email'),
            ],
            'summary_email' => [
                'label' => 'Daily summary recipient',
                'help' => 'Where the daily new-chapters summary is emailed.',
                'type' => 'email',
                'group' => 'Email & Kindle',
                'default' => config('mail.summary_email'),
            ],
            'summary_time' => [
                'label' => 'Daily summary time',
                'help' => 'Time of day the summary email is sent (24h, app timezone).',
                'type' => 'time',
                'group' => 'Email & Kindle',
                'default' => '08:00',
            ],
            'auto_kindle' => [
                'label' => 'Auto-send completed novels to Kindle',
                'help' => 'When a novel is marked complete, email its ePub to your Kindle automatically.',
                'type' => 'checkbox',
                'group' => 'Email & Kindle',
                'default' => '1',
            ],
            'flaresolverr_url' => [
                'label' => 'FlareSolverr URL',
                'help' => 'Endpoint used to bypass Cloudflare when scraping (e.g. http://192.168.1.41:8191/v1).',
                'type' => 'url',
                'group' => 'Scraping',
                'default' => config('novarr.flaresolverr_url'),
            ],
            'scrape_min_delay' => [
                'label' => 'Min delay between chapters (s)',
                'help' => 'Lower bound of the polite random pause between chapter downloads.',
                'type' => 'number',
                'group' => 'Scraping',
                'min' => 0,
                'max' => 600,
                'default' => (string) config('novarr.defaults.scrape_min_delay', 30),
            ],
            'scrape_max_delay' => [
                'label' => 'Max delay between chapters (s)',
                'help' => 'Upper bound of the random pause. Higher = gentler on the source site. Must be at least the min delay.',
                'type' => 'number',
                'group' => 'Scraping',
                'min' => 0,
                'max' => 600,
                'default' => (string) config('novarr.defaults.scrape_max_delay', 90),
            ],
            'min_chapter_words' => [
                'label' => 'Minimum words per chapter',
                'help' => 'A downloaded chapter with fewer words is treated as a failed/short fetch and retried later (50–2000).',
                'type' => 'number',
                'group' => 'Scraping',
                'min' => 50,
                'max' => 2000,
                'default' => (string) config('novarr.defaults.min_chapter_words', 250),
            ],
            'max_chapters_per_novel_per_run' => [
                'label' => 'Max chapters per novel per run',
                'help' => 'Caps how many chapters one novel can download in a scheduled run so a big backlog cannot starve the others. 0 = no cap (0–500).',
                'type' => 'number',
                'group' => 'Scraping',
                'min' => 0,
                'max' => 500,
                'default' => (string) config('novarr.defaults.max_chapters_per_novel_per_run', 25),
            ],
            'max_run_minutes' => [
                'label' => 'Max run length (minutes)',
                'help' => 'A scheduled chapter run stops starting new downloads after this many minutes (10–140). Must stay below the 150-minute scheduler lock on novel:chapter: if a run outlives the lock, the next scheduled run can start on top of it.',
                'type' => 'number',
                'group' => 'Scraping',
                'min' => 10,
                'max' => 140,
                'default' => (string) config('novarr.defaults.max_run_minutes', 100),
            ],
            'novelupdates_match_threshold' => [
                'label' => 'NovelUpdates match threshold',
                'help' => 'Minimum similarity score (0.5–1.0) for an automatic NovelUpdates match to be trusted for metadata and completion checks. Higher = stricter; below it you confirm the match by hand.',
                'type' => 'number',
                'group' => 'Scraping',
                'min' => 0.5,
                'max' => 1.0,
                'step' => 0.01,
                'default' => (string) NovelUpdatesMatcher::THRESHOLD,
            ],
            'snapshots_enabled' => [
                'label' => 'Save failure snapshots',
                'help' => 'Keep a gzipped copy of pages that fetched but yielded no usable text (Novel page → Snapshots), to diagnose markup changes.',
                'type' => 'checkbox',
                'group' => 'Failure snapshots',
                'default' => config('novarr.snapshots.enabled', true) ? '1' : '0',
            ],
            'snapshots_keep_per_novel' => [
                'label' => 'Snapshots kept per novel',
                'help' => 'Newest snapshots kept for each novel; older ones are deleted (0–50).',
                'type' => 'number',
                'group' => 'Failure snapshots',
                'min' => 0,
                'max' => 50,
                'default' => (string) config('novarr.snapshots.keep_per_novel', 5),
            ],
            'snapshots_days' => [
                'label' => 'Snapshot retention (days)',
                'help' => 'Snapshots older than this are deleted (1–90).',
                'type' => 'number',
                'group' => 'Failure snapshots',
                'min' => 1,
                'max' => 90,
                'default' => (string) config('novarr.snapshots.days', 14),
            ],
            'notification_webhook_url' => [
                'label' => 'Notification webhook',
                'help' => 'Optional Discord webhook or ntfy topic URL — pinged when a novel completes or a source starts failing.',
                'type' => 'url',
                'group' => 'Notifications',
                // May embed a token — masked in the UI with a reveal toggle.
                'secret' => true,
                'default' => config('novarr.notification_webhook_url'),
            ],
        ];
    }

    public function index()
    {
        $fields = [];
        foreach ($this->fields() as $key => $meta) {
            $meta['key'] = $key;
            $meta['value'] = Setting::get($key, $meta['default']);
            // Effective value = what the app uses right now; `overridden`
            // says whether it comes from a saved setting or the default.
            $meta['overridden'] = Setting::get($key) !== null;
            $fields[$key] = $meta;
        }

        return view('settings.index', ['fields' => $fields]);
    }

    public function update(Request $request)
    {
        $fields = $this->fields();

        $validator = Validator::make($request->all(), [
            'kindle_email' => 'nullable|email',
            'summary_email' => 'nullable|email',
            'flaresolverr_url' => 'nullable|url',
            'summary_time' => 'nullable|date_format:H:i',
            'notification_webhook_url' => 'nullable|url',
            'scrape_min_delay' => 'nullable|integer|min:0|max:600',
            // gte only when both are filled: a blank min falls back to its
            // default, which the after() hook below checks instead.
            'scrape_max_delay' => array_merge(
                ['nullable', 'integer', 'min:0', 'max:600'],
                $request->filled('scrape_min_delay') ? ['gte:scrape_min_delay'] : []
            ),
            'min_chapter_words' => 'nullable|integer|min:50|max:2000',
            'max_chapters_per_novel_per_run' => 'nullable|integer|min:0|max:500',
            'max_run_minutes' => 'nullable|integer|min:10|max:140',
            'novelupdates_match_threshold' => 'nullable|numeric|min:0.5|max:1',
            'snapshots_keep_per_novel' => 'nullable|integer|min:0|max:50',
            'snapshots_days' => 'nullable|integer|min:1|max:90',
            'snapshots_enabled' => 'nullable',
            'auto_kindle' => 'nullable',
        ], [
            'scrape_max_delay.gte' => 'The max delay must be greater than or equal to the min delay.',
            'max_run_minutes.max' => 'The max run length must stay below the 150-minute scheduler lock (140 minutes at most).',
            'novelupdates_match_threshold.min' => 'The match threshold must be between 0.5 and 1.0.',
            'novelupdates_match_threshold.max' => 'The match threshold must be between 0.5 and 1.0.',
        ]);

        // Audit L1: with one delay blank, compare against the default it
        // falls back to (e.g. min 120 + blank max would mean 120 > 90).
        $validator->after(function ($v) use ($request, $fields) {
            if ($v->errors()->hasAny(['scrape_min_delay', 'scrape_max_delay'])
                || ($request->filled('scrape_min_delay') && $request->filled('scrape_max_delay'))) {
                return;
            }
            $min = $request->filled('scrape_min_delay') ? (int) $request->input('scrape_min_delay') : (int) $fields['scrape_min_delay']['default'];
            $max = $request->filled('scrape_max_delay') ? (int) $request->input('scrape_max_delay') : (int) $fields['scrape_max_delay']['default'];
            if ($max < $min) {
                $v->errors()->add('scrape_max_delay', "The max delay ({$max}s) must be greater than or equal to the min delay ({$min}s).");
            }
        });

        $data = $validator->validate();

        foreach ($fields as $key => $meta) {
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
            ?: Setting::get('notification_webhook_url', config('novarr.notification_webhook_url'));

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
            ?: Setting::get('flaresolverr_url', config('novarr.flaresolverr_url'));

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
