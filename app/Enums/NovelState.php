<?php

namespace App\Enums;

/**
 * The status vocabulary the UI renders, mapped onto the brand's status triad
 * (full-value text, 12% fill, 35% border — see resources/css/_variables.scss).
 *
 * One place decides which colour a state is, so views never hand-pick
 * `text-success` / `bg-*` / badge classes. Render it with
 * `<x-status :state="NovelState::Paused" />` (or `state="paused"`).
 *
 * Tones: success (green), pending (cyan), warning (amber), danger (red),
 * muted (grey) and reading (amber — reading signals only, never an action).
 */
enum NovelState: string
{
    case Downloaded = 'downloaded';
    case Queued = 'queued';
    case Attention = 'attention';
    case Failed = 'failed';
    case Paused = 'paused';
    case Completed = 'completed';
    case Reading = 'reading';
    // Not in the handoff's five canonical states, but the novel status badge
    // has always said ACTIVE; the handoff maps ACTIVE → success.
    case Active = 'active';

    /** Resolve an enum, a value string ('paused') or a case name ('Paused'). */
    public static function resolve(self|string|null $state): ?self
    {
        if ($state instanceof self || $state === null) {
            return $state;
        }

        $state = trim($state);

        return self::tryFrom(strtolower($state))
            ?? (defined(self::class . '::' . $state) ? constant(self::class . '::' . $state) : null);
    }

    /**
     * A novel's lifecycle state: completed wins, then paused, else active.
     * Attention is a health overlay and is passed in (it costs queries).
     */
    public static function forNovel(object $novel, bool $needsAttention = false): self
    {
        if (!empty($novel->status)) {
            return self::Completed;
        }
        if (!empty($novel->paused_at)) {
            return self::Paused;
        }

        return $needsAttention ? self::Attention : self::Active;
    }

    /** A chapter row's download state. */
    public static function forChapter(object $chapter): self
    {
        return !empty($chapter->status) ? self::Downloaded : self::Queued;
    }

    public function label(): string
    {
        return match ($this) {
            self::Attention => 'Needs attention',
            default => ucfirst($this->value),
        };
    }

    /** The triad tone this state is drawn in. */
    public function tone(): string
    {
        return match ($this) {
            self::Downloaded, self::Completed, self::Active => 'success',
            self::Queued => 'pending',
            self::Attention => 'warning',
            self::Failed => 'danger',
            self::Paused => 'muted',
            self::Reading => 'reading',
        };
    }

    /** Badge class (resources/css/_components.scss → .badge-*). */
    public function badgeClass(): string
    {
        return 'badge-' . $this->value;
    }

    /** Tinted panel / row class (resources/css/_status.scss → .panel-*). */
    public function panelClass(): string
    {
        return 'panel-' . $this->tone();
    }

    /** Solid bar fill (resources/css/_dashboard.scss → .bar-*). */
    public function barClass(): string
    {
        return 'bar-' . $this->tone();
    }

    /** Bootstrap .progress modifier (resources/css/_components.scss → .progress-*). */
    public function progressClass(): string
    {
        return 'progress-' . match ($this) {
            self::Completed, self::Active => 'downloaded',
            default => $this->value,
        };
    }

    /** Text-only colour (figures, inline words) (→ .tone-*). */
    public function textClass(): string
    {
        return 'tone-' . $this->tone();
    }
}
