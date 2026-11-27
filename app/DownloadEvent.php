<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of the Downloads page's History (download_events). Written with
 * log(); never fails what it records.
 */
class DownloadEvent extends Model
{
    public const GRABBED = 'grabbed';
    public const AUTO_GRABBED = 'auto_grabbed';
    public const COMPLETED = 'completed';
    public const IMPORTED = 'imported';
    public const AUTO_APPROVED = 'auto_approved';
    public const FAILED = 'failed';
    public const BLOCKLISTED = 'blocklisted';
    public const NEEDS_CHOICE = 'needs_choice';
    public const SEARCHED = 'searched';

    public const LABELS = [
        self::GRABBED => 'Grabbed',
        self::AUTO_GRABBED => 'Grabbed automatically',
        self::COMPLETED => 'Download finished',
        self::IMPORTED => 'Imported',
        self::AUTO_APPROVED => 'Added automatically',
        self::FAILED => 'Failed',
        self::BLOCKLISTED => 'Blocklisted',
        self::NEEDS_CHOICE => 'Needs your choice',
        self::SEARCHED => 'Searched',
    ];

    public const UPDATED_AT = null;

    protected $table = 'download_events';

    protected $guarded = ['id'];

    protected $casts = ['grab_id' => 'integer', 'wanted_id' => 'integer'];

    public static function log(string $event, string $title, ?string $message = null, ?DownloadGrab $grab = null, ?DownloadWanted $wanted = null): void
    {
        try {
            static::create([
                'event' => $event,
                'kind' => $grab?->kind ?? $wanted?->kind,
                'grab_id' => $grab?->id,
                'wanted_id' => $wanted?->id ?? $grab?->wanted_id,
                'title' => mb_substr($title !== '' ? $title : '(untitled)', 0, 500),
                'message' => $message !== null ? mb_substr($message, 0, 1000) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Download history could not be written: ' . $e->getMessage());
        }
    }

    public function label(): string
    {
        return self::LABELS[$this->event] ?? ucfirst(str_replace('_', ' ', $this->event));
    }
}
