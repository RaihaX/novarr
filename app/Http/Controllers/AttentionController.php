<?php

namespace App\Http\Controllers;

use App\Novel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AttentionController extends Controller
{
    /** Default snooze length offered by the dashboard button. */
    public const DEFAULT_DAYS = 7;

    /**
     * Snooze a novel out of the Needs Attention panel for `days` (1-90,
     * default 7). Sets attention_ignored_until only — downloads carry on,
     * the novel is never paused.
     */
    public function snooze(Request $request, $id)
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:0', 'max:90'],
        ]);

        $novel = Novel::findOrFail($id);
        $days = (int) ($validated['days'] ?? self::DEFAULT_DAYS);
        // days=0 wakes the novel up again.
        $until = $days === 0 ? null : now()->addDays($days);

        $novel->attention_ignored_until = $until;
        $novel->save();

        // The dashboard serves the attention list from cache; drop it so the
        // next render (and the daily summary) reflects the snooze.
        Cache::forget('dashboard_attention');

        $message = $until
            ? "{$novel->name} snoozed until {$until->format('j M Y')}."
            : "{$novel->name} is back in Needs attention.";

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'id' => $novel->id,
                'until' => $until?->toIso8601String(),
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('status', $message);
    }
}
