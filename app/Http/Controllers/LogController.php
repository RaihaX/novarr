<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;

class LogController extends Controller
{
    protected $logPath;
    protected $linesPerPage = 100;

    public function __construct()
    {
        $this->logPath = storage_path('logs');
    }

    public function index()
    {
        return view('logs.index', [
            'logFiles' => $this->getLogFiles(),
        ]);
    }

    public function show(Request $request, string $filename)
    {
        $filename = $this->sanitizeFilename($filename);
        $filePath = $this->logPath . '/' . $filename;

        if (!File::exists($filePath)) {
            return redirect()->route('logs.index');
        }

        $level = $request->input('level', 'all');
        $search = $request->input('search', '');
        $page = max(1, (int) $request->input('page', 1));

        $result = $this->streamParseLogFile($filePath, $level, $search, $page, $this->linesPerPage);

        return view('logs.show', [
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

    public function tail(string $filename)
    {
        $filename = $this->sanitizeFilename($filename);
        $filePath = $this->logPath . '/' . $filename;

        if (!File::exists($filePath)) {
            return response()->json(['success' => false, 'message' => 'Log file not found'], 404);
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
        $filename = $this->sanitizeFilename($filename);
        $filePath = $this->logPath . '/' . $filename;

        if (!File::exists($filePath)) {
            return redirect()->route('logs.index');
        }

        return Response::download($filePath, $filename, ['Content-Type' => 'text/plain']);
    }

    public function destroy(string $filename)
    {
        $filename = $this->sanitizeFilename($filename);
        $filePath = $this->logPath . '/' . $filename;

        if (!File::exists($filePath)) {
            return response()->json(['success' => false, 'message' => 'Log file not found'], 404);
        }

        try {
            File::delete($filePath);
            return response()->json(['success' => true, 'message' => 'Log file deleted']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    protected function streamParseLogFile(string $filePath, string $level, string $search, int $page, int $perPage): array
    {
        $file = new \SplFileObject($filePath, 'r');
        $entries = [];
        $currentEntry = '';
        $entryPattern = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\.?\d*[+-]?\d*:?\d*)\]/';

        while (!$file->eof()) {
            $line = $file->fgets();

            if (preg_match($entryPattern, $line)) {
                if (!empty($currentEntry)) {
                    $parsed = $this->parseSingleEntry($currentEntry);
                    if ($parsed && $this->entryMatchesFilters($parsed, $level, $search)) {
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
            if ($parsed && $this->entryMatchesFilters($parsed, $level, $search)) {
                $entries[] = $parsed;
            }
        }

        $entries = array_reverse($entries);
        $totalEntries = count($entries);
        $totalPages = max(1, ceil($totalEntries / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'entries' => array_slice($entries, $offset, $perPage),
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalEntries' => $totalEntries,
        ];
    }

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

    protected function entryMatchesFilters(array $entry, string $level, string $search): bool
    {
        if ($level !== 'all' && strtolower($entry['level']) !== strtolower($level)) {
            return false;
        }

        if (!empty($search)) {
            $searchLower = strtolower($search);
            if (strpos(strtolower($entry['message']), $searchLower) === false
                && strpos(strtolower($entry['timestamp'] ?? ''), $searchLower) === false) {
                return false;
            }
        }

        return true;
    }

    protected function parseLogLines(array $lines): array
    {
        $entries = [];
        $currentEntry = '';
        $entryPattern = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\.?\d*[+-]?\d*:?\d*)\]/';

        foreach ($lines as $line) {
            if (preg_match($entryPattern, $line)) {
                if (!empty($currentEntry)) {
                    $parsed = $this->parseSingleEntry($currentEntry);
                    if ($parsed) $entries[] = $parsed;
                }
                $currentEntry = $line;
            } else {
                $currentEntry .= $line;
            }
        }

        if (!empty($currentEntry)) {
            $parsed = $this->parseSingleEntry($currentEntry);
            if ($parsed) $entries[] = $parsed;
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

    protected function getLogFiles(): array
    {
        $files = [];

        if (!File::isDirectory($this->logPath)) {
            return $files;
        }

        foreach (File::glob($this->logPath . '/*.log') as $file) {
            $files[] = [
                'name' => basename($file),
                'size' => $this->formatFileSize(File::size($file)),
                'size_bytes' => File::size($file),
                'modified' => date('Y-m-d H:i:s', File::lastModified($file)),
                'modified_timestamp' => File::lastModified($file),
            ];
        }

        usort($files, fn($a, $b) => $b['modified_timestamp'] - $a['modified_timestamp']);

        return $files;
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
