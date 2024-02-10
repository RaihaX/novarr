<?php

function urlExists($url) {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_FOLLOWLOCATION => TRUE,
        CURLOPT_NOBODY => TRUE // Only check the connection; don't download the content
    ]);
    curl_exec($handle);
    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    return $httpCode >= 200 && $httpCode < 400;
}

function chapterGenerator($data) {
    $novelUrl = preg_match('/^http/', $data->url) ? $data->url : $data->novel->group->url . $data->url;

    if (!empty($data->novel->alternative_url) && in_array($data->novel->group->id, [1, 3, 6])) {
        $chapter = $data->novel->id == 72 ? str_replace(".", "-", $data->chapter) : floor($data->chapter);
        $novelUrl = $data->novel->alternative_url . $chapter;
    }

    if (!urlExists($novelUrl)) return [];

    $crawler = Goutte::request('GET', $novelUrl);
    $selectors = [
        // Group ID to selectors mapping
    ];

    $result = [];
    $groupSelectors = $selectors[$data->novel->group->id] ?? [];
    foreach ($groupSelectors as $selector) {
        $crawler->filter($selector)->each(function ($node) use (&$result) {
            $result[] = '<p>' . $node->text() . '</p>';
        });
        $result = array_filter($result, 'strlen'); // Remove empty paragraphs
        if (count($result) >= 5) break;
    }

    return $result;
}

function tableOfContentGenerator($data) {
    $result = [];
    if (urlExists($data->translator_url)) {
        $crawler = Goutte::request('GET', $data->translator_url);

        $processChapter = function ($node) use (&$result, $data) {
            $label = $node->text();
            $url = trim($node->attr('href'));
            $result[] = __tocChapterLabelGenerator($label, $url);
        };

        // Handling different groups
        switch ($data->group_id) {
            case 40: // Read Light Novels
                $crawler->filter('.chapter-chs > li > a')->each($processChapter);
                break;
            case 41: // Box Novel Org
                for ($i = 1; $i <= 50; $i++) {
                    $pageCrawler = Goutte::request('GET', $data->translator_url . "?page=" . $i);
                    $pageCrawler->filter('.list-chapter > li > a')->each($processChapter);
                }
                break;
        }

        // Remove nulls and potentially adjust chapter numbers
        $result = array_filter($result, function ($item) {
            return $item !== null;
        });

        // Generate chapter numbers if necessary
        if (array_reduce($result, function ($carry, $item) { return $carry || $item["chapter"] > 0; }, false) === false) {
            foreach ($result as $key => &$item) {
                $item["chapter"] = $key + 1;
            }
        }
    }

    return $result;
}

function getMetadata($data) {
    $metadata = [];

    // Simplify the sanitization of the name
    $name = strtolower($data->name);
    $name = preg_replace(['/[\s!?\'",]+/', '/-{2,}/'], '-', $name); // Replace specified characters with a single dash

    $crawler = Goutte::request('GET', "https://www.novelupdates.com/series/{$name}");

    // Description
    $metadata["description"] = $crawler->filter('#editdescription')->first()->html() ?? '';

    // Author
    $metadata["author"] = $crawler->filter('#authtag')->first()->text() ?? '';

    // Number of Chapters
    $crawler->filter('#editstatus')->each(function ($node) use (&$metadata) {
        $text = str_replace("Chapter ", "Chapters ", $node->text());
        preg_match('/(\d+) Chapters/', $text, $matches);
        $metadata["no_of_chapters"] = $matches[1] ?? 0;
    });

    // Image
    $metadata["image"] = $crawler->filter(".seriesimg > img")->first()->attr('src') ?? '';

    return $metadata;
}

function convertWordToNumber($text) {
    // Mapping of number words to numeric values
    $wordToNumberMap = [
        'zero' => 0, 'a' => 1, 'one' => 1, 'two' => 2, 'three' => 3,
        'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
        'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
        'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
        'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30,
        'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70,
        'eighty' => 80, 'ninety' => 90, 'hundred' => 100, 'thousand' => 1000,
        'million' => 1000000, 'billion' => 1000000000, 'and' => '',
        // Handle common misspelling
        'fourty' => 40,
    ];

    // Replace all number words with their equivalent numeric value
    $data = strtr(strtolower($text), $wordToNumberMap);

    // Split into parts and convert to numbers
    $parts = array_map('floatval', preg_split('/[\s-]+/', $data));

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

function generateHTMLContent($object) {
    $manifestItems = '';
    $spineItems = '';
    foreach ($object->chapters as $item) {
        $itemId = str_replace("/Novel/{$item->novel_id}/Text/", "", $item->html_file);
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

function generateHTMLToc($object) {
    $firstChapterFile = str_replace("/Novel/{$object->chapters[0]->novel_id}/Text/", "", $object->chapters[0]->html_file);

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

function generateHTMLNav($chapters) {
    $items = '';
    foreach ($chapters as $chapter) {
        $chapterFile = str_replace("/Novel/{$chapter->novel_id}/Text/", "", $chapter->html_file);
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

function generateHTMLChapter($content) {
    return <<<HTML
<?xml version="1.0" encoding="utf-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
<head><title></title></head>
<body>{$content}</body>
</html>
HTML;
}


function splitAndCleanLargeString($input) {
    $result = [];

    // Ensure $input is treated uniformly as an array
    $strings = is_array($input) ? $input : [$input];

    foreach ($strings as $str) {
        // Split the string into sentences
        $sentences = preg_split('/(?<=[.?!])\s+(?=[A-Za-z])/', $str);

        foreach ($sentences as $sentence) {
            // Only add non-empty sentences
            if (!empty($sentence)) {
                $result[] = "<p>" . trim($sentence) . "</p>";
            }
        }
    }

    // Assuming __cleanseChapterArray is defined elsewhere and cleanses the content
    return __cleanseChapterArray($result);
}


function cleanseChapterArray($strings) {
    $cleanseArray = [];
    
    // Define an array of patterns to be filtered out
    $unwantedPatterns = [
        "<p></p>", "<p>&nbsp;</p>", "<p>&nbsp; &nbsp;</p>", 
        "<p><p>", "<p>  </p>", "<p>   </p>", "<p> </p>"
    ];

    foreach ($strings as $i) {
        // Check if the current string matches any unwanted pattern
        if (!in_array($i, $unwantedPatterns)) {
            // If it doesn't match, cleanse the content and add it to the cleanseArray
            $cleanseArray[] = __cleanseChapterContent($i);
        }
    }

    return $cleanseArray;
}

function cleanseChapterContent($string) {
    // Remove specific unwanted strings and replace them with desired alternatives
    $string = str_replace("<Mystic Moon>", "Mystic Moon", $string);
    
    // Consolidate multiple spaces, tabs, newlines into a single space and remove them
    $string = preg_replace('/[\s\t\n\r\0\x0B]+/', ' ', $string);

    // List of phrases to check for their absence in the string
    $phrasesToCheck = [
        "table of contents", "previous chapter", "translated by", "edited by", 
        "translator", "A+ A-", "edited:", "tl: ", "next chapter", 
        "proofreader:", "(tl by "
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

function generateTocChapterInfo($label, $url) {
    if (stripos($label, "teaser") !== false) {
        return; // Early exit if label contains "teaser"
    }
    
    // Normalize label
    $normalizedLabel = preg_replace(['/ +/', '/\(/'], [' ', ' ('], $label);
    $normalizedLabel = preg_replace('/[^A-Za-z0-9 _\.\-\+\&\(\)]/', '', $normalizedLabel);
    
    // Initial variables
    $chapter = $book = 0;
    $alphabets = range('a', 'f');
    
    // Extract book from URL if possible
    if (preg_match('/-(book|volume|vol)-(\d+)/i', $url, $bookMatches)) {
        $book = $bookMatches[2];
    }

    // Process label parts
    $labelParts = explode(' ', $normalizedLabel);
    foreach ($labelParts as $part) {
        // Normalize numeric ranges like 1-2 to 1.2
        if (preg_match('/^(\d+)-(\d+)$/', $part, $rangeMatches)) {
            $chapter = $rangeMatches[1] . '.' . $rangeMatches[2];
            continue;
        }

        // Process parts for chapter and subchapter identification
        if (preg_match('/^(\d+)([a-z])?$/i', $part, $chapterMatches)) {
            $chapter = $chapterMatches[1];
            if (!empty($chapterMatches[2])) {
                $subChapter = array_search(strtolower($chapterMatches[2]), $alphabets) + 1;
                $chapter .= '.' . $subChapter;
            }
        } elseif (preg_match('/^\((\d+)\)$/', $part, $parenthesisMatches)) {
            // Handle chapters like (1)
            $chapter .= '.' . $parenthesisMatches[1];
        }
    }

    // Further refine chapter information based on specific patterns in label
    if (preg_match('/part (\d+)/i', $normalizedLabel, $partMatches)) {
        $chapter .= '.' . $partMatches[1];
    }

    // Final adjustments to ensure chapter and book are correctly formatted
    $chapter = rtrim($chapter, ".-"); // Remove trailing dots or dashes
    $book = (int)$book; // Ensure book is an integer

    return [
        "label" => substr($normalizedLabel, 0, 250),
        "book" => $book,
        "url" => $url,
        "chapter" => $chapter
    ];
}