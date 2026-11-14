<?php

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

if (!function_exists('setting')) {
    /**
     * Read a value from the DB-backed settings store, falling back to the
     * given default. Tolerant of the table not existing yet (fresh install
     * / mid-migration) so boot never breaks.
     */
    function setting(string $key, $default = null)
    {
        try {
            return \App\Setting::get($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('notify_webhook')) {
    /**
     * Send a short message to the configured notification webhook.
     * Discord webhooks get a JSON {content}; everything else (ntfy, generic)
     * gets the raw text body. No-op when no webhook is configured.
     */
    function notify_webhook(string $message): bool
    {
        $url = setting('notification_webhook_url', config('novarr.notification_webhook_url'));

        if (empty($url)) {
            return false;
        }

        try {
            $isDiscord = stripos($url, 'discord.com') !== false || stripos($url, 'discordapp.com') !== false;

            $options = $isDiscord
                ? ['headers' => ['Content-Type' => 'application/json'], 'json' => ['content' => $message]]
                : ['headers' => ['Content-Type' => 'text/plain'], 'body' => $message];

            HttpClient::create(['timeout' => 10])->request('POST', $url, $options)->getStatusCode();

            return true;
        } catch (\Throwable $e) {
            \Log::warning('notify_webhook failed: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Like fetchWithBrowser() but also returns the Cloudflare clearance cookie
 * and user-agent, so subsequent same-site pages can be fetched with a plain
 * (fast) HTTP client carrying the cf_clearance cookie instead of a full
 * browser render each time.
 *
 * @return array{html: string, cf_clearance: ?string, user_agent: ?string}|null
 */
function fetchWithBrowserSession($url)
{
    // Body lives in App\Scraping\Fetcher::htmlWithSession() so tests can
    // swap the network layer; null on failure as before.
    $session = app(\App\Scraping\Fetcher::class)->htmlWithSession((string) $url);

    return empty($session['html']) ? null : $session;
}

/**
 * Classify a decoded FlareSolverr response. Pure, so the status handling can
 * be unit tested without a live FlareSolverr.
 *
 * FlareSolverr answers "ok" even when the *target* site returned an error
 * page — the target's HTTP status is in solution.status — so a 404/5xx page
 * must not be handed to the parsers as if it were content.
 *
 * @return array{html: ?string, reason: ?string, retry: bool, status: ?int}
 */
function classifyFlareSolverrResponse($data): array
{
    if (!is_array($data)) {
        return ['html' => null, 'reason' => 'decode_failed', 'retry' => true, 'status' => null];
    }

    if (($data['status'] ?? null) !== 'ok') {
        return ['html' => null, 'reason' => 'flaresolverr_error', 'retry' => true, 'status' => null];
    }

    $status = isset($data['solution']['status']) ? (int) $data['solution']['status'] : null;
    if ($status !== null && $status >= 400) {
        if ($status >= 500) {
            // Transient on the origin's side — worth another attempt.
            return ['html' => null, 'reason' => 'http_5xx', 'retry' => true, 'status' => $status];
        }
        // 4xx won't change on retry.
        $reason = $status === 404 ? 'http_404' : 'http_4xx';
        return ['html' => null, 'reason' => $reason, 'retry' => false, 'status' => $status];
    }

    $html = $data['solution']['response'] ?? null;
    if (!is_string($html) || $html === '') {
        return ['html' => null, 'reason' => 'empty_response', 'retry' => true, 'status' => $status];
    }

    return ['html' => $html, 'reason' => null, 'retry' => false, 'status' => $status];
}

/**
 * Fetch page HTML using FlareSolverr to bypass Cloudflare protection.
 *
 * Returns null on failure; the optional by-ref $reason then says why:
 * 'decode_failed', 'flaresolverr_error', 'http_404', 'http_4xx', 'http_5xx',
 * 'empty_response' or 'exception'.
 */
function fetchWithBrowser($url, $waitForSelector = null, $maxAttempts = 3, ?string &$reason = null)
{
    // Body lives in App\Scraping\Fetcher::html() (injectable for tests).
    return app(\App\Scraping\Fetcher::class)->html((string) $url, $reason, $waitForSelector, (int) $maxAttempts);
}

/**
 * Create a configured HTTP client with browser-like headers
 * Used as fallback for sites that don't need headless browser
 */
function createHttpClient()
{
    // Built by App\Scraping\Fetcher::client(); scraper code should prefer
    // Fetcher::plain()/json() so tests can intercept the request.
    return app(\App\Scraping\Fetcher::class)->client();
}

/**
 * Download a cover image to storage/app/public/ with validation.
 * Returns [filename, original_basename] on success, null on failure.
 */
function downloadCoverImage($imageUrl, $novelId)
{
    if (empty($imageUrl)) {
        return null;
    }

    try {
        $httpClient = createHttpClient();
        $response = $httpClient->request('GET', $imageUrl);

        if ($response->getStatusCode() !== 200) {
            \Log::warning("downloadCoverImage non-200 status for {$imageUrl}: " . $response->getStatusCode());
            return null;
        }

        $bytes = $response->getContent(false);
    } catch (\Exception $e) {
        \Log::error("downloadCoverImage fetch failed for {$imageUrl}: " . $e->getMessage());
        return null;
    }

    if (empty($bytes)) {
        \Log::warning("downloadCoverImage empty response body from {$imageUrl}");
        return null;
    }

    // Detect HTML challenge / error pages early so we don't waste a temp file
    // and so the log line tells the operator what actually happened.
    $head = ltrim(substr($bytes, 0, 256));
    if (stripos($head, '<!doctype') === 0 || stripos($head, '<html') === 0) {
        \Log::warning("downloadCoverImage received HTML (likely Cloudflare challenge) from {$imageUrl}");
        return null;
    }

    // Validate via getimagesize on a temp file
    $tmp = tempnam(sys_get_temp_dir(), 'novelcover_');
    if ($tmp === false) {
        \Log::error("downloadCoverImage tempnam failed for {$imageUrl}");
        return null;
    }

    if (file_put_contents($tmp, $bytes) === false) {
        @unlink($tmp);
        \Log::error("downloadCoverImage failed writing temp file for {$imageUrl}");
        return null;
    }

    $info = @getimagesize($tmp);

    if (!$info) {
        @unlink($tmp);
        \Log::warning("downloadCoverImage invalid image data from {$imageUrl} (bytes: " . strlen($bytes) . ")");
        return null;
    }

    $extMap = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    $ext = $extMap[$info[2]] ?? null;

    if (!$ext) {
        @unlink($tmp);
        \Log::warning("downloadCoverImage unsupported image type {$info['mime']} from {$imageUrl}");
        return null;
    }

    $filename = md5($novelId . microtime(true)) . '.' . $ext;
    $destDir = storage_path('app/public/');
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        @unlink($tmp);
        \Log::error("downloadCoverImage could not create destination directory {$destDir}");
        return null;
    }

    $destPath = $destDir . $filename;

    // rename() fails across filesystems (tempnam often lives on tmpfs while storage/
    // is on the project disk), so fall back to copy. Don't silence the copy — we
    // need the error if it fails, otherwise we hand back a "success" for a file
    // that isn't actually on disk and the caller writes a dangling File row.
    if (!@rename($tmp, $destPath)) {
        if (!copy($tmp, $destPath)) {
            @unlink($tmp);
            \Log::error("downloadCoverImage failed to write cover to {$destPath} for {$imageUrl}");
            return null;
        }
        @unlink($tmp);
    }

    // Confirm the file actually landed before reporting success.
    if (!file_exists($destPath) || filesize($destPath) < 100) {
        \Log::error("downloadCoverImage post-write verification failed for {$destPath}");
        return null;
    }

    // Ensure web server (www-data) can read regardless of the umask of whoever runs the command.
    @chmod($destPath, 0644);

    return [
        'filename' => $filename,
        'basename' => basename(parse_url($imageUrl, PHP_URL_PATH) ?: $imageUrl),
    ];
}

/**
 * Resolve the URL a chapter is (or would be) scraped from. Shared by the
 * scraper and by the daily summary email so failure reports link to the
 * exact URL that is failing.
 */
function chapterSourceUrl($data)
{
    $novelUrl = preg_match("/^http/", $data->url ?? "")
        ? $data->url
        : ($data->novel->group->url ?? "") . $data->url;

    // Install-specific ids (config novarr.alternative_url_group_ids /
    // dotted_chapter_url_novel_ids; defaults [1, 3, 6] and [72]).
    if (
        !empty($data->novel->alternative_url) &&
        \App\Sources\InstallIds::contains(\App\Sources\InstallIds::alternativeUrlGroupIds(), $data->novel->group->id ?? 0)
    ) {
        $chapter =
            \App\Sources\InstallIds::contains(\App\Sources\InstallIds::dottedChapterUrlNovelIds(), $data->novel->id)
                ? str_replace(".", "-", $data->chapter)
                : floor($data->chapter);
        $novelUrl = $data->novel->alternative_url . $chapter;
    }

    return $novelUrl;
}

/**
 * Drop a leading "Chapter N…" heading paragraph from scraped content — many
 * sources embed the chapter title as the first body paragraph, which then
 * renders twice (the reader and ePub already print the label as a heading).
 * Conservative: only a short first paragraph is dropped, and a plain title
 * without a separator must repeat this chapter's own number.
 */
function stripLeadingChapterTitle(array $paragraphs, $chapterNumber = null, ?string $label = null): array
{
    $paragraphs = array_values($paragraphs);

    $labelTail = '';
    if ($label !== null) {
        // Tail = the label minus its leading "Chapter N:" / bare "N" prefix.
        $labelTail = chapterTitleKey(preg_replace('/^\s*(?:chapter\s*)?\d+(?:\.\d+)?\s*[:\-–—.]?\s*/iu', '', $label) ?? '');
        // A tail that still starts with a number/Chapter token is a second
        // numbering, not a title — the Chapter-N branch handles that.
        if (mb_strlen($labelTail) < 3 || preg_match('/^(chapter\b|\d)/iu', $labelTail)) {
            $labelTail = '';
        }
    }

    // Sources stack up to two title paragraphs (site numbering + translation
    // numbering) and sometimes glue the title straight onto the body text, so
    // peel iteratively (bounded — this is defensive, not expected depth).
    for ($round = 0; $round < 4 && !empty($paragraphs); $round++) {
        $first = $paragraphs[0];

        // Inner HTML with zero-width/no-break characters normalized — some
        // sources pepper headings with them ("Chapter‌ ‌2334:‌ ‌…").
        $inner = preg_replace('/^\s*<p\b[^>]*>|<\/p>\s*$/i', '', trim($first));
        $inner = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]|&nbsp;/u', ' ', $inner);

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($inner)));
        if ($text === '') {
            break;
        }

        // The paragraph is just the label's title tail ("Ren's Choice" for
        // label "Chapter 7: Ren's Choice") — the heading without its number.
        if ($labelTail !== '' && chapterTitleKey($text) === $labelTail) {
            array_shift($paragraphs);
            continue;
        }

        // Title glued onto the body, with or without its number:
        // "1600 Beast FarmThe morning…" (NovelArrow's <h4> fused into
        // paragraph 1). Plain-text paragraphs only (no inline tags).
        if ($labelTail !== '' && strip_tags($inner) === $inner) {
            $rest = stripGluedTitlePrefix(
                html_entity_decode($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $labelTail,
                $chapterNumber,
                $label
            );
            if ($rest !== null) {
                if ($rest === '') {
                    array_shift($paragraphs);
                } else {
                    $paragraphs[0] = '<p>' . htmlspecialchars($rest) . '</p>';
                }
                continue;
            }
        }

        if (!preg_match('/^Chapter\s*(\d+(?:\.\d+)?)\s*([:\-–—]|\s|$)/iu', $text, $m)) {
            break;
        }
        $hasSeparator = in_array(trim($m[2]), [':', '-', '–', '—'], true);

        // A separator alone doesn't make it OUR heading — "Chapter 3 - The
        // Heavenly Art" can be an in-story book heading inside chapter 120.
        // Require the number to be this chapter's (or one the label itself
        // carries, for site + translation double numbering), or the text to
        // closely match the label.
        $isOwnHeading = chapterHeadingIsOwn($text, (int) $m[1], $chapterNumber, $label);

        // Glued heading first: "Chapter 329: Title   Chapter 329: Title   Body…"
        // — the title prefix ends at a run of 2+ spaces (the site's own gap).
        // Trim just the prefix and keep the body; the loop takes care of a
        // doubled title on the next round. Must run before the whole-drop so
        // a short paragraph with body glued on doesn't lose its story text.
        if ($hasSeparator && $isOwnHeading && preg_match('/^\s*Chapter\s*\d+(?:\.\d+)?\s*[:\-–—][^<]{0,150}?\s{2,}(?=\S)/iu', $inner, $g)) {
            $rest = trim(substr($inner, strlen($g[0])));
            if (strlen(trim(strip_tags($rest))) >= 20) {
                $paragraphs[0] = '<p>' . $rest . '</p>';
                continue;
            }
        }

        // Single-space glue: no whitespace gap to cut at, but the chapter's
        // own label says what the title text is — cut where the label's
        // title tail ends. ("Chapter 16: Chapter 16 Trading_1 After Guo Shen
        // had expounded…" with label "Chapter 16 - 16 Trading_1".)
        if ($hasSeparator && $label !== null) {
            $tail = trim(preg_replace('/^\s*chapter[\s\d.:\-–—]*(?:chapter[\s\d.:\-–—]*)?/iu', '', $label));
            $words = preg_split('/[^A-Za-z0-9]+/', $tail, -1, PREG_SPLIT_NO_EMPTY);
            if (count($words) >= 2 || mb_strlen($tail) >= 8) {
                $pattern = implode('[^A-Za-z0-9<]{1,6}', array_map(fn($w) => preg_quote($w, '/'), $words));
                if ($pattern !== '' && preg_match('/^\s*Chapter[^<]{0,80}?' . $pattern . '[^A-Za-z0-9<]*/iu', $inner, $lm)) {
                    $rest = trim(substr($inner, strlen($lm[0])));
                    if (strlen(trim(strip_tags($rest))) >= 20) {
                        $paragraphs[0] = '<p>' . $rest . '</p>';
                        continue;
                    }
                }
            }
        }

        // Whole paragraph is just a heading — drop it.
        // "Chapter 6639: Helping a Friend" (separator = unambiguous), or a
        // plain "Chapter 1963 Rush to the Future!" that names this very
        // chapter and is title-length. The word caps keep a title glued to a
        // single-space body sentence from taking the story text with it.
        if (mb_strlen($text) <= 200) {
            $words = count(explode(' ', $text));
            $isPlainOwnTitle = $chapterNumber !== null
                && (int) $m[1] === (int) floor((float) $chapterNumber)
                && $words <= 15;
            if (($hasSeparator && $isOwnHeading && $words <= 20) || $isPlainOwnTitle) {
                array_shift($paragraphs);
                continue;
            }
        }

        break;
    }

    return array_values($paragraphs);
}

/**
 * Comparison key for chapter titles: lowercase, whitespace collapsed,
 * surrounding punctuation trimmed.
 */
function chapterTitleKey(string $text): string
{
    $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 'UTF-8');

    return trim(preg_replace('/^[\p{P}\s]+|[\p{P}\s]+$/u', '', $text) ?? $text);
}

/**
 * If $text starts with the chapter's title — optionally preceded by its own
 * number ("1600 Beast Farm…", "Chapter 1600: Beast Farm…") — return the text
 * after it ('' when nothing follows); null when there is no such prefix.
 * Case- and punctuation-insensitive. Without a number the title must be
 * glued straight onto the body ("Beast FarmThe morning") or stand alone, so
 * a story sentence that merely opens with the title words is left alone.
 */
function stripGluedTitlePrefix(string $text, string $labelTail, $chapterNumber, ?string $label): ?string
{
    $words = preg_split('/[^\p{L}\p{N}]+/u', $labelTail, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($words)) {
        return null;
    }
    $titlePattern = implode('[^\p{L}\p{N}]{0,6}', array_map(fn($w) => preg_quote($w, '/'), $words));

    $pattern = '/^\s*(?:(?:chapter\s*)?(\d+)(?:\.\d+)?\s*[:\-–—.]?\s*)?' . $titlePattern . '[^\p{L}\p{N}\s]*/iu';
    if (!preg_match($pattern, $text, $m)) {
        return null;
    }

    $rest = (string) substr($text, strlen($m[0]));
    $numbered = isset($m[1]) && $m[1] !== '';

    if ($numbered) {
        $n = (int) $m[1];
        $own = $chapterNumber !== null && $n === (int) floor((float) $chapterNumber);
        if (!$own && $label !== null && preg_match('/^\s*(?:chapter\s*)?(\d+)/iu', $label, $lm)) {
            $own = $n === (int) $lm[1];
        }
        if (!$own) {
            return null;
        }
        // Must end on a word boundary: "Beast Farmers" is not "Beast Farm".
        if ($rest !== '' && preg_match('/^\p{Ll}/u', $rest)) {
            return null;
        }
    } elseif ($rest !== '' && !preg_match('/^[\p{Lu}"“‘\'«]/u', $rest)) {
        // Unnumbered: only a glued title ("FarmThe…") or a bare title.
        return null;
    }

    return trim($rest);
}

/**
 * Is a "Chapter N…" heading paragraph this chapter's own title? True when
 * N is the chapter's number, N appears as a number in the chapter's label
 * (site + translation double numbering), or the text is a close (>= 80%)
 * match for the label. With neither a number nor a label to check against,
 * the heading is assumed to be ours (historic behaviour).
 */
function chapterHeadingIsOwn(string $text, int $number, $chapterNumber, ?string $label): bool
{
    if ($chapterNumber === null && ($label === null || trim($label) === '')) {
        return true;
    }

    if ($chapterNumber !== null && $number === (int) floor((float) $chapterNumber)) {
        return true;
    }

    if ($label !== null && trim($label) !== '') {
        // Only the label's LEADING numbers count ("Chapter 1205 - 1150 X"),
        // not a number inside its title ("Chapter 120: The 3 Masters").
        if (preg_match('/^\s*chapter\s*(\d+)(?:\.\d+)?(?:\s*[:\-–—]?\s*(?:chapter\s*)?(\d+)\b)?/iu', $label, $nums)) {
            if (in_array($number, array_map('intval', array_slice($nums, 1)), true)) {
                return true;
            }
        }

        similar_text(chapterTitleKey($text), chapterTitleKey($label), $percent);
        if ($percent >= 80) {
            return true;
        }
    }

    return false;
}

/**
 * Fetch and parse a chapter into paragraphs. $failureReason is an optional
 * out-param telling the caller *why* an empty result came back —
 * 'fetch_failed', 'not_found', 'cloudflare', 'rate_limited', 'no_content' or
 * 'exception' — so failures can be reported with their actual cause instead
 * of a generic "the source may have changed".
 */
function chapterGenerator($data, ?string &$failureReason = null)
{
    $failureReason = null;
    $novelUrl = chapterSourceUrl($data);
    $html = null;

    // The chapter's novel picks the source adapter (content selectors,
    // noise selectors, multi-page support) and owns any failure snapshot.
    $novel = null;
    $source = null;
    try {
        $novel = $data->novel ?? null;
        if ($novel instanceof \App\Novel) {
            $source = \App\Sources\SourceResolver::for($novel);
        }
    } catch (\Throwable $e) {
        \Log::debug("ChapterGenerator could not resolve the source for {$novelUrl}: " . $e->getMessage());
    }

    $snapshot = function (string $reason) use (&$html, $novel, $data) {
        if ($novel instanceof \App\Novel && is_string($html) && $html !== '') {
            \App\Scraping\FailureSnapshot::store($novel, $data instanceof \App\NovelChapter ? $data : null, $html, $reason);
        }
    };

    \Log::debug("ChapterGenerator attempting to fetch URL: {$novelUrl}");

    try {
        // Politeness delay to avoid rate limiting (config, [min, max] ms).
        [$minDelay, $maxDelay] = array_map('intval', array_pad((array) config('novarr.chapter_fetch_delay_ms', [500, 1500]), 2, 0));
        if ($maxDelay > 0) {
            usleep(1000 * rand(max(0, $minDelay), max($minDelay, $maxDelay)));
        }

        // API fast path (Source::chapterContent()): the source that claims
        // the URL is asked first, then every registered source in registry
        // order; the first non-null answer wins. NovelArrowSource answers
        // for any NovelPing chapter URL (by URL shape, whatever the novel's
        // source) straight from its JSON API — no browser fetch or HTML
        // scrape needed. Only an API answer of HTTP 429 ends the fetch here:
        // the host asked us to slow down, and fetching the same chapter
        // again through FlareSolverr would ignore that. Every other API
        // failure (403 from a WAF, 404, 5xx including a 503 challenge,
        // transport error, undecodable body, empty content) falls through
        // to the page fetch, whose result decides the reason.
        $apiFailure = null;
        $apiResult = [];
        $apiSource = null;
        foreach (\App\Sources\SourceResolver::fastPathCandidates($novelUrl) as $candidate) {
            $candidateFailure = null;
            $answer = $candidate->chapterContent($novelUrl, $candidateFailure);
            if ($answer !== null) {
                $apiResult = $answer;
                $apiFailure = $candidateFailure;
                $apiSource = $candidate->name();
                break;
            }
        }
        if (count($apiResult) > 0) {
            \Log::debug("ChapterGenerator fetched via {$apiSource} API: {$novelUrl} (paragraphs: " . count($apiResult) . ")");
            return stripLeadingChapterTitle($apiResult, $data->chapter ?? null, $data->label ?? null);
        }
        if ($apiFailure === 'rate_limited') {
            \Log::warning("ChapterGenerator: {$apiSource} API answered 429 for {$novelUrl}; not falling back to the page fetch");
            $failureReason = $apiFailure;
            return [];
        }
        if ($apiFailure !== null) {
            \Log::info("ChapterGenerator: {$apiSource} API failed for {$novelUrl} ({$apiFailure}); trying the page fetch");
        }

        // Fetch the page: a plain request with the host's cached Cloudflare
        // clearance when there is one, else FlareSolverr (see Fetcher::page()).
        $fetcher = app(\App\Scraping\Fetcher::class);
        $fetchReason = null;
        $fetchVia = null;
        $html = $fetcher->page($novelUrl, $fetchReason, $fetchVia);
        \Log::debug("ChapterGenerator fetched {$novelUrl} via " . ($fetchVia ?? 'unknown') . ($html === null ? ' (failed)' : ''));

        if ($html === null) {
            \Log::warning("ChapterGenerator failed to fetch URL: {$novelUrl} (" . ($fetchReason ?? 'unknown') . ")");
            // A 404 means the chapter page isn't there (yet) — reported
            // separately from transport/Cloudflare failures.
            $failureReason = match ($fetchReason) {
                'http_404' => 'not_found',
                'rate_limited' => 'rate_limited',
                default => 'fetch_failed',
            };
            return [];
        }

        if (\App\Scraping\Fetcher::isCloudflareChallenge($html)) {
            \Log::error("ChapterGenerator detected Cloudflare challenge page for URL: {$novelUrl}");
            $failureReason = 'cloudflare';
            $snapshot('cloudflare');
            return [];
        }

        $extract = function (string $page) use ($source, $novelUrl, $fetcher, $data): array {
            $paragraphs = \App\Scraping\ContentExtractor::make()->extractMultiPage($page, $source, $novelUrl, $fetcher);
            $paragraphs = array_filter($paragraphs, "strlen");

            return stripLeadingChapterTitle(array_values($paragraphs), $data->chapter ?? null, $data->label ?? null);
        };

        $result = $extract($html);

        // A plain (cached-clearance) fetch that parses to nothing or to a
        // thin page may have been served something other than the real
        // chapter. Retry this one chapter once through FlareSolverr (a
        // second request to the host for the same chapter) before reporting
        // it; if that yields a complete chapter, the plain path was being
        // fed different content, so the host's clearance is dropped.
        if ($fetchVia === 'plain' && !chapterParagraphsLookComplete($result)) {
            \Log::info("ChapterGenerator: plain fetch of {$novelUrl} gave " . (count($result) === 0 ? 'no' : 'short') . " content; retrying once via FlareSolverr");
            $retryReason = null;
            $retryHtml = $fetcher->html($novelUrl, $retryReason);
            if ($retryHtml !== null && !\App\Scraping\Fetcher::isCloudflareChallenge($retryHtml)) {
                $retryResult = $extract($retryHtml);
                if (chapterParagraphsLookComplete($retryResult)) {
                    \Log::warning("ChapterGenerator: FlareSolverr served a complete {$novelUrl} where the plain fetch did not; forgetting the host's clearance");
                    $fetcher->forgetClearance($novelUrl);
                }
                if (count($retryResult) > 0) {
                    $html = $retryHtml;
                    $result = $retryResult;
                }
            } else {
                \Log::info("ChapterGenerator: FlareSolverr retry of {$novelUrl} failed (" . ($retryReason ?? 'cloudflare') . "); keeping the plain result");
            }
        }

        // Fetched fine, parsed to nothing: the chapter body is empty on the
        // source or the layout changed. Anything non-empty is left for the
        // caller to judge on word count (but a thin page is snapshotted).
        if (count($result) === 0) {
            $failureReason = 'no_content';
            $snapshot('no_content');
        } elseif (!chapterParagraphsLookComplete($result)) {
            $snapshot('short_content');
        }

        return $result;
    } catch (\Throwable $e) {
        \Log::error("ChapterGenerator exception for URL {$novelUrl}: " . $e->getMessage());
        $failureReason = 'exception';
        $snapshot('exception');
        return [];
    }
}

/**
 * Words across a list of "<p>…</p>" paragraph strings.
 */
function chapterParagraphWords(array $paragraphs): int
{
    return str_word_count(strip_tags(implode(" ", $paragraphs)));
}

/**
 * Does an extracted paragraph list look like a whole chapter?
 *
 * Judged on words, not paragraph count: novelfull serves some chapters as
 * a handful of very long <p> blocks ("Divine Emperor of Death" ch. 209 is
 * 1,004 words in 8 paragraphs), and a ">10 paragraphs" rule discarded those
 * pages as "no content" on every run. Ten short paragraphs still count so
 * dialogue-heavy chapters pass too. Anything thinner is left for the
 * caller's word-count gate to judge.
 */
function chapterParagraphsLookComplete(array $paragraphs): bool
{
    return count($paragraphs) >= 10 || chapterParagraphWords($paragraphs) >= 150;
}

/**
 * Pull the chapter body out of a fetched page as a list of "<p>…</p>"
 * strings. Pure — no network, no DB — so it can be tested against saved
 * page fixtures. Returns [] when nothing plausible was found.
 *
 * Strategy: the br-separated #chr-content container first, then a list of
 * known per-site paragraph selectors in order. The first candidate that
 * looks complete (see chapterParagraphsLookComplete) wins; failing that,
 * the candidate with the most words is kept so a short-but-real page is
 * returned rather than nothing.
 */
function extractChapterParagraphs(string $html, string $urlForLog = ""): array
{
    // Source-less run of the v2 extractor (generic selectors, then the
    // readability-style scorer); see App\Scraping\ContentExtractor.
    return \App\Scraping\ContentExtractor::make()->extract($html, null, $urlForLog);
}

/**
 * Split a DOM subtree into paragraph inner-HTML strings (escaped text plus
 * bare emphasis tags). Pure.
 *
 * @return string[]
 */
function chapterNodeParagraphs(\DOMNode $root): array
{
    static $inline = ['em', 'i', 'strong', 'b'];
    // Block elements start a new paragraph; headings and list items too, so
    // a title (<h3>/<h4>) is never glued to the first line of the story.
    static $breaks = ['br', 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'ul', 'ol',
        'blockquote', 'section', 'article', 'hr', 'table', 'tr', 'td', 'th'];
    static $skip = ['script', 'style', 'noscript', 'iframe', 'template'];

    $paragraphs = [];
    $current = '';
    $open = []; // emphasis tags open at this point of the walk

    $flush = function () use (&$paragraphs, &$current, &$open) {
        // Close tags left open by a break inside emphasis ("<em>a<br>b</em>")
        // and reopen them in the next paragraph so both halves stay valid.
        $closing = '';
        foreach (array_reverse($open) as $tag) {
            $closing .= "</{$tag}>";
        }
        $paragraphs[] = $current . $closing;
        $current = '';
        foreach ($open as $tag) {
            $current .= "<{$tag}>";
        }
    };

    $walk = function (\DOMNode $node) use (&$walk, &$current, &$open, $flush, $inline, $breaks, $skip) {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $current .= htmlspecialchars(normalizeChapterText($child->nodeValue));
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue; // comments, processing instructions
            }

            $name = strtolower($child->nodeName);
            if (in_array($name, $skip, true)) {
                continue;
            }
            if ($name === 'br') {
                $flush();
                continue;
            }
            if (in_array($name, $breaks, true)) {
                $flush();
                $walk($child);
                $flush();
                continue;
            }
            if (in_array($name, $inline, true)) {
                $current .= "<{$name}>";
                $open[] = $name;
                $walk($child);
                array_pop($open);
                $current .= "</{$name}>";
                continue;
            }

            $walk($child); // any other element: text only
        }
    };

    $walk($root);
    $flush();

    $out = [];
    foreach ($paragraphs as $inner) {
        // Drop emphasis pairs left empty by a break at their edge.
        $previous = null;
        while ($previous !== $inner) {
            $previous = $inner;
            $inner = preg_replace('/<(em|i|strong|b)>(\s*)<\/\1>/', '$2', $inner);
        }
        if (trim(strip_tags($inner)) !== '') {
            $out[] = trim($inner);
        }
    }

    return $out;
}

/**
 * Turn one paragraph's inner HTML (escaped text + bare emphasis tags) into a
 * "<p>…</p>" string, or null when it is empty, spam or a watermark. When
 * cleanChapterParagraphText() trims a trailing watermark sentence the
 * paragraph is rebuilt from the cleaned plain text (emphasis is lost for
 * that one paragraph — rare, and better than keeping the watermark).
 */
function finalizeChapterParagraph(string $inner): ?string
{
    $plain = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($plain === '' || isChapterSpamLine($plain)) {
        return null;
    }

    $cleaned = cleanChapterParagraphText($plain);
    if ($cleaned === null || $cleaned === '') {
        return null;
    }

    if ($cleaned !== $plain) {
        return '<p>' . htmlspecialchars($cleaned) . '</p>';
    }

    return '<p>' . trim($inner) . '</p>';
}

/**
 * Unicode clean-up shared by every paragraph path: NFKC normalisation (when
 * ext-intl is available) folds full-width / stylised look-alikes back to
 * plain characters, and zero-width characters — a favourite way of hiding
 * watermarks from naive filters — are removed.
 */
function normalizeChapterText(string $text): string
{
    if (class_exists(\Normalizer::class)) {
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        if (is_string($normalized)) {
            $text = $normalized;
        }
    }

    return preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text) ?? $text;
}

/**
 * Fold text for watermark matching: lowercase, undo common leet/homoglyph
 * substitutions (0→o 1→l 3→e 4→a 5→s @→a) and drop whitespace and dots, so
 * "N 0 v e l B 1 n . c 0 m" folds to "novelblncom". The config patterns in
 * novarr.watermarks are written against this folded form.
 */
function foldWatermarkText(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, ['0' => 'o', '1' => 'l', '3' => 'e', '4' => 'a', '5' => 's', '@' => 'a']);

    return preg_replace('/[\s.]+/u', '', $text) ?? $text;
}

/**
 * The configured watermark regexes, compiled and validated once per config
 * value. Invalid patterns are logged at warning and skipped (rather than
 * silenced with @preg_match on every paragraph).
 *
 * @return array{sites: string[], phrases: string[], lines: string[]}
 */
function chapterWatermarkPatterns(): array
{
    static $cache = [];

    $config = (array) config('novarr.watermarks', []);
    $key = md5(serialize($config));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $compiled = ['sites' => [], 'phrases' => [], 'lines' => []];
    foreach (array_keys($compiled) as $group) {
        foreach ((array) ($config[$group] ?? []) as $pattern) {
            $pattern = (string) $pattern;
            $regex = match ($group) {
                // Site name immediately followed by its TLD, whole line, on
                // the folded text ("N0velB1n.c0m" → "novelblncom").
                'sites' => '/^(?:https?:\/\/)?(?:www)?(?:' . str_replace('/', '\/', $pattern) . ')(?:com|net|me|org)\/?$/iu',
                default => '/' . str_replace('/', '\/', $pattern) . '/iu',
            };
            if (@preg_match($regex, '') === false) {
                \Log::warning('Invalid novarr.watermarks pattern skipped', ['group' => $group, 'pattern' => $pattern, 'error' => preg_last_error_msg()]);
                continue;
            }
            $compiled[$group][] = $regex;
        }
    }

    return $cache[$key] = $compiled;
}

/**
 * Leet/homoglyph fold for domain tokens (dots kept): "n0velb1n.c0m" →
 * "novelbln.com".
 */
function foldLeet(string $text): string
{
    return strtr($text, ['0' => 'o', '1' => 'l', '3' => 'e', '4' => 'a', '5' => 's', '@' => 'a']);
}

/**
 * Does the text contain a literal web-domain token ("novelbin.com",
 * "n0velb1n.c0m", "www.site.me")? Leet-folding is applied to the token only.
 */
function containsDomainToken(string $text): bool
{
    return chapterDomainTokens($text) !== [];
}

/**
 * The web-domain tokens in a text, leet-folded ("n0velb1n.c0m" →
 * "novelbln.com"). Only tokens ending in .com/.net/.org/.me count.
 *
 * @return string[]
 */
function chapterDomainTokens(string $text): array
{
    if (!preg_match_all('/(?<![\p{L}\p{N}.@-])[\p{L}\p{N}@-]+(?:\.[\p{L}\p{N}@-]+)+/u', mb_strtolower($text, 'UTF-8'), $m)) {
        return [];
    }

    $tokens = [];
    foreach ($m[0] as $token) {
        $token = foldLeet($token);
        if (preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|me)$/', $token)) {
            $tokens[] = $token;
        }
    }

    return $tokens;
}

/**
 * Does (plain) text match one of the configured site watermarks?
 *
 * - a bare domain line ("novelfull.me", "https://www.site.com/");
 * - a whole line that is a known site name + TLD, matched on folded text
 *   (config `sites`), or a known site's domain token anywhere in the line —
 *   never a bare name, so "a novel full of wonders" survives;
 * - a watermark phrase ("read the latest…", "find … at", "visit …",
 *   "source of this content", "updated by") matched on the lowercased raw
 *   text AND carrying a literal domain token (config `phrases`) — so
 *   "Find them on the planet." survives;
 * - a whole-line phrase on folded text needing no domain (config `lines`).
 */
function isChapterWatermark(string $text): bool
{
    $bare = rtrim(foldLeet(mb_strtolower(trim($text), 'UTF-8')), " \t!?,;:.");
    if (preg_match('/^(?:https?:\/\/)?(?:www\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:me|com|net|org)\/?$/', $bare)) {
        return true;
    }

    $patterns = chapterWatermarkPatterns();

    $folded = foldWatermarkText($text);
    // Trailing punctuation would defeat end-anchored patterns.
    $folded = preg_replace('/[^\p{L}\p{N}\/]+$/u', '', $folded) ?? $folded;
    if ($folded !== '') {
        foreach (array_merge($patterns['sites'], $patterns['lines']) as $regex) {
            if (preg_match($regex, $folded) === 1) {
                return true;
            }
        }
    }

    $domains = chapterDomainTokens($text);

    // A known site's domain anywhere in the (short) line: "R e a d latest
    // at n0velb1n.c0m!". The site regex is anchored on the token itself.
    foreach ($domains as $domain) {
        $token = str_replace('.', '', preg_replace('/^www\./', '', $domain));
        foreach ($patterns['sites'] as $regex) {
            if (preg_match($regex, $token) === 1) {
                return true;
            }
        }
    }

    if (!empty($patterns['phrases']) && $domains !== []) {
        $lower = preg_replace('/\s+/u', ' ', mb_strtolower(trim($text), 'UTF-8'));
        foreach ($patterns['phrases'] as $regex) {
            if (preg_match($regex, $lower) === 1) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Clean one paragraph of plain (decoded, unescaped) chapter text. Returns
 * the cleaned text, or null when the whole paragraph should be dropped.
 *
 * - NFKC + zero-width stripping (see normalizeChapterText()).
 * - A short (< 200 chars) paragraph that is a site watermark ("Read the
 *   latest chapters at n0velb1n.c0m") is dropped.
 * - A watermark sentence glued onto the END of a story paragraph is cut
 *   off and the story text kept.
 *
 * Whitespace inside the text is preserved: stripLeadingChapterTitle() reads
 * the site's own multi-space gaps.
 */
function cleanChapterParagraphText(string $text): ?string
{
    $text = trim(normalizeChapterText($text));
    if ($text === '') {
        return null;
    }

    // Inline trailing watermark: the last sentence alone matches. Checked
    // first so "He nodded. Read the latest at novelbin.com" keeps "He nodded."
    $sentences = preg_split('/(?<=[.!?…"”\'’])\s+(?=\S)/u', $text);
    if (is_array($sentences) && count($sentences) > 1) {
        $last = end($sentences);
        if (mb_strlen($last) < 200 && isChapterWatermark($last)) {
            $kept = trim(mb_substr($text, 0, mb_strlen($text) - mb_strlen($last)));
            \Log::debug('Chapter watermark sentence stripped', ['sentence' => $last]);
            return $kept === '' ? null : cleanChapterParagraphText($kept);
        }
    }

    if (mb_strlen($text) < 200 && isChapterWatermark($text)) {
        \Log::debug('Chapter watermark paragraph dropped', ['text' => $text]);
        return null;
    }

    return $text;
}

/**
 * Strip noise nodes (<script>, <style>, ad / recommendation widgets) from a
 * chapter HTML fragment before paragraph extraction.
 */
function stripChapterNoise($html)
{
    // <script> and <style> — strip_tags() would otherwise turn their bodies into text.
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

    // Inline ad slots used by novelarrow et al.
    $html = preg_replace('/<div[^>]*data-format[^>]*>.*?<\/div>/is', '', $html);

    // Taboola / Outbrain / generic recommendation widget containers. These tend to
    // have id/class fragments like trc_rbox, taboola, outbrain, OUTBRAIN_, ulplugin,
    // recommend, sponsored.
    $widgetPattern = '/<(div|section|aside|iframe)\b[^>]*(?:id|class)\s*=\s*"[^"]*(taboola|outbrain|trc[_-]?rbox|ulplugin|recommend|sponsored|ad-slot|ads-wrapper|adv-box)[^"]*"[^>]*>.*?<\/\1>/is';
    $previous = null;
    while ($previous !== $html) {
        $previous = $html;
        $html = preg_replace($widgetPattern, '', $html);
    }

    return $html;
}

/**
 * Match a paragraph of text against known ad/recommendation widget signatures.
 * Used as a defence-in-depth filter after stripChapterNoise().
 *
 * Patterns are anchored / widget-specific on purpose: a bare substring test
 * for "Sponsored" silently dropped story lines like "Lin Feng sponsored the
 * young disciple." Each dropped line is logged at debug for auditing.
 */
function isChapterSpamLine($text)
{
    static $patterns = [
        '/^(Sponsored|Promoted)( Content)?$/i',  // widget heading on its own
        '/Read MoreUndo|Play NowUndo/',          // Taboola card text run-ons
        '/\b(taboola|outbrain)\b/i',
        '/pf-config-/',                          // widget config blob
        // novelfull's footer notice: "If you find any errors ( Ads popup, …),
        // Please let us know < report chapter > so we can fix it…"
        '/please let us know\s*<?\s*report chapter\s*>?/i',
        '/^if you find any errors\b.*\b(ads popup|broken links)\b/i',
        // Reader navigation that leaked into the body.
        '/^(?:[«‹<]\s*)?(?:prev(?:ious)?|next)\s+chapter(?:\s*[»›>])?$/i',
        // A bare domain-suffix fragment (".me", ".me😉", ".com") — the tail
        // of a split watermark, never story text.
        '/^\.(?:me|com|net|org|io|co|cc|xyz|info)\b[^\p{L}\p{N}]*$/u',
    ];

    $text = trim((string) $text);

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            \Log::debug('Chapter spam line dropped', ['pattern' => $pattern, 'text' => mb_substr($text, 0, 200)]);
            return true;
        }
    }

    // Leaked CSS rules — only when short enough to be a stray style block,
    // not a story paragraph that happens to quote it.
    if (mb_strlen($text) < 120 && stripos($text, '!important') !== false) {
        \Log::debug('Chapter spam line dropped', ['pattern' => '!important', 'text' => $text]);
        return true;
    }

    return false;
}

/**
 * Last-run partial flag for the TOC helpers. A source TOC walk that had to
 * stop early (a page failed even after a retry) still returns what it
 * parsed, and calls markTocRunPartial(); NovelScraper reads
 * tocRunWasPartial() after tableOfContentGenerator() to record the run as
 * partial in the novel's TOC health. tableOfContentGenerator() resets it.
 */
function tocRunPartialState(?bool $set = null): bool
{
    static $partial = false;
    if ($set !== null) {
        $partial = $set;
    }

    return $partial;
}

function markTocRunPartial(bool $partial = true): void
{
    tocRunPartialState($partial);
}

function tocRunWasPartial(): bool
{
    return tocRunPartialState();
}

/**
 * Last-run incremental flag, like the partial flag above: a paginated TOC
 * walk that stopped early because it reached a page whose chapters are all
 * stored already returns only the newest entries and calls
 * markTocRunIncremental(). NovelScraper then syncs those entries without
 * treating the short list as the whole TOC (no shrink check, no parking, no
 * fingerprint). tableOfContentGenerator() resets it.
 */
function tocRunIncrementalState(?bool $set = null): bool
{
    static $incremental = false;
    if ($set !== null) {
        $incremental = $set;
    }

    return $incremental;
}

function markTocRunIncremental(bool $incremental = true): void
{
    tocRunIncrementalState($incremental);
}

function tocRunWasIncremental(): bool
{
    return tocRunIncrementalState();
}

/**
 * The "stored chapter URLs" callback for the TOC run in progress (set by
 * tableOfContentGenerator() for the duration of one call). A source that can
 * walk incrementally (empireNovelToc) reads it; null means a full walk.
 */
function tocKnownUrlsResolver(?callable $set = null, bool $reset = false): ?callable
{
    static $resolver = null;
    if ($reset) {
        $resolver = null;
    } elseif ($set !== null) {
        $resolver = $set;
    }

    return $resolver;
}

/**
 * @param callable|null $knownUrls fn(): array<string, true> of the novel's
 *        stored chapter URLs; given, a paginated source may stop at the first
 *        page that holds nothing new (see tocRunWasIncremental()).
 */
function tableOfContentGenerator($data, ?callable $knownUrls = null)
{
    $result = [];
    markTocRunPartial(false);
    markTocRunIncremental(false);
    tocKnownUrlsResolver($knownUrls, reset: $knownUrls === null);
    \App\Scraping\FailureSnapshot::takeTocHtml(); // clear any stale note

    try {
        // Per-source TOC (see App\Sources). The resolver picks the adapter by
        // the novel's URL; NovelArrowSource is the default.
        $result = finalizeTocResult(
            \App\Sources\SourceResolver::for($data)->tableOfContents($data)
        );
    } catch (\Throwable $e) {
        \Log::error("tableOfContentGenerator error: " . $e->getMessage());
    } finally {
        tocKnownUrlsResolver(reset: true);
    }

    // A page was fetched but parsed to no chapters: keep it for diagnosis.
    $html = \App\Scraping\FailureSnapshot::takeTocHtml();
    if ($result === [] && $html !== null && $data instanceof \App\Novel) {
        \App\Scraping\FailureSnapshot::store($data, null, $html, 'empty_toc');
    }

    return $result;
}

/**
 * Drop null entries and backfill sequential chapter numbers when none of
 * the labels carried one.
 */
function finalizeTocResult(array $result): array
{
    $result = array_values(array_filter($result, fn($item) => $item !== null));

    $hasNumbers = array_reduce(
        $result,
        fn($carry, $item) => $carry || $item["chapter"] > 0,
        false
    );

    if (!$hasNumbers) {
        foreach ($result as $key => &$item) {
            $item["chapter"] = $key + 1;
        }
    }

    return $result;
}

/** Novel Arrow's canonical host (config novarr.novelarrow_host); see NovelArrowSource::canonicalHost(). */
function novelArrowHost(): string
{
    return \App\Sources\NovelArrowSource::canonicalHost();
}

/** "https://{canonical host}"; see NovelArrowSource::canonicalBase(). */
function novelArrowBase(): string
{
    return \App\Sources\NovelArrowSource::canonicalBase();
}

/** Both brand hosts plus the configured one; see NovelArrowSource::brandHosts(). */
function novelArrowHosts(): array
{
    return \App\Sources\NovelArrowSource::brandHosts();
}

/** Is the URL on Novel Arrow / NovelPing (with $legacy: novelbin too)? See NovelArrowSource::isSiteUrl(). */
function isNovelArrowUrl(?string $url, bool $legacy = false): bool
{
    return \App\Sources\NovelArrowSource::isSiteUrl($url, $legacy);
}

/** Cover image URL for a slug; see NovelArrowSource::coverUrlFor(). */
function novelArrowCoverUrl(string $slug): string
{
    return \App\Sources\NovelArrowSource::coverUrlFor($slug);
}

/** A slug as the API wants it; see NovelArrowSource::cleanSlug(). */
function novelArrowCleanSlug(string $slug): string
{
    return \App\Sources\NovelArrowSource::cleanSlug($slug);
}

/** The novel slug from any Novel Arrow / Novel Bin URL shape; see NovelArrowSource::slugFrom(). */
function novelArrowSlug(string $url): string
{
    return \App\Sources\NovelArrowSource::slugFrom($url);
}

/** Canonical chapter page URL; see NovelArrowSource::chapterPageUrl(). */
function novelArrowChapterUrl(string $slug, string $chapterId): string
{
    return \App\Sources\NovelArrowSource::chapterPageUrl($slug, $chapterId);
}

/** Canonical novel page URL; see NovelArrowSource::novelPageUrl(). */
function novelArrowNovelUrl(string $slug): string
{
    return \App\Sources\NovelArrowSource::novelPageUrl($slug);
}

/** GET an api-web endpoint (decoded JSON or null); see NovelArrowSource::api(). */
function novelArrowApi(string $path): ?array
{
    return \App\Sources\NovelArrowSource::api($path);
}

/** Complete chapter list via the JSON API; see NovelArrowSource::chapterArchive(). */
function novelArrowChapterArchive(string $novelUrl): array
{
    return \App\Sources\NovelArrowSource::chapterArchive($novelUrl);
}

/**
 * Chapter content for a Novel Arrow chapter page URL via the JSON API —
 * NovelArrowSource::chapterContent(), with "not an API chapter URL" (null
 * there) as [] and $failure null, as before.
 */
function novelArrowChapterContent(string $url, ?string &$failure = null): array
{
    return (new \App\Sources\NovelArrowSource())->chapterContent($url, $failure) ?? [];
}

/**
 * Full chapter list for an Empire Novel novel (incremental / partial walk);
 * see EmpireNovelSource::toc().
 *
 * @param callable|null $knownUrls fn(): array<string, true> of stored chapter URLs
 */
function empireNovelToc(string $novelUrl, ?callable $knownUrls = null): array
{
    return (new \App\Sources\EmpireNovelSource())->toc($novelUrl, $knownUrls);
}

/** Metadata from an Empire Novel novel page; see EmpireNovelSource::pageMetadata(). */
function getMetadataFromEmpireNovel(string $novelUrl): array
{
    return (new \App\Sources\EmpireNovelSource())->pageMetadata($novelUrl);
}

/** GET a novelfull AJAX URL (clearance first); see NovelFullSource::fetchAjax(). */
function novelFullFetchAjax(string $ajaxUrl, array $session, &$status = null): ?string
{
    return (new \App\Sources\NovelFullSource())->fetchAjax($ajaxUrl, $session, $status);
}

/** novelfull's chapter <option> list as TOC rows, or null; see NovelFullSource::parseChapterOptions(). */
function parseNovelFullChapterOptions(string $html, string $origin): ?array
{
    return \App\Sources\NovelFullSource::parseChapterOptions($html, $origin);
}

/** Full chapter list for a novelfull.com novel; see NovelFullSource::toc(). */
function novelFullToc(string $novelUrl): array
{
    return (new \App\Sources\NovelFullSource())->toc($novelUrl);
}

/**
 * Sanitise scraped synopsis HTML before it is stored or rendered raw
 * ({!! !!}). <script>/<style> bodies are removed outright, only simple
 * formatting tags survive, and every attribute is stripped (no on*=,
 * style=, href=javascript:). Returns '' for empty input.
 */
function sanitizeSynopsisHtml(?string $html): string
{
    if ($html === null || trim($html) === '') {
        return '';
    }

    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', '', $html) ?? '';
    // Unterminated <script>/<style>: drop everything after it.
    $html = preg_replace('/<(script|style)\b.*$/is', '', $html) ?? '';

    $html = strip_tags($html, '<p><br><em><strong><i><b><ul><ol><li>');

    // Remove all attributes, honouring quoted values that contain ">".
    $html = preg_replace('/<(\w+)(?:\s+(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?\s*\/?>/', '<$1>', $html) ?? '';

    return trim($html);
}

/** Metadata from a novelfull.com novel page; see NovelFullSource::pageMetadata(). */
function getMetadataFromNovelFull(string $novelUrl): array
{
    return (new \App\Sources\NovelFullSource())->pageMetadata($novelUrl);
}

/**
 * Resolve the NovelUpdates series URL for a title by querying their live
 * search (the endpoint their search box uses). Search also matches
 * associated names / aliases, so e.g. "Outside of Time" finds the "Beyond
 * the Timescape" series.
 *
 * Every series result is scored with NovelUpdatesMatcher; the URL is only
 * returned when the best one clears NovelUpdatesMatcher::THRESHOLD. When the
 * best is borderline on its title alone (0.6–0.85), or is the only hit (a
 * likely alias match), its series page is fetched once to re-score it on
 * associated names + author.
 *
 * $report receives ['score' => ?float (best score), 'candidates' => ranked
 * candidates with 'score', 'metadata' => ?array (the best candidate's series
 * page when it was fetched, so callers needn't fetch it again), 'failed' =>
 * ?string (why the search gave no real answer: the search request failed or
 * was a Cloudflare challenge, or the re-score page fetch failed; null when
 * the answer — possibly "no results" — is real)].
 */
function resolveNovelUpdatesUrl(string $name, ?string $author = null, ?array &$report = null): ?string
{
    $report = ['score' => null, 'candidates' => [], 'metadata' => null, 'failed' => null];
    $flareSolverrUrl = setting('flaresolverr_url', config('novarr.flaresolverr_url'));

    try {
        // NovelUpdates' search requires %20-encoded spaces (rawurlencode).
        $searchReason = null;
        $body = app(\App\Scraping\Fetcher::class)->post('https://www.novelupdates.com/wp-admin/admin-ajax.php', 'action=nd_ajaxsearchmain&strType=desktop&strOne=' . rawurlencode($name), $searchReason);
        // An empty 200 body is an answer (no results); anything else
        // without a body is a failed request.
        if ($body === null && $searchReason !== 'empty_response') {
            $report['failed'] = $searchReason ?? 'fetch_failed';
            \Log::warning("resolveNovelUpdatesUrl: NovelUpdates search failed for '{$name}' ({$report['failed']})");
            return null;
        }
        if (\App\Scraping\Fetcher::isCloudflareChallenge($body)) {
            $report['failed'] = 'cloudflare';
            \Log::warning("resolveNovelUpdatesUrl: Cloudflare challenge on the NovelUpdates search for '{$name}'");
            return null;
        }
        $candidates = \App\Scraping\NovelUpdatesMatcher::parseSearchResults((string) $body);
    } catch (\Throwable $e) {
        $report['failed'] = 'exception';
        \Log::warning("resolveNovelUpdatesUrl failed for '{$name}': " . $e->getMessage());
        return null;
    }

    if (empty($candidates)) {
        \Log::info("resolveNovelUpdatesUrl: no NovelUpdates search results for '{$name}'");
        return null;
    }

    $threshold = \App\Scraping\NovelUpdatesMatcher::threshold();
    $ranked = \App\Scraping\NovelUpdatesMatcher::rank($candidates, $name, $author);
    $best = $ranked[0];

    if ($best['score'] < $threshold && ($best['score'] >= 0.6 || count($ranked) === 1)) {
        $pageFailure = null;
        $page = fetchNovelUpdatesMetadata($best['url'], $pageFailure);
        // The candidate couldn't be re-scored: the answer is incomplete.
        $report['failed'] = $pageFailure;
        if (!empty($page['title']) || !empty($page['associated'])) {
            $best['associated'] = $page['associated'];
            $best['author'] = $page['author'] !== '' ? $page['author'] : null;
            $best['score'] = \App\Scraping\NovelUpdatesMatcher::score($name, $author, $best);
            $report['metadata'] = $page;
            // More names (and the author bonus) can only raise the score, so
            // it stays the top candidate.
            $ranked[0] = $best;
        }
    }

    $report['candidates'] = $ranked;
    $report['score'] = $best['score'];

    \Log::info("resolveNovelUpdatesUrl: top matches for '{$name}': " . implode('; ', array_map(
        fn($c) => sprintf('%s (%s) %.3f', $c['title'], $c['url'], $c['score']),
        array_slice($ranked, 0, 3)
    )));

    if ($best['score'] >= $threshold) {
        return $best['url'];
    }

    \Log::info(sprintf("resolveNovelUpdatesUrl: best match for '%s' scored %.3f (< %.2f); not using it", $name, $best['score'], $threshold));
    return null;
}

/** The NovelUpdates metadata shape with nothing found. */
function emptyNovelUpdatesMetadata(): array
{
    return [
        "description" => "", "author" => "", "no_of_chapters" => 0, "image" => "",
        "status_text" => "", "completed" => false, "fully_translated" => null, "genres" => [],
        "title" => "", "associated" => [],
        // Origin (see App\Scraping\OriginInference::fromNovelUpdates):
        // #showtype verbatim ("Web Novel (KR)") and #showlang ("Korean").
        "type" => "", "original_language" => "",
    ];
}

/**
 * Fetch and parse a single NovelUpdates series page into the metadata array.
 * Also returns the page's own 'title' and 'associated' names (used to score
 * the page against a local novel — see NovelUpdatesMatcher).
 *
 * $failure is null when there is a real answer — the page was fetched, or
 * NovelUpdates answered 404 (no such series) — else why there is none:
 * the fetch reason ('http_4xx', 'rate_limited', 'flaresolverr_error'…),
 * 'cloudflare' or 'exception'.
 */
function fetchNovelUpdatesMetadata(string $url, ?string &$failure = null): array
{
    $metadata = emptyNovelUpdatesMetadata();
    $failure = null;
    $fetched = false;

    try {
        // NovelUpdates sits behind Cloudflare — use FlareSolverr, falling back
        // to a direct request. Not after a 4xx: the page is missing (or
        // refused), and a direct GET would only get the same answer.
        $reason = null;
        $html = fetchWithBrowser($url, null, 3, $reason);
        if (empty($html)) {
            if (in_array($reason, ['http_404', 'http_4xx'], true)) {
                $failure = $reason === 'http_404' ? null : $reason;
                return $metadata;
            }
            $failure = $reason ?? 'fetch_failed'; // until the direct GET succeeds
            $html = createHttpClient()->request("GET", $url)->getContent();
            $failure = null;
        }
        $fetched = true;

        if (stripos($html, '<title>Just a moment...</title>') !== false ||
            stripos($html, 'Verifying you are human') !== false) {
            \Log::error("fetchNovelUpdatesMetadata: Cloudflare challenge for {$url}");
            $failure = 'cloudflare';
            return $metadata;
        }

        $crawler = new Crawler($html);

        $title = $crawler->filter(".seriestitlenu");
        $metadata["title"] = $title->count() > 0 ? trim($title->first()->text()) : "";

        // Associated names are <br>-separated inside #editassociated.
        $associated = $crawler->filter("#editassociated");
        if ($associated->count() > 0) {
            $metadata["associated"] = array_values(array_filter(array_map(
                fn($part) => trim(html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                preg_split('~<br\s*/?>|\n~i', $associated->first()->html()) ?: []
            ), 'strlen'));
        }

        $desc = $crawler->filter("#editdescription");
        $metadata["description"] = $desc->count() > 0 ? sanitizeSynopsisHtml($desc->first()->html()) : "";

        $author = $crawler->filter("#authtag");
        $metadata["author"] = $author->count() > 0 ? $author->first()->text() : "";

        $status = $crawler->filter("#editstatus");
        if ($status->count() > 0) {
            $status->each(function ($node) use (&$metadata) {
                $metadata["status_text"] = trim($node->text());
                $text = str_replace("Chapter ", "Chapters ", $node->text());
                preg_match("/(\d+) Chapters/", $text, $matches);
                $metadata["no_of_chapters"] = $matches[1] ?? 0;
            });
            $metadata["completed"] = stripos($metadata["status_text"], "complete") !== false;
        }

        $squash = fn(string $text) => trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $type = $crawler->filter("#showtype");
        if ($type->count() > 0) {
            $metadata["type"] = $squash($type->first()->text());
        }
        $language = $crawler->filter("#showlang");
        if ($language->count() > 0) {
            $metadata["original_language"] = $squash($language->first()->text());
        }

        $translated = $crawler->filter("#showtranslated");
        if ($translated->count() > 0) {
            $metadata["fully_translated"] = stripos(trim($translated->first()->text()), "yes") !== false;
        }

        $img = $crawler->filter(".seriesimg > img");
        $metadata["image"] = $img->count() > 0 ? $img->first()->attr("src") : "";

        $genres = $crawler->filter("#seriesgenre a.genre");
        if ($genres->count() > 0) {
            $metadata["genres"] = normalizeGenres($genres->each(fn($n) => $n->text()));
        }
    } catch (\Throwable $e) {
        // A parse error on a fetched page is still an answer; an error
        // before that (the direct GET threw) is a failed fetch.
        if (!$fetched) {
            $failure ??= 'exception';
        }
        \Log::error("fetchNovelUpdatesMetadata error for {$url}: " . $e->getMessage());
    }

    return $metadata;
}

/**
 * NovelUpdates metadata for a novel, with an identity check.
 *
 * 1. A saved novelupdates_url is used as is. If it has no match score yet
 *    (saved before scoring existed, or typed in by hand) the page is scored
 *    against the novel and the score stored.
 * 2. Otherwise the name-slug guess is tried; it is accepted (and its URL
 *    saved) only when the page's title/associated names score >= the
 *    threshold.
 * 3. Otherwise NovelUpdates' search is scored (resolveNovelUpdatesUrl); a
 *    confident match is saved with its score.
 *
 * Nothing confident: the slug page's metadata (possibly empty) is returned
 * as before, but only the best score is stored — never the URL — so
 * novel:verify-completion won't trust it.
 *
 * Steps 2 and 3 stamp novelupdates_checked_at once they have a real answer
 * (pages fetched and scored, a 404 slug, an empty search result) — not when
 * a fetch failed, hit a Cloudflare challenge or a rate limit, so an outage
 * isn't remembered as a 7-day "no match". While a novel has no saved URL
 * and was looked up within the last 7 days, they are skipped (negative
 * cache): empty metadata is returned and the source's own fills in.
 */
function getMetadata($data)
{
    $recheckDays = 7;
    $threshold = \App\Scraping\NovelUpdatesMatcher::threshold();
    $persist = function (array $attributes) use ($data) {
        if (($data->exists ?? false)) {
            $data->forceFill($attributes)->saveQuietly();
        }
    };

    // Origin (translated / original) from a page we trust as this novel —
    // never over a manual choice (Novel::applyOrigin enforces precedence).
    $origin = function (array $metadata) use ($data) {
        if (($data->exists ?? false) && $data instanceof \App\Novel
            && ($attributes = \App\Scraping\OriginInference::fromNovelUpdates($metadata))) {
            $data->applyOrigin($attributes, 'novelupdates');
        }
    };

    // 1. Explicit override saved on the novel.
    if (!empty($data->novelupdates_url)) {
        $metadata = fetchNovelUpdatesMetadata($data->novelupdates_url);
        if ($data->novelupdates_match_score === null && ($metadata["title"] !== "" || $metadata["associated"])) {
            $score = \App\Scraping\NovelUpdatesMatcher::score($data->name, $data->author, $metadata);
            $persist(["novelupdates_match_score" => $score]);
            \Log::info("getMetadata: scored saved NovelUpdates URL for '{$data->name}' ({$data->novelupdates_url}): {$score}");
        }
        if ($data->novelupdates_match_score !== null && (float) $data->novelupdates_match_score >= $threshold) {
            $origin($metadata);
        }
        return $metadata;
    }

    // Negative cache: a recent lookup found no confident match.
    $checkedAt = $data->novelupdates_checked_at ?? null;
    if ($checkedAt !== null
        && \Illuminate\Support\Carbon::parse($checkedAt)->gt(now()->subDays($recheckDays))) {
        \Log::info("getMetadata: no NovelUpdates match for '{$data->name}' on {$checkedAt}; not searching again for {$recheckDays} days");
        return emptyNovelUpdatesMetadata();
    }

    // 2. Direct slug guess.
    $url = "https://www.novelupdates.com/series/" . novelSlug($data->name) . "/";
    $slugFailure = null;
    $metadata = fetchNovelUpdatesMetadata($url, $slugFailure);
    $slugScore = null;

    if (!empty($metadata["description"])) {
        $slugScore = \App\Scraping\NovelUpdatesMatcher::score($data->name, $data->author, $metadata);
        if ($slugScore >= $threshold) {
            $persist(["novelupdates_url" => $url, "novelupdates_match_score" => $slugScore, "novelupdates_checked_at" => now()]);
            $origin($metadata);
            return $metadata;
        }
        \Log::info("getMetadata: slug page '{$url}' ('{$metadata["title"]}') scored {$slugScore} for '{$data->name}'; trying NovelUpdates search");
    } else {
        \Log::info("getMetadata: slug '{$url}' missed for '{$data->name}'; trying NovelUpdates search");
    }

    // 3. Resolve via search (handles aliases) and remember a confident match.
    $resolved = resolveNovelUpdatesUrl($data->name, $data->author, $report);
    if ($resolved) {
        $found = $report["metadata"] ?? fetchNovelUpdatesMetadata($resolved);
        if (!empty($found["description"])) {
            $persist(["novelupdates_url" => $resolved, "novelupdates_match_score" => $report["score"], "novelupdates_checked_at" => now()]);
            \Log::info("getMetadata: resolved '{$data->name}' -> {$resolved} (score {$report["score"]})");
            $origin($found);
            return $found;
        }
    }

    // The score of what is returned (the slug page when it had content,
    // else the best search hit) — below the threshold, so it isn't trusted.
    $score = $slugScore ?? ($report["score"] ?? null);
    if ($score !== null) {
        $persist(["novelupdates_match_score" => $score]);
    }

    // Negative cache only for a real "no match": a failed slug fetch or
    // search is tried again on the next run.
    $searchFailure = $report["failed"] ?? null;
    if ($slugFailure === null && $searchFailure === null) {
        $persist(["novelupdates_checked_at" => now()]);
    } else {
        \Log::info("getMetadata: NovelUpdates lookup for '{$data->name}' did not complete (" . ($slugFailure ?? $searchFailure) . "); not caching the miss");
    }

    return $metadata;
}

/**
 * Build a NovelUpdates/NovelArrow-style slug from a novel name: accents are
 * transliterated ("Rébirth: Café" -> rebirth-cafe), apostrophes and quotes
 * vanish ("The King's Avatar" -> the-kings-avatar), every other
 * non-alphanumeric run becomes a single dash.
 */
function novelSlug($name)
{
    // Transliterate first: "Rébirth" used to become "r-birth". Not
    // Str::slug() itself — it deletes inner punctuation ("Re:Zero" ->
    // "rezero") where NovelUpdates/NovelArrow slugs use a dash.
    $slug = strtolower(\Illuminate\Support\Str::ascii((string) $name));
    $slug = str_replace(["'", "\u{2019}", '"', "\u{201C}", "\u{201D}"], "", $slug);

    return trim(preg_replace("/[^a-z0-9]+/", "-", $slug), "-");
}

/**
 * Clean a list of scraped genre strings into Title Case, de-duplicated tag
 * names. Handles UPPERCASE (NovelArrow) and HTML entities (e.g. "Anime &amp;
 * Comics"), drops blanks, caps the count so a novel isn't buried in tags.
 */
function normalizeGenres(array $genres): array
{
    return collect($genres)
        ->map(fn($g) => trim(html_entity_decode($g, ENT_QUOTES)))
        ->filter()
        ->map(fn($g) => \Illuminate\Support\Str::title(mb_strtolower($g)))
        ->unique()
        ->take(12)
        ->values()
        ->all();
}

/** Novel Arrow's novel_status as a status_text, or null; see NovelArrowSource::statusText(). */
function novelArrowStatus($status): ?string
{
    return \App\Sources\NovelArrowSource::statusText($status);
}

/** Metadata from the novelping.com JSON API (fallback source); see NovelArrowSource::apiMetadata(). */
function getMetadataFromNovelArrow($data)
{
    return \App\Sources\NovelArrowSource::apiMetadata($data);
}

/**
 * Volume/book number from a LEADING label prefix: "Vol. 2 Chapter 3",
 * "Volume 2 …", "Book 2 …", "Vol.4 …". Null when the label doesn't start
 * with one — a "Book 2" inside a title is not a volume. Shared with
 * ChapterNumberResolver so the parser and self-heal agree.
 */
function parseVolumePrefix(string $label): ?int
{
    if (preg_match('/^\s*(?:vol(?:ume)?|book)\.?\s*(\d+)/iu', $label, $m)) {
        return (int) $m[1];
    }

    return null;
}

/**
 * Volume/book number from a chapter URL path: a whole segment like "v2",
 * "vol2", "vol-2", "volume-2", "book-2", or a LAST segment that starts with
 * or contains such a marker directly followed by the chapter
 * ("v2-chapter-3", "vol2-ch-3", "novel-volume-6-chapter-43"). A novel slug
 * that merely ends in "-book-1" doesn't count.
 */
function parseVolumeFromUrlPath(string $path): ?int
{
    $segments = array_values(array_filter(explode('/', strtolower($path)), 'strlen'));
    if (empty($segments)) {
        return null;
    }

    foreach ($segments as $segment) {
        if (preg_match('/^(?:v|vol|volume|book)-?(\d+)$/', $segment, $m)) {
            return (int) $m[1];
        }
    }

    $last = preg_replace('/\.(html?|php)$/', '', end($segments));
    if (preg_match('/(?:^|-)(?:v|vol|volume|book)-?(\d+)-ch(?:ap(?:ter)?)?\b/', $last, $m)) {
        return (int) $m[1];
    }

    return null;
}

/**
 * Encode a split/part number onto a whole chapter number for ordering.
 * The column is DOUBLE(8,2), so parts 1–9 become .1–.9 and parts 10–18
 * .91–.99 (still after .9 and unique); anything higher is clamped to .99.
 * (A plain "+N/100" would put part 10 on .10 = .1, colliding with part 1.)
 */
function encodeChapterPart(string $base, int $part): string
{
    if ($part <= 0) {
        return $base;
    }
    if ($part < 10) {
        return $base . '.' . $part;
    }

    return $base . '.' . (90 + min($part - 9, 9));
}

/**
 * Parse a TOC entry into [label, book, url, chapter], or null for teasers.
 *
 * A thin adapter over ChapterLabelParser (the single label parser): `book`
 * is the parsed volume, `chapter` the legacy encoded value
 * (ChapterLabel::legacyChapter(), as a string: "12", "12.5", part 2 of 12 →
 * "12.2"; "0" when no number), `label` the ORIGINAL text (trimmed,
 * whitespace collapsed, mb-safe, capped at 250 chars) for display.
 */
function generateTocChapterInfo($label, $url)
{
    $label = (string) $label;
    $url = (string) $url;

    if (\App\Scraping\ChapterLabelParser::isTeaser($label)) {
        return null;
    }

    $parsed = \App\Scraping\ChapterLabelParser::parse($label, $url);

    if ($parsed->number === null) {
        $chapter = '0';
    } elseif ($parsed->part > 0) {
        $chapter = encodeChapterPart((string) (int) floor($parsed->number), $parsed->part);
    } else {
        // Float → string drops a whole number's ".0" ("12") and keeps a
        // decimal ("374.5").
        $chapter = (string) $parsed->number;
    }

    return [
        "label" => mb_substr($parsed->sourceLabel, 0, 250), // keep the column bounded
        "book" => $parsed->volume,
        "url" => $url,
        "chapter" => $chapter,
    ];
}
