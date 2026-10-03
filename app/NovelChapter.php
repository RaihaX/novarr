<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class NovelChapter extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** `kind` value for an author/translator message (or short special). */
    public const KIND_NOTE = 'note';

    protected $fillable = [
        "novel_id",
        "chapter",
        "label",
        "url",
        "book",
        "unique_id",
        "kind",
        // Structured chapter identity (audit A4) — see App\Scraping\ChapterLabelParser.
        "number",
        "part",
        "title",
        "source_label",
        "sort_key",
        // Chapter-sweep retry state.
        "attempts",
        "next_attempt_at",
        "last_failure_reason",
    ];

    /** Fetch attempts after which a pending chapter needs a human look. */
    public const REVIEW_ATTEMPTS = 8;

    /**
     * Pending body-text write; flushed to chapter_texts after save (the row
     * id must exist first). Note: saveQuietly() still fires no events —
     * never set description on a quiet save.
     */
    protected ?string $pendingContent = null;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'download_date' => 'datetime',
        'last_attempt_at' => 'datetime',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'status' => 'boolean',
        'novel_id' => 'integer',
        'chapter' => 'float',
        'number' => 'float',
        'part' => 'integer',
        'sort_key' => 'float',
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
    ];

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** The chapter's body text (split out of this table — see ChapterText). */
    public function text(): HasOne
    {
        return $this->hasOne(ChapterText::class, 'novel_chapter_id');
    }

    /**
     * Raw body text from chapter_texts, memoised on the relation so repeated
     * access doesn't re-query. Eager-load `text` in loops.
     */
    public function rawText(): ?string
    {
        if ($this->pendingContent !== null) {
            return $this->pendingContent;
        }
        if (!$this->relationLoaded('text')) {
            $this->setRelation('text', $this->text()->first());
        }

        return $this->getRelation('text')?->content;
    }

    /**
     * Sanitise stored content for display/ePub: whitelist basic formatting
     * tags; self-close <br>/<hr> so the output stays valid XHTML.
     */
    public static function presentContent(?string $value): string
    {
        $value = strip_tags($value ?? '', '<p><br><hr><em><strong><i><b><u><s>');
        // strip_tags keeps attributes on allowed tags, so <p onclick> or
        // <b style> would reach the reader. Drop every attribute.
        $value = preg_replace('/<(\/?)(\w+)\b[^>]*>/', '<$1$2>', $value);
        $value = preg_replace('/<(br|hr)\s*\/?>/i', '<$1/>', $value);

        return str_replace('<p>&nbsp;</p>', '', $value);
    }

    // description behaves like it always has, but is backed by chapter_texts.
    public function getDescriptionAttribute(): string
    {
        return static::presentContent($this->rawText());
    }

    public function setDescriptionAttribute($value): void
    {
        $this->pendingContent = $value ?? '';
    }

    /**
     * Note: NovelScraper::syncTableOfContents() batch-inserts new TOC rows
     * with NovelChapter::insert(), which bypasses model events — this hook
     * (and any future created/saved listener) does not run for them. Safe
     * today because new TOC rows carry no body text.
     */
    protected static function booted(): void
    {
        static::saved(function (self $chapter) {
            if ($chapter->pendingContent === null) {
                return;
            }
            if ($chapter->pendingContent === '') {
                ChapterText::where('novel_chapter_id', $chapter->id)->delete();
            } else {
                ChapterText::updateOrCreate(
                    ['novel_chapter_id' => $chapter->id],
                    ['content' => $chapter->pendingContent]
                );
            }
            $chapter->pendingContent = null;
            $chapter->unsetRelation('text');
        });
    }

    /** Is this chapter an author's note rather than a proper chapter? */
    public function isNote(): bool
    {
        return $this->kind === self::KIND_NOTE;
    }

    /**
     * Scope for author's-note chapters.
     */
    public function scopeNotes($query)
    {
        return $query->where('kind', self::KIND_NOTE);
    }

    /**
     * Scope for active (downloaded and not blacklisted) chapters.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 1)->where('blacklist', 0);
    }

    /**
     * Scope for chapters of a specific novel.
     */
    public function scopeForNovel($query, int $novelId)
    {
        return $query->where('novel_id', $novelId);
    }

    /**
     * Scope for non-blacklisted chapters.
     */
    public function scopeNotBlacklisted($query)
    {
        return $query->where('blacklist', 0);
    }

    /**
     * Scope for pending (not downloaded) chapters.
     */
    public function scopePending($query)
    {
        return $query->where('status', 0)->where('blacklist', 0);
    }

    /**
     * Scope to order by latest download date.
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('download_date', 'desc')->orderBy('id', 'desc');
    }

    /**
     * Reading order: book, then the structured sort key (number + part/1000,
     * NULL = unknown first), then the legacy number, then id.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('book')->orderBy('sort_key')->orderBy('chapter')->orderBy('id');
    }

    /** Reverse reading order. */
    public function scopeOrderedDesc($query)
    {
        return $query->orderByDesc('book')->orderByDesc('sort_key')->orderByDesc('chapter')->orderByDesc('id');
    }

    /**
     * Rows on one side of $chapter in reading order, comparing the tuple
     * (book, sort_key NULLS FIRST, chapter) — the ordered() keys minus id, so
     * a row sharing the exact position is not "before" or "after" it.
     * $operator: '<', '<=', '>' or '>='.
     */
    public function scopeRelativeTo($query, self $chapter, string $operator)
    {
        if (!in_array($operator, ['<', '<=', '>', '>='], true)) {
            throw new \InvalidArgumentException("Invalid position operator {$operator}");
        }

        $after = $operator[0] === '>';
        $strict = strlen($operator) === 1;
        $book = (int) $chapter->book;
        $key = $chapter->sort_key;
        $legacy = (float) $chapter->chapter;
        $id = (int) $chapter->id;

        // Rows that share (sort_key, chapter) — every unresolved chapter-0
        // row, for one — are ordered by id, so a strict comparison must
        // break the tie on id or prev/next would skip them.
        $tie = function ($t) use ($strict, $operator, $legacy, $id) {
            $t->where('chapter', $operator, $legacy);
            if ($strict) {
                $t->orWhere(fn($i) => $i->where('chapter', $legacy)->where('id', $operator, $id));
            }
        };

        return $query->where(function ($q) use ($after, $book, $key, $legacy, $operator, $tie) {
            $q->where('book', $after ? '>' : '<', $book)
                ->orWhere(function ($same) use ($after, $book, $key, $legacy, $operator, $tie) {
                    $same->where('book', $book)->where(function ($k) use ($after, $key, $legacy, $operator, $tie) {
                        if ($key === null) {
                            // NULL keys sort first: every keyed row is after us.
                            if ($after) {
                                $k->whereNotNull('sort_key');
                            }
                            $k->orWhere(fn($n) => $n->whereNull('sort_key')->where($tie));
                            return;
                        }
                        $k->where('sort_key', $after ? '>' : '<', $key);
                        if (!$after) {
                            $k->orWhereNull('sort_key');
                        }
                        $k->orWhere(fn($e) => $e->where('sort_key', $key)->where($tie));
                    });
                });
        });
    }

    /**
     * Re-derive number / part / sort_key from the row's label, URL, book and
     * (authoritative) legacy `chapter` — call after changing `chapter`
     * (resolver, renumbering, manual fixes). Does not save.
     */
    public function syncStructuredNumber(): static
    {
        $columns = \App\Scraping\ChapterLabelParser::columnsFor(
            (string) $this->label,
            $this->url,
            (int) $this->book,
            (float) $this->chapter
        );
        $this->number = $columns['number'];
        $this->part = $columns['part'];
        $this->sort_key = $columns['sort_key'];

        return $this;
    }

    /**
     * Human chapter number: "12", "12.5", "12 (part 2)", "Vol 2 · 3".
     * Falls back to the legacy `chapter` value when no number is stored.
     */
    public function displayNumber(): string
    {
        $number = $this->number;
        $part = (int) $this->part;
        if ($number === null) {
            $legacy = (float) $this->chapter;
            if ($legacy == 0.0) {
                return '';
            }
            [$number, $part] = \App\Scraping\ChapterLabelParser::decodeLegacy($legacy);
        }

        $text = rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
        if ($part > 0) {
            $text .= " (part {$part})";
        }
        if ((int) $this->book > 0) {
            $text = "Vol {$this->book} · {$text}";
        }

        return $text;
    }

    /**
     * Not a plain numbered chapter: no number, an author's note, or a label
     * the parser files as prologue / epilogue / side story / extra / notice.
     */
    public function isSpecial(): bool
    {
        if ($this->isNote() || ($this->number === null && (float) $this->chapter == 0.0)) {
            return true;
        }

        return \App\Scraping\ChapterLabelParser::parse((string) $this->label, $this->url, (int) $this->book)->isSpecial();
    }

    /**
     * Pending rows the sweep may fetch now (retry backoff elapsed).
     */
    public function scopeDue($query)
    {
        return $query->where('status', 0)->where('blacklist', 0)
            ->where(fn($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
    }

    /**
     * Pending rows that keep failing — surfaced for manual review.
     */
    public function scopeNeedsReview($query)
    {
        return $query->where('status', 0)->where('attempts', '>=', self::REVIEW_ATTEMPTS);
    }
}
