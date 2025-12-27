<?php

namespace App\Http\Controllers\Voyager;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use TCG\Voyager\Http\Controllers\Controller as VoyagerController;

class LogController extends VoyagerController
{
    protected $logPath;
    protected $linesPerPage = 100;

    public function __construct()
    {
        $this->logPath = storage_path('logs');
    }

    public function index()
    {
        $this->authorize('browse_admin');

        $logFiles = $this->getLogFiles();

        return view('voyager::logs.index', [
            'logFiles' => $logFiles,
        ]);
    }

    public function show(Request $request, string $filename)
    {
        $this->authorize('browse_admin');

        $filename = $this->sanitizeFilename($filename);
        $filePath = $this->logPath . '/' . $filename;

        if (!File::exists($filePath)) {
            return redirect()->route('voyager.logs.index')
                ->with(['message' => 'Log file not found', 'alert-type' => 'error']);
        }

        $level = $request->input('level', 'all');
        $search = $request->input('search', '');
        $page = max(1, (int) $request->input('page', 1));

        // Stream the file and parse entries without loading entire file into memory
        $result = $this->streamParseLogFile($filePath, $level, $search, $page, $this->linesPerPage);

        return view('voyager::logs.show', [
            'filename' => $filename,
            'entries' => $result['entries'],
            'currentPage' => $result['currentPage'],
            'totalPages' => $result['totalPages'],
            'totalEntries' => $result['totalEntries'],
            'level' => $level,
            'search' => $search,
            'levels' => ['all', 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
        ]);
    }

    /**
     * Stream parse log file to avoid loading entire file into memory.
     * Reads file in chunks, parses entries, applies filters, and returns only the requested page.
     */
    protected function streamParseLogFile(string $filePath, string $level, string $search, int $page, int $perPage): array
    {
        $file = new \SplFileObject($filePath, 'r');
        $entries = [];
        $currentEntry = '';
        $entryPattern = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\.?\d*[+-]?\d*:?\d*)\]/';

        // Read file line by line
        while (!$file->eof()) {
            $line = $file->fgets();

            // Check if this line starts a new log entry
            if (preg_match($entryPattern, $line)) {
                // Process the previous entry if we have one
                if (!empty($currentEntry)) {
                    $parsed = $this->parseSingleEntry($currentEntry);
                    if ($parsed && $this->entryMatchesFilters($parsed, $level, $search)) {
                        $entries[] = $parsed;
                    }
                }
                $currentEntry = $line;
            } else {
                // This is a continuation of the current entry (stack trace, etc.)
                $currentEntry .= $line;
            }
        }

        // Process the last entry
        if (!empty($currentEntry)) {
            $parsed = $this->parseSingleEntry($currentEntry);
            if ($parsed && $this->entryMatchesFilters($parsed, $level, $search)) {
                $entries[] = $parsed;
            }
        }

        // Reverse to show newest first
        $entries = array_reverse($entries);

        $totalEntries = count($entries);
        $totalPages = max(1, ceil($totalEntries / $perPage));
        $page = min($page, $totalPages);

        $offset = ($page - 1) * $perPage;
        $paginatedEntries = array_slice($entries, $offset, $perPage);

        return [
            'entries' => $paginatedEntries,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalEntries' => $totalEntries,
        ];
    }

    /**
     * Parse a single log entry string into structured data.
     */
    protected function parseSingleEntry(string $entry): ?array
    {
        $pattern = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\.?\d*[+-]?\d*:?\d*)\]\s+(\w+)\.(\w+):\s+(.*)/s';

        if (preg_match($pattern, $entry, $match)) {
            return [
                'timestamp' => $match[1],
                'environment' => $match[2],
                'level' => strtolower($match[3]),
                'message' => trim($match[4]),
            ];
        }

        // Fallback for non-standard log lines
        $trimmed = trim($entry);
        if (!empty($trimmed)) {
            return [
                'timestamp' => '',
                'environment' => '',
                'level' => 'info',
                'message' => $trimmed,
            ];
        }

        return null;
    }

    /**
     * Check if an entry matches the given level and search filters.
     */
    protected function entryMatchesFilters(array $entry, string $level, string $search): bool
    {
        // Check level filter
        if ($level !== 'all' && strtolower($entry['level']) !== strtolower($level)) {
            return false;
        }

        // Check search filter
        if (!empty($search)) {
            $searchLower = strtolower($search);
            $messageMatch = strpos(strtolower($entry['message']), $searchLower) !== false;
            $timestampMatch = strpos(strtolower($entry['timestamp'] ?? ''), $searchLower) !== false;

            if (!$messageMatch && !$timestampMatch) {
                return false;
            }
        }

        return true;
    }

    public function tail(string $filename)
    {
        $this->authorize('browse_admin');

        $filename = $this->sanitizeFilename($filename);
        $filePath = $this->logPath . '/' . $filename;

        if (!File::exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Log file not found',
            ], 404);
        }

        $lines = $this->getLastLines($filePath, 100);
        $entries = $this->parseLogLines($lines);

        return response()->json([
            'success' => true,
            'entries' => $entries,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function download(string $filename)
    {
        $this->authorize('browse_admin');

        $filename = $this->sanitizeFilename($filename);
        $filePath = $this->logPath . '/' . $filename;

        if (!File::exists($filePath)) {
            return redirect()->route('voyager.logs.index')
                ->with(['message' => 'Log file not found', 'alert-type' => 'error']);
        }

        return Response::download($filePath, $filename, [
            'Content-Type' => 'text/plain',
        ]);
    }

    public function destroy(string $filename)
    {
        $this->authorize('browse_admin');

        $filename = $this->sanitizeFilename($filename);
        $filePath = $this->logPath . '/' . $filename;

        if (!File::exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Log file not found',
            ], 404);
        }

        try {
            File::delete($filePath);

            return response()->json([
                'success' => true,
                'message' => 'Log file deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete log file: ' . $e->getMessage(),
            ], 500);
        }
    }

    protected function getLogFiles(): array
    {
        $files = [];

        if (!File::isDirectory($this->logPath)) {
            return $files;
        }

        $logFiles = File::glob($this->logPath . '/*.log');

        foreach ($logFiles as $file) {
            $files[] = [
                'name' => basename($file),
                'size' => $this->formatFileSize(File::size($file)),
                'size_bytes' => File::size($file),
                'modified' => date('Y-m-d H:i:s', File::lastModified($file)),
                'modified_timestamp' => File::lastModified($file),
            ];
        }

        usort($files, function ($a, $b) {
            return $b['modified_timestamp'] - $a['modified_timestamp'];
        });

        return $files;
    }

    /**
     * Parse an array of log lines into structured entries.
     * Used by the tail() method which already reads only the last N lines.
     */
    protected function parseLogLines(array $lines): array
    {
        $entries = [];
        $currentEntry = '';
        $entryPattern = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\.?\d*[+-]?\d*:?\d*)\]/';

        foreach ($lines as $line) {
            if (preg_match($entryPattern, $line)) {
                if (!empty($currentEntry)) {
                    $parsed = $this->parseSingleEntry($currentEntry);
                    if ($parsed) {
                        $entries[] = $parsed;
                    }
                }
                $currentEntry = $line;
            } else {
                $currentEntry .= $line;
            }
        }

        if (!empty($currentEntry)) {
            $parsed = $this->parseSingleEntry($currentEntry);
            if ($parsed) {
                $entries[] = $parsed;
            }
        }

        return array_reverse($entries);
    }

    protected function getLastLines(string $filePath, int $lines): array
    {
        $file = new \SplFileObject($filePath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $lines);
        $result = [];

        $file->seek($startLine);
        while (!$file->eof()) {
            $result[] = $file->fgets();
        }

        return $result;
    }

    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        $factor = min($factor, count($units) - 1);

        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }

    protected function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);

        if (!preg_match('/\.log$/', $filename)) {
            $filename .= '.log';
        }

        return $filename;
    }
}
