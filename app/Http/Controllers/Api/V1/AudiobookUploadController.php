<?php

namespace App\Http\Controllers\Api\V1;

use App\AudiobookUpload;
use App\Audiobooks\AudiobookUploads;
use App\Audiobooks\UploadRefused;
use App\Http\Controllers\Controller;
use App\Support\CurrentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The chunked, resumable audiobook upload API (App\Audiobooks\AudiobookUploads;
 * readers, by bearer token or the web session):
 *
 *   POST   /api/v1/audiobook-uploads                     {files:[{name,size,sha256?}], novel_id?, note?} → 201 {upload}
 *   PUT    /api/v1/audiobook-uploads/{id}/files/{index}/chunks/{n}   raw bytes (Content-Range optional) → 204
 *   GET    /api/v1/audiobook-uploads/{id}                → 200 {upload} (which chunks are in: resume)
 *   POST   /api/v1/audiobook-uploads/{id}/complete       {files?:[{index,sha256}]} → 200 {status:"pending", upload}
 *                                                          | 202 {status:"processing", upload} (poll GET)
 *                                                          | 422 {message, code, missing?, upload}
 *   DELETE /api/v1/audiobook-uploads/{id}                → 204 (cancelled, files deleted)
 *
 * Refusals are {message, code} with the status. A reader sees and changes
 * only their own uploads (anyone else's is 404); the owner may read any.
 */
class AudiobookUploadController extends Controller
{
    public function __construct(private AudiobookUploads $uploads)
    {
    }

    public function store(Request $request, CurrentReader $reader): JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        if ($reader->isGuest()) {
            return response()->json(['message' => 'Sign in to upload audiobooks.', 'code' => 'unauthenticated'], 401);
        }
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'array'],
            'files.*.name' => ['required', 'string', 'max:1000'],
            'files.*.size' => ['required', 'integer', 'min:1'],
            'files.*.sha256' => ['nullable', 'string', 'max:64'],
            'novel_id' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $upload = $this->uploads->create($reader->user(), array_values($data['files']), isset($data['novel_id']) ? (int) $data['novel_id'] : null, $data['note'] ?? null);
        } catch (UploadRefused $e) {
            return $e->toResponse();
        }

        return response()->json(['upload' => $this->present($upload)], 201);
    }

    public function show(int $id): JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        $upload = $this->find($id, true);

        return response()->json(['upload' => $this->present($upload)]);
    }

    public function chunk(Request $request, int $id, int $index, int $n): Response|JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        $upload = $this->find($id);
        try {
            $this->uploads->putChunk($upload, $index, $n, $request->getContent(true), $request->header('Content-Range'));
        } catch (UploadRefused $e) {
            return $e->toResponse();
        }

        return response()->noContent();
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        $upload = $this->find($id);
        $data = $request->validate([
            'files' => ['nullable', 'array'],
            'files.*.index' => ['required_with:files', 'integer', 'min:0'],
            'files.*.sha256' => ['nullable', 'string', 'max:64'],
        ]);
        $hashes = [];
        foreach ((array) ($data['files'] ?? []) as $file) {
            if (!empty($file['sha256'])) {
                $hashes[(int) $file['index']] = (string) $file['sha256'];
            }
        }

        try {
            $status = $this->uploads->complete($upload, $hashes);
        } catch (UploadRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode] + $e->extra + ['upload' => $this->present($upload->fresh() ?? $upload)], $e->status);
        }

        $fresh = AudiobookUpload::find($upload->id) ?? $upload;
        $body = ['status' => $status, 'upload' => $this->present($fresh)];

        return match ($status) {
            AudiobookUpload::STATUS_PENDING, AudiobookUpload::STATUS_APPROVED => response()->json($body),
            AudiobookUpload::STATUS_PROCESSING => response()->json($body, 202),
            // Not audio (failed), or a file arrived damaged (uploading again: send what `missing` names).
            default => response()->json([
                'message' => (string) ($fresh->error ?: 'The files could not be checked.'),
                'code' => $status === AudiobookUpload::STATUS_FAILED ? 'not_audio' : 'checksum',
                'missing' => $status === AudiobookUpload::STATUS_UPLOADING ? $this->missing($fresh) : null,
            ] + $body, 422),
        };
    }

    public function destroy(int $id): Response|JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        $upload = $this->find($id);
        try {
            $this->uploads->cancel($upload);
        } catch (UploadRefused $e) {
            return $e->toResponse();
        }

        return response()->noContent();
    }

    /** The reader's own upload (404 for anyone else's; the owner may read any with $ownerReads). */
    private function find(int $id, bool $ownerReads = false): AudiobookUpload
    {
        $upload = AudiobookUpload::find($id);
        $reader = app(CurrentReader::class);
        $mine = $upload !== null && !$reader->isGuest() && (int) $upload->user_id === (int) $reader->id();
        $owner = $ownerReads && $upload !== null && \Illuminate\Support\Facades\Gate::allows('admin');
        abort_unless($mine || $owner, 404, 'There is no such upload.');

        return $upload;
    }

    /** file index => chunks not yet in, as a JSON object ({"0": [3, 4]}) */
    private function missing(AudiobookUpload $upload): object
    {
        $out = [];
        foreach ($this->uploads->received($upload) as $index => $have) {
            $gap = array_values(array_diff(range(0, (int) $upload->files[$index]['chunks'] - 1), $have));
            if ($gap !== []) {
                $out[(string) $index] = $gap;
            }
        }

        return (object) $out;
    }

    /** The upload as the API shows it (the contract: docs/plans/audiobook-uploads.md). */
    public function present(AudiobookUpload $upload): array
    {
        return static::payload($upload, $this->uploads->received($upload));
    }

    /** @param array<int, list<int>> $received */
    public static function payload(AudiobookUpload $upload, array $received): array
    {
        $files = [];
        $have = 0;
        foreach ((array) $upload->files as $index => $file) {
            $chunks = $received[(int) $index] ?? [];
            foreach ($chunks as $n) {
                $have += AudiobookUploads::chunkLength($upload, (int) $index, (int) $n);
            }
            $files[] = [
                'index' => (int) $index,
                'name' => (string) $file['name'],
                'size' => (int) $file['size'],
                'chunks' => (int) $file['chunks'],
                'received' => $chunks,
                'done' => count($chunks) === (int) $file['chunks'],
            ];
        }
        $novel = $upload->novel_id ? \App\Novel::find($upload->novel_id, ['id', 'name', 'author']) : null;
        $probe = (array) ($upload->probe ?? []);

        return [
            'id' => (int) $upload->id,
            'status' => (string) $upload->status,
            'status_label' => $upload->statusLabel(),
            'title' => $upload->displayTitle(),
            'author' => $probe['author'] ?? null,
            'duration_ms' => isset($probe['duration_ms']) ? (int) $probe['duration_ms'] : null,
            'novel_id' => $upload->novel_id ? (int) $upload->novel_id : null,
            'novel' => $novel ? ['id' => (int) $novel->id, 'name' => (string) $novel->name, 'author' => $novel->author] : null,
            'note' => $upload->note,
            'chunk_size' => (int) $upload->chunk_size,
            'total_bytes' => (int) $upload->total_bytes,
            'received_bytes' => in_array($upload->status, [AudiobookUpload::STATUS_UPLOADING, AudiobookUpload::STATUS_PROCESSING], true) ? $have : (int) $upload->received_bytes,
            'files' => $files,
            'error' => $upload->error,
            'decline_reason' => $upload->decline_reason,
            'audiobook_id' => $upload->audiobook_id ? (int) $upload->audiobook_id : null,
            'created_at' => $upload->created_at?->toIso8601String(),
            'updated_at' => $upload->updated_at?->toIso8601String(),
        ];
    }

    public static function disabled(): ?JsonResponse
    {
        return AudiobookUploads::enabled() ? null : response()->json(['message' => 'Audiobook uploads are turned off on this server.', 'code' => 'uploads_off'], 404);
    }
}
