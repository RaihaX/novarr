<?php

namespace App\Console\Commands;

use App\Http\Controllers\HomeController;
use App\Mail\NewChapters;
use App\Novel;
use App\NovelChapter;
use App\Services\NovelHealth;
use App\Setting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailSummary extends Command
{
    /** Settings that make the summary stateful between runs. */
    public const LAST_SENT_KEY = 'summary_last_sent_at';
    public const ATTENTION_HASH_KEY = 'summary_attention_hash';

    /** Novel rows rendered in full (links, progress); the rest are counted. */
    public const NOVEL_LIMIT = 25;

    protected $signature = "novel:email-summary
        {--hours= : Look-back window in hours (default: since the last summary, else 24)}
        {--to= : Override the recipient address}";

    protected $description = "Email a summary of newly downloaded chapters, newly completed novels and novels needing attention.";

    public function handle()
    {
        $to = $this->option("to") ?: setting("summary_email", config("mail.summary_email"));

        if (empty($to)) {
            $this->error("No recipient configured. Set MAIL_SUMMARY_EMAIL or pass --to.");
            return 1;
        }

        $now = Carbon::now();
        $since = $this->windowStart($now);

        $chapters = $this->chapterRows($since);
        $novels = $this->novelRows($since);
        $completed = $this->completedNovels($since);

        // Same cache key/TTL as the home page, so the email and the dashboard
        // agree (and the scheduler's 5-minute warm-up is reused).
        $attention = array_values(Cache::remember('dashboard_attention', 900, fn() => app(NovelHealth::class)->needingAttention()));
        $attentionHash = $this->attentionHash($attention);

        $hasNews = !empty($novels) || !empty($completed);

        if (!$hasNews) {
            if (empty($attention)) {
                // Clear the hash so the same problem re-appearing later is news again.
                Setting::put(self::ATTENTION_HASH_KEY, '');
                $this->info("Nothing new since {$since->toDateTimeString()} — no email sent.");
                return 0;
            }

            if ($attentionHash === (string) setting(self::ATTENTION_HASH_KEY, '')) {
                $this->info("Only unchanged attention items since {$since->toDateTimeString()} — no email sent.");
                return 0;
            }
        }

        $stats = [
            'new_chapters' => array_sum(array_column($novels, 'count')),
            'novels_updated' => count($novels),
            'completed' => count($completed),
            'queued' => NovelChapter::where('status', 0)->where('blacklist', 0)->count(),
            'attention' => count($attention),
        ];

        try {
            Mail::to($to)->send(new NewChapters([
                "since" => $since,
                "chapters" => $chapters,
                "completed" => $completed,
                "attention" => $attention,
                "continue" => $this->continueBlock(),
                "stats" => $stats,
                "novels" => $novels,
                "summary_time" => setting("summary_time", "08:00"),
            ]));
        } catch (\Throwable $e) {
            Log::error("Failed to send chapter summary email to {$to}: " . $e->getMessage());
            $this->error("Failed to send summary: " . $e->getMessage());
            return 1;
        }

        Setting::put(self::LAST_SENT_KEY, $now->toIso8601String());
        Setting::put(self::ATTENTION_HASH_KEY, $attentionHash);

        $summary = "{$stats['new_chapters']} chapter(s) in {$stats['novels_updated']} novel(s), "
            . "{$stats['completed']} completed, {$stats['attention']} needing attention";
        Log::info("Sent chapter summary email to {$to}: {$summary}.");
        $this->info("Summary sent to {$to}: {$summary}.");
        return 0;
    }

    /**
     * An explicit --hours wins; otherwise pick up where the last email left
     * off (so a missed day isn't lost), falling back to 24 hours.
     */
    private function windowStart(Carbon $now): Carbon
    {
        $hours = $this->option("hours");
        if ($hours !== null && $hours !== '') {
            return $now->copy()->subHours(max(1, (int) $hours));
        }

        $last = setting(self::LAST_SENT_KEY);
        if ($last) {
            try {
                $parsed = Carbon::parse($last);
                if ($parsed->lt($now)) {
                    return $parsed;
                }
            } catch (\Throwable $e) {
                Log::warning("Ignoring unparseable " . self::LAST_SENT_KEY . " setting: {$last}");
            }
        }

        return $now->copy()->subHours(24);
    }

    /**
     * Legacy flat chapter rows (kept so the payload shape stays backward
     * compatible). Light columns only — description is a longtext.
     */
    private function chapterRows(Carbon $since): array
    {
        return NovelChapter::with("novel:id,name,no_of_chapters")
            ->where("status", 1)
            ->where("blacklist", 0)
            ->where("download_date", ">=", $since)
            ->orderBy("novel_id")
            ->ordered()
            ->get(["id", "novel_id", "label", "chapter", "book", "download_date"])
            ->filter(fn($chapter) => $chapter->novel !== null)
            ->map(function ($chapter) {
                $novel = $chapter->novel;
                $progress = $novel->no_of_chapters == 0
                    ? 0
                    : round(($chapter->chapter / $novel->no_of_chapters) * 100, 2);

                return [
                    "novel" => $novel->name,
                    "label" => $chapter->label,
                    "chapter" => $chapter->chapter,
                    "book" => $chapter->book,
                    "progress" => number_format($progress, 2, ".", ","),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One row per novel with chapters downloaded in the window, most new
     * chapters first. The first NOVEL_LIMIT rows are enriched with reading
     * progress (read ÷ total, the home page's definition) and the first
     * unread downloaded chapter; the rest only feed the counts.
     */
    private function novelRows(Carbon $since): array
    {
        $groups = NovelChapter::where('status', 1)
            ->where('blacklist', 0)
            ->where('download_date', '>=', $since)
            ->selectRaw('novel_id, COUNT(*) as new_count,
                SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) as notes,
                MIN(chapter) as first_chapter, MAX(chapter) as last_chapter', [NovelChapter::KIND_NOTE])
            ->groupBy('novel_id')
            ->get();

        if ($groups->isEmpty()) {
            return [];
        }

        $novels = Novel::whereIn('id', $groups->pluck('novel_id')->all())
            ->get(['id', 'name', 'translator_url', 'last_toc_count', 'origin', 'origin_language'])
            ->keyBy('id');

        $rows = [];
        foreach ($groups as $g) {
            $novel = $novels->get($g->novel_id);
            if (!$novel) {
                continue;
            }
            $host = parse_url((string) $novel->translator_url, PHP_URL_HOST);
            $rows[] = [
                'id' => $novel->id,
                'name' => $novel->name,
                'url' => route('novels.show', $novel->id),
                'source' => $host ? preg_replace('/^www\./', '', $host) : null,
                // "Translated (KO)" / "Original" / null — appended to the subline.
                'origin' => $novel->originShortLabel(),
                'count' => (int) $g->new_count,
                'notes' => (int) $g->notes,
                'first' => (float) $g->first_chapter,
                'last' => (float) $g->last_chapter,
                'percent' => null,
                'read_from' => null,
                'read_from_url' => null,
                '_toc' => (int) $novel->last_toc_count,
            ];
        }

        usort($rows, fn($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        // Enrich the rendered rows: one grouped count query + one lookup each.
        $shown = array_slice(array_column($rows, 'id'), 0, self::NOVEL_LIMIT);
        $counts = NovelChapter::whereIn('novel_id', $shown)
            ->where('blacklist', 0)
            ->selectRaw('novel_id, COUNT(*) as total, SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) as read_count')
            ->groupBy('novel_id')
            ->get()
            ->keyBy('novel_id');

        foreach ($rows as $i => &$row) {
            if ($i < self::NOVEL_LIMIT) {
                $c = $counts->get($row['id']);
                $total = max($row['_toc'], (int) ($c->total ?? 0));
                $row['percent'] = $total > 0 ? (int) min(100, round((int) ($c->read_count ?? 0) / $total * 100)) : 0;

                $readFrom = NovelChapter::where('novel_id', $row['id'])
                    ->where('status', 1)->where('blacklist', 0)
                    ->whereNull('read_at')
                    ->ordered()
                    ->first(['id', 'chapter']);
                if ($readFrom) {
                    $row['read_from'] = (float) $readFrom->chapter;
                    $row['read_from_url'] = route('chapters.show', $readFrom->id);
                }
            }
            unset($row['_toc']);
        }
        unset($row);

        return $rows;
    }

    private function completedNovels(Carbon $since): array
    {
        $kindle = setting("auto_kindle", "1") === "1";

        return Novel::where("status", 1)
            ->where("completed_at", ">=", $since)
            ->orderBy("name")
            ->get(["id", "name", "completed_at"])
            ->map(fn($novel) => [
                "id" => $novel->id,
                "name" => $novel->name,
                "completed_at" => $novel->completed_at,
                "url" => route('novels.show', $novel->id),
                "epub_url" => route('novels.download_epub', $novel->id),
                "kindle" => $kindle,
            ])
            ->all();
    }

    /**
     * The current book (HomeController::continueReading(1)) with its position
     * — "Chapter N of M", ranked like the home hero — or null.
     */
    private function continueBlock(): ?array
    {
        try {
            $item = app(HomeController::class)->continueReading(1)[0] ?? null;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }

        if (!$item) {
            return null;
        }

        $novel = $item['novel'];
        $chapter = NovelChapter::find($item['next']->id);
        if (!$chapter) {
            return null;
        }

        $rows = NovelChapter::where('novel_id', $novel->id)->where('blacklist', 0)->count();
        $index = NovelChapter::where('novel_id', $novel->id)
            ->where('blacklist', 0)
            ->relativeTo($chapter, '<=')
            ->count();

        return [
            'name' => $novel->name,
            'author' => $novel->author,
            'novel_url' => route('novels.show', $novel->id),
            'chapter_label' => $chapter->label,
            'index' => $index,
            'total' => max((int) $novel->last_toc_count, $rows),
            'chapter_percent' => $item['resume'] ? (int) max(0, min(100, (int) $chapter->read_progress)) : 0,
            'resume' => (bool) $item['resume'],
            'url' => route('chapters.show', $chapter->id),
        ];
    }

    /** Order-independent fingerprint of the attention set ("id:reason" lines). */
    private function attentionHash(array $attention): string
    {
        if ($attention === []) {
            return '';
        }

        $lines = array_map(fn($a) => ($a['id'] ?? '') . ':' . ($a['reason'] ?? ''), $attention);
        sort($lines, SORT_STRING);

        return sha1(implode("\n", $lines));
    }
}
