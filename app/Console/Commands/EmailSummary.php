<?php

namespace App\Console\Commands;

use App\Http\Controllers\HomeController;
use App\Mail\NewChapters;
use App\Novel;
use App\NovelChapter;
use App\Services\DownloadLanes;
use App\Services\ReadState;
use App\Services\ResumePoint;
use App\Services\NovelHealth;
use App\Services\SecondStep;
use App\Setting;
use App\Support\AuthMode;
use App\Support\CurrentReader;
use App\Support\PublicAddress;
use App\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class EmailSummary extends Command
{
    /** Settings that make the summary stateful between runs. */
    public const LAST_SENT_KEY = 'summary_last_sent_at';
    public const ATTENTION_HASH_KEY = 'summary_attention_hash';
    /** Sorted, comma-joined lanes the webhook was last told are stalled. */
    public const STALLED_NOTIFIED_KEY = 'stalled_lanes_notified';

    /** Novel rows rendered in full (links, progress); the rest are counted. */
    public const NOVEL_LIMIT = 25;

    /** App setting: readers get their own summary ('1', the default) or not. */
    public const READERS_SETTING = 'summary_readers';

    /** A reader's window never reaches further back than this. */
    public const READER_MAX_DAYS = 7;

    /** Outcomes of one reader's summary. */
    private const SENT = 'sent';
    private const NOTHING = 'nothing';
    private const FAILED = 'failed';

    protected $signature = "novel:email-summary
        {--hours= : Look-back window in hours (default: since the last summary, else 24)}
        {--to= : Override the recipient address (owner only; with --reader, a preview to this address)}
        {--reader= : Only this reader's summary (sign-in name); no owner email}
        {--stalled-only : Only tell the webhook about sources that stopped downloading; send no email}";

    protected $description = "Email the owner a summary of newly downloaded chapters, newly completed novels and novels needing attention, and each reader one of the downloads and completions.";

    public function handle()
    {
        // Stalled sources go to the webhook whether or not an email follows.
        $stalled = $this->stalledLanes();
        if ($stalled !== null) {
            $this->notifyStalled($stalled);
        }
        // The hourly check (routes/console.php): the webhook part alone.
        if ($this->option('stalled-only')) {
            $this->info($stalled === null
                ? 'Stalled sources could not be worked out.'
                : count($stalled) . ' source(s) not downloading.');
            return 0;
        }
        $stalled ??= [];

        // One reader only (an admin's explicit run): no owner email.
        $only = $this->option('reader');
        if ($only !== null && $only !== '') {
            return $this->oneReader((string) $only);
        }

        $code = $this->ownerSummary($stalled);

        // --to alone: the owner's summary to another address, nothing else.
        if (!$this->option('to')) {
            // Whatever the owner's part did (sent, nothing new, failed), and
            // never changing its exit code.
            $this->readerSummaries();
        }

        return $code;
    }

    /**
     * The owner's summary (console output, logging, settings and exit code
     * exactly as before reader summaries existed).
     */
    private function ownerSummary(array $stalled): int
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
        $attentionHash = $this->attentionHash($attention, $stalled);

        $hasNews = !empty($novels) || !empty($completed);

        if (!$hasNews) {
            if (empty($attention) && empty($stalled)) {
                // Clear the hash so the same problem re-appearing later is news again.
                Setting::put(self::ATTENTION_HASH_KEY, '');
                $this->info("Nothing new since {$since->toDateTimeString()} — no email sent.");
                return 0;
            }

            if ($attentionHash === (string) setting(self::ATTENTION_HASH_KEY, '')) {
                $this->info("Only unchanged attention items / stalled sources since {$since->toDateTimeString()} — no email sent.");
                return 0;
            }
        }

        $stats = $this->stats($novels, $completed) + [
            'queued' => NovelChapter::where('status', 0)->where('blacklist', 0)->count(),
            'attention' => count($attention),
            'stalled' => count($stalled),
        ];

        try {
            Mail::to($to)->send(new NewChapters([
                "since" => $since,
                "chapters" => $chapters,
                "completed" => $completed,
                "attention" => $attention,
                "stalled" => $stalled,
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

        $summary = "{$stats['new_chapters']} chapter(s) in {$stats['novels_updated']} novel(s)"
            . ($stats['manga_updated'] > 0 ? " and {$stats['manga_updated']} manga" : '') . ", "
            . "{$stats['completed']} completed, {$stats['attention']} needing attention, "
            . "{$stats['stalled']} source(s) not downloading";
        Log::info("Sent chapter summary email to {$to}: {$summary}.");
        $this->info("Summary sent to {$to}: {$summary}.");
        return 0;
    }

    /** The chapter / series / completed figures every summary shows. */
    private function stats(array $novels, array $completed): array
    {
        $manga = array_values(array_filter($novels, fn($row) => $row['medium'] === Novel::MEDIUM_MANGA));

        return [
            // New chapters counts both media; the per-medium figures split it.
            'new_chapters' => array_sum(array_column($novels, 'count')),
            'novels_updated' => count($novels) - count($manga),
            'manga_updated' => count($manga),
            'manga_chapters' => array_sum(array_column($manga, 'count')),
            'completed' => count($completed),
        ];
    }

    // ---------------------------------------------------------------- readers

    /**
     * Every reader who gets one (readerRecipients()), each on their own
     * window; a failure is logged and the next reader carries on.
     */
    private function readerSummaries(): void
    {
        if (!AuthMode::required()) {
            $this->info('Reader summaries: none (sign-in is not required).');
            return;
        }
        if (setting(self::READERS_SETTING, '1') !== '1') {
            $this->info('Reader summaries: switched off in Settings.');
            return;
        }

        $counts = [self::SENT => 0, self::NOTHING => 0, self::FAILED => 0];
        try {
            $readers = $this->readerRecipients();
        } catch (\Throwable $e) {
            Log::error('Could not list the readers for their summary emails: ' . $e->getMessage());
            $this->error('Reader summaries: could not list the readers: ' . $e->getMessage());
            return;
        }
        foreach ($readers as $user) {
            $counts[$this->readerSummary($user, (string) SecondStep::emailAddress($user), false)]++;
        }

        $this->info("Reader summaries: {$counts[self::SENT]} sent, {$counts[self::NOTHING]} nothing new, {$counts[self::FAILED]} failed.");
    }

    /**
     * Readers who get a summary: not the owner, not disabled, invite
     * accepted (a password set), a usable email, not turned off.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function readerRecipients()
    {
        return User::whereKeyNot(CurrentReader::ownerId())
            ->whereNull('disabled_at')
            ->whereNotNull('password_set_at')
            ->whereNull('summary_email_off_at')
            ->orderBy('id')
            ->get()
            ->filter(fn(User $user) => SecondStep::emailAddress($user) !== null)
            ->values();
    }

    /**
     * --reader=<username>: that reader only, whatever the opt-out and the
     * Settings switch. With --to a preview (sent even with nothing new,
     * nothing recorded); without, the normal rule to their own address.
     */
    private function oneReader(string $username): int
    {
        $user = User::where('username', User::normaliseUsername($username))->first();
        if ($user === null) {
            $this->error("No account with the sign-in name {$username}.");
            return 1;
        }
        if ((int) $user->id === CurrentReader::ownerId()) {
            $this->error("{$user->username} is the owner: run the command without --reader for the owner's summary.");
            return 1;
        }

        $preview = (string) $this->option('to') !== '';
        $to = $preview ? (string) $this->option('to') : SecondStep::emailAddress($user);
        if ($to === null || $to === '') {
            $this->error("{$user->username} has no email address the summary can go to. Pass --to to preview it.");
            return 1;
        }

        $outcome = $this->readerSummary($user, $to, $preview);
        $counts = [self::SENT => 0, self::NOTHING => 0, self::FAILED => 0];
        $counts[$outcome]++;
        $this->info("Reader summaries: {$counts[self::SENT]} sent, {$counts[self::NOTHING]} nothing new, {$counts[self::FAILED]} failed.");

        return $outcome === self::FAILED ? 1 : 0;
    }

    /**
     * One reader's summary: everything downloaded or completed in the library,
     * with their progress and their continue block. Sent when there is
     * news (or always, for a preview). summary_sent_at moves on unless the
     * mail failed (or it is a preview).
     *
     * @return string self::SENT | self::NOTHING | self::FAILED
     */
    private function readerSummary(User $user, string $to, bool $preview): string
    {
        $now = Carbon::now();
        $userId = (int) $user->id;

        try {
            $since = $this->readerWindowStart($user, $now);
            $novels = $this->novelRows($since, $userId, true);
            $completed = $this->completedNovels($since, true);
            $hasNews = $novels !== [] || $completed !== [];

            if (!$hasNews && !$preview) {
                $this->recordReaderSent($userId, $now);
                $this->line("Nothing new for {$user->username} since {$since->toDateTimeString()} — no email sent.");
                return self::NOTHING;
            }

            $stats = $this->stats($novels, $completed) + ['queued' => null, 'attention' => 0, 'stalled' => 0];

            Mail::to($to)->send(new NewChapters([
                'since' => $since,
                'chapters' => $this->chapterRows($since),
                'completed' => $completed,
                'attention' => [],
                'stalled' => [],
                'continue' => $this->continueBlock($userId, true),
                'stats' => $stats,
                'novels' => $novels,
                'summary_time' => setting('summary_time', '08:00'),
                'reader' => [
                    'name' => (string) $user->name,
                    'home_url' => NewChapters::readerUrl('home'),
                    'account_url' => NewChapters::readerUrl('account'),
                    'unsubscribe_url' => self::unsubscribeUrl($user),
                ],
            ]));
        } catch (\Throwable $e) {
            Log::error("Failed to send reader summary email to {$to} ({$user->username}): " . $e->getMessage());
            $this->error("Failed to send the summary of {$user->username}: " . $e->getMessage());
            return self::FAILED;
        }

        if (!$preview) {
            $this->recordReaderSent($userId, $now);
        }

        $summary = "{$stats['new_chapters']} chapter(s) in {$stats['novels_updated']} novel(s)"
            . ($stats['manga_updated'] > 0 ? " and {$stats['manga_updated']} manga" : '') . ", "
            . "{$stats['completed']} completed";
        Log::info("Sent reader summary email to {$to} ({$user->username}): {$summary}.");
        $this->line("Summary of {$user->username} sent to {$to}: {$summary}.");

        return self::SENT;
    }

    private function recordReaderSent(int $userId, Carbon $now): void
    {
        User::whereKey($userId)->update(['summary_sent_at' => $now]);
    }

    /**
     * The "Stop these emails" link: signed over the path only (made here
     * under APP_URL, opened on the public address), no expiry.
     */
    public static function unsubscribeUrl(User $user): string
    {
        $relative = URL::signedRoute('summary.unsubscribe', ['id' => $user->id], null, false);

        return (PublicAddress::url() ?? rtrim(url('/'), '/')) . $relative;
    }

    /**
     * A reader's window: an explicit --hours wins; else from their
     * summary_sent_at (never more than READER_MAX_DAYS back); else 24 hours.
     */
    private function readerWindowStart(User $user, Carbon $now): Carbon
    {
        $hours = $this->option('hours');
        if ($hours !== null && $hours !== '') {
            return $now->copy()->subHours(max(1, (int) $hours));
        }

        $last = $user->summary_sent_at;
        if ($last !== null && $last->lt($now)) {
            $floor = $now->copy()->subDays(self::READER_MAX_DAYS);

            return Carbon::instance($last->lt($floor) ? $floor : $last);
        }

        return $now->copy()->subHours(24);
    }

    /**
     * Stalled download lanes (DownloadLanes::stalled()); null when they
     * cannot be worked out (the webhook's remembered set is then kept).
     */
    private function stalledLanes(): ?array
    {
        try {
            return app(DownloadLanes::class)->stalled();
        } catch (\Throwable $e) {
            Log::warning('Could not work out stalled download sources: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Tell the webhook once per new set of stalled lanes (remembered in
     * STALLED_NOTIFIED_KEY, cleared when nothing is stalled, so the same
     * lane stalling again later is news). Never fails the command.
     */
    private function notifyStalled(array $stalled): void
    {
        try {
            $lanes = array_column($stalled, 'lane');
            sort($lanes, SORT_STRING);
            $key = implode(',', $lanes);

            if ($key === (string) setting(self::STALLED_NOTIFIED_KEY, '')) {
                return;
            }

            if ($stalled !== []) {
                $plural = fn(int $n, string $one, string $many) => number_format($n) . ' ' . ($n === 1 ? $one : $many);
                $message = implode("\n", array_map(
                    fn($row) => "Novarr: {$row['label']} has " . $plural($row['queued'], 'chapter', 'chapters')
                        . ' waiting and has downloaded nothing for ' . $plural($row['hours'], 'hour', 'hours') . '.',
                    $stalled
                ));
                $this->sendWebhook($message);
            }

            Setting::put(self::STALLED_NOTIFIED_KEY, $key);
        } catch (\Throwable $e) {
            Log::warning('Stalled-source webhook failed: ' . $e->getMessage());
        }
    }

    /** The webhook call, apart so tests can observe it. */
    protected function sendWebhook(string $message): void
    {
        notify_webhook($message);
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
     * chapters first, tagged with its medium (the email lists novels and
     * manga in separate sections). The first NOVEL_LIMIT rows of each medium
     * are enriched with reading progress (read ÷ total, the home page's
     * definition) and the first unread downloaded chapter; the rest only
     * feed the counts.
     *
     * $userId: whose progress (null = the current reader, the owner in a
     * console run); $forReader:
     * links on the public address (NewChapters::readerUrl()).
     */
    private function novelRows(Carbon $since, ?int $userId = null, bool $forReader = false): array
    {
        $link = $this->linker($forReader);
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

        $novels = Novel::with('group:id,url')
            ->whereIn('id', $groups->pluck('novel_id')->all())
            ->get(['id', 'name', 'translator_url', 'last_toc_count', 'origin', 'origin_language', 'medium', 'source_ref', 'group_id'])
            ->keyBy('id');
        $lanes = app(DownloadLanes::class);

        $rows = [];
        foreach ($groups as $g) {
            $novel = $novels->get($g->novel_id);
            if (!$novel) {
                continue;
            }
            $host = parse_url((string) $novel->translator_url, PHP_URL_HOST);
            $source = $host ? preg_replace('/^www\./', '', $host) : null;
            if ($novel->isManga()) {
                // Every Suwayomi series shares the server's host: name the
                // source behind it instead ("Suwayomi · MangaFox"). No request.
                $source = $this->mangaSource($lanes, $novel) ?? $source;
            }
            $rows[] = [
                'id' => $novel->id,
                'name' => $novel->name,
                'url' => $link('novels.show', $novel->id),
                'medium' => $novel->isManga() ? Novel::MEDIUM_MANGA : Novel::MEDIUM_NOVEL,
                'source' => $source,
                // "Translated (KO)" / "Original" / null — appended to the subline.
                // A manga has no origin (its own section says what it is).
                'origin' => $novel->isManga() ? null : $novel->originShortLabel(),
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

        // Enrich the rendered rows (the first NOVEL_LIMIT of each medium):
        // one grouped count query + the resume points (two statements).
        $seen = [];
        $enrich = [];
        foreach ($rows as $i => $row) {
            $seen[$row['medium']] = ($seen[$row['medium']] ?? 0) + 1;
            if ($seen[$row['medium']] <= self::NOVEL_LIMIT) {
                $enrich[$i] = true;
            }
        }
        // Reading progress is $userId's (null: the current reader, the owner
        // in a console run — the owner's email).
        $readState = app(ReadState::class);
        $shown = array_values(array_map(fn($i) => $rows[$i]['id'], array_keys($enrich)));
        $counts = NovelChapter::whereIn('novel_id', $shown)
            ->where('blacklist', 0)
            ->selectRaw('novel_id, COUNT(*) as total')
            ->groupBy('novel_id')
            ->get()
            ->keyBy('novel_id');
        $reads = $readState->readCounts($shown, $userId);
        // "Read from": the resume point every device shares (none when
        // caught up), all rendered novels at once.
        $points = app(ResumePoint::class)->forNovels($shown, ['id', 'chapter'], $userId);

        foreach ($rows as $i => &$row) {
            if (isset($enrich[$i])) {
                $c = $counts->get($row['id']);
                $total = max($row['_toc'], (int) ($c->total ?? 0));
                $row['percent'] = $total > 0 ? (int) min(100, round(($reads[$row['id']]['read'] ?? 0) / $total * 100)) : 0;

                $readFrom = $points[$row['id']]['chapter'] ?? null;
                if ($readFrom) {
                    $row['read_from'] = (float) $readFrom->chapter;
                    $row['read_from_url'] = $link('chapters.show', $readFrom->id);
                }
            }
            unset($row['_toc']);
        }
        unset($row);

        return $rows;
    }

    /** Readable source of a manga from its download lane; null when it has none. */
    private function mangaSource(DownloadLanes $lanes, Novel $novel): ?string
    {
        $lane = $lanes->laneFor($novel, null, false);
        if ($lane === DownloadLanes::NO_HOST) {
            return null;
        }
        $label = DownloadLanes::label($lane);

        return $label !== '' ? $label : null;
    }

    /**
     * Series completed in the window. A reader's
     * email ($forReader) never says "Sent to Kindle" (that is the owner's
     * Kindle) and links on the public address.
     */
    private function completedNovels(Carbon $since, bool $forReader = false): array
    {
        $kindle = !$forReader && setting("auto_kindle", "1") === "1";
        $link = $this->linker($forReader);

        return Novel::where("status", 1)
            ->where("completed_at", ">=", $since)
            ->orderBy("name")
            ->get(["id", "name", "completed_at"])
            ->map(fn($novel) => [
                "id" => $novel->id,
                "name" => $novel->name,
                "completed_at" => $novel->completed_at,
                "url" => $link('novels.show', $novel->id),
                "epub_url" => $link('novels.download_epub', $novel->id),
                "kindle" => $kindle,
            ])
            ->all();
    }

    /**
     * The current book (HomeController::continueReading(1)) of $userId (null:
     * the current reader, the owner here) with its position — "Chapter N of
     * M", ranked like the home hero — or null.
     */
    private function continueBlock(?int $userId = null, bool $forReader = false): ?array
    {
        $link = $this->linker($forReader);
        try {
            $item = app(HomeController::class)->continueReading(1, $userId)[0] ?? null;
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
            'novel_url' => $link('novels.show', $novel->id),
            'chapter_label' => $chapter->label,
            'index' => $index,
            'total' => max((int) $novel->last_toc_count, $rows),
            // A resumed item carries $userId's state: ResumePoint::forNovels()
            // attaches the state it joined for that user (continueReading()).
            'chapter_percent' => $item['resume'] ? (int) max(0, min(100, (int) $item['next']->readProgress())) : 0,
            'resume' => (bool) $item['resume'],
            'url' => $link('chapters.show', $chapter->id),
        ];
    }

    /** route() for the owner's email; the public address for a reader's. */
    private function linker(bool $forReader): \Closure
    {
        return $forReader
            ? fn(string $name, $parameters = []) => NewChapters::readerUrl($name, $parameters)
            : fn(string $name, $parameters = []) => route($name, $parameters);
    }

    /**
     * Order-independent fingerprint of the attention set ("id:reason" lines)
     * and the stalled lanes ("lane:{lane}" lines). Without stalled lanes it
     * is the attention set's fingerprint alone, as before.
     */
    private function attentionHash(array $attention, array $stalled = []): string
    {
        if ($attention === [] && $stalled === []) {
            return '';
        }

        $lines = array_map(fn($a) => ($a['id'] ?? '') . ':' . ($a['reason'] ?? ''), $attention);
        foreach ($stalled as $lane) {
            $lines[] = 'lane:' . $lane['lane'];
        }
        sort($lines, SORT_STRING);

        return sha1(implode("\n", $lines));
    }
}
