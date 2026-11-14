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
    ];

    /** Who set a novel's origin, weakest first (see applyOrigin). */
    public const ORIGIN_SOURCES = ['inferred' => 1, 'novelupdates' => 2, 'manual' => 3];

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
    ];

    public function chapters(): HasMany
    {
        return $this->hasMany(NovelChapter::class);
    }

    public function file(): MorphOne
    {
        // The newest upload is the cover; older rows are history.
        return $this->morphOne(File::class, "file")->latestOfMany();
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
        if ($translated === null) {
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
        if ($translated === null) {
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
     * set them (manual > novelupdates > inferred). Saves quietly (no
     * observers / timestamps churn) when the model exists. Returns whether
     * anything was written.
     *
     * @param array{origin: string, origin_language?: ?string, origin_type?: ?string} $attributes
     */
    public function applyOrigin(array $attributes, string $source): bool
    {
        $rank = self::ORIGIN_SOURCES[$source] ?? 0;
        $current = self::ORIGIN_SOURCES[$this->origin_source ?? ''] ?? 0;
        if ($rank === 0 || $current > $rank) {
            return false;
        }

        $values = [
            'origin' => $attributes['origin'],
            'origin_language' => $attributes['origin_language'] ?? null,
            'origin_source' => $source,
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
     * source host, the first three downloaded chapters and the TOC labels.
     * Never overrides a NovelUpdates or manual origin.
     *
     * @return array{origin: string, origin_language: ?string, reason: string}|null null when skipped
     */
    public function inferOrigin(): ?array
    {
        if ((self::ORIGIN_SOURCES[$this->origin_source ?? ''] ?? 0) > self::ORIGIN_SOURCES['inferred']) {
            return null;
        }

        $texts = [];
        $labels = [];
        if ($this->exists) {
            $texts = $this->chapters()
                ->where('status', 1)
                ->where('blacklist', 0)
                ->ordered()
                ->limit(3)
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
        );

        $this->applyOrigin($result, 'inferred');

        return $result;
    }
}
