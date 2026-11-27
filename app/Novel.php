<?php

namespace App;

use App\Scraping\NovelUpdatesMatcher;
use App\Scraping\OriginInference;
use App\Services\CoverThumbnails;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Novel extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        "name",
        "author",
        "description",
        "translator_url",
        "novelupdates_url",
        "status",
        "completed_at",
        "group_id",
        "language_id",
        "no_of_chapters",
        "origin",
        "origin_language",
        "origin_source",
        "origin_type",
        "origin_confidence",
        // Manga support: set at creation from the source (SourceResolver),
        // reading_mode by the reader / metadata (never over a user's choice).
        "medium",
        "reading_mode",
        // The upstream source behind a shared host (a Suwayomi source id);
        // see App\Services\DownloadLanes.
        "source_ref",
        // Books only (App\Books\BookSeries): the series, the book's number
        // in it, and who set them (calibre | epub | inferred | manual).
        "series",
        "series_index",
        "series_source",
    ];

    /** In memory as in the database: a new row is a novel unless set. */
    protected $attributes = [
        'medium' => 'novel',
    ];

    public const MEDIUM_NOVEL = 'novel';
    public const MEDIUM_MANGA = 'manga';
    /** A book uploaded as an ePub (App\Books\BookLibrary): text, complete on arrival, never scraped. */
    public const MEDIUM_BOOK = 'book';

    /** Reader page modes of a manga (novels.reading_mode). */
    public const READING_MODES = ['rtl', 'ltr', 'vertical'];

    /**
     * Who set a novel's origin, weakest first (see applyOrigin): a guess from
     * the text, the series' own source (MangaDex originalLanguage, a
     * Suwayomi Manga/Manhwa/Manhua genre), NovelUpdates, the edit form.
     */
    public const ORIGIN_SOURCES = ['inferred' => 1, 'source' => 2, 'novelupdates' => 3, 'manual' => 4];

    /** origin_language => the two-letter country badge (originCountry()). */
    public const ORIGIN_COUNTRIES = ['ja' => 'JP', 'ko' => 'KR', 'zh' => 'CN', 'en' => 'EN'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'completed_at' => 'datetime',
        'paused_at' => 'datetime',
        'epub_generated' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'status' => 'boolean',
        'frequent_toc' => 'boolean',
        'scrape_failures' => 'integer',
        'no_of_chapters' => 'integer',
        'last_toc_at' => 'datetime',
        'last_toc_count' => 'integer',
        'toc_failures' => 'integer',
        'toc_full_walk_at' => 'datetime',
        'novelupdates_checked_at' => 'datetime',
        'source_fail_streak' => 'integer',
        'source_recheck_at' => 'datetime',
        'origin' => 'string',
        'origin_language' => 'string',
        'origin_source' => 'string',
        'origin_type' => 'string',
        'origin_confidence' => 'string',
        'source_ref' => 'string',
        // decimal(8,2) comes back as "2.00" (MariaDB) or 2 (SQLite): one type.
        'series_index' => 'float',
        // Books that are web novels (App\Books\WebNovelCheck).
        'webnovel_checked_at' => 'datetime',
        'webfiction_checked_at' => 'datetime',
        'webnovel_kept_at' => 'datetime',
        'converted_snapshot' => 'array',
        'alternative_titles' => 'array',
    ];

    public function chapters(): HasMany
    {
        return $this->hasMany(NovelChapter::class);
    }

    protected static function booted(): void
    {
        // Only a hard delete removes downloaded manga pages (and every
        // reader's read state and reading position); a soft-deleted novel
        // keeps them so a restore brings them back.
        static::forceDeleted(function (self $novel) {
            ChapterPage::purgeNovel((int) $novel->id);
            // An uploaded book's original ePub goes with it (also once it
            // was moved to Novels as a web novel: the file is still its own).
            if ($novel->hasLibraryFile()) {
                @unlink(\App\Books\BookLibrary::path($novel));
            }
            app(\App\Services\ReadState::class)->forgetNovel((int) $novel->id);
            ReadingPosition::where('novel_id', $novel->id)->delete();
            // …and takes it out of every reader's lists (a soft-deleted one stays, hidden).
            ReadingList::forgetNovel((int) $novel->id);
            // Its audiobook stays, standalone (the files are the owner's).
            Audiobook::withTrashed()->where('novel_id', $novel->id)->update(['novel_id' => null]);
            \App\Audiobooks\AudiobookLibrary::forgetNovelMap();
        });
        // A request approved into this series no longer points at it once
        // the series is deleted (soft or hard): the request stays as history.
        static::deleted(function (self $novel) {
            NovelRequest::where('novel_id', $novel->id)->update(['novel_id' => null]);
        });
    }

    // ---------------------------------------------------------------------
    // Medium: text novel or manga
    // ---------------------------------------------------------------------

    /** Is this a manga (pages, not text)? A row from before the column is a novel. */
    public function isManga(): bool
    {
        return $this->medium === self::MEDIUM_MANGA;
    }

    /** Is this an uploaded book (text from its own ePub, nothing to scrape)? */
    public function isBook(): bool
    {
        return $this->medium === self::MEDIUM_BOOK;
    }

    /**
     * Is its text from an ePub in the Books library (book://library/{sha1})?
     * True for a book, and for a book moved to Novels as a web novel
     * (App\Books\WebNovelCheck): its identity is still the file, so its
     * translator_url is never replaced and nothing is scraped for it.
     */
    public function hasLibraryFile(): bool
    {
        return $this->isBook() || str_starts_with(trim((string) $this->translator_url), \App\Books\BookLibrary::URL_PREFIX);
    }

    /** A book moved to Novels by the web-novel check (undo: `books:webnovel-check --undo=`). */
    public function wasConvertedFromBook(): bool
    {
        return $this->converted_from === self::MEDIUM_BOOK;
    }

    /** Uploaded books only. */
    public function scopeBooks($query)
    {
        return $query->where($query->qualifyColumn('medium'), self::MEDIUM_BOOK);
    }

    /**
     * Read as text: web novels and books (everything but manga). The
     * reader, ePub download, Kindle and OPDS go through this one.
     */
    public function scopeReadable($query)
    {
        return $query->whereIn($query->qualifyColumn('medium'), [self::MEDIUM_NOVEL, self::MEDIUM_BOOK]);
    }

    /**
     * Text novels only — every text-only feature (ePub, Kindle, word counts,
     * NovelUpdates, the native clients that can't show pages yet) goes
     * through this scope.
     */
    public function scopeText($query)
    {
        return $query->where($query->qualifyColumn('medium'), self::MEDIUM_NOVEL);
    }

    /** Manga only. */
    public function scopeManga($query)
    {
        return $query->where($query->qualifyColumn('medium'), self::MEDIUM_MANGA);
    }

    /** Ids of every manga (soft-deleted ones included) — for chapter-level exclusions. */
    public static function mangaIdsQuery()
    {
        return \Illuminate\Support\Facades\DB::table('novels')->select('id')->where('medium', self::MEDIUM_MANGA);
    }

    /** The novel's reading mode for the reader: its own, else left-to-right. */
    public function effectiveReadingMode(): string
    {
        return in_array($this->reading_mode, self::READING_MODES, true) ? $this->reading_mode : 'ltr';
    }

    public function file(): MorphOne
    {
        // The newest upload is the cover; older rows are history.
        return $this->morphOne(File::class, "file")->latestOfMany();
    }

    /** The book's audiobook (App\Audiobook; one per book in v1), or none. */
    public function audiobook(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Audiobook::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'novel_tag');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Scope to eager load common relations.
     */
    public function scopeWithRelations($query)
    {
        return $query->with(['file', 'group', 'language']);
    }

    /**
     * Scope to order by name.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }

    // ---------------------------------------------------------------------
    // Cover
    // ---------------------------------------------------------------------

    /**
     * Make an image already on the public disk this novel's cover: a new File
     * row, both thumbnails, and the previous cover (row, image, thumbnails)
     * removed unless something still points at its image.
     *
     * @param  string  $storedPath  path on the public disk ("abc.jpg"); a
     *                              "public/abc.jpg" file_path is accepted too
     */
    public function attachCover(string $storedPath, ?string $originalName = null): File
    {
        $relative = CoverThumbnails::relative($storedPath);
        $previous = $this->file()->first();

        $file = $this->file()->save(new File([
            'file_name' => $originalName ?? basename($relative),
            'file_path' => 'public/' . $relative,
        ]));
        $this->setRelation('file', $file);

        $thumbnails = app(CoverThumbnails::class);
        $thumbnails->generate($file->file_path);

        if ($previous) {
            $previousRelative = CoverThumbnails::relative((string) $previous->file_path);
            $shared = File::whereKeyNot($previous->getKey())
                ->whereIn('file_path', [$previousRelative, 'public/' . $previousRelative])
                ->exists();

            if ($previousRelative !== '' && $previousRelative !== $relative && !$shared) {
                $thumbnails->delete($previous->file_path);
                Storage::disk('public')->delete($previousRelative);
                $previous->delete();
            }
        }

        return $file;
    }

    /**
     * Cover URL for display: the thumbnail when it exists, else the original,
     * else null. Never lazy-loads `file` (views render many novels at once):
     * an unloaded relation reads as no cover.
     *
     * @param  string  $size  CoverThumbnails size: sm | md
     */
    public function coverUrl(string $size = 'md'): ?string
    {
        return $this->coverThumbnailUrl($size) ?? $this->originalCoverUrl();
    }

    /** The thumbnail URL only, or null when it has not been generated. */
    public function coverThumbnailUrl(string $size): ?string
    {
        $path = $this->loadedCoverPath();
        if ($path === null) {
            return null;
        }

        $thumbnails = app(CoverThumbnails::class);
        if (!$thumbnails->exists($path, $size)) {
            return null;
        }

        // Built the same way as the original's URL so both resolve alike.
        $prefix = str_starts_with(ltrim($path, '/'), 'public/') ? 'public/' : '';

        return Storage::url($prefix . $thumbnails->path($path, $size));
    }

    /** The uploaded cover, full size (ePubs, OPDS image, links). */
    public function originalCoverUrl(): ?string
    {
        $path = $this->loadedCoverPath();

        return $path === null ? null : Storage::url($path);
    }

    private function loadedCoverPath(): ?string
    {
        if (!$this->relationLoaded('file') || !$this->file || !$this->file->file_path) {
            return null;
        }

        return $this->file->file_path;
    }

    // ---------------------------------------------------------------------
    // Origin: translated web novel vs original English
    // ---------------------------------------------------------------------

    /** true = translated, false = original English, null = unknown. */
    public function isTranslated(): ?bool
    {
        return match ($this->origin) {
            OriginInference::TRANSLATED => true,
            OriginInference::ORIGINAL => false,
            default => null,
        };
    }

    /** "Translated · Korean" / "Original · English" / null when unknown. */
    public function originLabel(): ?string
    {
        $translated = $this->isTranslated();
        // A manga shows its origin as the country badge only (originCountry()).
        if ($translated === null || $this->isManga()) {
            return null;
        }

        $language = OriginInference::languageName($this->origin_language);
        if (!$translated) {
            return 'Original · ' . ($language ?? 'English');
        }

        return $language ? "Translated · {$language}" : 'Translated';
    }

    /** Cover micro-label: "TRANSLATED · KO" / "ORIGINAL · EN" / null. */
    public function originChip(): ?string
    {
        $translated = $this->isTranslated();
        if ($translated === null || $this->isManga()) {
            return null;
        }

        $code = $this->origin_language ? strtoupper($this->origin_language) : ($translated ? null : 'EN');

        return ($translated ? 'TRANSLATED' : 'ORIGINAL') . ($code ? " · {$code}" : '');
    }

    /** Daily-email subline: "Translated (KO)" / "Translated" / "Original" / null. */
    public function originShortLabel(): ?string
    {
        return match ($this->isTranslated()) {
            true => 'Translated' . ($this->origin_language ? ' (' . strtoupper($this->origin_language) . ')' : ''),
            false => 'Original',
            null => null,
        };
    }

    /** A NovelUpdates series URL matched at or above the trust threshold. */
    public function hasConfidentNovelUpdatesMatch(): bool
    {
        return !empty($this->novelupdates_url)
            && $this->novelupdates_match_score !== null
            && (float) $this->novelupdates_match_score >= NovelUpdatesMatcher::threshold();
    }

    /**
     * Set origin attributes from $source unless a stronger source already
     * set them (manual > novelupdates > source > inferred); the same source
     * replaces its own earlier answer. Saves quietly (no observers /
     * timestamps churn) when the model exists. Returns whether anything was
     * written.
     *
     * origin_confidence: an inferred result's own `confidence` (low when it
     * has none); any other source is high unless `confidence` says otherwise
     * (a Suwayomi genre is medium).
     *
     * @param array{origin: string, origin_language?: ?string, origin_type?: ?string, confidence?: ?string} $attributes
     */
    public function applyOrigin(array $attributes, string $source): bool
    {
        $rank = self::ORIGIN_SOURCES[$source] ?? 0;
        $current = self::ORIGIN_SOURCES[$this->origin_source ?? ''] ?? 0;
        if ($rank === 0 || $current > $rank) {
            return false;
        }

        $confidence = $attributes['confidence'] ?? null;
        if (!in_array($confidence, [OriginInference::HIGH, OriginInference::MEDIUM, OriginInference::LOW], true)) {
            $confidence = $source === 'inferred' ? OriginInference::LOW : OriginInference::HIGH;
        }

        $values = [
            'origin' => $attributes['origin'],
            'origin_language' => $attributes['origin_language'] ?? null,
            'origin_source' => $source,
            'origin_confidence' => $confidence,
        ];
        // Inference knows nothing of NovelUpdates' type — keep what's there.
        if (array_key_exists('origin_type', $attributes)) {
            $values['origin_type'] = $attributes['origin_type'];
        }

        $this->forceFill($values);
        if (!$this->isDirty(array_keys($values))) {
            return false;
        }
        if ($this->exists) {
            $this->saveQuietly();
        }

        return true;
    }

    /**
     * Infer origin from what is already stored (no network): the author, the
     * source host, the first OriginInference::SAMPLE_CHAPTERS downloaded
     * chapters, the TOC labels and the series' genres (its tags). Never
     * overrides a source, NovelUpdates or manual origin; replaces an earlier
     * inferred one. $apply false only computes (the command's --dry-run).
     *
     * @return array{origin: string, origin_language: ?string, reason: string, confidence: string}|null null when skipped
     */
    public function inferOrigin(bool $apply = true): ?array
    {
        if ((self::ORIGIN_SOURCES[$this->origin_source ?? ''] ?? 0) > self::ORIGIN_SOURCES['inferred']) {
            return null;
        }

        $texts = [];
        $labels = [];
        $genres = [];
        if ($this->exists) {
            $genres = $this->relationLoaded('tags')
                ? $this->tags->pluck('name')->all()
                : $this->tags()->pluck('name')->all();
            $texts = $this->chapters()
                ->where('status', 1)
                ->where('blacklist', 0)
                ->ordered()
                ->limit(OriginInference::SAMPLE_CHAPTERS)
                ->with('text')
                ->get(['id', 'novel_id'])
                ->map(fn($c) => (string) $c->rawText())
                ->all();
            $labels = $this->chapters()
                ->where('blacklist', 0)
                ->ordered()
                ->limit(300)
                ->pluck('label')
                ->filter()
                ->all();
        }

        $result = OriginInference::infer(
            $this->author,
            parse_url((string) $this->translator_url, PHP_URL_HOST) ?: null,
            $texts,
            $labels,
            $this->hasConfidentNovelUpdatesMatch(),
            $this->novelupdates_match_score === null ? null : (float) $this->novelupdates_match_score,
            $genres,
        );

        if ($apply) {
            $this->applyOrigin($result, 'inferred');
        }

        return $result;
    }

    /**
     * Drop a manual origin (the edit form's "Auto"): source, language,
     * type and confidence reset, then detection runs again — inference for
     * a novel, App\Services\MangaOrigin (stored genres, the cached MangaDex
     * title lookup) for a manga.
     */
    public function clearManualOrigin(): void
    {
        if ($this->origin_source === 'manual') {
            $this->forceFill([
                'origin' => null,
                'origin_language' => null,
                'origin_source' => null,
                'origin_type' => null,
                'origin_confidence' => null,
            ]);
            if ($this->exists) {
                $this->saveQuietly();
            }
        }

        if ($this->isManga()) {
            app(\App\Services\MangaOrigin::class)->detect($this);
        } else {
            $this->inferOrigin();
        }
    }

    // ---------------------------------------------------------------------
    // Origin country: the JP / KR / CN / EN badge, filter and API field
    // ---------------------------------------------------------------------

    /**
     * 'JP' | 'KR' | 'CN' | 'EN' from origin_language, or null: another
     * language, an unknown origin, or an inferred origin rated below
     * medium (an unrated old inferred row included). scopeOriginCountry()
     * is the same rule in SQL — keep the two together.
     */
    public function originCountry(): ?string
    {
        $code = self::ORIGIN_COUNTRIES[$this->origin_language ?? ''] ?? null;
        if ($code === null || ($this->origin ?? OriginInference::UNKNOWN) === OriginInference::UNKNOWN) {
            return null;
        }
        if (($this->origin_source ?? '') === 'inferred'
            && !in_array($this->origin_confidence, [OriginInference::HIGH, OriginInference::MEDIUM], true)) {
            return null;
        }

        return $code;
    }

    /** Badge tooltip / aria-label: "Korean manhwa", "Japanese", "English original", or null. */
    public function originCountryLabel(): ?string
    {
        return self::originCountryName($this->originCountry(), $this->isManga());
    }

    /** The label of a country code (also the Library filter's option names). */
    public static function originCountryName(?string $code, bool $manga = false): ?string
    {
        return match ($code) {
            'JP' => $manga ? 'Japanese manga' : 'Japanese',
            'KR' => $manga ? 'Korean manhwa' : 'Korean',
            'CN' => $manga ? 'Chinese manhua' : 'Chinese',
            'EN' => 'English original',
            default => null,
        };
    }

    /**
     * Novels whose originCountry() is $code ('JP' | 'KR' | 'CN' | 'EN'),
     * or is null when $code is null ("Unknown"). Every operand is wrapped
     * in COALESCE so a NULL column never turns the test into NULL (which
     * NOT would not flip): each row is in exactly one bucket.
     */
    public function scopeOriginCountry($query, ?string $code)
    {
        $grammar = $query->getQuery()->getGrammar();
        $col = fn(string $name) => $grammar->wrap($query->qualifyColumn($name));

        $shown = "COALESCE({$col('origin')}, 'unknown') <> 'unknown'"
            . " AND (COALESCE({$col('origin_source')}, '') <> 'inferred'"
            . " OR COALESCE({$col('origin_confidence')}, '') IN ('high', 'medium'))";

        if ($code === null) {
            $languages = implode(', ', array_map(fn($l) => "'{$l}'", array_keys(self::ORIGIN_COUNTRIES)));

            return $query->whereRaw("NOT (COALESCE({$col('origin_language')}, '') IN ({$languages}) AND {$shown})");
        }

        $language = array_search($code, self::ORIGIN_COUNTRIES, true);
        if ($language === false) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereRaw("COALESCE({$col('origin_language')}, '') = ? AND {$shown}", [$language]);
    }
}
