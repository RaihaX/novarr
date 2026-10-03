<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\NovelChapter;
use Illuminate\Support\Facades\Log;

class CleanChapterContent extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'novel:clean_chapter_content
                            {novel : Novel ID to clean}
                            {--dry-run : Report what would change without saving}';

    /**
     * The console command description.
     */
    protected $description = 'Strip leftover <style> CSS and Taboola/Outbrain ad widget text from previously downloaded chapter descriptions.';

    /**
     * Widget/ad paragraph detection. A short (< WIDGET_MAX_CHARS) paragraph
     * matching one of these as a whole token is a widget heading/marker —
     * "The Azure Sect sponsored the tournament" is prose.
     */
    protected const WIDGET_PATTERNS = [
        '/^(?:Sponsored|Promoted)(?:\s+Content)?$/i',
        '/\btaboola\b/i',
        '/\boutbrain\b/i',
    ];

    /**
     * Card signatures that never occur in prose: a line carrying one is a
     * widget card at any length ("Doctors stunned by … Read MoreUndo").
     */
    protected const WIDGET_SIGNATURES = [
        '/Read\s*MoreUndo/i',
        '/Play\s*NowUndo/i',
    ];

    protected const WIDGET_MAX_CHARS = 60;

    /** Recommendation-card lines are short; story paragraphs usually aren't. */
    protected const CARD_MAX_CHARS = 120;

    /** From a widget heading on, truncate when >= 60% of the rest is card-shaped. */
    protected const WIDGET_BLOCK_RATIO = 0.6;

    /** Refuse to save when the cleaned story keeps fewer than half its words. */
    protected const MIN_KEEP_RATIO = 0.5;

    /**
     * Marker substrings for leading/inline CSS noise. Any <p> containing one of
     * these is dropped wherever it appears.
     */
    protected const INLINE_MARKERS = [
        'pf-config-',
        '!important',
    ];

    public function handle()
    {
        $novelId = (int) $this->argument('novel');
        $dryRun = (bool) $this->option('dry-run');

        if ($novelId <= 0) {
            $this->error('A novel ID is required.');
            return 1;
        }

        $query = NovelChapter::with('text')
            ->where('novel_id', $novelId)
            ->where('status', 1)
            ->whereHas('text');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("No downloaded chapters found for novel {$novelId}.");
            return 0;
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Scanning {$total} chapters for novel {$novelId}...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $changed = 0;
        $skipped = 0;
        $tailTruncated = 0;
        $cssRemoved = 0;
        $titleRemoved = 0;
        $bytesSaved = 0;
        $report = [];

        $query->chunkById(200, function ($chapters) use (&$changed, &$skipped, &$tailTruncated, &$cssRemoved, &$titleRemoved, &$bytesSaved, &$report, $dryRun, $bar) {
            foreach ($chapters as $chapter) {
                $original = $chapter->description;
                if ($original === null || $original === '') {
                    $bar->advance();
                    continue;
                }

                [$cleaned, $stats] = self::cleanDescription($original, $chapter->chapter, $chapter->label);

                if ($stats['refused']) {
                    $skipped++;
                    if ($stats['reason'] === 'all_widget') {
                        // Body is nothing but ad widget: re-queue the chapter
                        // for download. The text row is kept until the
                        // scraper overwrites it.
                        $msg = "Chapter {$chapter->chapter} (#{$chapter->id}, novel {$chapter->novel_id}) is all ad-widget text"
                            . ($dryRun ? ' — would re-queue it for download.' : ' — re-queued for download.');
                        if (!$dryRun) {
                            $chapter->status = 0;
                            $chapter->save();
                        }
                    } else {
                        $msg = "Refused to clean chapter {$chapter->chapter} (#{$chapter->id}, novel {$chapter->novel_id}): "
                            . "would keep {$stats['words_after']} of {$stats['story_words']} story words.";
                    }
                    Log::warning('[clean_chapter_content] ' . $msg);
                    $report[] = ['warn', $msg];
                    $bar->advance();
                    continue;
                }

                if ($cleaned !== $original) {
                    $changed++;
                    $bytesSaved += strlen($original) - strlen($cleaned);
                    if ($stats['tail_truncated']) {
                        $tailTruncated++;
                    }
                    if ($stats['css_removed']) {
                        $cssRemoved++;
                    }
                    if ($stats['title_removed']) {
                        $titleRemoved++;
                    }

                    if ($dryRun) {
                        $what = array_keys(array_filter([
                            'widget tail' => $stats['tail_truncated'],
                            'widget paragraph' => $stats['widget_removed'],
                            'css' => $stats['css_removed'],
                            'title' => $stats['title_removed'],
                        ]));
                        $report[] = ['line', "Would clean chapter {$chapter->chapter} ({$chapter->label}): "
                            . implode(', ', $what) . " — {$stats['words_before']} -> {$stats['words_after']} words"];
                    } else {
                        $chapter->description = $cleaned;
                        $chapter->save();
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->line('');

        foreach ($report as [$level, $msg]) {
            $level === 'warn' ? $this->warn($msg) : $this->line($msg);
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Chapters scanned', $total],
                ['Chapters ' . ($dryRun ? 'that would change' : 'changed'), $changed],
                ['Skipped (would lose > 50% of story / all widget)', $skipped],
                ['Tail (widget) truncated', $tailTruncated],
                ['CSS paragraph removed', $cssRemoved],
                ['Leading title removed', $titleRemoved],
                ['Bytes saved', number_format($bytesSaved)],
            ]
        );

        if ($dryRun) {
            $this->warn('Dry run — no rows were saved.');
        }

        return 0;
    }

    /**
     * Clean one chapter description (pure — no DB access).
     *
     * Strategy:
     *   - Split into <p>...</p> paragraphs (the scraper always wraps content this way).
     *   - Strip a duplicated leading chapter-title heading.
     *   - Drop paragraphs containing inline CSS markers (e.g. pf-config-...).
     *   - At each widget paragraph (short "Sponsored"/taboola/outbrain heading,
     *     or any line carrying a "Read MoreUndo"/"Play NowUndo" signature):
     *     if >= 60% of the paragraphs from there on are card-shaped (< 120
     *     chars or widget-shaped) and they carry >= 2 widget markers (see
     *     isWidgetBlock), truncate from there — wherever it is.
     *     Otherwise drop just that paragraph and keep going.
     *   - A story paragraph with widget text glued on after a blank-line gap
     *     is cut at the gap when what follows it is likewise a widget block.
     *   - Guard: story words = words outside CSS/widget/card paragraphs. If
     *     the result keeps < 50% of them, refuse (reason 'too_much_loss'); if
     *     there are no story words at all, refuse with reason 'all_widget'.
     *     A refusal returns the original unchanged.
     *
     * @return array{0: string, 1: array}  [html, stats]
     */
    public static function cleanDescription(string $html, $chapterNumber = null, ?string $label = null): array
    {
        $stats = [
            'tail_truncated' => false,
            'widget_removed' => false,
            'css_removed' => false,
            'title_removed' => false,
            'refused' => false,
            'reason' => null,
            'words_before' => self::wordCount($html),
            'story_words' => 0,
            'words_after' => 0,
        ];

        if (!preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches, PREG_SET_ORDER)) {
            $stats['story_words'] = $stats['words_after'] = $stats['words_before'];
            return [$html, $stats];
        }

        // Strip duplicated/glued chapter-title headings from the front —
        // the reader/ePub already render the label as a heading.
        $paras = array_map(fn($m) => $m[0], $matches);
        $stripped = stripLeadingChapterTitle($paras, $chapterNumber, $label);
        if ($stripped !== $paras) {
            $stats['title_removed'] = true;
            $paras = $stripped;
        }

        // Classify paragraphs: [full html, inner html, text, css?].
        $items = [];
        foreach ($paras as $p) {
            if (!preg_match('/<p\b[^>]*>(.*?)<\/p>/is', $p, $m)) {
                continue;
            }
            $text = trim(strip_tags($m[1]));
            $items[] = [
                'full' => $m[0],
                'inner' => $m[1],
                'text' => $text,
                'css' => self::containsAny($text, self::INLINE_MARKERS),
            ];
        }
        // CSS paragraphs are dropped wherever they are and ignored when
        // judging the widget block.
        $content = array_values(array_filter($items, fn($it) => !$it['css']));
        if (count($content) !== count($items)) {
            $stats['css_removed'] = true;
        }

        $kept = [];
        $story = 0; // words of paragraphs judged to be story (guard baseline)
        $n = count($content);

        for ($i = 0; $i < $n; $i++) {
            $it = $content[$i];

            // Story text with widget lines glued on after a blank-line gap —
            // checked first, since a glued signature would otherwise make the
            // whole paragraph (story included) look like a widget card.
            if (($cut = self::findGluedWidget($it['inner'])) !== null
                && !self::isWidgetText(trim(strip_tags(substr($it['inner'], 0, $cut))))
                && self::isWidgetBlock(array_slice($content, $i + 1), self::widgetLineCount(substr($it['inner'], $cut)))) {
                $stats['tail_truncated'] = true;
                $beforeCut = rtrim(substr($it['inner'], 0, $cut));
                if (strlen(trim(strip_tags($beforeCut))) >= 20) {
                    $kept[] = '<p>' . $beforeCut . '</p>';
                }
                // Before the gap is story; in the glued tail only lines that
                // aren't card-shaped count as story (guards a bad cut).
                $story += self::wordCount($beforeCut)
                    + self::storyLinesWords(substr($it['inner'], $cut))
                    + self::storyWordsIn(array_slice($content, $i + 1));
                break;
            }

            if (self::isWidgetText($it['text'])) {
                if (self::isWidgetBlock(array_slice($content, $i))) {
                    $stats['tail_truncated'] = true;
                    $story += self::storyWordsIn(array_slice($content, $i));
                    break;
                }
                $stats['widget_removed'] = true;
                continue;
            }

            $words = self::wordCount($it['inner']);

            $story += $words;
            $kept[] = $it['full'];
        }

        $cleaned = implode('', $kept);
        $stats['story_words'] = $story;
        $stats['words_after'] = self::wordCount($cleaned);

        if ($story === 0 && $stats['words_before'] > 0) {
            $stats['refused'] = true;
            $stats['reason'] = 'all_widget';
            return [$html, $stats];
        }

        if (self::losesTooMuch($story, $stats['words_after'])) {
            $stats['refused'] = true;
            $stats['reason'] = 'too_much_loss';
            return [$html, $stats];
        }

        return [$cleaned, $stats];
    }

    /**
     * True when the paragraphs form a widget block: >= 60% of them are
     * card-shaped AND the block carries at least two widget markers
     * (heading + a signature/marker card), counting $seen markers already
     * found (e.g. in glued text). The second-marker requirement matters:
     * dialogue-heavy story is mostly < 120-char lines too, so one stray
     * "Sponsored" must not be enough to truncate it. A lone marker that is
     * the last paragraph is a block of one.
     */
    protected static function isWidgetBlock(array $items, int $seen = 0): bool
    {
        if (empty($items)) {
            return $seen > 0;
        }

        $cards = 0;
        $markers = $seen;
        foreach ($items as $it) {
            if (self::isWidgetText($it['text'])) {
                $markers++;
                $cards++;
            } elseif (self::isCardText($it['text'])) {
                $cards++;
            }
        }

        if ($cards < count($items) * self::WIDGET_BLOCK_RATIO) {
            return false;
        }

        return $markers >= 2 || ($seen === 0 && count($items) === 1 && $markers === 1);
    }

    /** Words in paragraphs of a truncated block that look like story (not card-shaped). */
    protected static function storyWordsIn(array $items): int
    {
        $words = 0;
        foreach ($items as $it) {
            if (!self::isCardText($it['text'])) {
                $words += self::wordCount($it['inner']);
            }
        }
        return $words;
    }

    /** Number of widget-shaped lines in glued text. */
    protected static function widgetLineCount(string $html): int
    {
        $n = 0;
        foreach (preg_split('/\n+/', strip_tags($html)) as $line) {
            if (self::isWidgetText(trim($line))) {
                $n++;
            }
        }
        return $n;
    }

    /** Words on lines of glued text that are not card-shaped. */
    protected static function storyLinesWords(string $html): int
    {
        $words = 0;
        foreach (preg_split('/\n+/', strip_tags($html)) as $line) {
            if (trim($line) !== '' && !self::isCardText($line)) {
                $words += self::wordCount($line);
            }
        }
        return $words;
    }

    /** A recommendation card / widget line: widget-shaped or short. */
    public static function isCardText(string $text): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));

        return self::isWidgetText($text) || mb_strlen($text) < self::CARD_MAX_CHARS;
    }

    /** Word count of HTML as a reader sees it. */
    public static function wordCount(string $html): int
    {
        return ChapterScraper::countChapterWords($html);
    }

    /**
     * True when keeping $after of $before story words falls under the 50%
     * guard. An empty result always counts as too much loss when there was
     * anything to begin with.
     */
    public static function losesTooMuch(int $before, int $after): bool
    {
        if ($before <= 0) {
            return false;
        }

        return $after === 0 || $after < $before * self::MIN_KEEP_RATIO;
    }

    /** A short paragraph that is ad/recommendation-widget chrome. */
    public static function isWidgetText(string $text): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));

        if ($text === '') {
            return false;
        }

        foreach (self::WIDGET_SIGNATURES as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        if (mb_strlen($text) >= self::WIDGET_MAX_CHARS) {
            return false;
        }

        foreach (self::WIDGET_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Widget text glued onto the end of a story paragraph follows a blank-line
     * + indent gap. Return the offset of the first gap whose following line is
     * widget-shaped, or null.
     */
    protected static function findGluedWidget(string $inner): ?int
    {
        if (!preg_match_all('/\n{2,}\s+/', $inner, $gaps, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($gaps[0] as [$gap, $offset]) {
            $after = substr($inner, $offset + strlen($gap));
            $firstLine = trim(strip_tags(strtok($after, "\n") ?: ''));
            if (self::isWidgetText($firstLine)) {
                return $offset;
            }
        }

        return null;
    }

    protected static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
