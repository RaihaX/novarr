<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Markup the offline shell and page scripts depend on.
 */
class OfflineShellViewsTest extends TestCase
{
    public function test_offline_page_links_to_the_cached_downloads_page(): void
    {
        $html = $this->get('/offline')->assertOk()->getContent();

        $this->assertStringContainsString('href="' . route('library') . '"', $html);
        $this->assertStringNotContainsString('href="' . route('novels.index') . '" class="btn btn-primary"', $html);
    }

    public function test_downloads_page_has_storage_usage_and_persistence_notes(): void
    {
        $html = $this->get('/library')->assertOk()->getContent();

        $this->assertStringContainsString('id="dlStorageNote"', $html);
        $this->assertStringContainsString('id="dlPersistNote"', $html);
        $this->assertStringContainsString('storageStatus', $html);
    }

    public function test_log_live_tail_stops_when_the_page_is_left(): void
    {
        $name = 'offline-shell-test.log';
        $path = storage_path('logs/' . $name);
        File::put($path, "[2026-11-14 12:00:00] local.INFO: hello\n");

        try {
            $html = $this->get(route('logs.show', $name))->assertOk()->getContent();
        } finally {
            File::delete($path);
        }

        foreach (['turbo:before-render', 'turbo:before-cache', 'pagehide'] as $event) {
            $this->assertStringContainsString("'{$event}', stopLive", $html);
        }
    }
}
