<?php

namespace App\Audiobooks;

use App\Services\Requests\RequestRefused;

/**
 * A refused audiobook-upload action (App\Audiobooks\AudiobookUploads): the
 * HTTP status, a sentence for the reader, a machine-readable code and any
 * extra fields. The API answers it as JSON {message, code, ...extra}.
 */
class UploadRefused extends RequestRefused
{
}
