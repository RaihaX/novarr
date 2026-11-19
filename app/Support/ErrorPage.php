<?php

namespace App\Support;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * resources/views/errors: the message an error page shows in place of its
 * own text — only one the app set itself (abort(404, 'No such chapter.')).
 * Framework defaults ("The route … could not be found.", "No query results
 * for model …", "CSRF token mismatch.", "Too Many Attempts.") and anything
 * that is not an HTTP exception (a 500's internals) are never shown.
 */
class ErrorPage
{
    public static function message(?\Throwable $exception): ?string
    {
        if (!$exception instanceof HttpExceptionInterface
            || $exception instanceof ThrottleRequestsException
            // Converted exceptions (model not found, token mismatch) carry the original.
            || $exception->getPrevious() !== null) {
            return null;
        }

        $message = trim($exception->getMessage());
        if ($message === '' || preg_match('/^The route .* could not be found\.$/s', $message)) {
            return null;
        }

        return $message;
    }
}
