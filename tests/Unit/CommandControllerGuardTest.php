<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Audit F16: destructive per-novel commands must never sweep all novels
 * (novel id 0) when triggered from the web UI.
 */
class CommandControllerGuardTest extends TestCase
{
    public function testNormalizeRejectsAllNovelsSync()
    {
        $this->postJson('/commands/execute', ['command' => 'normalize_labels', 'novel_id' => 0])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function testNormalizeRejectsAllNovelsAsync()
    {
        $this->postJson('/commands/execute-async', ['command' => 'normalize_labels', 'novel_id' => 0])
            ->assertStatus(422);
        $this->postJson('/commands/execute-async', ['command' => 'normalize_labels'])
            ->assertStatus(422);
    }

    public function testStringFalseFlagsAreNotPassedAsOptions()
    {
        Artisan::shouldReceive('call')->once()
            ->with('novel:normalize_labels', ['novel' => 5])
            ->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');

        $this->postJson('/commands/execute', [
            'command' => 'normalize_labels', 'novel_id' => 5,
            'dry_run' => 'false', 'renumber' => 'false', 'dedupe' => '0',
        ])->assertOk();
    }

    public function testTrueFlagsArePassedAsOptions()
    {
        Artisan::shouldReceive('call')->once()
            ->with('novel:normalize_labels', ['novel' => 5, '--dry-run' => true, '--renumber' => true, '--dedupe' => true])
            ->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');

        $this->postJson('/commands/execute', [
            'command' => 'normalize_labels', 'novel_id' => 5,
            'dry_run' => 'true', 'renumber' => 1, 'dedupe' => 'on',
        ])->assertOk();
    }
}
