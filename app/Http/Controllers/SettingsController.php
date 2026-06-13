<?php

namespace App\Http\Controllers;

use App\Setting;
use Illuminate\Http\Request;

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
}
