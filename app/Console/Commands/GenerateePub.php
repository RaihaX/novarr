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
     * Cover image info for current book
     */
    protected ?array $coverInfo = null;

    /**
     * OEBPS-relative paths of every file listed in the OPF manifest for the
     * current book. createEpub() zips exactly these (plus mimetype and
     * META-INF/container.xml) and nothing else.
     *
     * @var string[]
     */
    protected array $manifestFiles = [];

    /** Elements kept in chapter bodies (the reader's allowlist plus a few harmless blocks). */
    protected const ALLOWED_TAGS = ['p', 'br', 'hr', 'em', 'strong', 'i', 'b', 'u', 's', 'sub', 'sup', 'blockquote'];

    /** Elements removed together with their content. */
    protected const DROP_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'head', 'title', 'meta', 'link', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math'];

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

        // Chapters are NOT eager-loaded here: their `description` is a longtext
        // and loading every chapter for a batch of novels at once is the whole
        // book xN in RAM. generateEpubForNovel() loads lightweight chapter
        // metadata once and streams the bodies in id-keyed batches.
        $query = Novel::whereHas("chapters")
            ->with("file"); // cover file relationship only

        if ($novelId == 0) {
            $query->where("status", 1)->whereNull("epub_generated");
        } else {
            $query->where("id", $novelId);
        }

        $totalNovels = $query->count();

        if ($totalNovels === 0) {
            $this->info("No novels found to process.");
            return self::SUCCESS;
        }

        $this->info("Found {$totalNovels} novel(s) to process.");
        $processed = 0;
        $failed = [];

        // lazyById, not chunk(): each successful novel gets epub_generated set,
        // which drops it out of the whereNull() filter. Offset-based chunk()
        // would then skip a page's worth of eligible novels on every batch;
        // keyset pagination on the id is immune to the result set shrinking.
        foreach ($query->lazyById(5) as $novel) {
            $processed++;
            $this->line("");
            $this->info("[{$processed}/{$totalNovels}] Processing: {$novel->name}");

            try {
                $this->generateEpubForNovel($novel, $novelId != 0);
            } catch (\Throwable $e) {
                $failed[] = $novel->id;
                $this->error("  Error: " . $e->getMessage());
                \Log::error("ePub generation failed for novel {$novel->id}: " . $e->getMessage());
            }
        }

        $this->line("");

        // A non-zero exit is what VerifyCompletion (and the scheduler) key off
        // to raise the "ePub generation failed" webhook.
        if ($failed) {
            $this->error(sprintf(
                "ePub generation finished with %d failure(s) (novel id: %s).",
                count($failed),
                implode(", ", $failed)
            ));
            return self::FAILURE;
        }

        $this->info("ePub generation completed.");

        return self::SUCCESS;
    }

    /**
     * Generate ePub for a single novel.
     *
     * $forceRegenerate is kept for callers/overrides; every run now builds in
     * a fresh directory and atomically replaces the previous ePub, so there is
     * nothing left to clean up beforehand.
     */
    protected function generateEpubForNovel(Novel $novel, bool $forceRegenerate = false): void
    {
        $id = $novel->id;

        // Generate unique UUID for this book
        $this->bookUuid = (string) Str::uuid();
        $this->coverInfo = null;

        // Lightweight chapter metadata (no `description` longtext). Used for the
        // count, the OPF manifest, the NCX and the nav document — none of which
        // need the body. Set as the relation so the metadata generators that read
        // $novel->chapters operate on this slim collection.
        $chapters = $novel->chapters()
            ->where("blacklist", 0)
            ->where("status", 1)
            ->ordered()
            ->get(["id", "novel_id", "label", "book", "chapter"]);
        $novel->setRelation("chapters", $chapters);

        // Validate chapters exist
        if ($novel->chapters->isEmpty()) {
            $this->warn("  No chapters found. Skipping.");
            return;
        }

        $chapterCount = $novel->chapters->count();
        $this->info("  Found {$chapterCount} chapters.");

        // Sanitize filename (shared with the download action / OPDS feed)
        $safeFilename = basename(self::epubFilename($novel), ".epub");
        $epubPath = self::epubPath($novel);

        // Builds left behind by older versions of this command, which reused
        // one fixed directory per novel (and so could zip stale chapters).
        $this->removeLegacyBuildDir($novel);

        // A fresh, private build directory per run: nothing from an earlier or
        // concurrent run can leak into the archive, and it is always removed.
        $buildDir = storage_path("app/epub-build/{$id}-" . Str::random(12));
        $this->manifestFiles = [];

        try {
            $this->buildEpub($novel, $chapters, $buildDir, $epubPath);
        } finally {
            if (File::isDirectory($buildDir)) {
                File::deleteDirectory($buildDir);
            }
        }

        // Update novel record
        $novel->epub_generated = Carbon::now();
        $novel->save();

        // Get file size
        $fileSize = $this->formatBytes(File::size($epubPath));
        $this->info("  Created: {$safeFilename}.epub ({$fileSize})");
    }

    /**
     * Write every ePub part into $buildDir, zip it and move the archive into
     * place at $epubPath. The previous ePub (if any) is only replaced once the
     * new archive has been written successfully.
     */
    protected function buildEpub(Novel $novel, $chapters, string $buildDir, string $epubPath): void
    {
        $oebpsDir = "{$buildDir}/OEBPS";
        $textDir = "{$oebpsDir}/Text";
        $imagesDir = "{$oebpsDir}/Images";
        $metaInfDir = "{$buildDir}/META-INF";

        foreach ([$buildDir, $oebpsDir, $textDir, $imagesDir, $metaInfDir] as $dir) {
            File::ensureDirectoryExists($dir, 0755);
        }

        // Step 1: Process cover image
        $this->processCoverImage($novel, $imagesDir);
        if ($this->coverInfo) {
            $this->manifestFiles[] = "Images/{$this->coverInfo['filename']}";
        }

        // Step 2: Generate chapter files with progress
        $this->info("  Generating chapters...");
        $bar = $this->output->createProgressBar($chapters->count());
        $bar->start();

        // Only chapters in the metadata list (the OPF manifest/spine) are
        // written and zipped.
        $wanted = $chapters->pluck("id")->flip();

        // Stream chapter bodies in id-keyed batches — only one batch of body
        // text is resident at a time, so peak memory is flat regardless of
        // book length. File order inside the archive doesn't matter (reading
        // order comes from the spine), so keyset pagination on the id is fine.
        \App\NovelChapter::query()
            ->leftJoin("chapter_texts", "chapter_texts.novel_chapter_id", "=", "novel_chapters.id")
            ->where("novel_chapters.novel_id", $novel->id)
            ->where("novel_chapters.blacklist", 0)
            ->where("novel_chapters.status", 1)
            ->select(["novel_chapters.id", "novel_chapters.label", "chapter_texts.content as raw_content"])
            ->lazyById(100, "novel_chapters.id", "id")
            ->each(function ($chapter) use ($textDir, $bar, $wanted) {
                if (!$wanted->has($chapter->id)) {
                    return;
                }
                $chapterFilename = $this->getChapterFilename($chapter);
                File::put("{$textDir}/{$chapterFilename}", $this->generateChapterXhtml($chapter));
                $bar->advance();
            });

        foreach ($chapters as $chapter) {
            $this->manifestFiles[] = "Text/" . $this->getChapterFilename($chapter);
        }

        $bar->finish();
        $this->line("");

        // Step 3: Generate metadata files
        $this->info("  Generating metadata...");

        // Mimetype (plain text, no XML declaration)
        File::put("{$buildDir}/mimetype", "application/epub+zip");

        // container.xml
        File::put("{$metaInfDir}/container.xml", $this->generateContainerXml());

        // Cover page (if cover exists)
        if ($this->coverInfo) {
            File::put("{$textDir}/cover.xhtml", $this->generateCoverXhtml($novel));
            $this->manifestFiles[] = "Text/cover.xhtml";
        }

        // content.opf (package document)
        File::put("{$oebpsDir}/content.opf", $this->generateContentOpf($novel));
        $this->manifestFiles[] = "content.opf";

        // toc.ncx (NCX navigation for ePub 2 compatibility)
        File::put("{$oebpsDir}/toc.ncx", $this->generateTocNcx($novel));
        $this->manifestFiles[] = "toc.ncx";

        // nav.xhtml (ePub 3 navigation)
        File::put("{$textDir}/nav.xhtml", $this->generateNavXhtml($novel));
        $this->manifestFiles[] = "Text/nav.xhtml";

        // Step 4: Create ePub archive (inside the build dir, then moved into
        // place so a failed run never leaves a half-written ePub behind).
        $this->info("  Creating ePub archive...");

        $tmpEpub = "{$buildDir}/book.epub";
        if (!$this->createEpub($buildDir, $tmpEpub)) {
            throw new \RuntimeException("Failed to create ePub archive");
        }

        // Validate the ePub
        if (!$this->validateEpub($tmpEpub)) {
            $this->warn("  Warning: ePub validation found issues.");
        }

        File::ensureDirectoryExists(dirname($epubPath), 0755);
        if (!@rename($tmpEpub, $epubPath) && !(File::copy($tmpEpub, $epubPath))) {
            throw new \RuntimeException("Failed to move the ePub into place at {$epubPath}");
        }
    }

    /**
     * Process and copy cover image
     */
    protected function processCoverImage(Novel $novel, string $imagesDir): void
    {
        $coverPath = null;

        // Try to get cover from file relationship first.
        // file_path is stored as "public/{hash}.jpg" — actual file lives at storage/app/public/{hash}.jpg,
        // so resolve via storage_path("app/" . file_path). Also accept a bare filename for forward-compat.
        if ($novel->file && $novel->file->file_path) {
            $candidates = [
                storage_path("app/" . $novel->file->file_path),
                storage_path("app/public/" . $novel->file->file_path),
            ];
            foreach ($candidates as $candidate) {
                if (File::exists($candidate)) {
                    $coverPath = $candidate;
                    break;
                }
            }
        }

        // Try cover field (public storage)
        if (!$coverPath && $novel->cover) {
            $publicPath = storage_path("app/public/" . $novel->cover);
            if (File::exists($publicPath)) {
                $coverPath = $publicPath;
            }
        }

        if (!$coverPath) {
            // Last resort: render the Novarr brand placeholder cover so every
            // book still gets a real library thumbnail instead of a grey tile.
            if ($this->generatePlaceholderCover($novel, $imagesDir)) {
                return;
            }

            $this->info("  No cover image found.");
            return;
        }

        // Get image info
        $imageInfo = @getimagesize($coverPath);
        if (!$imageInfo) {
            $this->warn("  Cover image is invalid or corrupted.");
            return;
        }

        // Amazon's Send-to-Kindle converter only reliably renders JPEG covers —
        // WebP/GIF covers produce the generic grey "DOC" tile in the library.
        // Stored covers often have a .jpg name but WebP data, so decide by the
        // real mime (from getimagesize) and re-encode anything non-JPEG.
        $mimeType = $imageInfo['mime'];
        $supported = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($mimeType, $supported, true)) {
            $this->warn("  Unsupported cover image format: {$mimeType}");
            return;
        }

        $coverFilename = "cover.jpg";
        $destPath = "{$imagesDir}/{$coverFilename}";

        $written = $mimeType === 'image/jpeg'
            ? File::copy($coverPath, $destPath)
            : $this->convertCoverToJpeg($coverPath, $mimeType, $destPath);

        if (!$written) {
            $this->warn("  Failed to prepare cover image.");
            return;
        }

        $this->coverInfo = [
            'filename' => $coverFilename,
            'mime' => 'image/jpeg',
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
        ];

        if (max($imageInfo[0], $imageInfo[1]) < 500) {
            $this->warn("  Cover is only {$imageInfo[0]}x{$imageInfo[1]} — Kindle may skip the library thumbnail for very small covers.");
        }

        $converted = $mimeType === 'image/jpeg' ? '' : " (converted from {$mimeType})";
        $this->info("  Cover image added: {$coverFilename}{$converted}");
    }

    /**
     * Render the brand fallback cover (1600x2400) for a novel with no artwork.
     * Written as JPEG because Amazon's Send-to-Kindle converter only reliably
     * renders JPEG covers — same constraint as the downloaded-cover path above.
     */
    protected function generatePlaceholderCover(Novel $novel, string $imagesDir): bool
    {
        $generator = app(\App\Services\DefaultCoverGenerator::class);

        if (!$generator->isAvailable()) {
            $this->warn("  No cover image found and the placeholder generator is unavailable (GD/FreeType or brand fonts missing).");
            return false;
        }

        $coverFilename = "cover.jpg";
        $destPath = "{$imagesDir}/{$coverFilename}";

        try {
            $generator->generateForNovel($novel, $destPath, 'jpg');
        } catch (\Throwable $e) {
            $this->warn("  Failed to render placeholder cover: " . $e->getMessage());
            \Log::warning("Placeholder cover generation failed for novel {$novel->id}: " . $e->getMessage());
            return false;
        }

        $this->coverInfo = [
            'filename' => $coverFilename,
            'mime' => 'image/jpeg',
            'width' => \App\Services\DefaultCoverGenerator::WIDTH,
            'height' => \App\Services\DefaultCoverGenerator::HEIGHT,
        ];

        $this->info("  No cover image found — generated the Novarr placeholder cover.");

        return true;
    }

    /**
     * Re-encode a PNG/GIF/WebP cover as a flat JPEG (transparency composited onto white)
     */
    protected function convertCoverToJpeg(string $sourcePath, string $mimeType, string $destPath): bool
    {
        $image = match ($mimeType) {
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            default => false,
        };

        if (!$image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        return imagejpeg($canvas, $destPath, 85);
    }

    /**
     * Generate cover page XHTML
     */
    protected function generateCoverXhtml(Novel $novel): string
    {
        $title = htmlspecialchars($novel->name, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $coverFile = $this->coverInfo['filename'];

        // Plain <img> tag — Amazon's Send-to-Kindle converter does not reliably
        // extract covers from SVG-wrapped XHTML. <img> renders correctly in
        // Calibre/Apple Books too.
        return <<<XHTML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="en" lang="en">
<head>
    <meta charset="UTF-8"/>
    <title>Cover</title>
    <style type="text/css">
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            text-align: center;
        }
        img {
            max-width: 100%;
            max-height: 100%;
            height: auto;
        }
    </style>
</head>
<body epub:type="cover">
    <div>
        <img src="../Images/{$coverFile}" alt="{$title}"/>
    </div>
</body>
</html>
XHTML;
    }

    /**
     * Generate XHTML content for a chapter
     */
    protected function generateChapterXhtml($chapter): string
    {
        $title = htmlspecialchars($chapter->label, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $content = $this->sanitizeHtmlContent(\App\NovelChapter::presentContent($chapter->raw_content));

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

        // Cover metadata
        $coverMeta = '';
        $coverManifest = '';
        $coverSpine = '';
        $coverGuide = '';

        if ($this->coverInfo) {
            // Meta tag for ePub 2 readers (Calibre uses this)
            $coverMeta = '        <meta name="cover" content="cover-image"/>';
            // Manifest items for cover image and cover page
            $coverManifest = <<<MANIFEST
        <item id="cover-image" href="Images/{$this->coverInfo['filename']}" media-type="{$this->coverInfo['mime']}" properties="cover-image"/>
        <item id="cover" href="Text/cover.xhtml" media-type="application/xhtml+xml"/>
MANIFEST;
            // Cover must be first in spine and linear="yes" for Calibre
            $coverSpine = '        <itemref idref="cover" linear="yes"/>';
            // Guide reference for cover (important for Calibre)
            $coverGuide = '        <reference type="cover" title="Cover" href="Text/cover.xhtml"/>';
        }

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
{$coverMeta}
    </metadata>
    <manifest>
        <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
        <item id="nav" href="Text/nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
{$coverManifest}
{$manifestStr}
    </manifest>
    <spine toc="ncx">
{$coverSpine}
        <itemref idref="nav" linear="no"/>
{$spineStr}
    </spine>
    <guide>
{$coverGuide}
        <reference type="toc" title="Table of Contents" href="Text/nav.xhtml"/>
    </guide>
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

        // Cover landmark
        $coverLandmark = '';
        if ($this->coverInfo) {
            $coverLandmark = '            <li><a epub:type="cover" href="cover.xhtml">Cover</a></li>';
        }

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
{$coverLandmark}
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
            $zip->addFile($mimetypePath, "mimetype");
            $zip->setCompressionName("mimetype", ZipArchive::CM_STORE);
        }

        // Add META-INF/container.xml
        $containerPath = "{$novelDir}/META-INF/container.xml";
        if (File::exists($containerPath)) {
            $zip->addFile($containerPath, "META-INF/container.xml");
        }

        // Only the files listed in the OPF manifest — never a directory scan,
        // so nothing stray (stale chapters, temp files) ends up in the book.
        foreach ($this->manifestFiles as $relative) {
            $source = "{$novelDir}/OEBPS/{$relative}";
            if (!File::exists($source)) {
                $zip->close();
                $this->error("Manifest file missing from build: OEBPS/{$relative}");
                return false;
            }
            $zip->addFile($source, "OEBPS/{$relative}");
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

        // Validate cover image if present
        if ($this->coverInfo) {
            $coverPath = "OEBPS/Images/{$this->coverInfo['filename']}";
            if ($zip->locateName($coverPath) === false) {
                $this->warn("  Cover image missing from archive");
                $valid = false;
            }

            // Parse content.opf and verify cover wiring (manifest properties, ePub2 meta, spine, guide).
            $opf = $zip->getFromName('OEBPS/content.opf');
            if ($opf !== false) {
                $checks = [
                    'manifest properties="cover-image"' => '/<item[^>]+id="cover-image"[^>]+properties="cover-image"/',
                    'ePub2 <meta name="cover">' => '/<meta\s+name="cover"\s+content="cover-image"\s*\/>/',
                    'cover in spine' => '/<itemref\s+idref="cover"[^>]*\/>/',
                    'cover guide reference' => '/<reference\s+type="cover"[^>]+href="Text\/cover\.xhtml"/',
                ];
                foreach ($checks as $label => $pattern) {
                    if (!preg_match($pattern, $opf)) {
                        $this->warn("  OPF missing: {$label}");
                        $valid = false;
                    }
                }
            }
        }

        // Every XML part must be well-formed: Kindle's converter rejects the
        // whole book over a single malformed chapter.
        $malformed = [];
        $previous = libxml_use_internal_errors(true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!preg_match('/\.(xhtml|opf|ncx|xml)$/', $name)) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            if ($xml === false || simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET) === false) {
                $malformed[] = $name;
            }
            libxml_clear_errors();
        }
        libxml_use_internal_errors($previous);

        if ($malformed) {
            $this->warn("  Malformed XML in " . count($malformed) . " file(s): " . implode(", ", array_slice($malformed, 0, 5)));
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
     * Turn a stored chapter body (an HTML fragment whose text is already
     * entity-escaped) into well-formed XHTML.
     *
     * The fragment is parsed with libxml's HTML parser and re-serialised node
     * by node with saveXML(), so text is always escaped (&amp; &lt;) and void
     * elements are self-closed. Entities are never decoded into raw markup.
     */
    protected function sanitizeHtmlContent(?string $content): string
    {
        if ($content === null || trim($content) === '') {
            return '<p></p>';
        }

        // Invalid UTF-8 and C0 control characters are not allowed in XML.
        $content = mb_scrub($content, 'UTF-8');
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);

        // A "<" that cannot start a tag ("I <3 you", "a < b") is text. libxml's
        // HTML parser can swallow everything up to the next ">" after one, so
        // escape it before parsing.
        $content = preg_replace('/<(?![a-zA-Z\/!])/', '&lt;', $content);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            // The <?xml encoding> hint makes libxml read the fragment as UTF-8
            // (its HTML parser otherwise assumes ISO-8859-1).
            $loaded = $doc->loadHTML(
                '<?xml encoding="UTF-8"?><html><body><div id="novarr-epub-root">' . $content . '</div></body></html>',
                LIBXML_NONET | LIBXML_COMPACT
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $loaded ? $doc->getElementById('novarr-epub-root') : null;
        if (!$root) {
            // Unparseable — fall back to the plain text, fully escaped.
            $text = htmlspecialchars(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_XML1, 'UTF-8');
            return $text === '' ? '<p></p>' : "<p>{$text}</p>";
        }

        $this->sanitizeNode($root);

        $xhtml = '';
        foreach ($root->childNodes as $child) {
            $xhtml .= $doc->saveXML($child);
        }

        // saveXML() writes empty non-void elements as <p/>; some reading
        // systems treat that as an unclosed start tag, so expand them.
        $xhtml = preg_replace('/<(?!br\b|hr\b)([a-z][a-z0-9]*)\/>/', '<$1></$1>', $xhtml);
        $xhtml = trim($xhtml);

        return $xhtml === '' ? '<p></p>' : $xhtml;
    }

    /**
     * Recursively drop disallowed elements (with content), unwrap unknown
     * ones (keeping their text), strip every attribute and remove comments /
     * processing instructions.
     */
    protected function sanitizeNode(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->nodeName);

                if (in_array($tag, self::DROP_TAGS, true)) {
                    $node->removeChild($child);
                    continue;
                }

                $this->sanitizeNode($child);

                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                while ($child->attributes->length > 0) {
                    $child->removeAttributeNode($child->attributes->item(0));
                }
            } elseif ($child instanceof \DOMCdataSection) {
                $node->replaceChild($node->ownerDocument->createTextNode($child->data), $child);
            } elseif (!$child instanceof \DOMText) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * Remove the fixed per-novel build directory older versions of this
     * command left in storage/app/Novel/{id}.
     */
    protected function removeLegacyBuildDir(Novel $novel): void
    {
        $legacyDir = storage_path("app/Novel/{$novel->id}");
        if (File::isDirectory($legacyDir)) {
            File::deleteDirectory($legacyDir);
        }
    }

    /**
     * The ePub file name for a novel ("{name} - {author}.epub", sanitised,
     * "Unknown" for a missing author). The single source of truth for the
     * generator, the download action and the OPDS feed — so names with
     * reserved characters ("Re:Zero") or no author resolve to the same file.
     */
    public static function epubFilename(Novel $novel): string
    {
        return self::sanitizeFilename($novel->name . " - " . ($novel->author ?: "Unknown")) . ".epub";
    }

    /**
     * Absolute path of a novel's generated ePub (it may not exist yet).
     */
    public static function epubPath(Novel $novel): string
    {
        return storage_path("app/ePub/" . self::epubFilename($novel));
    }

    /**
     * Sanitize filename for safe filesystem usage
     */
    protected static function sanitizeFilename(string $filename): string
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
