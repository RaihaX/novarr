<?php

namespace App\Console\Commands;

use App\Services\AdminAlert;
use App\Services\Requests\RequestNotifier;
use App\Services\StorageUsage;
use App\Support\Bytes;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Measure where the disk goes (App\Services\StorageUsage) and replace the
 * stored figures Health → Storage shows. Daily (routes/console.php) and from
 * "Measure now" (queued through the Commands runner).
 *
 * When a volume's free space is under novarr.storage.low_free_percent and it
 * was not at the previous measurement, the admin is told once (AdminAlert),
 * and not again within ALERT_REPEAT_HOURS even if it recovered in between.
 */
class MeasureStorage extends Command
{
    protected $signature = 'storage:measure
        {--exact : Sum every chapter\'s text length instead of estimating it from stored word counts (slow)}';

    protected $description = 'Measure storage used by novels, manga, books, audiobooks, download inboxes, backups, logs and the database (Health → Storage)';

    /** When the last low-space alert went out (ISO-8601), in the cache. */
    public const ALERT_KEY = 'storage_usage_low_alerted_at';

    public const ALERT_REPEAT_HOURS = 24;

    /** Two measurements never run at once (the schedule and "Measure now"). */
    public const LOCK = 'storage:measure';

    public function handle(StorageUsage $usage, AdminAlert $alert): int
    {
        $lock = Cache::lock(self::LOCK, 3 * 3600);
        if (!$lock->get()) {
            $this->warn('A storage measurement is already running; nothing done.');

            return self::SUCCESS;
        }

        try {
            $wasLow = $this->previouslyLow();

            $result = $usage->measure((bool) $this->option('exact'), fn(string $step) => $this->line("Measuring: {$step}"));
            $usage->store($result);

            $disks = array_map(
                fn(array $row) => $row['meta'] + ['key' => $row['key'], 'used' => $row['bytes']],
                array_values(array_filter($result['rows'], fn($r) => $r['scope'] === StorageUsage::SCOPE_DISK)),
            );
            $low = StorageUsage::lowDisks($disks);

            $this->summary($result, $disks);

            if ($low !== [] && !$wasLow) {
                $this->sendLowSpaceAlert($alert, $low);
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /** Whether any volume was under the threshold at the stored measurement. */
    private function previouslyLow(): bool
    {
        return DB::table('storage_usage')->where('scope', StorageUsage::SCOPE_DISK)->pluck('meta')
            ->contains(fn($meta) => !empty((json_decode((string) $meta, true) ?: [])['low']));
    }

    private function sendLowSpaceAlert(AdminAlert $alert, array $low): void
    {
        $last = Cache::get(self::ALERT_KEY);
        if (is_string($last) && Carbon::parse($last)->gt(now()->subHours(self::ALERT_REPEAT_HOURS))) {
            $this->line('Free space is low; the admin was already told in the last ' . self::ALERT_REPEAT_HOURS . ' hours.');

            return;
        }

        [$subject, $message] = self::message($low);
        $alert->send($subject, $message, self::pageUrl(), self::mailDetails($low));
        Cache::put(self::ALERT_KEY, now()->toIso8601String(), now()->addHours(self::ALERT_REPEAT_HOURS * 2));
        Log::warning("Storage: {$message}");
        $this->warn("Alert sent: {$message}");
    }

    /** @return array{0: string, 1: string} subject, message */
    public static function message(array $low): array
    {
        $parts = array_map(fn(array $d) => sprintf(
            '%s has %s free of %s (%s%%)',
            self::diskName($d),
            Bytes::human($d['free'] ?? 0),
            Bytes::human($d['total'] ?? 0),
            rtrim(rtrim(number_format((float) ($d['free_percent'] ?? 0), 1), '0'), '.'),
        ), $low);

        return [
            'Disk space low',
            'Disk space is low: ' . implode('; ', $parts) . '. Threshold: '
                . rtrim(rtrim(number_format(StorageUsage::lowFreePercent(), 1), '0'), '.') . '% free.',
        ];
    }

    /** The email's structure: one list row per low disk, the threshold under it. */
    public static function mailDetails(array $low): array
    {
        $pct = fn($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');

        return [
            'eyebrow' => 'Storage',
            'heading' => 'Disk space is low',
            'tone' => 'warning',
            'intro' => 'Novarr is running out of room: downloads, covers and new chapters may start to fail. Free some space or move the library to a bigger disk.'
                . (array_filter($low, fn(array $d) => !empty($d['share'])) !== []
                    ? ' The ' . StorageUsage::SHARE_LABEL . ' is where downloads land before they are imported: when it is full, downloads fail.'
                    : ''),
            'items' => array_map(fn(array $d) => [
                'title' => self::diskName($d),
                'meta' => Bytes::human($d['free'] ?? 0) . ' free of ' . Bytes::human($d['total'] ?? 0),
                'status' => $pct($d['free_percent'] ?? 0) . '% free',
                'tone' => 'warning',
            ], $low),
            'items_label' => 'Low disks',
            'facts' => ['Alert below' => $pct(StorageUsage::lowFreePercent()) . '% free'],
            'button' => 'Open Storage',
        ];
    }

    /** A disk's name: its title ("Big drive: manga, audiobooks, …"), else its labels. */
    public static function diskName(array $d): string
    {
        return (string) ($d['title'] ?? implode(' + ', (array) ($d['labels'] ?? ['A disk'])));
    }

    /** Health → Storage on the home address (APP_URL), where admin pages work. */
    public static function pageUrl(): string
    {
        return RequestNotifier::adminBase() . route('health.storage', [], false);
    }

    private function summary(array $result, array $disks): void
    {
        $series = array_filter($result['rows'], fn($r) => $r['scope'] === StorageUsage::SCOPE_SERIES);
        $audiobooks = array_filter($result['rows'], fn($r) => $r['scope'] === StorageUsage::SCOPE_AUDIOBOOK);
        $buckets = array_filter($result['rows'], fn($r) => $r['scope'] === StorageUsage::SCOPE_BUCKET);

        $this->info(sprintf('Measured %d series and %d audiobooks in %.1f s%s.', count($series), count($audiobooks), $result['duration_ms'] / 1000,
            $result['exact'] ? ' (exact text sizes)' : ''));
        foreach ($buckets as $row) {
            $label = StorageUsage::BUCKETS[$row['key']] ?? ($row['key'] === StorageUsage::NOVEL_TEXT ? 'Novel text (in the database)' : $row['key']);
            $note = !empty($row['meta']['external']) ? '  (' . StorageUsage::SHARE_LABEL . ', not counted)' : '';
            if (!empty($row['meta']['partial'])) {
                $note .= '  PARTIAL (time limit)';
            }
            $this->line(sprintf('  %-28s %12s  %s files%s', $label, Bytes::human($row['bytes']), number_format($row['files']), $note));
        }
        foreach ($disks as $d) {
            if (!empty($d['missing'])) {
                $this->line(sprintf('  Disk %-23s not mounted (%s)', self::diskName($d), implode(', ', (array) ($d['paths'] ?? []))));
                continue;
            }
            $this->line(sprintf('  Disk %-23s %12s free of %s%s', self::diskName($d),
                Bytes::human($d['free'] ?? 0), Bytes::human($d['total'] ?? 0), !empty($d['low']) ? '  LOW' : ''));
        }
    }
}
