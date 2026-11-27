<?php

namespace App\Covers;

use App\Books\BookCovers;
use App\Books\BookLookup;
use App\File;
use App\Http\Helpers\CacheHelper;
use App\Novel;
use App\Services\CoverThumbnails;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AI covers for series without a real one ("Where there is no actual cover,
 * go over the storyline and generate a nice cover that suits the book"):
 *
 *   1. CoverBrief    the local LLM reads the description and the opening
 *                    chapters and writes a visual brief (or the genre
 *                    fallback when it cannot);
 *   2. CoverPainter  SDXL on the homelab GPU paints the artwork from it;
 *   3. CoverComposer the title and author are set on it (2:3, 1000×1500);
 *   4. save()        it becomes the series' cover (Novel::attachCover():
 *                    File row, thumbnails) with novels.cover_source
 *                    'generated', cover_generated_at and the brief
 *                    (cover_brief) kept so the owner can see why.
 *
 * Which series (eligibility()):
 *   - never one whose cover the owner chose (cover_source 'manual');
 *   - one already painted only when asked to repaint (--regenerate,
 *     "Regenerate cover");
 *   - only when the current cover is a placeholder: none, too small, a
 *     generated text card (Calibre / FanFicFare / FicHub title cards) or a
 *     blank page with a little text (BookCovers::current());
 *   - a book only after the online cover search had its chance
 *     (cover_checked_at set, books:covers found nothing), unless no book
 *     catalogue is switched on; a web novel or manga (no catalogue to ask)
 *     once it is new_series_days old, so its source's own cover comes first.
 * "Generate a cover" on the series page is the owner's own choice: it paints
 * whatever the current cover is ($force).
 *
 * Precedence: generated < catalogue < manual. A real catalogue cover found
 * later by books:covers replaces a generated one (it rechecks those every
 * BookCovers::GENERATED_RECHECK_DAYS), and so does a source cover found by a
 * metadata refresh (UpdateMetadata).
 *
 * Calibre: a generated cover is NOT pushed to Calibre (no PushBookToCalibre):
 * Calibre keeps its own cover, so the AI one stays a Novarr-only stand-in.
 *
 * A book moved to Novels by the web-novel check keeps its own cover file
 * for undo (WebNovelCheck::undo()): the AI cover is added beside it, its File
 * id kept in converted_snapshot.generated_cover, and undo removes it.
 */
class CoverGenerator
{
    /** Cache key prefix of "Generate a cover"'s progress (status()). */
    public const STATUS_KEY = 'ai_cover_status_v1_';

    public const STATE_QUEUED = 'queued';
    public const STATE_PAINTING = 'painting';
    public const STATE_DONE = 'done';
    public const STATE_FAILED = 'failed';

    public const REASON_REAL_COVER = 'it has a real cover';

    public function __construct(
        private BookCovers $covers,
        private CoverBrief $briefs,
        private CoverPainter $painter,
        private CoverComposer $composer,
    ) {
    }

    /** Set up: a painter to ask and GD to set the type. */
    public static function enabled(): bool
    {
        return CoverPainter::enabled() && CoverComposer::available();
    }

    /**
     * May this series get an AI cover now? (See the class comment.)
     * $explicit: named by id (no waiting for the online search or a new
     * series' source); $force: the owner asked on the series page (always).
     *
     * @return array{ok: bool, reason: string}
     */
    public function eligibility(Novel $novel, bool $regenerate = false, bool $explicit = false, bool $force = false): array
    {
        if ($force) {
            return ['ok' => true, 'reason' => 'the owner asked'];
        }
        if ($novel->cover_source === BookCovers::SOURCE_MANUAL) {
            return ['ok' => false, 'reason' => 'the owner chose this cover'];
        }
        if ($novel->cover_source === BookCovers::SOURCE_GENERATED) {
            return $regenerate
                ? ['ok' => true, 'reason' => 'repainting the AI cover']
                : ['ok' => false, 'reason' => 'it has an AI cover already'];
        }
        $current = $this->covers->current($novel);
        if (!$current['placeholder']) {
            return ['ok' => false, 'reason' => self::REASON_REAL_COVER];
        }
        if (!$explicit) {
            if ($novel->isBook() && $novel->cover_checked_at === null && BookLookup::sources() !== []) {
                return ['ok' => false, 'reason' => 'the online cover search has not looked yet'];
            }
            $days = max(0, (int) config('novarr.covers.ai.new_series_days', 2));
            if (!$novel->isBook() && $days > 0 && $novel->created_at !== null && $novel->created_at->gt(now()->subDays($days))) {
                return ['ok' => false, 'reason' => 'new: its source may still bring a cover'];
            }
        }

        return ['ok' => true, 'reason' => (string) ($current['reason'] ?? 'no cover')];
    }

    /**
     * Brief, paint, compose and save one cover.
     *
     * @return array{brief: array, record: array}
     *
     * @throws PainterBusy        the GPU stayed busy
     * @throws \RuntimeException  painting or saving failed
     */
    public function generate(Novel $novel): array
    {
        $built = $this->briefs->build($novel);
        $brief = $built['brief'];
        $previous = is_array($novel->cover_brief) ? $novel->cover_brief : [];
        // The first painting uses the series' own seed; each repaint the next.
        $variant = $novel->cover_source === BookCovers::SOURCE_GENERATED || $previous !== []
            ? (int) ($previous['variant'] ?? 0) + 1
            : 0;
        $prompt = CoverPainter::prompt($brief);
        $seed = CoverPainter::seed((int) $novel->id, $variant);

        $art = $this->painter->paint($prompt, $seed);
        $style = CoverComposer::styleFor($novel);
        $bytes = $this->composer->compose($art, (string) $novel->name, $novel->author, $style, 'jpg');

        $record = $brief + [
            'brief_source' => $built['source'],
            'model' => $built['model'],
            'brief_error' => $built['error'],
            'prompt' => $prompt,
            'seed' => $seed,
            'variant' => $variant,
            'layout' => $style,
            'painted_at' => now()->toIso8601String(),
        ];
        $this->save($novel, $bytes, $record);

        return ['brief' => $brief, 'record' => $record];
    }

    /**
     * Make the composed cover the series' (no Calibre push: see the class comment).
     *
     * @throws \RuntimeException
     */
    public function save(Novel $novel, string $bytes, array $record): void
    {
        $name = 'cover-' . $novel->id . '-ai-' . substr(sha1($bytes), 0, 16) . '.jpg';
        if (!Storage::disk('public')->put($name, $bytes)) {
            throw new \RuntimeException('the cover could not be stored');
        }
        try {
            if ($novel->wasConvertedFromBook() && is_array($novel->converted_snapshot)) {
                $this->addBeside($novel, $name);
            } else {
                $novel->attachCover($name, 'AI cover');
            }
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($name);
            throw new \RuntimeException('the cover could not be attached: ' . $e->getMessage(), 0, $e);
        }

        // No updated_at: a cover is no edit of the series.
        $values = [
            'cover_source' => BookCovers::SOURCE_GENERATED,
            'cover_generated_at' => now(),
            'cover_brief' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'cover_ai_checked_at' => now(),
        ];
        DB::table('novels')->where('id', $novel->id)->update($values);
        $novel->forceFill(['cover_source' => BookCovers::SOURCE_GENERATED, 'cover_generated_at' => $values['cover_generated_at'], 'cover_brief' => $record]);
        try {
            CacheHelper::clearNovelCache((int) $novel->id);
        } catch (\Throwable) {
        }
        Log::info("AI cover painted for #{$novel->id} {$novel->name}", ['brief' => $record['brief_source'] ?? null, 'seed' => $record['seed'] ?? null]);
    }

    /**
     * A converted book: the AI cover beside its own (kept for undo), the
     * previous AI cover removed.
     */
    private function addBeside(Novel $novel, string $name): void
    {
        $snapshot = $novel->converted_snapshot;
        $thumbnails = app(CoverThumbnails::class);
        if (!empty($snapshot['generated_cover']) && ($old = File::find((int) $snapshot['generated_cover'])) !== null) {
            $relative = CoverThumbnails::relative((string) $old->file_path);
            $thumbnails->delete($old->file_path);
            if ($relative !== '' && $relative !== $name) {
                Storage::disk('public')->delete($relative);
            }
            $old->delete();
        }
        $file = $novel->file()->save(new File(['file_name' => 'AI cover', 'file_path' => 'public/' . $name]));
        $thumbnails->generate($file->file_path);
        $novel->setRelation('file', $file);
        $snapshot['generated_cover'] = (int) $file->id;
        $novel->forceFill(['converted_snapshot' => $snapshot])->saveQuietly();
    }

    /** Remember that this series has a real cover (the nightly run skips it). */
    public static function markReal(Novel $novel): void
    {
        DB::table('novels')->where('id', $novel->id)->update(['cover_ai_checked_at' => now()]);
    }

    /**
     * The series `covers:generate` looks at (walked by id): the given ids;
     * else those not known to have a real cover; with $regenerate (and no
     * ids) the AI covers.
     */
    public static function candidates(array $ids, bool $regenerate)
    {
        $query = Novel::query()->with('file');
        if ($ids !== []) {
            return $query->whereIn('id', $ids);
        }
        if ($regenerate) {
            return $query->where('cover_source', BookCovers::SOURCE_GENERATED);
        }

        return $query
            ->where(fn($q) => $q->whereNull('cover_source')->orWhereNotIn('cover_source', [BookCovers::SOURCE_MANUAL, BookCovers::SOURCE_GENERATED]))
            ->whereNull('cover_ai_checked_at');
    }

    // --- "Generate a cover" progress (GenerateCover job) ---------------------

    public static function setStatus(int $novelId, string $state, string $message = ''): void
    {
        Cache::put(self::STATUS_KEY . $novelId, ['state' => $state, 'message' => $message, 'at' => now()->getTimestamp()], now()->addHours(2));
    }

    /** @return ?array{state: string, message: string, at: int} */
    public static function status(int $novelId): ?array
    {
        $status = Cache::get(self::STATUS_KEY . $novelId);

        return is_array($status) ? $status : null;
    }
}
