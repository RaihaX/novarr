<?php

namespace App\Http\Controllers\Voyager;

use App\Novel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use TCG\Voyager\Http\Controllers\Controller as VoyagerController;

class CommandController extends VoyagerController
{
    /**
     * Commands that modify or delete data - require confirmation
     */
    protected $destructiveCommands = [
        'chapter_cleanser',
        'chapter_cleaner',
    ];

    /**
     * Rate limit key prefix for async commands
     */
    protected $rateLimitPrefix = 'command_execution';

    /**
     * Maximum async commands per minute per user
     */
    protected $maxAsyncPerMinute = 5;

    protected $commands = [
        'toc' => [
            'name' => 'Scrape Table of Contents',
            'description' => 'Scrape novel table of contents to get all chapters',
            'command' => 'novel:toc',
            'params' => ['novel_id'],
            'icon' => 'voyager-list',
        ],
        'chapter' => [
            'name' => 'Download Chapters',
            'description' => 'Download chapter content for novels',
            'command' => 'novel:chapter',
            'params' => ['novel_id'],
            'icon' => 'voyager-download',
        ],
        'epub' => [
            'name' => 'Generate ePub',
            'description' => 'Generate ePub files from downloaded chapters',
            'command' => 'novel:epub',
            'params' => ['novel_id'],
            'icon' => 'voyager-book',
        ],
        'metadata' => [
            'name' => 'Update Metadata',
            'description' => 'Update novel metadata from external sources',
            'command' => 'novel:metadata',
            'params' => [],
            'icon' => 'voyager-refresh',
        ],
        'normalize_labels' => [
            'name' => 'Normalize Chapter Labels',
            'description' => 'Normalize chapter labels to consistent format',
            'command' => 'novel:normalize_labels',
            'params' => ['novel_id', 'dry_run'],
            'icon' => 'voyager-tag',
        ],
        'calculate_chapter' => [
            'name' => 'Calculate Chapters',
            'description' => 'Calculate total chapter count for novels',
            'command' => 'novel:calculate_chapter',
            'params' => [],
            'icon' => 'voyager-calculator',
        ],
        'info' => [
            'name' => 'Show Novel Info',
            'description' => 'Display novel information and completion status',
            'command' => 'novel:info',
            'params' => [],
            'icon' => 'voyager-info-circled',
        ],
        'chapter_cleanser' => [
            'name' => 'Chapter Cleanser',
            'description' => 'Remove unwanted tags and characters from chapters',
            'command' => 'novel:chapter_cleanser',
            'params' => ['novel_id'],
            'icon' => 'voyager-brush',
        ],
        'chapter_cleaner' => [
            'name' => 'Chapter Cleaner',
            'description' => 'Clean chapters with insufficient content',
            'command' => 'novel:chaptercleaner',
            'params' => ['novel_id'],
            'icon' => 'voyager-trash',
        ],
        'create_novel' => [
            'name' => 'Create Novel',
            'description' => 'Create a new novel from URL',
            'command' => 'novel:create',
            'params' => ['name', 'url'],
            'icon' => 'voyager-plus',
        ],
        'queue_health' => [
            'name' => 'Queue Health Check',
            'description' => 'Check the health of the queue system',
            'command' => 'queue:health-check',
            'params' => [],
            'icon' => 'voyager-pulse',
        ],
    ];

    public function index()
    {
        $this->authorize('browse_admin');

        return view('voyager::commands.index', [
            'commands' => $this->commands,
        ]);
    }

    public function showForm(string $command)
    {
        $this->authorize('browse_admin');

        if (!isset($this->commands[$command])) {
            return redirect()->route('voyager.commands.index')
                ->with(['message' => 'Command not found', 'alert-type' => 'error']);
        }

        $commandConfig = $this->commands[$command];
        $novels = Novel::orderBy('name')->get(['id', 'name']);
        $isDestructive = in_array($command, $this->destructiveCommands);

        return view('voyager::commands.form', [
            'command' => $command,
            'config' => $commandConfig,
            'novels' => $novels,
            'isDestructive' => $isDestructive,
        ]);
    }

    public function execute(Request $request)
    {
        $this->authorize('browse_admin');

        $command = $request->input('command');

        if (!isset($this->commands[$command])) {
            return response()->json([
                'success' => false,
                'message' => 'Command not found',
            ], 404);
        }

        $commandConfig = $this->commands[$command];
        $artisanCommand = $commandConfig['command'];
        $params = [];

        if (in_array('novel_id', $commandConfig['params'])) {
            $novelId = $request->input('novel_id', 0);
            $params['novel'] = (int) $novelId;
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

        Log::info("Executing command: {$artisanCommand}", [
            'user' => auth()->user()->email ?? 'unknown',
            'params' => $params,
        ]);

        try {
            $exitCode = Artisan::call($artisanCommand, $params);
            $output = Artisan::output();

            Log::info("Command completed: {$artisanCommand}", [
                'exit_code' => $exitCode,
                'output_length' => strlen($output),
            ]);

            return response()->json([
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => $output,
                'message' => $exitCode === 0 ? 'Command executed successfully' : 'Command failed',
            ]);
        } catch (\Exception $e) {
            Log::error("Command failed: {$artisanCommand}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error executing command: ' . $e->getMessage(),
                'output' => '',
            ], 500);
        }
    }

    public function executeAsync(Request $request)
    {
        $this->authorize('browse_admin');

        $command = $request->input('command');

        if (!isset($this->commands[$command])) {
            return response()->json([
                'success' => false,
                'message' => 'Command not found',
            ], 404);
        }

        // Rate limiting to prevent queue flooding
        $userId = auth()->id() ?? 'guest';
        $rateLimitKey = "{$this->rateLimitPrefix}:{$userId}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, $this->maxAsyncPerMinute)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            Log::warning("Rate limit exceeded for async commands", [
                'user' => auth()->user()->email ?? 'unknown',
                'retry_after' => $seconds,
            ]);

            return response()->json([
                'success' => false,
                'message' => "Too many commands queued. Please wait {$seconds} seconds before trying again.",
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 60);

        $commandConfig = $this->commands[$command];
        $artisanCommand = $commandConfig['command'];
        $novelId = $request->input('novel_id', 0);
        $dryRun = $request->input('dry_run', false);
        $name = $request->input('name', '');
        $url = $request->input('url', '');

        $jobId = uniqid('cmd_', true);

        Log::info("Queuing async command: {$artisanCommand}", [
            'job_id' => $jobId,
            'user' => auth()->user()->email ?? 'unknown',
            'novel_id' => $novelId,
            'name' => $name,
            'url' => $url,
        ]);

        dispatch(function () use ($artisanCommand, $novelId, $dryRun, $name, $url, $jobId, $commandConfig) {
            $params = [];
            if (in_array('novel_id', $commandConfig['params']) && $novelId) {
                $params['novel'] = (int) $novelId;
            }
            if (in_array('name', $commandConfig['params']) && $name) {
                $params['name'] = $name;
            }
            if (in_array('url', $commandConfig['params']) && $url) {
                $params['url'] = $url;
            }
            if (in_array('dry_run', $commandConfig['params']) && $dryRun) {
                $params['--dry-run'] = true;
            }

            try {
                $exitCode = Artisan::call($artisanCommand, $params);
                $output = Artisan::output();

                Log::info("Async command completed: {$artisanCommand}", [
                    'job_id' => $jobId,
                    'exit_code' => $exitCode,
                    'output_length' => strlen($output),
                ]);

                cache()->put("command_result_{$jobId}", [
                    'success' => $exitCode === 0,
                    'exit_code' => $exitCode,
                    'output' => $output,
                    'completed_at' => now()->toIso8601String(),
                ], now()->addHours(1));
            } catch (\Exception $e) {
                Log::error("Async command failed: {$artisanCommand}", [
                    'job_id' => $jobId,
                    'error' => $e->getMessage(),
                ]);

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
        $this->authorize('browse_admin');

        $result = cache()->get("command_result_{$jobId}");

        if ($result === null) {
            return response()->json([
                'status' => 'running',
                'message' => 'Command is still running',
            ]);
        }

        return response()->json([
            'status' => 'completed',
            'result' => $result,
        ]);
    }
}
