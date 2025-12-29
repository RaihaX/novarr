<?php

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Create a configured HTTP client with browser-like headers
 * to bypass Cloudflare and other protections
 */
function createHttpClient()
{
    return HttpClient::create([
        'timeout' => 30,
        'verify_peer' => false,
        'verify_host' => false,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
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

function urlExists($url)
{
    $handle = curl_init($url);

    // Set browser-like headers to bypass Cloudflare
    $headers = [
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Cache-Control: max-age=0',
    ];

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_NOBODY => true, // Only check the connection; don't download the content
        CURLOPT_TIMEOUT => 10, // Add timeout to prevent hanging
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false, // Bypass SSL verification if needed
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => '', // Handle all encodings
    ]);

    curl_exec($handle);
    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    // Return true only if HTTP status is in the 200 range
    return $httpCode >= 200 && $httpCode < 300;
}

function chapterGenerator($data)
{
    $novelUrl = preg_match("/^http/", $data->url)
        ? $data->url
        : $data->novel->group->url . $data->url;

    if (
        !empty($data->novel->alternative_url) &&
        in_array($data->novel->group->id, [1, 3, 6])
    ) {
        $chapter =
            $data->novel->id == 72
                ? str_replace(".", "-", $data->chapter)
                : floor($data->chapter);
        $novelUrl = $data->novel->alternative_url . $chapter;
    }

    \Log::debug("ChapterGenerator attempting to fetch URL: {$novelUrl}");

    if (!urlExists($novelUrl)) {
        \Log::warning("ChapterGenerator URL does not exist or returned error: {$novelUrl}");
        return [];
    }

    try {
        // Add delay to avoid rate limiting
        usleep(rand(500000, 1500000)); // Random delay between 0.5-1.5 seconds

        // Create Symfony HTTP client and fetch the page
        $httpClient = createHttpClient();
        $response = $httpClient->request('GET', $novelUrl);
        $crawler = new Crawler($response->getContent());

        // Expanded list of common selectors for novel content
        $selectors = [
            "#chr-content p",           // Direct paragraphs in chr-content
            "#chr-content > p",         // Only direct child paragraphs
            "#chr-content div p",       // Paragraphs inside divs
            ".chapter-content p",       // Common alternative class
            ".entry-content p",         // WordPress-style content
            "div.content p",            // Generic content div
            "#chapter-content p",       // Alternative ID
            "article p",                // Article tag paragraphs
            ".text p",                  // Common text class
            ".text_story p",            // Novel-specific class
            "#content p",               // Simple content ID
        ];

        $result = [];

        foreach ($selectors as $selector) {
            $tempResult = [];
            try {
                $crawler->filter($selector)->each(function ($node) use (&$tempResult) {
                    extractTextRecursively($node, $tempResult);
                });
            } catch (\Exception $e) {
                // Selector might not exist, continue to next one
                continue;
            }

            // If we found content with this selector, use it
            if (count($tempResult) > 10) { // At least 10 paragraphs to be considered valid
                \Log::debug("ChapterGenerator found content using selector: {$selector} (paragraphs: " . count($tempResult) . ")");
                $result = $tempResult;
                break;
            }
        }

        // If no selector found enough content, log it
        if (count($result) < 10) {
            \Log::warning("ChapterGenerator found insufficient content for URL: {$novelUrl} (paragraphs: " . count($result) . ")");

            // Debug: Log the page HTML to see what we actually got
            $html = $crawler->html();
            if (stripos($html, 'cloudflare') !== false || stripos($html, 'challenge') !== false) {
                \Log::error("ChapterGenerator detected Cloudflare challenge page for URL: {$novelUrl}");
            }
        }

        $result = array_filter($result, "strlen"); // Remove empty paragraphs

        return $result;
    } catch (\Exception $e) {
        \Log::error("ChapterGenerator exception for URL {$novelUrl}: " . $e->getMessage());
        return [];
    }
}

function extractTextRecursively($node, &$result)
{
    if (trim($node->text()) != "") {
        $result[] = "<p>" . htmlspecialchars($node->text()) . "</p>"; // Ensure text is properly escaped
    }

    // Check if the node has children that are paragraphs and recurse
    $node->children()->each(function ($child) use (&$result) {
        if ($child->nodeName() == "p" || $child->nodeName() == "div") {
            extractTextRecursively($child, $result);
        }
    });
}

function tableOfContentGenerator($data)
{
    $result = [];

    if (urlExists($data->translator_url)) {
        try {
            $httpClient = createHttpClient();
            $response = $httpClient->request('GET', $data->translator_url);
            $crawler = new Crawler($response->getContent());

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

            // Remove nulls and potentially adjust chapter numbers
            $result = array_filter($result, function ($item) {
                return $item !== null;
            });

            // Generate chapter numbers if necessary
            if (
                array_reduce(
                    $result,
                    function ($carry, $item) {
                        return $carry || $item["chapter"] > 0;
                    },
                    false
                ) === false
            ) {
                foreach ($result as $key => &$item) {
                    $item["chapter"] = $key + 1;
                }
            }
        } catch (TransportExceptionInterface $e) {
            \Log::error("tableOfContentGenerator transport error: " . $e->getMessage());
        } catch (\Exception $e) {
            \Log::error("tableOfContentGenerator error: " . $e->getMessage());
        }
    }

    return $result;
}

function getMetadata($data)
{
    $metadata = [
        "description" => "",
        "author" => "",
        "no_of_chapters" => 0,
        "image" => ""
    ];

    // Simplify the sanitization of the name
    $name = strtolower($data->name);
    $name = preg_replace(['/[\s!?\'",]+/', "/-{2,}/"], "-", $name); // Replace specified characters with a single dash

    try {
        $httpClient = createHttpClient();
        $response = $httpClient->request(
            "GET",
            "https://www.novelupdates.com/series/{$name}"
        );
        $crawler = new Crawler($response->getContent());

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

        // Number of Chapters
        $statusFilter = $crawler->filter("#editstatus");
        if ($statusFilter->count() > 0) {
            $statusFilter->each(function ($node) use (&$metadata) {
                $text = str_replace("Chapter ", "Chapters ", $node->text());
                preg_match("/(\d+) Chapters/", $text, $matches);
                $metadata["no_of_chapters"] = $matches[1] ?? 0;
            });
        }

        // Image
        $imageFilter = $crawler->filter(".seriesimg > img");
        $metadata["image"] = $imageFilter->count() > 0
            ? $imageFilter->first()->attr("src")
            : "";
    } catch (TransportExceptionInterface $e) {
        \Log::error("getMetadata transport error for {$name}: " . $e->getMessage());
    } catch (\Exception $e) {
        \Log::error("getMetadata error for {$name}: " . $e->getMessage());
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

    // Capture the basic chapter number from the label
    if (preg_match("/chapter (\d+)/i", $normalizedLabel, $chapterMatches)) {
        $chapter = $chapterMatches[1];
    } elseif (preg_match("/^(\d+)/", $normalizedLabel, $startChapterMatches)) {
        $chapter = $startChapterMatches[1];
    }

    // Handle chapter splits with priority given to numeric splits in parentheses
    if (
        preg_match("/(\d+)\((\d+)\)/", $normalizedLabel, $numericSplitMatches)
    ) {
        $chapter = $numericSplitMatches[1]; // Basic chapter number
        $splitChapterSuffix = $numericSplitMatches[2]; // Numeric split
    } elseif (
        preg_match(
            "/(\d+)[\s–]*([A-Z])[\s–]/i",
            $normalizedLabel,
            $letterSplitMatches
        )
    ) {
        $chapter = $letterSplitMatches[1]; // Basic chapter number
        $splitChapterSuffix =
            ord(strtoupper($letterSplitMatches[2])) - ord("A") + 1; // Convert letter to numeric
    }

    // Construct the final chapter designation based on the presence of a split suffix
    if ($splitChapterSuffix !== "") {
        $chapter .= "." . $splitChapterSuffix;
    }

    // Dynamic check for patterns like "(1) – A –", "(2) – B –", "(3) – C –", etc.
    if (preg_match("/\((\d+)\)\s*–\s*[A-Z]\s*–/i", $label, $matches)) {
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
