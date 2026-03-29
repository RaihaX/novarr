<?php

namespace App\Http\Controllers;

use App\Novel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class CommandController extends Controller
{
    protected $destructiveCommands = [
        'chapter_cleanser',
        'chapter_cleaner',
    ];

    protected $rateLimitPrefix = 'command_execution';
    protected $maxAsyncPerMinute = 5;

    protected $commands = [
        'toc' => [
            'name' => 'Scrape Table of Contents',
            'description' => 'Scrape novel table of contents to get all chapters',
            'command' => 'novel:toc',
            'params' => ['novel_id'],
        ],
        'chapter' => [
            'name' => 'Download Chapters',
            'description' => 'Download chapter content for novels',
            'command' => 'novel:chapter',
            'params' => ['novel_id'],
        ],
        'epub' => [
            'name' => 'Generate ePub',
            'description' => 'Generate ePub files from downloaded chapters',
            'command' => 'novel:epub',
            'params' => ['novel_id'],
        ],
        'metadata' => [
            'name' => 'Update Metadata',
            'description' => 'Update novel metadata from external sources',
            'command' => 'novel:metadata',
            'params' => [],
        ],
        'normalize_labels' => [
            'name' => 'Normalize Chapter Labels',
            'description' => 'Normalize chapter labels to consistent format',
            'command' => 'novel:normalize_labels',
            'params' => ['novel_id', 'dry_run'],
        ],
        'calculate_chapter' => [
            'name' => 'Calculate Chapters',
            'description' => 'Calculate total chapter count for novels',
            'command' => 'novel:calculate_chapter',
            'params' => [],
        ],
        'info' => [
            'name' => 'Show Novel Info',
            'description' => 'Display novel information and completion status',
            'command' => 'novel:info',
            'params' => [],
        ],
        'chapter_cleanser' => [
            'name' => 'Chapter Cleanser',
            'description' => 'Remove unwanted tags and characters from chapters',
            'command' => 'novel:chapter_cleanser',
            'params' => ['novel_id'],
        ],
        'chapter_cleaner' => [
            'name' => 'Chapter Cleaner',
            'description' => 'Clean chapters with insufficient content',
            'command' => 'novel:chaptercleaner',
            'params' => ['novel_id'],
        ],
        'create_novel' => [
            'name' => 'Create Novel',
            'description' => 'Create a new novel from URL',
            'command' => 'novel:create',
            'params' => ['name', 'url'],
        ],
        'queue_health' => [
            'name' => 'Queue Health Check',
            'description' => 'Check the health of the queue system',
            'command' => 'queue:health-check',
            'params' => [],
        ],
    ];

    public function index()
    {
        return view('commands.index', [
            'commands' => $this->commands,
        ]);
    }

    public function showForm(string $command)
    {
        if (!isset($this->commands[$command])) {
            return redirect()->route('commands.index');
        }

        return view('commands.form', [
            'command' => $command,
            'config' => $this->commands[$command],
            'novels' => Novel::orderBy('name')->get(['id', 'name']),
            'isDestructive' => in_array($command, $this->destructiveCommands),
        ]);
    }

    public function execute(Request $request)
    {
        $command = $request->input('command');

        if (!isset($this->commands[$command])) {
            return response()->json(['success' => false, 'message' => 'Command not found'], 404);
        }

        $commandConfig = $this->commands[$command];
        $artisanCommand = $commandConfig['command'];
        $params = $this->buildParams($request, $commandConfig);

        Log::info("Executing command: {$artisanCommand}", [
            'user' => 'local',
            'params' => $params,
        ]);

        try {
            $exitCode = Artisan::call($artisanCommand, $params);
            $output = Artisan::output();

            return response()->json([
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => $output,
                'message' => $exitCode === 0 ? 'Command executed successfully' : 'Command failed',
            ]);
        } catch (\Exception $e) {
            Log::error("Command failed: {$artisanCommand}", ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'output' => '',
            ], 500);
        }
    }

    public function executeAsync(Request $request)
    {
        $command = $request->input('command');

        if (!isset($this->commands[$command])) {
            return response()->json(['success' => false, 'message' => 'Command not found'], 404);
        }

        $userId = 'local';
        $rateLimitKey = "{$this->rateLimitPrefix}:{$userId}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, $this->maxAsyncPerMinute)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'success' => false,
                'message' => "Too many commands queued. Please wait {$seconds} seconds.",
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 60);

        $commandConfig = $this->commands[$command];
        $artisanCommand = $commandConfig['command'];
        $requestData = $request->all();
        $jobId = uniqid('cmd_', true);

        Log::info("Queuing async command: {$artisanCommand}", ['job_id' => $jobId]);

        dispatch(function () use ($artisanCommand, $requestData, $jobId, $commandConfig) {
            $params = [];
            if (in_array('novel_id', $commandConfig['params'])) {
                $params['novel'] = (int) ($requestData['novel_id'] ?? 0);
            }
            if (in_array('name', $commandConfig['params']) && !empty($requestData['name'])) {
                $params['name'] = $requestData['name'];
            }
            if (in_array('url', $commandConfig['params']) && !empty($requestData['url'])) {
                $params['url'] = $requestData['url'];
            }
            if (in_array('dry_run', $commandConfig['params']) && !empty($requestData['dry_run'])) {
                $params['--dry-run'] = true;
            }

            try {
                $exitCode = Artisan::call($artisanCommand, $params);
                $output = Artisan::output();

                cache()->put("command_result_{$jobId}", [
                    'success' => $exitCode === 0,
                    'exit_code' => $exitCode,
                    'output' => $output,
                    'completed_at' => now()->toIso8601String(),
                ], now()->addHours(1));
            } catch (\Exception $e) {
                cache()->put("command_result_{$jobId}", [
                    'success' => false,
                    'exit_code' => 1,
                    'error' => $e->getMessage(),
                    'output' => '',
                    'completed_at' => now()->toIso8601String(),
                ], now()->addHours(1));
            }
        })->onQueue('commands');

        return response()->json([
            'success' => true,
            'job_id' => $jobId,
            'message' => 'Command queued for execution',
        ]);
    }

    public function status(string $jobId)
    {
        $result = cache()->get("command_result_{$jobId}");

        if ($result === null) {
            return response()->json(['status' => 'running', 'message' => 'Command is still running']);
        }

        return response()->json(['status' => 'completed', 'result' => $result]);
    }

    protected function buildParams(Request $request, array $commandConfig): array
    {
        $params = [];

        if (in_array('novel_id', $commandConfig['params'])) {
            $params['novel'] = (int) $request->input('novel_id', 0);
        }
        if (in_array('name', $commandConfig['params'])) {
            $params['name'] = $request->input('name', '');
        }
        if (in_array('url', $commandConfig['params'])) {
            $params['url'] = $request->input('url', '');
        }
        if (in_array('dry_run', $commandConfig['params']) && $request->input('dry_run')) {
            $params['--dry-run'] = true;
        }

        return $params;
    }
}
