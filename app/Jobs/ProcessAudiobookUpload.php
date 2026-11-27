<?php

namespace App\Jobs;

use App\AudiobookUpload;
use App\Audiobooks\AudiobookUploads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Checks a large audiobook upload after its last chunk (SHA-256 of every
 * file, ffprobe, cover, chapters: AudiobookUploads::process()), which can
 * take minutes for a few GB on the HDD — too long for the /complete call.
 * On the default queue (drained every minute by the scheduler); the client
 * polls GET /api/v1/audiobook-uploads/{id} meanwhile (status "processing").
 */
class ProcessAudiobookUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $uploadId)
    {
    }

    public function handle(AudiobookUploads $uploads): void
    {
        $upload = AudiobookUpload::find($this->uploadId);
        if ($upload !== null) {
            $uploads->process($upload);
        }
    }

    /** The job died (timeout, crash): the upload fails with the reason instead of staying "processing". */
    public function failed(?\Throwable $e): void
    {
        $upload = AudiobookUpload::find($this->uploadId);
        if ($upload !== null && $upload->status === AudiobookUpload::STATUS_PROCESSING) {
            app(AudiobookUploads::class)->fail($upload, 'The server could not check the files' . ($e ? ' (' . $e->getMessage() . ')' : '') . '. Try uploading again, or tell the owner.');
        }
    }
}
