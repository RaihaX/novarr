<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\NovelChapter;
use App\Novel;

class NormalizeChapterLabels extends Command
{
    protected $signature = 'novel:normalize_labels {novel=0} {--dry-run : Preview changes without saving}';
    protected $description = 'Normalize chapter labels and fix chapter numbers for proper sorting.';

    /**
     * Spam phrases to remove from chapter labels
     */
    private $spamPhrases = [
        // Reading requests (various forms)
        '(Please Continue Reading)',
        '(Please Chase Reading)',
        '(Seeking Chase Reading)',
        '(Chase Reading Requested)',
        '(Chase Reading)',
        '(Request for Chase Reading)',
        '(Seeking to Chase Reading)',
        '(Seeking for Chase Reading)',
        '(seeking subscription)',
        '(Subscription Requested)',
        '(Subscription Request)',
        '(Subscribe Please)',
        '(Please Subscribe)',
        '(Please subscribe)',
        '(Subscriptions Wanted)',
        '(Seeking Subscriptions)',
        '(Subscribe)',
        '(Request for Subscription)',
        '(Must-read Chapter)',
        '(Pseudo)',

        // Partial/truncated versions (without closing paren)
        'Seeking Chase Reading)',
        'Please Continue Reading)',
        'Chase Reading)',
        'Please Subscribe)',
        'Subscribe)',

        // Embedded in text without parentheses
        'Seeking for Chase Reading',
        'Seeking Chase Reading',
        'Please Continue Reading',
        'Chase Reading Requested',
        'Request for Chase Reading',
        'Please Chase Reading',
        'Chase Reading',
        'Please Subscribe',
        'Subscribe',
    ];

    /**
     * Regex patterns for spam that might be embedded in title
     */
    private $spamPatterns = [
        '/\s*\(Please[^)]*\)?\s*$/i',
        '/\s*\(Seeking[^)]*\)?\s*$/i',
        '/\s*\(Subscribe[^)]*\)?\s*$/i',
        '/\s*\(Chase[^)]*\)?\s*$/i',
        '/\s*\(Request[^)]*\)?\s*$/i',
        '/\s*\(Must-read[^)]*\)?\s*$/i',
        '/\s*\(Pseudo\)\s*$/i',
        '/\s*Seeking for Chase Reading\s*$/i',
        '/\s*Seeking for\s*$/i',
        '/\s*Please\s*$/i',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $novelId = $this->argument('novel');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be saved');
        }

        $query = NovelChapter::query();

        if ($novelId != 0) {
            $query->where('novel_id', $novelId);
            $novel = Novel::find($novelId);
            $this->info("Processing novel: {$novel->name}");
        } else {
            $this->info("Processing all novels");
        }

        $chapters = $query->orderBy('novel_id')->orderBy('chapter')->get();
        $updatedCount = 0;

        foreach ($chapters as $chapter) {
            $originalLabel = $chapter->label;
            $originalChapterNum = $chapter->chapter;

            // Extract the correct chapter number from label and URL
            $extractedChapterNum = $this->extractChapterNumber($originalLabel, $originalChapterNum, $chapter->url);

            // Normalize the label using the extracted chapter number
            $normalizedLabel = $this->normalizeLabel($originalLabel, $extractedChapterNum);

            $labelChanged = $originalLabel !== $normalizedLabel;
            $chapterNumChanged = $originalChapterNum != $extractedChapterNum;

            if ($labelChanged || $chapterNumChanged) {
                $updatedCount++;

                if ($dryRun) {
                    $this->line("Chapter {$originalChapterNum}:");
                    $this->line("  Label FROM: {$originalLabel}");
                    $this->info("  Label TO:   {$normalizedLabel}");
                    if ($chapterNumChanged) {
                        $this->warn("  Chapter# FROM: {$originalChapterNum} -> TO: {$extractedChapterNum}");
                    }
                    $this->line('');
                } else {
                    $chapter->label = $normalizedLabel;
                    if ($chapterNumChanged) {
                        $chapter->chapter = $extractedChapterNum;
                    }
                    $chapter->save();
                    $chapterInfo = $chapterNumChanged
                        ? "Chapter {$originalChapterNum}->{$extractedChapterNum}"
                        : "Chapter {$extractedChapterNum}";
                    $this->info("Updated: {$chapterInfo} - {$normalizedLabel}");
                }
            }
        }

        $this->info("Total chapters updated: {$updatedCount}");
    }

    /**
     * Extract the correct chapter number from the label and URL
     *
     * Strategy:
     * - Extract from URL first (most reliable source)
     * - Fall back to label if URL doesn't have chapter number
     * - Handle part suffixes (_2 -> .2)
     */
    private function extractChapterNumber(string $label, $currentChapterNum, ?string $url = null)
    {
        $currentBase = floor((float) $currentChapterNum);
        $currentDecimal = (float) $currentChapterNum - $currentBase;

        // Try to extract chapter number from URL first (most reliable)
        $urlChapterNum = null;
        if ($url && preg_match('/chapter-(\d+)/i', $url, $urlMatches)) {
            $urlChapterNum = (int) $urlMatches[1];
        }

        // Extract chapter number from label
        $labelChapterNum = null;
        if (preg_match('/^Chapter\s*(\d+)/i', $label, $matches)) {
            $labelChapterNum = (int) $matches[1];
        }

        // Handle part suffixes like _2 -> add .2
        // URL pattern: ends with a digit directly attached (no dash), e.g., "wizard2" means Part 2
        $partSuffix = 0;
        if ($url && preg_match('/[a-z](\d)$/', $url, $partMatch)) {
            // URL ends with letter+digit like "wizard2" -> Part 2
            $partSuffix = (float) $partMatch[1] / 10;
        } elseif (preg_match('/_(\d+)$/', $label, $partMatch)) {
            // Label suffix like "Title_2"
            $partSuffix = (float) $partMatch[1] / 10;
        } elseif (preg_match('/\(Part\s*(\d+)\)/i', $label, $partMatch)) {
            // Already formatted as "(Part 2)"
            $partSuffix = (float) $partMatch[1] / 10;
        }

        // Priority 1: If URL has a chapter number and current is 0 or very wrong, use URL
        if ($urlChapterNum !== null && ($currentBase == 0 || abs($urlChapterNum - $currentBase) > 100)) {
            return $urlChapterNum + $partSuffix;
        }

        // Priority 2: If label chapter is 0 but URL has valid chapter, use URL
        if ($labelChapterNum === 0 && $urlChapterNum !== null && $urlChapterNum > 0) {
            return $urlChapterNum + $partSuffix;
        }

        // Priority 3: Current has a .9 decimal (parsing error) - use URL or label
        if ($currentDecimal >= 0.9 && $currentDecimal < 1.0) {
            if ($urlChapterNum !== null) {
                return $urlChapterNum + $partSuffix;
            }
            if ($labelChapterNum !== null) {
                return $labelChapterNum + $partSuffix;
            }
        }

        // Priority 4: If we have URL chapter and it's close to current, trust URL
        if ($urlChapterNum !== null && abs($urlChapterNum - $currentBase) <= 50) {
            return $urlChapterNum + $partSuffix;
        }

        // Priority 5: Label chapter matches current - add part suffix if any
        if ($labelChapterNum !== null && $labelChapterNum == $currentBase) {
            return $labelChapterNum + $partSuffix;
        }

        // Default: keep current but add part suffix
        return $currentBase + $partSuffix;
    }

    /**
     * Normalize a chapter label
     */
    private function normalizeLabel(string $label, $chapterNumber): string
    {
        // Step 1: Remove spam phrases
        $cleaned = $this->removeSpamPhrases($label);

        // Step 2: Parse and reformat the chapter structure
        $cleaned = $this->reformatChapterLabel($cleaned, $chapterNumber);

        // Step 3: Clean up extra whitespace and trailing characters
        $cleaned = $this->cleanupWhitespace($cleaned);

        return $cleaned;
    }

    /**
     * Remove spam/promotional phrases from label
     */
    private function removeSpamPhrases(string $label): string
    {
        // Sort by length descending to match longer phrases first
        $phrases = $this->spamPhrases;
        usort($phrases, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($phrases as $phrase) {
            $label = str_ireplace($phrase, '', $label);
        }

        // Apply regex patterns for partial/embedded spam
        foreach ($this->spamPatterns as $pattern) {
            $label = preg_replace($pattern, '', $label);
        }

        return $label;
    }

    /**
     * Reformat chapter label to standard format: "Chapter X - Title"
     *
     * Input patterns detected:
     * - "Chapter 1 - 1 1 Title" -> "Chapter 1 - Title"
     * - "Chapter 5 - 5 5 Title" -> "Chapter 5 - Title"
     * - "Chapter 100 - 94 Title" -> "Chapter 100 - Title"
     * - "Chapter 101 - 101 94 Title_2" -> "Chapter 101 - Title (Part 2)"
     * - "Chapter321 191 Title_2" -> "Chapter 321 - Title (Part 2)"
     */
    private function reformatChapterLabel(string $label, $chapterNumber): string
    {
        // Handle labels starting with "ChapterXXX" (no space after Chapter)
        // e.g., "Chapter321 191 Level 8 Wizard..."
        if (preg_match('/^Chapter(\d+)\s+(\d+)\s+(.+)$/i', $label, $matches)) {
            $title = $this->cleanTitle($matches[3]);
            return "Chapter " . floor($chapterNumber) . " - " . $title;
        }

        // Handle standard format: "Chapter X - Y Z Title" or "Chapter X - Y Title"
        // Where Y and Z are redundant chapter numbers
        if (preg_match('/^Chapter\s*(\d+)\s*-\s*(.+)$/i', $label, $matches)) {
            $afterDash = trim($matches[2]);

            // Pattern: "101 94 Title" or "5 5 Title" - multiple numbers before title
            if (preg_match('/^(\d+)\s+(\d+)\s+(.+)$/i', $afterDash, $subMatches)) {
                $title = $this->cleanTitle($subMatches[3]);
                return "Chapter " . floor($chapterNumber) . " - " . $title;
            }

            // Pattern: "94 Title" - single number before title
            if (preg_match('/^(\d+)\s+(.+)$/i', $afterDash, $subMatches)) {
                // Check if the number is actually part of the title (like "94 The Reappearance")
                // If the first word after number starts with uppercase, it's likely a title
                $potentialTitle = trim($subMatches[2]);
                if (preg_match('/^[A-Z]/', $potentialTitle)) {
                    $title = $this->cleanTitle($potentialTitle);
                    return "Chapter " . floor($chapterNumber) . " - " . $title;
                }
            }

            // Pattern: just "Title" after dash
            $title = $this->cleanTitle($afterDash);
            return "Chapter " . floor($chapterNumber) . " - " . $title;
        }

        // If no pattern matched, just clean the original
        return $this->cleanTitle($label);
    }

    /**
     * Clean title text - handle part suffixes and extra characters
     */
    private function cleanTitle(string $title): string
    {
        $title = trim($title);

        // Extract part number suffix BEFORE removing spam (handles cases like "(Please Subscribe)_2")
        $partNumber = null;
        if (preg_match('/\s*_(\d+)$/', $title, $matches)) {
            $partNumber = $matches[1];
            $title = preg_replace('/\s*_\d+$/', '', $title);
        }

        // Remove any spam phrases that might be embedded
        $title = $this->removeSpamPhrases($title);

        // Remove leading numbers if they duplicate the chapter number
        // e.g., "1 Wizards Life Simulator" when it's already Chapter 1
        $title = preg_replace('/^\d+\s+/', '', $title);

        // Remove "I " or "ll " at start (likely OCR errors for part numbers)
        $title = preg_replace('/^(I|ll|Il)\s+/', '', $title);

        // Remove trailing incomplete parentheses (unclosed only)
        // But preserve valid closing parens like "(Part 2)"
        $title = preg_replace('/\s*\([^)]*$/', '', $title);

        // Only remove orphan closing paren if there's no matching open paren
        if (preg_match('/\)$/', $title) && substr_count($title, '(') < substr_count($title, ')')) {
            $title = preg_replace('/\s*\)$/', '', $title);
        }

        // Remove leading/trailing special characters (but not parens)
        $title = trim($title, " \t\n\r\0\x0B-_.");

        // Add part number suffix if we extracted one
        if ($partNumber !== null) {
            $title .= " (Part " . $partNumber . ")";
        }

        return $title;
    }

    /**
     * Clean up extra whitespace
     */
    private function cleanupWhitespace(string $label): string
    {
        // Replace multiple spaces with single space
        $label = preg_replace('/\s+/', ' ', $label);

        // Remove space before punctuation
        $label = preg_replace('/\s+([,.\)])/', '$1', $label);

        // Clean up dash spacing ONLY for separator dashes (surrounded by spaces or at word boundaries)
        // Don't touch hyphens in compound words like "Quasi-Knight"
        // Pattern: space(s) + dash + space(s) -> single space + dash + single space
        $label = preg_replace('/\s+-\s+/', ' - ', $label);

        // Remove trailing dash
        $label = preg_replace('/\s*-\s*$/', '', $label);

        return trim($label);
    }
}
