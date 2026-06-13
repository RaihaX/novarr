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
        $url = setting('notification_webhook_url', env('NOTIFICATION_WEBHOOK_URL'));

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
    $flareSolverrUrl = setting('flaresolverr_url', env('FLARESOLVERR_URL', 'http://192.168.1.41:8191/v1'));

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
    $flareSolverrUrl = setting('flaresolverr_url', env('FLARESOLVERR_URL', 'http://192.168.1.41:8191/v1'));
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
 * Check if URL exists using headless browser
 * Returns true if page loads successfully without Cloudflare challenge
 */
function urlExists($url)
{
    try {
        $html = fetchWithBrowser($url);

        if ($html === null) {
            return false;
        }

        // Check if we got a Cloudflare challenge page
        if (stripos($html, 'cf-challenge') !== false ||
            stripos($html, 'cloudflare') !== false && stripos($html, 'challenge') !== false) {
            \Log::warning("urlExists detected Cloudflare challenge for: {$url}");
            return false;
        }

        // Check if page has minimal content (not an error page)
        if (strlen($html) < 1000) {
            \Log::warning("urlExists page too short for: {$url} (length: " . strlen($html) . ")");
            return false;
        }

        return true;
    } catch (\Exception $e) {
        \Log::error("urlExists exception for {$url}: " . $e->getMessage());
        return false;
    }
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

    // Inline ad slots used by novelbin et al.
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
        // Empire Novel: detected by URL, paginated chapter list.
        if (stripos($data->translator_url ?? "", "empirenovel.com") !== false) {
            return finalizeTocResult(empireNovelToc($data->translator_url));
        }

        // Novel Bin pages only embed the newest ~30 chapters; the complete
        // list lives behind an AJAX endpoint keyed by the URL slug. Try it
        // first and fall back to parsing the page.
        if (
            $data->group_id == 1 &&
            stripos($data->translator_url ?? "", "novelbin") !== false
        ) {
            $result = novelBinChapterArchive($data->translator_url);

            if (!empty($result)) {
                return finalizeTocResult($result);
            }

            \Log::warning("tableOfContentGenerator: novelbin archive empty for {$data->translator_url}; falling back to page parse");
        }

        $html = fetchWithBrowser($data->translator_url, '.list-chapter');

        if ($html !== null) {
            $crawler = new Crawler($html);

            $processChapter = function ($node) use (&$result, $data) {
                $label = $node->text();
                $url = trim($node->attr("href"));
                $result[] = generateTocChapterInfo($label, $url);
            };

            // Handling different groups
            switch ($data->group_id) {
                case 1: // Novel Bin
                    $crawler
                        ->filter(".list-chapter > li > a")
                        ->each($processChapter);
                    break;
            }

            $result = finalizeTocResult($result);
        }
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
 * Extract [host, slug] from any Novel Bin URL shape: a novel page
 * (…/novel-book/slug, …/b/slug) or an AJAX endpoint
 * (…/ajax/chapter-archive?novelId=slug).
 */
function novelBinSlugAndHost(string $url): array
{
    $host = parse_url($url, PHP_URL_HOST) ?: "";
    $slug = "";

    parse_str(parse_url($url, PHP_URL_QUERY) ?: "", $query);

    if (!empty($query["novelId"])) {
        $slug = $query["novelId"];
    } else {
        $path = parse_url($url, PHP_URL_PATH) ?: "";
        $slug = basename(rtrim($path, "/"));
    }

    return [$host, $slug];
}

/**
 * Fetch the complete chapter list for a Novel Bin novel via its AJAX
 * archive endpoint (the novel page itself only embeds the newest ~30).
 */
function novelBinChapterArchive(string $novelUrl): array
{
    [$host, $slug] = novelBinSlugAndHost($novelUrl);
    $scheme = parse_url($novelUrl, PHP_URL_SCHEME) ?: "https";

    if ($slug === "" || empty($host)) {
        return [];
    }

    $url = "{$scheme}://{$host}/ajax/chapter-option?novelId=" . urlencode($slug);

    try {
        $html = null;

        try {
            $response = createHttpClient()->request("GET", $url);
            if ($response->getStatusCode() === 200) {
                $html = $response->getContent(false);
            }
        } catch (\Throwable $e) {
            \Log::warning("novelBinChapterArchive direct fetch failed for {$url}: " . $e->getMessage());
        }

        if (empty($html) || stripos($html, "Just a moment...") !== false) {
            $html = fetchWithBrowser($url);
        }

        if (empty($html)) {
            return [];
        }

        $crawler = new Crawler($html);
        $result = [];

        $crawler->filter("option")->each(function ($node) use (&$result) {
            $chapterUrl = trim($node->attr("value") ?? "");
            $label = trim(preg_replace('/\s+/', " ", $node->text()));

            if ($chapterUrl !== "" && $label !== "") {
                $result[] = generateTocChapterInfo($label, $chapterUrl);
            }
        });

        $result = array_values(array_filter($result));
        \Log::info("novelBinChapterArchive: parsed " . count($result) . " chapters from {$url}");

        return $result;
    } catch (\Throwable $e) {
        \Log::error("novelBinChapterArchive error for {$url}: " . $e->getMessage());
        return [];
    }
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

function getMetadata($data)
{
    $metadata = [
        "description" => "",
        "author" => "",
        "no_of_chapters" => 0,
        "image" => "",
        "status_text" => "",
        "completed" => false,
        "fully_translated" => null,
        "genres" => [],
    ];

    $name = novelSlug($data->name);
    $url = "https://www.novelupdates.com/series/{$name}/";

    try {
        // NovelUpdates sits behind Cloudflare: a plain HTTP request works from
        // residential IPs but gets challenged from others, which silently
        // empties every field. Use FlareSolverr (same path the chapter
        // scraper relies on) and only fall back to a direct request.
        $html = fetchWithBrowser($url);

        if (empty($html)) {
            \Log::warning("getMetadata: FlareSolverr failed for {$url}; falling back to direct fetch");
            $html = createHttpClient()->request("GET", $url)->getContent();
        }

        if (stripos($html, '<title>Just a moment...</title>') !== false ||
            stripos($html, 'Verifying you are human') !== false) {
            \Log::error("getMetadata: Cloudflare challenge page for {$url} — metadata unavailable");
            return $metadata;
        }

        $crawler = new Crawler($html);

        // Description
        $descriptionFilter = $crawler->filter("#editdescription");
        $metadata["description"] = $descriptionFilter->count() > 0
            ? $descriptionFilter->first()->html()
            : "";

        // Author
        $authorFilter = $crawler->filter("#authtag");
        $metadata["author"] = $authorFilter->count() > 0
            ? $authorFilter->first()->text()
            : "";

        // Number of Chapters + status in country of origin (e.g. "1234 Chapters (Completed)")
        $statusFilter = $crawler->filter("#editstatus");
        if ($statusFilter->count() > 0) {
            $statusFilter->each(function ($node) use (&$metadata) {
                $metadata["status_text"] = trim($node->text());
                $text = str_replace("Chapter ", "Chapters ", $node->text());
                preg_match("/(\d+) Chapters/", $text, $matches);
                $metadata["no_of_chapters"] = $matches[1] ?? 0;
            });
            $metadata["completed"] =
                stripos($metadata["status_text"], "complete") !== false;
        }

        // Fully Translated flag ("Yes" / "No")
        $translatedFilter = $crawler->filter("#showtranslated");
        if ($translatedFilter->count() > 0) {
            $metadata["fully_translated"] =
                stripos(trim($translatedFilter->first()->text()), "yes") !== false;
        }

        // Image
        $imageFilter = $crawler->filter(".seriesimg > img");
        $metadata["image"] = $imageFilter->count() > 0
            ? $imageFilter->first()->attr("src")
            : "";

        // Genres — the #seriesgenre block holds the broad genres (Action,
        // Fantasy, Xuanhuan…); the granular #showtags list is deliberately
        // skipped to keep tags meaningful.
        $genreFilter = $crawler->filter("#seriesgenre a.genre");
        if ($genreFilter->count() > 0) {
            $metadata["genres"] = normalizeGenres(
                $genreFilter->each(fn($n) => $n->text())
            );
        }

        if (empty($metadata["description"])) {
            $title = $crawler->filter("title")->count() > 0
                ? trim($crawler->filter("title")->first()->text())
                : "(no title)";
            \Log::warning(
                "getMetadata: no description found for {$url} "
                . "(html length: " . strlen($html) . ", page title: {$title}). "
                . "Wrong slug or NovelUpdates markup change."
            );
        }
    } catch (TransportExceptionInterface $e) {
        \Log::error("getMetadata transport error for {$name}: " . $e->getMessage());
    } catch (\Exception $e) {
        \Log::error("getMetadata error for {$name}: " . $e->getMessage());
    }

    return $metadata;
}

/**
 * Build a NovelUpdates/NovelBin-style slug from a novel name: apostrophes
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
 * names. Handles UPPERCASE (NovelBin) and HTML entities (e.g. "Anime &amp;
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
 * Fetch novel metadata from novelbin.com as a fallback source.
 * Tries translator_url first if it's already a novelbin URL, then falls back
 * to building a slug from the novel name.
 */
function getMetadataFromNovelBin($data)
{
    $metadata = [
        "description" => "",
        "author" => "",
        "no_of_chapters" => 0,
        "image" => "",
        "genres" => [],
    ];

    $candidateUrls = [];

    if (!empty($data->translator_url) && stripos($data->translator_url, "novelbin") !== false) {
        if (stripos($data->translator_url, "/ajax/") !== false) {
            // translator_url points at an AJAX endpoint (used for chapter
            // lists) — derive the actual novel page from its slug.
            [$host, $slug] = novelBinSlugAndHost($data->translator_url);
            if ($slug !== "" && $host !== "") {
                $candidateUrls[] = "https://{$host}/novel-book/{$slug}";
                $candidateUrls[] = "https://novelbin.me/novel-book/{$slug}";
                $candidateUrls[] = "https://novelbin.com/b/{$slug}";
            }
        } else {
            $candidateUrls[] = rtrim($data->translator_url, "/");
        }
    }

    if (!empty($data->name)) {
        $slug = novelSlug($data->name);
        $candidateUrls[] = "https://novelbin.me/novel-book/{$slug}";
        $candidateUrls[] = "https://novelbin.com/b/{$slug}";
    }

    $metadata["tried_urls"] = array_values(array_unique($candidateUrls));

    foreach (array_unique($candidateUrls) as $url) {
        try {
            $html = fetchWithBrowser($url, '.book-img');

            if (empty($html)) {
                \Log::warning("getMetadataFromNovelBin empty response for {$url}");
                continue;
            }

            $crawler = new Crawler($html);

            // Cover image — novelbin lazy-loads, so check common attribute variants
            $imageFilter = $crawler->filter('.book-img img, .book-cover img, .book img');
            if ($imageFilter->count() > 0) {
                $node = $imageFilter->first();
                foreach (['data-src', 'data-cfsrc', 'src'] as $attr) {
                    $value = $node->attr($attr);
                    if (!empty($value) && stripos($value, 'data:image') !== 0) {
                        $metadata["image"] = $value;
                        break;
                    }
                }
            }

            // Description
            $descFilter = $crawler->filter('.desc-text, #tab-description .desc-text, .desc');
            if ($descFilter->count() > 0) {
                $metadata["description"] = trim($descFilter->first()->html());
            }

            // Genres — novelbin exposes them in a <meta name="genre"> tag and
            // as genre links under the info block.
            if (empty($metadata["genres"])) {
                $genreMeta = $crawler->filterXPath('//meta[@name="genre"]');
                if ($genreMeta->count() > 0) {
                    $metadata["genres"] = normalizeGenres(explode(',', $genreMeta->attr('content') ?? ''));
                } else {
                    $genreLinks = $crawler->filter('.info a[href*="genre"]');
                    if ($genreLinks->count() > 0) {
                        $metadata["genres"] = normalizeGenres($genreLinks->each(fn($n) => $n->text()));
                    }
                }
            }

            // Author + chapter count — both live under ul.info-meta > li with an h3 label
            $infoFilter = $crawler->filter('ul.info-meta > li, .info-holder .info > div > li, .info > .meta > p');
            $infoFilter->each(function ($node) use (&$metadata) {
                $label = strtolower(trim($node->filter('h3, b')->count() > 0 ? $node->filter('h3, b')->first()->text() : ''));
                $text = trim($node->text());

                if (str_contains($label, 'author')) {
                    $authorNode = $node->filter('a');
                    $metadata["author"] = $authorNode->count() > 0
                        ? trim($authorNode->first()->text())
                        : trim(str_ireplace('author', '', $text));
                }

                if (str_contains($label, 'chapter') || stripos($text, 'Latest Chapter') !== false) {
                    if (preg_match('/(\d+)/', $text, $matches)) {
                        $metadata["no_of_chapters"] = (int) $matches[1];
                    }
                }
            });

            \Log::debug("getMetadataFromNovelBin fetched from {$url}", [
                'has_image' => !empty($metadata['image']),
                'has_description' => !empty($metadata['description']),
                'has_author' => !empty($metadata['author']),
                'no_of_chapters' => $metadata['no_of_chapters'],
            ]);

            // Stop on the first URL that yields any useful field
            if (!empty($metadata['image']) || !empty($metadata['description']) || !empty($metadata['author'])) {
                break;
            }
        } catch (\Exception $e) {
            \Log::error("getMetadataFromNovelBin error for {$url}: " . $e->getMessage());
        }
    }

    return $metadata;
}

function convertWordToNumber($text)
{
    // Mapping of number words to numeric values
    $wordToNumberMap = [
        "zero" => 0,
        "a" => 1,
        "one" => 1,
        "two" => 2,
        "three" => 3,
        "four" => 4,
        "five" => 5,
        "six" => 6,
        "seven" => 7,
        "eight" => 8,
        "nine" => 9,
        "ten" => 10,
        "eleven" => 11,
        "twelve" => 12,
        "thirteen" => 13,
        "fourteen" => 14,
        "fifteen" => 15,
        "sixteen" => 16,
        "seventeen" => 17,
        "eighteen" => 18,
        "nineteen" => 19,
        "twenty" => 20,
        "thirty" => 30,
        "forty" => 40,
        "fifty" => 50,
        "sixty" => 60,
        "seventy" => 70,
        "eighty" => 80,
        "ninety" => 90,
        "hundred" => 100,
        "thousand" => 1000,
        "million" => 1000000,
        "billion" => 1000000000,
        "and" => "",
        // Handle common misspelling
        "fourty" => 40,
    ];

    // Replace all number words with their equivalent numeric value
    $data = strtr(strtolower($text), $wordToNumberMap);

    // Split into parts and convert to numbers
    $parts = array_map("floatval", preg_split("/[\s-]+/", $data));

    $sum = 0;
    $stack = new SplStack();

    foreach ($parts as $part) {
        if (!$stack->isEmpty() && $stack->top() > $part) {
            if ($last >= 1000) {
                $sum += $stack->pop();
            } else {
                $stack->push($stack->pop() + $part);
            }
        } else {
            $stack->push($stack->isEmpty() ? $part : $stack->pop() * $part);
        }
        $last = $part;
    }

    return $sum + ($stack->isEmpty() ? 0 : $stack->pop());
}

function generateHTMLContent($object)
{
    $manifestItems = "";
    $spineItems = "";
    foreach ($object->chapters as $item) {
        $itemId = str_replace(
            "/Novel/{$item->novel_id}/Text/",
            "",
            $item->html_file
        );
        $manifestItems .= "<item id=\"$itemId\" href=\"Text/$itemId\" media-type=\"application/xhtml+xml\"/>";
        $spineItems .= "<itemref idref=\"$itemId\"/>";
    }

    return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<package version="3.0" unique-identifier="BookId" xmlns="http://www.idpf.org/2007/opf">
<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
<dc:language>en</dc:language>
<dc:title>{$object->name}</dc:title>
<dc:creator id="cre">{$object->author}</dc:creator>
<meta refines="#cre" scheme="marc:relators" property="role">aut</meta>
<dc:identifier id="BookId">urn:uuid:de5b9c8f-ce5a-4b5c-b418-e50dba48e2b9</dc:identifier>
<meta content="0.9.9" name="Sigil version" />
<meta property="dcterms:modified">2018-03-21T09:42:16Z</meta>
</metadata>
<manifest>
<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
{$manifestItems}
<item id="nav.xhtml" href="Text/nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
</manifest>
<spine toc="ncx">
{$spineItems}
<itemref idref="nav.xhtml" linear="no"/>
</spine>
</package>
XML;
}

function generateHTMLToc($object)
{
    $firstChapterFile = str_replace(
        "/Novel/{$object->chapters[0]->novel_id}/Text/",
        "",
        $object->chapters[0]->html_file
    );

    return <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<ncx version="2005-1" xmlns="http://www.daisy.org/z3986/2005/ncx/">
<head>
<meta content="urn:uuid:de5b9c8f-ce5a-4b5c-b418-e50dba48e2b9" name="dtb:uid"/>
<meta content="0" name="dtb:depth"/>
<meta content="0" name="dtb:totalPageCount"/>
<meta content="0" name="dtb:maxPageNumber"/>
</head>
<docTitle><text>Unknown</text></docTitle>
<navMap>
<navPoint id="navPoint-1" playOrder="1">
<navLabel><text>Start</text></navLabel>
<content src="Text/{$firstChapterFile}"/>
</navPoint>
</navMap>
</ncx>
XML;
}

function generateHTMLNav($chapters)
{
    $items = "";
    foreach ($chapters as $chapter) {
        $chapterFile = str_replace(
            "/Novel/{$chapter->novel_id}/Text/",
            "",
            $chapter->html_file
        );
        $items .= "<li><a href=\"../Text/$chapterFile\">{$chapter->label}</a></li>";
    }

    return <<<HTML
<?xml version="1.0" encoding="utf-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="en" xml:lang="en">
<head><meta charset="utf-8"/><title></title></head>
<body epub:type="frontmatter">
<nav epub:type="toc" id="toc"><h1>Table of Contents</h1><ol>{$items}</ol></nav>
<nav epub:type="landmarks" id="landmarks" hidden=""><h2>Landmarks</h2><ol><li><a epub:type="toc" href="#toc">Table of Contents</a></li></ol></nav>
</body>
</html>
HTML;
}

function generateHTMLChapter($content)
{
    return <<<HTML
<?xml version="1.0" encoding="utf-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
<head><title></title></head>
<body>{$content}</body>
</html>
HTML;
}

function splitAndCleanLargeString($input)
{
    $result = [];

    // Ensure $input is treated uniformly as an array
    $strings = is_array($input) ? $input : [$input];

    foreach ($strings as $str) {
        // Split the string into sentences
        $sentences = preg_split("/(?<=[.?!])\s+(?=[A-Za-z])/", $str);

        foreach ($sentences as $sentence) {
            // Only add non-empty sentences
            if (!empty($sentence)) {
                $result[] = "<p>" . trim($sentence) . "</p>";
            }
        }
    }

    // Assuming __cleanseChapterArray is defined elsewhere and cleanses the content
    return cleanseChapterArray($result);
}

function cleanseChapterArray($strings)
{
    $cleanseArray = [];

    // Define an array of patterns to be filtered out
    $unwantedPatterns = [
        "<p></p>",
        "<p>&nbsp;</p>",
        "<p>&nbsp; &nbsp;</p>",
        "<p><p>",
        "<p>  </p>",
        "<p>   </p>",
        "<p> </p>",
    ];

    foreach ($strings as $i) {
        // Check if the current string matches any unwanted pattern
        if (!in_array($i, $unwantedPatterns)) {
            // If it doesn't match, cleanse the content and add it to the cleanseArray
            $cleanseArray[] = cleanseChapterContent($i);
        }
    }

    return $cleanseArray;
}

function cleanseChapterContent($string)
{
    // Remove specific unwanted strings and replace them with desired alternatives
    $string = str_replace("<Mystic Moon>", "Mystic Moon", $string);

    // Consolidate multiple spaces, tabs, newlines into a single space and remove them
    $string = preg_replace('/[\s\t\n\r\0\x0B]+/', " ", $string);

    // List of phrases to check for their absence in the string
    $phrasesToCheck = [
        "table of contents",
        "previous chapter",
        "translated by",
        "edited by",
        "translator",
        "A+ A-",
        "edited:",
        "tl: ",
        "next chapter",
        "proofreader:",
        "(tl by ",
    ];

    // Convert string to lower case once to avoid multiple conversions in the loop
    $lowerCaseString = strtolower($string);

    // Check if any of the phrases exist in the string
    foreach ($phrasesToCheck as $phrase) {
        if (stripos($lowerCaseString, $phrase) !== false) {
            return; // Early return if any unwanted phrase is found
        }
    }

    // Return the trimmed and cleaned string if none of the phrases are found
    return trim($string);
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
