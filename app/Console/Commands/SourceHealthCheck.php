<?php

namespace App\Console\Commands;

use App\Services\AdminAlert;
use App\Services\Requests\RequestNotifier;
use App\Services\SourceHealth;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hourly (routes/console.php): tell the admin when a source turns Failing
 * (App\Services\SourceHealth::judge() over the last 24 hours) — once, on
 * the transition: a source already Failing at the previous check is not
 * told again, and one told about within novarr.source_health.
 * alert_repeat_hours is not told again even if it recovered in between.
 * No "recovered" message. The previous check's Failing set and the alert
 * times are remembered in the cache (STATE_KEY).
 *
 * With --prune: only delete counters older than the retention (daily).
 */
class SourceHealthCheck extends Command
{
    protected $signature = 'sources:health-check
        {--prune : Only delete source health counters older than the retention (90 days)}';

    protected $description = 'Tell the admin when a source starts failing (or prune old source health counters)';

    /** {failing: string[], alerted: array<string, ISO-8601>} */
    public const STATE_KEY = 'source_health_alert_state';

    public function handle(SourceHealth $health, AdminAlert $alert): int
    {
        if ($this->option('prune')) {
            $removed = SourceHealth::prune();
            $this->info("Removed {$removed} source health row(s) older than " . SourceHealth::retentionDays() . ' days.');

            return self::SUCCESS;
        }

        $state = Cache::get(self::STATE_KEY);
        $state = is_array($state) ? $state : [];
        $previous = array_flip(array_map('strval', (array) ($state['failing'] ?? [])));
        $alerted = array_filter((array) ($state['alerted'] ?? []), 'is_string');
        $repeatHours = max(1, (int) config('novarr.source_health.alert_repeat_hours', 24));

        $failing = array_values(array_filter($health->overview(), fn($row) => $row['status'] === SourceHealth::FAILING));

        $told = 0;
        foreach ($failing as $row) {
            $key = $row['key'];
            if (isset($previous[$key])) {
                continue;
            }
            $last = isset($alerted[$key]) ? Carbon::parse($alerted[$key]) : null;
            if ($last !== null && $last->gt(now()->subHours($repeatHours))) {
                continue;
            }

            [$subject, $message] = self::message($row);
            $alert->send($subject, $message, self::pageUrl());
            $alerted[$key] = now()->toIso8601String();
            $told++;
            Log::warning("Source health: {$message}");
        }

        // Forget alert times past the repeat window.
        $alerted = array_filter($alerted, fn($at) => Carbon::parse($at)->gt(now()->subHours($repeatHours)));

        Cache::forever(self::STATE_KEY, [
            'failing' => array_column($failing, 'key'),
            'alerted' => $alerted,
        ]);

        $this->info(count($failing) . " source(s) failing; {$told} new alert(s) sent.");

        return self::SUCCESS;
    }

    /**
     * The alert for one overview() row: the source, its 24-hour numbers per
     * kind of call, and the top failure reason.
     *
     * @return array{0: string, 1: string} subject, message
     */
    public static function message(array $row): array
    {
        $parts = [];
        foreach (SourceHealth::KINDS as $kind) {
            $day = $row['kinds'][$kind]['day'];
            if ($day['attempts'] === 0) {
                continue;
            }
            $parts[] = strtolower(SourceHealth::KIND_LABELS[$kind]) . ": {$day['ok']} of {$day['attempts']} ok ("
                . (int) round(100 * $day['rate']) . '%)';
        }

        $reason = $row['top_reason'] !== null
            ? " Top reason: {$row['top_reason']['label']} ({$row['top_reason']['count']}× in 7 days)."
            : '';

        $name = $row['name'] !== $row['key'] ? "{$row['name']} ({$row['key']})" : $row['name'];

        return [
            "Source failing: {$row['name']}",
            "Source {$name} is failing. Last 24 h — " . implode('; ', $parts) . ".{$reason}",
        ];
    }

    /** Health → Sources on the home address (APP_URL), where admin pages work. */
    public static function pageUrl(): string
    {
        return RequestNotifier::adminBase() . route('health.sources', [], false);
    }
}
