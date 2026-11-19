<?php

namespace App\Services\Requests;

use Illuminate\Http\JsonResponse;

/**
 * A refused request-feature action: the HTTP status, a sentence for the
 * person, a machine-readable code, and any extra fields / headers.
 * The API answers it as JSON {message, code, ...extra}.
 */
class RequestRefused extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $errorCode,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra, $this->status, $this->headers);
    }
}
