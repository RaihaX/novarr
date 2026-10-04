<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewChapters extends Mailable
{
    use Queueable, SerializesModels;

    /** Novel rows rendered in the email; the rest are linked to Activity. */
    public const NOVEL_LIMIT = 25;

    /**
     * Payload: ['since', 'chapters', 'completed', 'attention'] plus, from the
     * daily summary, ['continue', 'stats', 'novels', 'summary_time']. A flat
     * list of chapter rows (the legacy shape) is still accepted.
     */
    public $mailData;

    public function __construct($mailData)
    {
        $this->mailData = $mailData;
    }

    public function build()
    {
        $view = $this->viewData();

        return $this->subject($this->summarySubject($view))
            ->view('emails.newchapters')
            ->text('emails.newchapters_text')
            ->with([
                'data' => $this->mailData,
                'v' => $view,
            ]);
    }

    /**
     * One normalised view model for both the HTML and the plain-text part,
     * whichever payload shape came in.
     */
    public function viewData(): array
    {
        $data = is_array($this->mailData) ? $this->mailData : [];
        $legacy = $data !== [] && array_is_list($data);

        $chapters = $legacy ? $data : ($data['chapters'] ?? []);
        $completed = $legacy ? [] : ($data['completed'] ?? []);
        $attention = $legacy ? [] : ($data['attention'] ?? []);
        $novels = $legacy ? null : ($data['novels'] ?? null);

        // Older callers send only flat chapter rows: fold them into one row
        // per novel (no ids, so no links or progress).
        if ($novels === null) {
            $novels = collect($chapters)
                ->groupBy('novel')
                ->map(fn($rows, $name) => [
                    'id' => null,
                    'name' => (string) $name,
                    'url' => null,
                    'source' => null,
                    'count' => $rows->count(),
                    'notes' => 0,
                    'first' => (float) $rows->min(fn($r) => (float) ($r['chapter'] ?? 0)),
                    'last' => (float) $rows->max(fn($r) => (float) ($r['chapter'] ?? 0)),
                    'percent' => null,
                    'read_from' => null,
                    'read_from_url' => null,
                ])
                ->sortByDesc('count')
                ->values()
                ->all();
        }

        $newChapters = array_sum(array_column($novels, 'count')) ?: count($chapters);

        $stats = ($data['stats'] ?? []) + [
            'new_chapters' => $newChapters,
            'novels_updated' => count($novels),
            'completed' => count($completed),
            'queued' => null,
            'attention' => count($attention),
        ];

        return [
            'since' => $legacy ? null : ($data['since'] ?? null),
            'continue' => $legacy ? null : ($data['continue'] ?? null),
            'stats' => $stats,
            'novels' => array_slice($novels, 0, self::NOVEL_LIMIT),
            'more_novels' => max(0, count($novels) - self::NOVEL_LIMIT),
            'completed' => $completed,
            'attention' => $attention,
            'summary_time' => $legacy ? setting('summary_time', '08:00') : ($data['summary_time'] ?? setting('summary_time', '08:00')),
            'date' => now()->timezone(config('app.timezone')),
            'links' => [
                'home' => route('home'),
                'activity' => route('activity.index'),
                'settings' => route('settings.index'),
                'health' => route('health.index') . '#attentionPanel',
            ],
        ];
    }

    /** "Chapters 2,990 to 3,207" / "Chapter 12" — whole numbers without decimals. */
    public static function chapterRange(array $novel): string
    {
        $fmt = fn(float $n) => fmod($n, 1.0) === 0.0
            ? number_format($n)
            : rtrim(rtrim(number_format($n, 2), '0'), '.');

        return $novel['first'] == $novel['last']
            ? 'Chapter ' . $fmt($novel['first'])
            : 'Chapters ' . $fmt($novel['first']) . ' to ' . $fmt($novel['last']);
    }

    /** "Chapter 3,208" style label for a single chapter number. */
    public static function chapterNumber(float $n): string
    {
        return fmod($n, 1.0) === 0.0 ? number_format($n) : rtrim(rtrim(number_format($n, 2), '0'), '.');
    }

    /**
     * "Novarr · 382 new chapters in 12 novels · 1 completed · 3 need attention",
     * zero parts omitted.
     */
    protected function summarySubject(?array $view = null): string
    {
        $s = ($view ?? $this->viewData())['stats'];
        $plural = fn(int $n, string $one, string $many) => $n === 1 ? $one : $many;

        $parts = [];

        if (($n = (int) $s['new_chapters']) > 0) {
            $novels = (int) $s['novels_updated'];
            $parts[] = number_format($n) . ' new ' . $plural($n, 'chapter', 'chapters')
                . ($novels > 0 ? ' in ' . number_format($novels) . ' ' . $plural($novels, 'novel', 'novels') : '');
        }

        if (($n = (int) $s['completed']) > 0) {
            $parts[] = $n . ' completed';
        }

        if (($n = (int) $s['attention']) > 0) {
            $parts[] = $n . ' ' . $plural($n, 'needs', 'need') . ' attention';
        }

        return 'Novarr · ' . ($parts ? implode(' · ', $parts) : 'Nothing new');
    }
}
