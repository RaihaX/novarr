<?php

namespace App\Books;

use App\Mail\BookInboxReply;
use App\Services\Requests\RequestNotifier;
use App\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * "Send to Kindle", for Novarr: a reader emails ePubs to the books address
 * (config novarr.books.inbox.address, e.g. books@theedev.online). Cloudflare
 * Email Routing hands each email to a small Worker (cloudflare/books-inbox),
 * which posts the raw message here (App\Http\Controllers\Api\V1\BookInboxController).
 *
 *   - The sender must be a reader: the From address (or the envelope sender)
 *     is matched to an active account's email. Anyone else is refused, and
 *     the Worker bounces the email with the reason.
 *   - Every .epub attached (also inside a forwarded email) goes through
 *     BookUploads::receiveMany(), the same approval queue as the upload page;
 *     the subject is kept as the note.
 *   - The sender gets one reply listing what arrived and what did not.
 *
 * Automatic mail (bounces, out-of-office, mailing lists) is dropped without
 * a reply, so two robots never answer each other.
 */
class BookInbox
{
    public const ACCEPTED = 'accepted';

    public const UNKNOWN_SENDER = 'unknown_sender';

    public const IGNORED = 'ignored';

    /** Forwarded emails inside forwarded emails: how deep to look for attachments. */
    private const MAX_DEPTH = 3;

    public function __construct(private BookUploads $uploads)
    {
    }

    public static function address(): ?string
    {
        $address = strtolower(trim((string) config('novarr.books.inbox.address', '')));

        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : null;
    }

    public static function secret(): ?string
    {
        $secret = (string) config('novarr.books.inbox.secret', '');

        return strlen($secret) >= 32 ? $secret : null;
    }

    /** Ready to take email: an address and a long enough shared secret. */
    public static function enabled(): bool
    {
        return self::address() !== null && self::secret() !== null;
    }

    /**
     * @return array{status: string, message: string, sent?: array, refused?: array, user_id?: int}
     */
    public function receive(string $raw, ?string $envelopeFrom = null): array
    {
        $message = (new MailMimeParser())->parse($raw, false);

        if (self::isAutomatic($message)) {
            return ['status' => self::IGNORED, 'message' => 'Automatic email, not answered.'];
        }

        $from = self::fromAddress($message);
        $user = self::reader($from) ?? self::reader($envelopeFrom);
        if ($user === null) {
            Log::info('Books inbox: email from an unknown sender refused', ['from' => $from, 'envelope' => $envelopeFrom]);

            return [
                'status' => self::UNKNOWN_SENDER,
                'message' => 'Novarr only takes books from its readers. Send from the email address of your Novarr account.',
            ];
        }

        $dir = sys_get_temp_dir() . '/novarr-inbox-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);
        try {
            [$files, $skipped] = $this->attachments($message, $dir);
            $note = self::note($message);
            $result = $files === []
                ? ['sent' => [], 'refused' => []]
                : $this->uploads->receiveMany($user, $files, $note);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }

        $refused = array_merge($skipped, $result['refused']);
        $this->reply($user, $result['sent'], $refused);

        Log::info('Books inbox: email received', [
            'user' => $user->id,
            'sent' => count($result['sent']),
            'refused' => count($refused),
        ]);

        return [
            'status' => self::ACCEPTED,
            'message' => count($result['sent']) . ' received, ' . count($refused) . ' refused.',
            'user_id' => (int) $user->id,
            'sent' => array_map(fn($r) => ['request_id' => (int) $r->id, 'title' => (string) $r->title], $result['sent']),
            'refused' => array_map(fn($r) => array_intersect_key($r, array_flip(['file', 'message', 'code'])), $refused),
        ];
    }

    /** An active account whose email is this address. */
    private static function reader(?string $address): ?User
    {
        $address = strtolower(trim((string) $address));
        if ($address === '' || $address === self::address()) {
            return null;
        }
        $user = User::findBySignInEmail($address);

        return $user !== null && !$user->isDisabled() ? $user : null;
    }

    private static function fromAddress(IMessage $message): ?string
    {
        $header = $message->getHeader('From');

        return $header instanceof AddressHeader ? $header->getEmail() : null;
    }

    /** Bounces, auto-replies and list mail (RFC 3834, and the usual headers). */
    private static function isAutomatic(IMessage $message): bool
    {
        $auto = strtolower(trim((string) $message->getHeaderValue('Auto-Submitted', 'no')));
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        $precedence = strtolower(trim((string) $message->getHeaderValue('Precedence', '')));
        if (in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return true;
        }
        if ($message->getHeader('X-Autoreply') !== null || $message->getHeader('X-Autorespond') !== null || $message->getHeader('List-Id') !== null) {
            return true;
        }
        $from = strtolower((string) self::fromAddress($message));

        return $from === '' || str_starts_with($from, 'mailer-daemon@') || str_starts_with($from, 'postmaster@');
    }

    /** The subject, as the reader's note to the owner (forward prefixes dropped). */
    private static function note(IMessage $message): ?string
    {
        $subject = trim((string) $message->getSubject());
        $subject = trim((string) preg_replace('/^((re|fwd?|fw)\s*:\s*)+/i', '', $subject));

        return $subject === '' ? null : mb_substr($subject, 0, 500);
    }

    /**
     * The .epub attachments, saved to $dir.
     *
     * @return array{0: array<int, array{path: string, name: string}>, 1: array<int, array{file: string, message: string, code: string}>}
     */
    private function attachments(IMessage $message, string $dir, int $depth = 0): array
    {
        $files = [];
        $skipped = [];
        $maxMb = max(1, (int) config('novarr.books.max_upload_mb', 50));
        $maxFiles = max(1, (int) config('novarr.books.max_files_per_upload', 20));

        foreach ($message->getAllAttachmentParts() as $part) {
            /** @var IMessagePart $part */
            $type = strtolower($part->getContentType(''));
            if ($type === 'message/rfc822' && $depth < self::MAX_DEPTH) {
                $inner = $part->getContentStream();
                if ($inner !== null) {
                    [$more, $moreSkipped] = $this->attachments((new MailMimeParser())->parse((string) $inner, false), $dir, $depth + 1);
                    $files = array_merge($files, $more);
                    $skipped = array_merge($skipped, $moreSkipped);
                }
                continue;
            }

            $name = self::cleanName((string) $part->getFilename());
            $isEpub = str_ends_with(strtolower($name), '.epub') || $type === 'application/epub+zip';
            if (!$isEpub) {
                continue; // signatures, inline images, other files: not books, not worth a complaint
            }
            if ($name === '' || !str_ends_with(strtolower($name), '.epub')) {
                $name = ($name === '' ? 'book' : $name) . '.epub';
            }
            if (count($files) >= $maxFiles) {
                $skipped[] = ['file' => $name, 'message' => "Up to {$maxFiles} books per email: send this one again.", 'code' => 'too_many'];
                continue;
            }

            $path = $dir . '/' . count($files) . '.epub';
            $part->saveContent($path);
            $size = (int) @filesize($path);
            if ($size === 0) {
                $skipped[] = ['file' => $name, 'message' => 'The attachment was empty.', 'code' => 'unreadable'];
                @unlink($path);
                continue;
            }
            if ($size > $maxMb * 1024 * 1024) {
                $skipped[] = ['file' => $name, 'message' => "It is larger than {$maxMb} MB.", 'code' => 'too_large'];
                @unlink($path);
                continue;
            }
            $files[] = ['path' => $path, 'name' => $name];
        }

        return [$files, $skipped];
    }

    private static function cleanName(string $name): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], ['', '-', '-'], $name));

        return mb_substr($name, 0, 200);
    }

    /**
     * @param  \App\NovelRequest[]  $sent
     */
    private function reply(User $user, array $sent, array $refused): void
    {
        if (!RequestNotifier::hasEmail($user)) {
            return;
        }
        $forOwner = $user->isAdmin();
        $button = $forOwner && count($sent) === 1
            ? RequestNotifier::adminBase() . URL::route('books.review', $sent[0]->id, false)
            : ($forOwner ? RequestNotifier::requestsPageUrl() : RequestNotifier::readerBase() . '/requests');
        $cover = collect($sent)->pluck('cover_url')->filter()->first();
        // Covers are stored under the home address; the reader may be away from home.
        if (is_string($cover) && str_starts_with($cover, RequestNotifier::adminBase() . '/')) {
            $cover = substr($cover, strlen(RequestNotifier::adminBase()));
        }
        if (is_string($cover) && str_starts_with($cover, '/')) {
            $cover = RequestNotifier::readerBase() . $cover;
        }

        app(RequestNotifier::class)->sendMail((string) $user->email, new BookInboxReply(
            array_map(fn($r) => (string) $r->title, $sent),
            array_map(fn($r) => "{$r['file']}: {$r['message']}", $refused),
            $forOwner,
            $cover,
            $button,
        ));
    }
}
