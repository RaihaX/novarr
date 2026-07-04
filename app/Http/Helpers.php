<?php

use Spatie\Browsershot\Browsershot;
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
    $flareSolverrUrl = setting('flaresolverr_url', config('novarr.flaresolverr_url'));

    try {
        $response = HttpClient::create(['timeout' => 120])->request('POST', $flareSolverrUrl, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['cmd' => 'request.get', 'url' => $url, 'maxTimeout' => 60000],
        ]);
        // FlareSolverr embeds page HTML (with raw control chars) in the JSON;
        // decode leniently.
        $data = json_decode($response->getContent(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        if (($data['status'] ?? null) !== 'ok' || empty($data['solution']['response'])) {
            return null;
        }

        $cf = null;
        foreach ($data['solution']['cookies'] ?? [] as $cookie) {
            if (($cookie['name'] ?? '') === 'cf_clearance') {
                $cf = $cookie['value'];
                break;
            }
        }

        return [
            'html' => $data['solution']['response'],
            'cf_clearance' => $cf,
            'user_agent' => $data['solution']['userAgent'] ?? null,
        ];
    } catch (\Throwable $e) {
        \Log::error("fetchWithBrowserSession error for {$url}: " . $e->getMessage());
        return null;
    }
}

/**
 * Fetch page HTML using FlareSolverr to bypass Cloudflare protection
 */
function fetchWithBrowser($url, $waitForSelector = null, $maxAttempts = 3)
{
    $flareSolverrUrl = setting('flaresolverr_url', config('novarr.flaresolverr_url'));
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            \Log::debug("Fetching URL via FlareSolverr (attempt {$attempt}/{$maxAttempts}): {$url}");

            $httpClient = HttpClient::create(['timeout' => 120]);

            $response = $httpClient->request('POST', $flareSolverrUrl, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => [
                    'cmd' => 'request.get',
                    'url' => $url,
                    'maxTimeout' => 60000,
                ],
            ]);

            $data = json_decode($response->getContent(), true);

            if (($data['status'] ?? null) !== 'ok') {
                $lastError = $data['message'] ?? 'Unknown error';
            } else {
                $html = $data['solution']['response'] ?? null;

                if (!empty($html)) {
                    \Log::debug("Successfully fetched URL via FlareSolverr: {$url} (length: " . strlen($html) . ")");
                    return $html;
                }

                $lastError = 'empty response body';
            }
        } catch (\Exception $e) {
            $lastError = $e->getMessage();
        }

        if ($attempt < $maxAttempts) {
            $delay = 2 ** $attempt; // 2s, 4s
            \Log::warning("FlareSolverr attempt {$attempt} failed for {$url} ({$lastError}); retrying in {$delay}s");
            sleep($delay);
        }
    }

    \Log::error("FlareSolverr failed after {$maxAttempts} attempts for URL {$url}: {$lastError}");
    return null;
}

/**
 * Create a configured HTTP client with browser-like headers
 * Used as fallback for sites that don't need headless browser
 */
function createHttpClient()
{
    return HttpClient::create([
        'timeout' => 30,
        'verify_peer' => false,
        'verify_host' => false,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'Cache-Control' => 'max-age=0',
        ],
    ]);
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

    if (
        !empty($data->novel->alternative_url) &&
        in_array($data->novel->group->id ?? 0, [1, 3, 6])
    ) {
        $chapter =
            $data->novel->id == 72
                ? str_replace(".", "-", $data->chapter)
                : floor($data->chapter);
        $novelUrl = $data->novel->alternative_url . $chapter;
    }

    return $novelUrl;
}

function chapterGenerator($data)
{
    $novelUrl = chapterSourceUrl($data);

    \Log::debug("ChapterGenerator attempting to fetch URL: {$novelUrl}");

    try {
        // Add delay to avoid rate limiting
        usleep(rand(500000, 1500000)); // Random delay between 0.5-1.5 seconds

        // Novel Arrow chapters come straight from the JSON API — no browser
        // fetch or HTML scrape needed. Falls through on failure.
        $apiResult = novelArrowChapterContent($novelUrl);
        if (count($apiResult) > 0) {
            \Log::debug("ChapterGenerator fetched via Novel Arrow API: {$novelUrl} (paragraphs: " . count($apiResult) . ")");
            return $apiResult;
        }

        // Fetch page using headless browser (bypasses Cloudflare)
        // Don't wait for specific selector - let the page load fully
        $html = fetchWithBrowser($novelUrl);

        if ($html === null) {
            \Log::warning("ChapterGenerator failed to fetch URL: {$novelUrl}");
            return [];
        }

        // Check for Cloudflare challenge page (not just any mention of cloudflare)
        // Look for specific challenge indicators in the page title/body
        if (stripos($html, '<title>Just a moment...</title>') !== false ||
            stripos($html, 'cf-challenge-running') !== false ||
            stripos($html, 'Verifying you are human') !== false) {
            \Log::error("ChapterGenerator detected Cloudflare challenge page for URL: {$novelUrl}");
            return [];
        }

        $crawler = new Crawler($html);
        $result = [];

        // First, try to get content from #chr-content which may have br-separated text
        try {
            $chrContent = $crawler->filter('#chr-content');
            if ($chrContent->count() > 0) {
                // Get the inner HTML and split by <br> tags
                $innerHtml = $chrContent->html();

                // Strip non-content nodes before extracting text. <style> matters here
                // because strip_tags() would otherwise turn CSS rules into a paragraph.
                $innerHtml = stripChapterNoise($innerHtml);

                // Split by br tags (various formats)
                $paragraphs = preg_split('/<br\s*\/?>/i', $innerHtml);

                foreach ($paragraphs as $para) {
                    $text = trim(strip_tags($para));
                    if (strlen($text) <= 10) { // Skip very short fragments
                        continue;
                    }
                    if (isChapterSpamLine($text)) {
                        continue;
                    }
                    $result[] = "<p>" . htmlspecialchars($text) . "</p>";
                }

                if (count($result) > 10) {
                    \Log::debug("ChapterGenerator found content using #chr-content br-split (paragraphs: " . count($result) . ")");
                }
            }
        } catch (\Exception $e) {
            \Log::debug("Failed to extract from #chr-content: " . $e->getMessage());
        }

        // If br-split didn't work, try traditional p-tag selectors
        if (count($result) < 10) {
            $selectors = [
                "#chr-content p",
                ".chr-c p",
                ".chapter-content p",
                ".entry-content p",
                "#chapter-content p",
                ".reader-page p",   // Empire Novel
                "article p",
                ".text p",
                "#content p",
            ];

            foreach ($selectors as $selector) {
                $tempResult = [];
                try {
                    $crawler->filter($selector)->each(function ($node) use (&$tempResult) {
                        extractTextRecursively($node, $tempResult);
                    });
                } catch (\Exception $e) {
                    continue;
                }

                if (count($tempResult) > 10) {
                    \Log::debug("ChapterGenerator found content using selector: {$selector} (paragraphs: " . count($tempResult) . ")");
                    $result = $tempResult;
                    break;
                }
            }
        }

        if (count($result) < 10) {
            // The page fetched fine but no selector matched enough content —
            // the strongest signal that the site changed its markup. Error
            // level so the operator actually sees it, with enough context to
            // diagnose without re-fetching.
            \Log::error(
                "ChapterGenerator found insufficient content for URL: {$novelUrl} "
                . "(paragraphs: " . count($result) . ", html length: " . strlen($html) . "). "
                . "Site markup may have changed. First 300 chars of body: "
                . substr(trim(strip_tags($html)), 0, 300)
            );
        }

        $result = array_filter($result, "strlen");

        return $result;
    } catch (\Exception $e) {
        \Log::error("ChapterGenerator exception for URL {$novelUrl}: " . $e->getMessage());
        return [];
    }
}

function extractTextRecursively($node, &$result)
{
    $text = trim($node->text());
    if ($text != "" && !isChapterSpamLine($text)) {
        $result[] = "<p>" . htmlspecialchars($text) . "</p>"; // Ensure text is properly escaped
    }

    // Check if the node has children that are paragraphs and recurse
    $node->children()->each(function ($child) use (&$result) {
        if ($child->nodeName() == "p" || $child->nodeName() == "div") {
            extractTextRecursively($child, $result);
        }
    });
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
 */
function isChapterSpamLine($text)
{
    static $markers = [
        'Sponsored',
        'Read MoreUndo',
        'Play NowUndo',
        'taboola',
        'Outbrain',
        'pf-config-',
        '!important',
    ];

    foreach ($markers as $marker) {
        if (stripos($text, $marker) !== false) {
            return true;
        }
    }

    return false;
}

function tableOfContentGenerator($data)
{
    $result = [];

    try {
        // Per-source TOC (see App\Sources). The resolver picks the adapter by
        // the novel's URL; NovelArrowSource is the default.
        $result = finalizeTocResult(
            \App\Sources\SourceResolver::for($data)->tableOfContents($data)
        );
    } catch (\Exception $e) {
        \Log::error("tableOfContentGenerator error: " . $e->getMessage());
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

/**
 * Extract the novel slug from any Novel Arrow URL shape — a novel page
 * (…/novel/slug), a chapter page (…/chapter/slug/chapter-…) — or a legacy
 * Novel Bin shape (…/novel-book/slug, …/b/slug,
 * …/ajax/chapter-archive?novelId=slug). Slugs are identical across the
 * rebrand, so legacy URLs still resolve.
 */
function novelArrowSlug(string $url): string
{
    parse_str(parse_url($url, PHP_URL_QUERY) ?: "", $query);

    if (!empty($query["novelId"])) {
        return $query["novelId"];
    }

    $path = trim(parse_url($url, PHP_URL_PATH) ?: "", "/");
    $parts = $path === "" ? [] : explode("/", $path);

    // Chapter pages carry the slug one segment before the chapter id.
    if (count($parts) >= 2 && $parts[0] === "chapter") {
        return $parts[1];
    }

    return $parts === [] ? "" : end($parts);
}

/**
 * GET a novelarrow.com api-web endpoint and return the decoded JSON,
 * or null on any failure (logged).
 */
function novelArrowApi(string $path): ?array
{
    $url = "https://novelarrow.com/api-web/" . ltrim($path, "/");

    try {
        $response = createHttpClient()->request("GET", $url, [
            "headers" => ["Accept" => "application/json"],
        ]);

        if ($response->getStatusCode() !== 200) {
            \Log::warning("novelArrowApi HTTP " . $response->getStatusCode() . " for {$url}");
            return null;
        }

        $json = json_decode($response->getContent(false), true);

        return is_array($json) ? $json : null;
    } catch (\Throwable $e) {
        \Log::error("novelArrowApi error for {$url}: " . $e->getMessage());
        return null;
    }
}

/**
 * Fetch the complete chapter list for a Novel Arrow novel via its JSON API
 * (the novel page itself only embeds ~30 chapters).
 */
function novelArrowChapterArchive(string $novelUrl): array
{
    $slug = novelArrowSlug($novelUrl);

    if ($slug === "") {
        return [];
    }

    $json = novelArrowApi("novels/" . rawurlencode($slug) . "/chapters?sort=asc");

    $result = [];
    foreach (($json["items"] ?? []) as $item) {
        $chapterId = trim($item["chapter_id"] ?? "");
        $label = trim(preg_replace('/\s+/', " ", $item["chapter_name"] ?? ""));

        if ($chapterId !== "" && $label !== "") {
            $result[] = generateTocChapterInfo(
                $label,
                "https://novelarrow.com/chapter/{$slug}/{$chapterId}"
            );
        }
    }

    $result = array_values(array_filter($result));
    \Log::info("novelArrowChapterArchive: parsed " . count($result) . " chapters for {$slug}");

    return $result;
}

/**
 * Fetch chapter content for a Novel Arrow chapter page URL via the JSON API.
 * Returns escaped <p> paragraphs, or [] when the URL isn't a Novel Arrow
 * chapter page or the API yields nothing — the caller then falls back to the
 * generic HTML scrape.
 */
function novelArrowChapterContent(string $url): array
{
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: "");
    if ($host !== "novelarrow.com" && !str_ends_with($host, ".novelarrow.com")) {
        return [];
    }

    $path = trim(parse_url($url, PHP_URL_PATH) ?: "", "/");
    $parts = explode("/", $path);
    if (count($parts) !== 3 || $parts[0] !== "chapter") {
        return [];
    }
    [, $slug, $chapterId] = $parts;

    $json = novelArrowApi(
        "novels/" . rawurlencode($slug) . "/chapters/" . rawurlencode($chapterId)
    );
    $content = $json["item"]["chapterInfo"]["chapter_content"] ?? "";
    if (trim($content) === "") {
        return [];
    }

    $content = str_replace("\u{FEFF}", "", stripChapterNoise($content));

    // Content arrives as <p> blocks, occasionally br-separated inside them —
    // split on both so each paragraph comes out on its own line.
    $paragraphs = preg_split('/<\/p>|<br\s*\/?>/i', $content);

    $result = [];
    foreach ($paragraphs as $para) {
        $text = trim(html_entity_decode(strip_tags($para), ENT_QUOTES));
        if ($text === "" || isChapterSpamLine($text)) {
            continue;
        }
        $result[] = "<p>" . htmlspecialchars($text) . "</p>";
    }

    return $result;
}

/**
 * Full chapter list for an Empire Novel novel. The novel page paginates the
 * chapter list (?page=N, newest first); walk every page and return chapters
 * in ascending order with absolute URLs. All fetches go through FlareSolverr
 * (the site is behind Cloudflare).
 */
function empireNovelToc(string $novelUrl): array
{
    $base = "https://www.empirenovel.com";
    $novelPath = parse_url($novelUrl, PHP_URL_PATH) ?: "";
    $novelUrl = $base . $novelPath; // normalise to canonical host/path
    $result = [];

    // Page 1 via FlareSolverr to clear Cloudflare and grab the clearance
    // cookie, which lets the remaining pages be fetched with a plain (fast)
    // client — ~0.4s each vs ~7s through the headless browser.
    $session = fetchWithBrowserSession($novelUrl . "?page=1");
    if (empty($session['html'])) {
        \Log::error("empireNovelToc: could not fetch {$novelUrl}");
        return [];
    }
    $firstHtml = $session['html'];

    $cfClient = null;
    if (!empty($session['cf_clearance']) && !empty($session['user_agent'])) {
        $cfClient = HttpClient::create([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => $session['user_agent'],
                'Cookie' => 'cf_clearance=' . $session['cf_clearance'],
            ],
        ]);
    }

    // Fetch a page: plain client with the clearance cookie, falling back to
    // FlareSolverr if that 403s (cookie expired / not available).
    $fetchPage = function (int $page) use ($novelUrl, $cfClient) {
        $url = $novelUrl . "?page=" . $page;
        if ($cfClient) {
            try {
                $resp = $cfClient->request('GET', $url);
                if ($resp->getStatusCode() === 200) {
                    return $resp->getContent(false);
                }
            } catch (\Throwable $e) {
                // fall through to FlareSolverr
            }
        }
        return fetchWithBrowser($url);
    };

    // Highest ?page=N in the pagination is the last page.
    preg_match_all('/[?&]page=(\d+)/', $firstHtml, $m);
    $lastPage = $m[1] ? max(array_map('intval', $m[1])) : 1;
    $lastPage = min($lastPage, 2000); // hard safety cap

    $parsePage = function (string $html) use (&$result, $novelPath) {
        $crawler = new Crawler($html);
        $crawler->filter('a[href*="' . $novelPath . '/"]')->each(function ($node) use (&$result, $novelPath) {
            $href = $node->attr('href');
            // Only chapter links: /novel/{slug}/{numericId}
            if (!preg_match('#' . preg_quote($novelPath, '#') . '/(\d+)$#', $href)) {
                return;
            }
            // Normalise non-breaking spaces (the list renders "Chapter&nbsp;
            // 7174") before collapsing whitespace.
            $text = str_replace("\u{a0}", ' ', $node->text());
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            // Label is like "First Chapter Chapter 1" / "Chapter 4173" — pull
            // the chapter number from anywhere in it.
            if (!preg_match('/chapter\s*([\d.]+)/i', $text, $m)) {
                return;
            }
            $url = str_starts_with($href, 'http') ? $href : 'https://www.empirenovel.com' . $href;
            $result[] = [
                'label' => 'Chapter ' . $m[1],
                'book' => 0,
                'url' => $url,
                'chapter' => $m[1],
            ];
        });
    };

    $parsePage($firstHtml);
    for ($page = 2; $page <= $lastPage; $page++) {
        $html = $fetchPage($page);
        if (empty($html)) {
            \Log::warning("empireNovelToc: page {$page} failed for {$novelUrl}; stopping");
            break;
        }
        $parsePage($html);
    }

    // Dedupe by URL and sort ascending by chapter number.
    $seen = [];
    $unique = [];
    foreach ($result as $row) {
        if ($row && !isset($seen[$row['url']])) {
            $seen[$row['url']] = true;
            $unique[] = $row;
        }
    }
    usort($unique, fn($a, $b) => ($a['chapter'] <=> $b['chapter']));

    \Log::info("empireNovelToc: parsed " . count($unique) . " chapters across {$lastPage} page(s) for {$novelUrl}");

    return $unique;
}

/**
 * Metadata for an Empire Novel novel page (cover, summary, chapter count).
 */
function getMetadataFromEmpireNovel(string $novelUrl): array
{
    $metadata = ["description" => "", "author" => "", "no_of_chapters" => 0, "image" => "", "genres" => []];

    $html = fetchWithBrowser($novelUrl);
    if (empty($html)) {
        return $metadata;
    }

    try {
        $crawler = new Crawler($html);

        $og = $crawler->filterXPath('//meta[@property="og:image"]');
        if ($og->count() > 0) {
            $metadata["image"] = $og->attr("content") ?? "";
        }

        $desc = $crawler->filterXPath('//meta[@name="description"]');
        if ($desc->count() > 0) {
            $metadata["description"] = trim($desc->attr("content") ?? "");
        }

        // Chapter count: highest ?page=N × ~30, refined by parsing later; use
        // the largest "Chapter N" label visible as a floor.
        if (preg_match_all('/Chapter\s+([\d.]+)/i', $html, $m)) {
            $metadata["no_of_chapters"] = (int) max(array_map('floatval', $m[1]));
        }
    } catch (\Throwable $e) {
        \Log::error("getMetadataFromEmpireNovel error for {$novelUrl}: " . $e->getMessage());
    }

    return $metadata;
}

/**
 * Full chapter list for a novelfull.com novel. Like Novel Bin it exposes an
 * AJAX chapter-option endpoint that returns every chapter in one request,
 * but keyed by the numeric data-novel-id from the novel page rather than the
 * slug. Cloudflare-protected, so fetched via FlareSolverr (clearance reused
 * for the AJAX call).
 */
function novelFullToc(string $novelUrl): array
{
    $host = parse_url($novelUrl, PHP_URL_HOST) ?: 'novelfull.com';
    $scheme = parse_url($novelUrl, PHP_URL_SCHEME) ?: 'https';
    $origin = "{$scheme}://{$host}";

    $session = fetchWithBrowserSession($novelUrl);
    if (empty($session['html'])) {
        \Log::error("novelFullToc: could not fetch {$novelUrl}");
        return [];
    }

    if (!preg_match('/data-novel-id="(\d+)"/', $session['html'], $m)) {
        \Log::warning("novelFullToc: no data-novel-id on {$novelUrl}");
        return [];
    }
    $ajaxUrl = "{$origin}/ajax/chapter-option?novelId=" . $m[1];

    // Reuse the clearance cookie for the AJAX call; fall back to FlareSolverr.
    $html = null;
    if (!empty($session['cf_clearance']) && !empty($session['user_agent'])) {
        try {
            $resp = HttpClient::create([
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => $session['user_agent'],
                    'Cookie' => 'cf_clearance=' . $session['cf_clearance'],
                ],
            ])->request('GET', $ajaxUrl);
            if ($resp->getStatusCode() === 200) {
                $html = $resp->getContent(false);
            }
        } catch (\Throwable $e) {
            // fall through
        }
    }
    if (empty($html)) {
        $html = fetchWithBrowser($ajaxUrl);
    }
    if (empty($html)) {
        return [];
    }

    // novelfull renders the chapter dropdown more than once (top + bottom
    // nav), so dedupe by URL.
    $seen = [];
    $result = [];
    (new Crawler($html))->filter('option')->each(function ($node) use (&$result, &$seen, $origin) {
        $href = trim($node->attr('value') ?? '');
        $label = trim($node->text());
        if ($href === '' || $label === '') {
            return;
        }
        $url = str_starts_with($href, 'http') ? $href : $origin . $href;
        if (isset($seen[$url])) {
            return;
        }
        $seen[$url] = true;
        $row = generateTocChapterInfo($label, $url);
        if ($row) {
            $result[] = $row;
        }
    });

    \Log::info("novelFullToc: parsed " . count($result) . " chapters for {$novelUrl}");

    return $result;
}

/**
 * Metadata for a novelfull.com novel page (cover, description, author,
 * genres). novelfull covers are fetchable with a plain client.
 */
function getMetadataFromNovelFull(string $novelUrl): array
{
    $metadata = ["description" => "", "author" => "", "no_of_chapters" => 0, "image" => "", "genres" => []];
    $host = parse_url($novelUrl, PHP_URL_HOST) ?: 'novelfull.com';
    $scheme = parse_url($novelUrl, PHP_URL_SCHEME) ?: 'https';
    $origin = "{$scheme}://{$host}";

    $html = fetchWithBrowser($novelUrl);
    if (empty($html)) {
        return $metadata;
    }

    try {
        $crawler = new Crawler($html);

        $desc = $crawler->filter('.desc-text');
        if ($desc->count() > 0) {
            $metadata["description"] = trim($desc->first()->html());
        }

        // Cover + author + genres live in the .info / .book block.
        $img = $crawler->filter('.book img, .info img, [itemprop="image"]');
        if ($img->count() > 0) {
            $src = $img->first()->attr('src') ?? '';
            if ($src !== '') {
                $metadata["image"] = str_starts_with($src, 'http') ? $src : $origin . $src;
            }
        }

        $info = $crawler->filter('.info');
        if ($info->count() > 0) {
            $author = $info->filter('a[href*="/author/"]');
            if ($author->count() > 0) {
                $metadata["author"] = trim($author->first()->text());
            }
            $genres = $info->filter('a[href*="/genre/"]');
            if ($genres->count() > 0) {
                $metadata["genres"] = normalizeGenres($genres->each(fn($n) => $n->text()));
            }
        }
    } catch (\Throwable $e) {
        \Log::error("getMetadataFromNovelFull error for {$novelUrl}: " . $e->getMessage());
    }

    return $metadata;
}

/**
 * Resolve the NovelUpdates series URL for a title by querying their live
 * search (the endpoint their search box uses). Matches associated names /
 * aliases, so e.g. "Outside of Time" resolves to the "Beyond the Timescape"
 * series. Returns null when nothing matches.
 */
function resolveNovelUpdatesUrl(string $name): ?string
{
    $flareSolverrUrl = setting('flaresolverr_url', config('novarr.flaresolverr_url'));

    try {
        $response = HttpClient::create(['timeout' => 60])->request('POST', $flareSolverrUrl, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'cmd' => 'request.post',
                'url' => 'https://www.novelupdates.com/wp-admin/admin-ajax.php',
                // NovelUpdates' search requires %20-encoded spaces (rawurlencode);
                // http_build_query's + form gives an empty result.
                'postData' => 'action=nd_ajaxsearchmain&strType=desktop&strOne=' . rawurlencode($name),
                'maxTimeout' => 60000,
            ],
        ]);

        $data = json_decode($response->getContent(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        $html = $data['solution']['response'] ?? '';

        if (preg_match('#href="(https://www\.novelupdates\.com/series/[a-z0-9-]+/?)"#i', $html, $m)) {
            return rtrim($m[1], '/') . '/';
        }
    } catch (\Throwable $e) {
        \Log::warning("resolveNovelUpdatesUrl failed for '{$name}': " . $e->getMessage());
    }

    return null;
}

/**
 * Fetch and parse a single NovelUpdates series page into the metadata array.
 */
function fetchNovelUpdatesMetadata(string $url): array
{
    $metadata = [
        "description" => "", "author" => "", "no_of_chapters" => 0, "image" => "",
        "status_text" => "", "completed" => false, "fully_translated" => null, "genres" => [],
    ];

    try {
        // NovelUpdates sits behind Cloudflare — use FlareSolverr, falling back
        // to a direct request.
        $html = fetchWithBrowser($url);
        if (empty($html)) {
            $html = createHttpClient()->request("GET", $url)->getContent();
        }

        if (stripos($html, '<title>Just a moment...</title>') !== false ||
            stripos($html, 'Verifying you are human') !== false) {
            \Log::error("fetchNovelUpdatesMetadata: Cloudflare challenge for {$url}");
            return $metadata;
        }

        $crawler = new Crawler($html);

        $desc = $crawler->filter("#editdescription");
        $metadata["description"] = $desc->count() > 0 ? $desc->first()->html() : "";

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
        \Log::error("fetchNovelUpdatesMetadata error for {$url}: " . $e->getMessage());
    }

    return $metadata;
}

/**
 * NovelUpdates metadata for a novel. Uses the novel's saved novelupdates_url
 * when set; otherwise guesses from the name slug, and if that misses (e.g.
 * the title is an alias) falls back to NovelUpdates' search and persists the
 * resolved URL so future refreshes are deterministic.
 */
function getMetadata($data)
{
    // 1. Explicit override saved on the novel.
    if (!empty($data->novelupdates_url)) {
        return fetchNovelUpdatesMetadata($data->novelupdates_url);
    }

    // 2. Direct slug guess.
    $url = "https://www.novelupdates.com/series/" . novelSlug($data->name) . "/";
    $metadata = fetchNovelUpdatesMetadata($url);

    if (!empty($metadata["description"])) {
        return $metadata;
    }

    // 3. Slug missed — resolve via search (handles aliases) and remember it.
    \Log::info("getMetadata: slug '{$url}' missed for '{$data->name}'; trying NovelUpdates search");
    $resolved = resolveNovelUpdatesUrl($data->name);
    if ($resolved && $resolved !== $url) {
        $found = fetchNovelUpdatesMetadata($resolved);
        if (!empty($found["description"])) {
            if (($data->exists ?? false)) {
                $data->forceFill(['novelupdates_url' => $resolved])->saveQuietly();
            }
            \Log::info("getMetadata: resolved '{$data->name}' -> {$resolved}");
            return $found;
        }
    }

    return $metadata;
}

/**
 * Build a NovelUpdates/NovelArrow-style slug from a novel name: apostrophes
 * and quotes vanish ("The King's Avatar" -> the-kings-avatar), every other
 * non-alphanumeric run becomes a single dash.
 */
function novelSlug($name)
{
    $slug = strtolower($name);
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

/**
 * Fetch novel metadata from novelarrow.com (formerly novelbin) as a fallback
 * source, via its JSON API. Tries the slug from translator_url first when
 * it's already a Novel Arrow URL, then a slug built from the novel name.
 */
function getMetadataFromNovelArrow($data)
{
    $metadata = [
        "description" => "",
        "author" => "",
        "no_of_chapters" => 0,
        "image" => "",
        "genres" => [],
    ];

    $slugs = [];

    if (!empty($data->translator_url) && preg_match('/novelarrow|novelbin/i', $data->translator_url)) {
        $slug = novelArrowSlug($data->translator_url);
        if ($slug !== "") {
            $slugs[] = $slug;
        }
    }

    if (!empty($data->name)) {
        $slugs[] = novelSlug($data->name);
    }

    $slugs = array_values(array_unique($slugs));
    $metadata["tried_urls"] = array_map(fn($s) => "https://novelarrow.com/novel/{$s}", $slugs);

    foreach ($slugs as $slug) {
        $json = novelArrowApi("novels/" . rawurlencode($slug));
        $info = $json["item"]["novelInfo"] ?? null;

        if (empty($info)) {
            \Log::warning("getMetadataFromNovelArrow: no data for slug '{$slug}'");
            continue;
        }

        $metadata["description"] = trim($info["novel_desc"] ?? "");
        $metadata["author"] = trim($info["novel_author"] ?? "");
        $metadata["no_of_chapters"] = (int) ($info["totalChapter"] ?? 0);

        // Covers live on the image host, keyed by slug (see the site's
        // og:image tags) — downloadCoverImage() validates it's a real image.
        $metadata["image"] = "https://images.novelarrow.com/novel/{$slug}.jpg";

        $genres = $info["novel_genres"] ?? [];
        if (empty($genres)) {
            $genres = $info["novel_tags"] ?? [];
        }
        if (!empty($genres)) {
            $metadata["genres"] = normalizeGenres(is_array($genres) ? $genres : explode(",", $genres));
        }

        \Log::debug("getMetadataFromNovelArrow fetched slug '{$slug}'", [
            'has_description' => !empty($metadata['description']),
            'has_author' => !empty($metadata['author']),
            'no_of_chapters' => $metadata['no_of_chapters'],
        ]);

        // Stop on the first slug that yields any useful field.
        if (!empty($metadata['description']) || !empty($metadata['author'])) {
            break;
        }
    }

    return $metadata;
}

function generateTocChapterInfo($label, $url)
{
    if (stripos($label, "teaser") !== false) {
        return; // Early exit if the label contains "teaser"
    }

    // Normalize label
    $normalizedLabel = preg_replace(["/ +/", "/\(/"], [" ", " ("], $label);
    $normalizedLabel = preg_replace(
        "/[^A-Za-z0-9 _\.\-\+\&\(\)]/",
        "",
        $normalizedLabel
    );

    // Initial variables
    $chapter = $book = 0;
    $splitChapterSuffix = "";

    // Extract book from label if present, fallback to URL extraction
    if (preg_match("/vol\.?(\d+)/i", $normalizedLabel, $volumeMatches)) {
        $book = $volumeMatches[1];
    } elseif (preg_match("/-(book|volume|vol)-(\d+)/i", $url, $bookMatches)) {
        $book = $bookMatches[2];
    }

    // Capture the FIRST chapter number from the label (unique chapter number)
    // This is the primary ordering number - matches "Chapter 164" in "Chapter 164 - 106 Title"
    if (preg_match("/^chapter\s*(\d+)/i", $normalizedLabel, $chapterMatches)) {
        $chapter = $chapterMatches[1];
    } elseif (preg_match("/^(\d+)/", $normalizedLabel, $startChapterMatches)) {
        $chapter = $startChapterMatches[1];
    }

    // Handle chapter splits - only check patterns IMMEDIATELY after the chapter number
    // Pattern: "Chapter 164(2)" or "Chapter164(2)" - numeric split in parentheses directly attached
    if (preg_match("/^chapter\s*(\d+)\s*\((\d+)\)/i", $normalizedLabel, $numericSplitMatches)) {
        $chapter = $numericSplitMatches[1];
        $splitChapterSuffix = $numericSplitMatches[2];
    }
    // Pattern: "Chapter 164A" or "Chapter 164 A -" - letter split directly after chapter number
    elseif (preg_match("/^chapter\s*(\d+)\s*([A-Z])(?:\s*[-–]|\s*$)/i", $normalizedLabel, $letterSplitMatches)) {
        $chapter = $letterSplitMatches[1];
        $splitChapterSuffix = ord(strtoupper($letterSplitMatches[2])) - ord("A") + 1;
    }
    // Pattern: "_2" or "_3" suffix at the END of the label (for multi-part chapters)
    elseif (preg_match("/_(\d+)$/", $normalizedLabel, $suffixMatches)) {
        $splitChapterSuffix = $suffixMatches[1];
    }
    // Pattern: "(Part 2)" at the end
    elseif (preg_match("/\(Part\s*(\d+)\)\s*$/i", $normalizedLabel, $partMatches)) {
        $splitChapterSuffix = $partMatches[1];
    }

    // Construct the final chapter designation based on the presence of a split suffix
    if ($splitChapterSuffix !== "") {
        $chapter .= "." . $splitChapterSuffix;
    }

    // Dynamic check for patterns like "(1) – A –", "(2) – B –", "(3) – C –", etc.
    // Only at the START of title content, not anywhere in the string
    if (preg_match("/^chapter\s*\d+[^0-9]*\((\d+)\)\s*–\s*[A-Z]\s*–/i", $label, $matches)) {
        $chapter = $chapter . "." . (int) $matches[1];
    }

    // Final adjustments
    $chapter = rtrim($chapter, ".-"); // Remove trailing dots or dashes
    $book = (int) $book; // Ensure book is an integer

    return [
        "label" => substr($normalizedLabel, 0, 250), // Ensure the label is not excessively long
        "book" => $book,
        "url" => $url,
        "chapter" => $chapter,
    ];
}
