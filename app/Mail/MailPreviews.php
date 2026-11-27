<?php

namespace App\Mail;

use App\Console\Commands\MeasureStorage;
use App\Console\Commands\SourceHealthCheck;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;

/**
 * Every email Novarr sends, built with sample data (nothing is read from or
 * written to the database, nothing is sent): the owner's /mail-preview
 * pages (App\Http\Controllers\MailPreviewController) and the tests that
 * check every mail renders in the shared frame.
 */
final class MailPreviews
{
    /** @return array<string, array{group: string, label: string, make: \Closure(): Mailable}> */
    public static function all(): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $p = fn(string $group, string $label, \Closure $make) => compact('group', 'label', 'make');

        return [
            // Readers' requests
            'request-created' => $p('Requests', 'New request (to the owner)', fn() => new RequestCreated(
                'Sam', 'Shadow Slave', 'web novel', self::cover('Shadow Slave', 250), "Heard it gets great after book two.\nThanks!", "{$base}/requests",
            )),
            'request-created-book' => $p('Requests', 'Book uploaded by a reader (to the owner)', fn() => new RequestCreated(
                'Sam', 'The Quiet Orchard', 'book', self::cover('The Quiet Orchard', 140), null, "{$base}/books/review/12",
            )),
            'request-created-wanted' => $p('Requests', 'Book asked for by title (to the owner)', fn() => new RequestCreated(
                'Sam', 'Mistborn: The Final Empire', 'book', self::cover('Mistborn', 20), null, "{$base}/books/review/14", true,
            )),
            'request-available' => $p('Requests', 'Ready to read (to the reader)', fn() => new RequestAvailable(
                'Sam', 'Shadow Slave', 'web novel', self::cover('Shadow Slave', 250), "{$base}/novels/3",
            )),
            'request-declined' => $p('Requests', 'Declined (to the reader)', fn() => new RequestDeclined(
                'Sam', 'Unwanted Saga', 'manga', self::cover('Unwanted Saga', 330), 'Not licensed in English yet.', "{$base}/requests",
            )),
            'book-inbox-one' => $p('Books inbox', 'One book received (reader)', fn() => new BookInboxReply(
                ['The Quiet Orchard'], [], false, self::cover('The Quiet Orchard', 140), "{$base}/requests",
            )),
            'book-inbox-many' => $p('Books inbox', 'Several files, one refused (owner)', fn() => new BookInboxReply(
                ['The Quiet Orchard', 'Northern Lights'], ['scan.pdf: Only ePub files can be added.'], true, null, "{$base}/requests",
            )),
            'book-inbox-none' => $p('Books inbox', 'Nothing added', fn() => new BookInboxReply(
                [], [], false, null, "{$base}/requests",
            )),

            // The owner's alerts
            'alert-added' => $p('Owner alerts', 'Downloads: added automatically', fn() => new AdminAlertMail(
                'Added automatically: Blightfall',
                'Added automatically: “Blightfall” by Ava Reid was downloaded and is in the library; the reader has been told.',
                "{$base}/novels/41",
                [
                    'eyebrow' => 'Downloads', 'heading' => 'Added automatically', 'tone' => 'success',
                    'intro' => '“Blightfall” was downloaded, matched the request, and is in the library. The reader who asked for it has been told.',
                    'cover' => self::cover('Blightfall', 10), 'title' => 'Blightfall', 'meta' => 'Book · Ava Reid', 'chip' => 'In the library',
                    'button' => 'Open the book', 'secondary' => ['text' => 'Open Downloads', 'url' => "{$base}/downloads"],
                ],
            )),
            'alert-downloaded' => $p('Owner alerts', 'Downloads: finished, check it', fn() => new AdminAlertMail(
                'Downloaded: The Quiet Orchard',
                'Your download of “The Quiet Orchard” finished. Downloaded — check it\'s the right book: review and add it.',
                "{$base}/books/review/12",
                [
                    'eyebrow' => 'Downloads', 'heading' => 'Download finished', 'tone' => 'warning',
                    'intro' => 'Your download of “The Quiet Orchard” finished, but it is not a clear match to the request. Check it is the right book, then add it to the library.',
                    'cover' => self::cover('The Quiet Orchard', 140), 'title' => 'The Quiet Orchard', 'meta' => 'Book · Lena Hart', 'chip' => 'Needs review',
                    'button' => 'Review and match', 'secondary' => ['text' => 'Open Downloads', 'url' => "{$base}/downloads"],
                ],
            )),
            'alert-choice' => $p('Owner alerts', 'Downloads: need your choice', fn() => new AdminAlertMail(
                'Downloads need your choice (3)',
                'Novarr found results but no clear match to grab by itself for “Dune”, “Hyperion” (audiobook), “Piranesi”. Pick one on the Downloads page.',
                "{$base}/downloads?tab=wanted",
                [
                    'eyebrow' => 'Downloads', 'heading' => '3 downloads need your choice', 'tone' => 'warning',
                    'intro' => 'Novarr found results but no clear match to grab by itself. Pick the right result for each on the Downloads page.',
                    'items' => [
                        ['title' => 'Dune', 'meta' => 'Book · Frank Herbert · 7 results', 'status' => 'Pick one', 'tone' => 'warning', 'cover' => self::cover('Dune', 35)],
                        ['title' => 'Hyperion', 'meta' => 'Audiobook · Dan Simmons · 2 results', 'status' => 'Pick one', 'tone' => 'warning', 'cover' => self::cover('Hyperion', 200)],
                        ['title' => 'Piranesi', 'meta' => 'Book · Susanna Clarke · 4 results', 'status' => 'Pick one', 'tone' => 'warning'],
                    ],
                    'button' => 'Pick a result',
                ],
            )),
            'alert-in-library' => $p('Owner alerts', 'Downloads: already in the library', fn() => new AdminAlertMail(
                'Already in the library: Northern Lights',
                '“Northern Lights” was already in the library, so the download was not needed: the request is marked ready.',
                "{$base}/novels/9",
                [
                    'eyebrow' => 'Downloads', 'heading' => 'Already in the library',
                    'intro' => '“Northern Lights” was already in the library, so the download was not needed. The request is marked ready and the reader can read it now.',
                    'cover' => self::cover('Northern Lights', 190), 'title' => 'Northern Lights', 'meta' => 'Book · Philip Pullman', 'chip' => 'Already here',
                    'button' => 'Open the book', 'secondary' => ['text' => 'Open Downloads', 'url' => "{$base}/downloads"],
                ],
            )),
            'alert-audiobook' => $p('Owner alerts', 'Audiobook asked for', fn() => new AdminAlertMail(
                'Audiobook asked for: The Final Empire',
                'Sam asked for the audiobook of “The Final Empire”. Novarr is looking for it.',
                "{$base}/downloads?tab=wanted",
                [
                    'eyebrow' => 'Audiobooks', 'heading' => 'Audiobook asked for',
                    'intro' => 'Sam asked for the audiobook of “The Final Empire”. Novarr is looking for it now, and again later until it finds one.',
                    'cover' => self::cover('The Final Empire', 20), 'title' => 'The Final Empire', 'title_url' => "{$base}/novels/7",
                    'meta' => 'Audiobook · Brandon Sanderson', 'chip' => 'Wanted', 'button' => 'Open Downloads',
                ],
            )),
            'alert-source' => $p('Owner alerts', 'Health: a source is failing', function () {
                $row = [
                    'key' => 'novelfull.test', 'name' => 'NovelFull',
                    'kinds' => [
                        'chapter' => ['day' => ['attempts' => 30, 'ok' => 3, 'rate' => 0.1]],
                        'toc' => ['day' => ['attempts' => 12, 'ok' => 9, 'rate' => 0.75]],
                        'search' => ['day' => ['attempts' => 0, 'ok' => 0, 'rate' => 0]],
                    ],
                    'top_reason' => ['label' => 'Not found (404)', 'count' => 41],
                ];
                [$subject, $message] = SourceHealthCheck::message($row);

                return new AdminAlertMail($subject, $message, rtrim((string) config('app.url'), '/') . '/health/sources', SourceHealthCheck::mailDetails($row));
            }),
            'alert-storage' => $p('Owner alerts', 'Health: disk space low', function () {
                $low = [
                    ['labels' => ['Library', 'Covers'], 'free' => 7.5 * 1024 ** 3, 'total' => 500 * 1024 ** 3, 'free_percent' => 1.5],
                ];
                [$subject, $message] = MeasureStorage::message($low);

                return new AdminAlertMail($subject, $message, rtrim((string) config('app.url'), '/') . '/health/storage', MeasureStorage::mailDetails($low));
            }),
            'alert-plain' => $p('Owner alerts', 'A plain alert (no details)', fn() => new AdminAlertMail(
                'Something to look at', 'A one-line message, as the webhook gets it.', "{$base}/",
            )),

            // Daily summary
            'summary-owner' => $p('Daily summary', "The owner's daily summary", fn() => new NewChapters(self::summary(false))),
            'summary-reader' => $p('Daily summary', "A reader's daily summary", fn() => new NewChapters(self::summary(true))),
            'summary-empty' => $p('Daily summary', 'Nothing new', fn() => new NewChapters([
                'since' => now()->subDay()->toIso8601String(), 'chapters' => [], 'completed' => [], 'attention' => [], 'novels' => [],
                'stats' => ['queued' => 0], 'summary_time' => '08:00',
            ])),

            // Accounts
            'invite' => $p('Accounts', 'Reader invite', fn() => new ReaderInvite(
                'Sam', "{$base}/invite/" . Str::random(40), now()->addDays(7), 'Reyhan', "{$base}/app/android",
            )),
            'sign-in-code' => $p('Accounts', 'Sign-in code', fn() => new SignInCode('Sam', '482913', 10, '203.0.113.24')),
            'new-sign-in' => $p('Accounts', 'New sign-in', fn() => new NewSignIn('Sam', now(), 'Safari on iPhone', '203.0.113.24', true)),

            // Other
            'kindle' => $p('Other', 'Send to Kindle / email (ePub attached)', fn() => new SendToKindle(
                self::sampleEpub(), 'Shadow Slave', 'Shadow Slave.epub', 'Guiltythree',
            )),
            'test' => $p('Other', 'Settings: test email', fn() => new TestMail()),
        ];
    }

    public static function make(string $name): ?Mailable
    {
        $entry = self::all()[$name] ?? null;

        return $entry !== null ? ($entry['make'])() : null;
    }

    /** The plain-text part of a mail, as it is sent. */
    public static function text(Mailable $mail): string
    {
        $mail->render(); // builds it (view data, subject)

        return $mail->textView !== null ? view($mail->textView, $mail->buildViewData())->render() : '';
    }

    /** A sample cover: a coloured SVG with the title, as a data URI (previews only). */
    public static function cover(string $title, int $hue): string
    {
        $words = array_slice(preg_split('/\s+/', $title) ?: [], 0, 3);
        $lines = '';
        foreach ($words as $i => $word) {
            $lines .= '<text x="12" y="' . (40 + $i * 18) . '" font-family="Georgia,serif" font-size="15" font-weight="700" fill="#fff">' . e($word) . '</text>';
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="192" viewBox="0 0 128 192">'
            . '<defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="hsl(' . $hue . ',55%,42%)"/><stop offset="1" stop-color="hsl(' . ($hue + 30) . ',60%,18%)"/></linearGradient></defs>'
            . '<rect width="128" height="192" fill="url(#g)"/>' . $lines
            . '<rect x="12" y="166" width="40" height="3" fill="#F0B429"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** A tiny stand-in ePub for the Kindle mail's attachment (never sent). */
    private static function sampleEpub(): string
    {
        $path = storage_path('framework/cache/mail-preview-sample.epub');
        if (!is_file($path)) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, 'Sample ePub for the mail preview.');
        }

        return $path;
    }

    /** A daily-summary payload with every section filled in. */
    private static function summary(bool $reader): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $novel = fn(int $id, string $name, int $count, float $first, ?int $percent, string $medium = 'novel', ?string $origin = null, int $notes = 0) => [
            'id' => $id, 'name' => $name, 'url' => "{$base}/novels/{$id}", 'source' => $medium === 'manga' ? 'mangadex.test' : 'novelfull.test',
            'count' => $count, 'notes' => $notes, 'first' => $first, 'last' => $first + $count - 1,
            'percent' => $percent, 'read_from' => $percent !== null ? $first : null,
            'read_from_url' => $percent !== null ? "{$base}/chapters/" . ($id * 100) : null,
            'medium' => $medium, 'origin' => $origin,
        ];

        $data = [
            'since' => now()->subDay()->toIso8601String(),
            'chapters' => [],
            'continue' => [
                'name' => 'Shadow Slave', 'author' => 'Guiltythree', 'novel_url' => "{$base}/novels/3",
                'chapter_label' => 'Chapter 2,214: The Long Night', 'index' => 2214, 'total' => 2390,
                'chapter_percent' => 40, 'resume' => true, 'url' => "{$base}/chapters/52214",
            ],
            'novels' => [
                $novel(3, 'Shadow Slave', 12, 2379, 92, 'novel', 'Translated (ZH)', 1),
                $novel(5, 'Lord of the Mysteries 2', 4, 310, 40, 'novel', 'Translated (ZH)'),
                $novel(8, 'The Wandering Inn', 2, 1201, null, 'novel', 'Original'),
                $novel(21, 'One Punch Scroll', 3, 188, 75, 'manga'),
            ],
            'completed' => [
                ['name' => 'Mother of Learning', 'url' => "{$base}/novels/11", 'completed_at' => now()->subHours(6)->toIso8601String(), 'epub_url' => "{$base}/novels/11/epub", 'kindle' => !$reader],
            ],
            'attention' => [
                ['id' => 14, 'name' => 'Gamma Broken', 'reason' => 'The chapter list could not be read for 3 days (TOC sync failed).', 'url' => 'https://novelfull.test/gamma'],
            ],
            'stalled' => [
                ['lane' => 'novelfull.test', 'label' => 'novelfull.test', 'queued' => 42, 'hours' => 7],
            ],
            'stats' => ['queued' => 57],
            'summary_time' => '08:00',
        ];
        if ($reader) {
            $data['reader'] = [
                'name' => 'Sam', 'home_url' => "{$base}/", 'account_url' => "{$base}/account",
                'unsubscribe_url' => "{$base}/summary/unsubscribe/sample",
            ];
        }

        return $data;
    }
}
