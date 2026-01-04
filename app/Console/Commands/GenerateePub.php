<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Novel;
use Carbon\Carbon;
use ZipArchive;

class GenerateePub extends Command
{
    protected $signature = "novel:epub {novel=0}";
    protected $description = "Generate ePub for all the completed novels.";

    /**
     * ePub unique identifier for the current book
     */
    protected string $bookUuid;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $novelId = $this->argument("novel");

        // Ensure ePub output directory exists
        $epubDir = storage_path("app/ePub");
        if (!File::isDirectory($epubDir)) {
            File::makeDirectory($epubDir, 0755, true);
        }

        $query = Novel::whereHas("chapters")
            ->with([
                "chapters" => function ($q) {
                    $q->where("blacklist", 0)
                        ->where("status", 1)
                        ->orderBy("book")
                        ->orderBy("chapter");
                },
            ]);

        if ($novelId == 0) {
            $query->where("status", 1)->whereNull("epub_generated");
        } else {
            $query->where("id", $novelId);
        }

        $totalNovels = $query->count();

        if ($totalNovels === 0) {
            $this->info("No novels found to process.");
            return 0;
        }

        $this->info("Found {$totalNovels} novel(s) to process.");
        $processed = 0;

        $query->chunk(5, function ($novels) use ($novelId, &$processed, $totalNovels) {
            foreach ($novels as $novel) {
                $processed++;
                $this->line("");
                $this->info("[{$processed}/{$totalNovels}] Processing: {$novel->name}");

                try {
                    $this->generateEpubForNovel($novel, $novelId != 0);
                } catch (\Exception $e) {
                    $this->error("  Error: " . $e->getMessage());
                    \Log::error("ePub generation failed for novel {$novel->id}: " . $e->getMessage());
                }
            }
        });

        $this->line("");
        $this->info("ePub generation completed.");

        return 0;
    }

    /**
     * Generate ePub for a single novel
     */
    protected function generateEpubForNovel(Novel $novel, bool $forceRegenerate = false): void
    {
        $id = $novel->id;

        // Generate unique UUID for this book
        $this->bookUuid = (string) Str::uuid();

        // Validate chapters exist
        if ($novel->chapters->isEmpty()) {
            $this->warn("  No chapters found. Skipping.");
            return;
        }

        $chapterCount = $novel->chapters->count();
        $this->info("  Found {$chapterCount} chapters.");

        // Sanitize filename
        $safeFilename = $this->sanitizeFilename($novel->name . " - " . ($novel->author ?: "Unknown"));
        $epubPath = storage_path("app/ePub/{$safeFilename}.epub");

        // Clean up existing files if regenerating
        if ($forceRegenerate) {
            $this->cleanupExistingFiles($novel, $epubPath);
        }

        // Setup directories
        $novelDir = storage_path("app/Novel/{$id}");
        $oebpsDir = "{$novelDir}/OEBPS";
        $textDir = "{$oebpsDir}/Text";
        $metaInfDir = "{$novelDir}/META-INF";

        foreach ([$novelDir, $oebpsDir, $textDir, $metaInfDir] as $dir) {
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
        }

        // Step 1: Generate chapter files with progress
        $this->info("  Generating chapters...");
        $bar = $this->output->createProgressBar($chapterCount);
        $bar->start();

        foreach ($novel->chapters as $chapter) {
            $chapterContent = $this->generateChapterXhtml($chapter);
            $chapterFilename = $this->getChapterFilename($chapter);
            $chapterPath = "{$textDir}/{$chapterFilename}";

            File::put($chapterPath, $chapterContent);

            // Update chapter record with relative path for content.opf reference
            $chapter->html_file = "/Novel/{$id}/OEBPS/Text/{$chapterFilename}";
            $chapter->save();

            $bar->advance();
        }

        $bar->finish();
        $this->line("");

        // Reload chapters with updated html_file paths
        $novel->load([
            "chapters" => function ($q) {
                $q->where("blacklist", 0)
                    ->where("status", 1)
                    ->orderBy("book")
                    ->orderBy("chapter");
            },
        ]);

        // Step 2: Generate metadata files
        $this->info("  Generating metadata...");

        // Mimetype (plain text, no XML declaration)
        File::put("{$novelDir}/mimetype", "application/epub+zip");

        // container.xml
        File::put("{$metaInfDir}/container.xml", $this->generateContainerXml());

        // content.opf (package document)
        File::put("{$oebpsDir}/content.opf", $this->generateContentOpf($novel));

        // toc.ncx (NCX navigation for ePub 2 compatibility)
        File::put("{$oebpsDir}/toc.ncx", $this->generateTocNcx($novel));

        // nav.xhtml (ePub 3 navigation)
        File::put("{$textDir}/nav.xhtml", $this->generateNavXhtml($novel));

        // Step 3: Create ePub archive
        $this->info("  Creating ePub archive...");

        if (!$this->createEpub($novelDir, $epubPath)) {
            throw new \RuntimeException("Failed to create ePub archive");
        }

        // Validate the ePub
        if (!$this->validateEpub($epubPath)) {
            $this->warn("  Warning: ePub validation found issues.");
        }

        // Update novel record
        $novel->epub_generated = Carbon::now();
        $novel->save();

        // Get file size
        $fileSize = $this->formatBytes(File::size($epubPath));
        $this->info("  Created: {$safeFilename}.epub ({$fileSize})");
    }

    /**
     * Generate XHTML content for a chapter
     */
    protected function generateChapterXhtml($chapter): string
    {
        $title = htmlspecialchars($chapter->label, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $content = $this->sanitizeHtmlContent($chapter->description);

        return <<<XHTML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="en" lang="en">
<head>
    <meta charset="UTF-8"/>
    <title>{$title}</title>
    <style type="text/css">
        body { font-family: serif; line-height: 1.6; margin: 1em; }
        h1 { font-size: 1.5em; margin-bottom: 1em; text-align: center; }
        p { text-indent: 1.5em; margin: 0.5em 0; }
    </style>
</head>
<body>
    <section epub:type="chapter">
        <h1>{$title}</h1>
        {$content}
    </section>
</body>
</html>
XHTML;
    }

    /**
     * Generate container.xml
     */
    protected function generateContainerXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
    <rootfiles>
        <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
    </rootfiles>
</container>
XML;
    }

    /**
     * Generate content.opf (OPF Package Document)
     */
    protected function generateContentOpf(Novel $novel): string
    {
        $title = htmlspecialchars($novel->name, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $author = htmlspecialchars($novel->author ?: 'Unknown', ENT_QUOTES | ENT_XML1, 'UTF-8');
        $description = htmlspecialchars(strip_tags($novel->description ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $modifiedDate = Carbon::now()->format('Y-m-d\TH:i:s\Z');

        // Generate manifest items
        $manifestItems = [];
        $spineItems = [];

        foreach ($novel->chapters as $index => $chapter) {
            $filename = $this->getChapterFilename($chapter);
            $itemId = "chapter-" . ($index + 1);

            $manifestItems[] = "        <item id=\"{$itemId}\" href=\"Text/{$filename}\" media-type=\"application/xhtml+xml\"/>";
            $spineItems[] = "        <itemref idref=\"{$itemId}\"/>";
        }

        $manifestStr = implode("\n", $manifestItems);
        $spineStr = implode("\n", $spineItems);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="BookId" xml:lang="en">
    <metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">
        <dc:identifier id="BookId">urn:uuid:{$this->bookUuid}</dc:identifier>
        <dc:title>{$title}</dc:title>
        <dc:creator id="creator">{$author}</dc:creator>
        <meta refines="#creator" property="role" scheme="marc:relators">aut</meta>
        <dc:language>en</dc:language>
        <dc:description>{$description}</dc:description>
        <meta property="dcterms:modified">{$modifiedDate}</meta>
    </metadata>
    <manifest>
        <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
        <item id="nav" href="Text/nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
{$manifestStr}
    </manifest>
    <spine toc="ncx">
        <itemref idref="nav" linear="no"/>
{$spineStr}
    </spine>
</package>
XML;
    }

    /**
     * Generate toc.ncx (NCX Navigation - for ePub 2 compatibility)
     */
    protected function generateTocNcx(Novel $novel): string
    {
        $title = htmlspecialchars($novel->name, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $navPoints = [];
        foreach ($novel->chapters as $index => $chapter) {
            $playOrder = $index + 1;
            $label = htmlspecialchars($chapter->label, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $filename = $this->getChapterFilename($chapter);

            $navPoints[] = <<<NAV
        <navPoint id="navPoint-{$playOrder}" playOrder="{$playOrder}">
            <navLabel><text>{$label}</text></navLabel>
            <content src="Text/{$filename}"/>
        </navPoint>
NAV;
        }

        $navPointsStr = implode("\n", $navPoints);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
    <head>
        <meta name="dtb:uid" content="urn:uuid:{$this->bookUuid}"/>
        <meta name="dtb:depth" content="1"/>
        <meta name="dtb:totalPageCount" content="0"/>
        <meta name="dtb:maxPageNumber" content="0"/>
    </head>
    <docTitle>
        <text>{$title}</text>
    </docTitle>
    <navMap>
{$navPointsStr}
    </navMap>
</ncx>
XML;
    }

    /**
     * Generate nav.xhtml (ePub 3 Navigation Document)
     */
    protected function generateNavXhtml(Novel $novel): string
    {
        $title = htmlspecialchars($novel->name, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $tocItems = [];
        foreach ($novel->chapters as $chapter) {
            $label = htmlspecialchars($chapter->label, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $filename = $this->getChapterFilename($chapter);
            $tocItems[] = "                <li><a href=\"{$filename}\">{$label}</a></li>";
        }

        $tocItemsStr = implode("\n", $tocItems);

        // Get first chapter for landmarks
        $firstChapter = $novel->chapters->first();
        $firstChapterFile = $firstChapter ? $this->getChapterFilename($firstChapter) : '';

        return <<<XHTML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="en" lang="en">
<head>
    <meta charset="UTF-8"/>
    <title>Table of Contents</title>
    <style type="text/css">
        nav { font-family: sans-serif; }
        nav ol { list-style-type: none; padding-left: 1em; }
        nav a { text-decoration: none; color: #333; }
        nav a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <nav epub:type="toc" id="toc">
        <h1>Table of Contents</h1>
        <ol>
{$tocItemsStr}
        </ol>
    </nav>
    <nav epub:type="landmarks" id="landmarks" hidden="">
        <h2>Landmarks</h2>
        <ol>
            <li><a epub:type="toc" href="nav.xhtml">Table of Contents</a></li>
            <li><a epub:type="bodymatter" href="{$firstChapterFile}">Start of Content</a></li>
        </ol>
    </nav>
</body>
</html>
XHTML;
    }

    /**
     * Create the ePub file with proper structure
     */
    protected function createEpub(string $novelDir, string $epubPath): bool
    {
        $zip = new ZipArchive();

        if ($zip->open($epubPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Cannot create zip file: {$epubPath}");
            return false;
        }

        // CRITICAL: mimetype must be the first file, stored uncompressed, with no extra field
        $mimetypePath = "{$novelDir}/mimetype";
        if (File::exists($mimetypePath)) {
            // Add mimetype file uncompressed
            $zip->addFile($mimetypePath, "mimetype");
            $zip->setCompressionName("mimetype", ZipArchive::CM_STORE);
        }

        // Add META-INF/container.xml
        $containerPath = "{$novelDir}/META-INF/container.xml";
        if (File::exists($containerPath)) {
            $zip->addFile($containerPath, "META-INF/container.xml");
        }

        // Add OEBPS contents
        $oebpsDir = "{$novelDir}/OEBPS";

        // Add content.opf
        if (File::exists("{$oebpsDir}/content.opf")) {
            $zip->addFile("{$oebpsDir}/content.opf", "OEBPS/content.opf");
        }

        // Add toc.ncx
        if (File::exists("{$oebpsDir}/toc.ncx")) {
            $zip->addFile("{$oebpsDir}/toc.ncx", "OEBPS/toc.ncx");
        }

        // Add all Text files
        $textDir = "{$oebpsDir}/Text";
        if (File::isDirectory($textDir)) {
            $files = File::files($textDir);
            foreach ($files as $file) {
                $zip->addFile($file->getPathname(), "OEBPS/Text/" . $file->getFilename());
            }
        }

        $result = $zip->close();

        return $result;
    }

    /**
     * Validate the ePub file structure
     */
    protected function validateEpub(string $epubPath): bool
    {
        if (!File::exists($epubPath)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($epubPath) !== true) {
            return false;
        }

        $valid = true;
        $requiredFiles = [
            'mimetype',
            'META-INF/container.xml',
            'OEBPS/content.opf',
        ];

        foreach ($requiredFiles as $file) {
            if ($zip->locateName($file) === false) {
                $this->warn("  Missing required file: {$file}");
                $valid = false;
            }
        }

        // Check mimetype content
        $mimetype = $zip->getFromName('mimetype');
        if ($mimetype !== 'application/epub+zip') {
            $this->warn("  Invalid mimetype content");
            $valid = false;
        }

        // Check that mimetype is first entry
        $stat = $zip->statIndex(0);
        if ($stat && $stat['name'] !== 'mimetype') {
            $this->warn("  Mimetype is not the first file in archive");
            $valid = false;
        }

        $zip->close();

        return $valid;
    }

    /**
     * Get standardized chapter filename
     */
    protected function getChapterFilename($chapter): string
    {
        return sprintf("chapter_%05d.xhtml", $chapter->id);
    }

    /**
     * Sanitize HTML content for XHTML compatibility
     */
    protected function sanitizeHtmlContent(?string $content): string
    {
        if (empty($content)) {
            return '<p></p>';
        }

        // Convert common HTML entities
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove scripts and styles
        $content = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $content);

        // Remove event handlers
        $content = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);

        // Convert <br> and <br/> to <br/>
        $content = preg_replace('/<br\s*\/?>/i', '<br/>', $content);

        // Ensure paragraphs are properly closed
        $content = preg_replace('/<p([^>]*)>/i', '<p$1>', $content);

        // Fix self-closing tags for XHTML
        $selfClosingTags = ['br', 'hr', 'img', 'input', 'meta', 'link'];
        foreach ($selfClosingTags as $tag) {
            $content = preg_replace("/<{$tag}([^>]*?)(?<!\/)>/i", "<{$tag}$1/>", $content);
        }

        // Remove invalid XML characters
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);

        // Encode special XML characters in text (not in tags)
        $content = preg_replace_callback(
            '/>(.*?)</s',
            function ($matches) {
                $text = $matches[1];
                // Only encode if not already encoded
                if (strpos($text, '&') !== false && !preg_match('/&(amp|lt|gt|quot|apos|#\d+|#x[0-9a-f]+);/i', $text)) {
                    $text = str_replace('&', '&amp;', $text);
                }
                return '>' . $text . '<';
            },
            $content
        );

        return trim($content);
    }

    /**
     * Clean up existing ePub and temporary files
     */
    protected function cleanupExistingFiles(Novel $novel, string $epubPath): void
    {
        if (File::exists($epubPath)) {
            File::delete($epubPath);
            $this->info("  Removed existing ePub file.");
        }

        $novelDir = storage_path("app/Novel/{$novel->id}");
        if (File::isDirectory($novelDir)) {
            File::deleteDirectory($novelDir);
            $this->info("  Cleaned up temporary files.");
        }
    }

    /**
     * Sanitize filename for safe filesystem usage
     */
    protected function sanitizeFilename(string $filename): string
    {
        // Remove or replace problematic characters
        $filename = preg_replace('/[\/\\\\:*?"<>|]/', '', $filename);
        $filename = preg_replace('/\s+/', ' ', $filename);
        $filename = trim($filename);

        if (strlen($filename) > 200) {
            $filename = substr($filename, 0, 200);
        }

        return $filename ?: 'novel';
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor] ?? 'B');
    }
}
