<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class TailscaleTest extends TestCase
{
    /** With no tailscale CLI present, the panel reports unavailable (no error). */
    public function testStatusUnavailableWhenCliMissing()
    {
        Process::fake([
            'command -v tailscale' => Process::result(output: '', exitCode: 1),
        ]);

        $this->getJson('/settings/tailscale')
            ->assertOk()
            ->assertJson(['available' => false]);
    }

    /** A reachable daemon yields parsed status + serve/funnel flags. */
    public function testStatusReportsConnectedState()
    {
        Process::fake([
            'command -v tailscale' => Process::result(output: '/usr/local/bin/tailscale'),
            'tailscale status --json' => Process::result(output: json_encode([
                'BackendState' => 'Running',
                'Self' => [
                    'HostName' => 'novarr',
                    'DNSName' => 'novarr.tailnet.ts.net.',
                    'TailscaleIPs' => ['100.64.0.1'],
                ],
            ])),
            'tailscale serve status --json' => Process::result(output: json_encode([
                'TCP' => ['443' => ['HTTPS' => true]],
                'Web' => ['novarr.tailnet.ts.net:443' => ['Handlers' => ['/' => ['Proxy' => 'http://127.0.0.1:8000']]]],
            ])),
        ]);

        $this->getJson('/settings/tailscale')
            ->assertOk()
            ->assertJson([
                'available' => true,
                'backend' => 'Running',
                'loggedIn' => true,
                'hostname' => 'novarr',
                'serve' => true,
                'funnel' => false,
                'url' => 'https://novarr.tailnet.ts.net/',
            ]);
    }

    /** Toggling Serve is rejected cleanly when the CLI is absent. */
    public function testServeToggleRejectedWithoutCli()
    {
        Process::fake([
            'command -v tailscale' => Process::result(output: '', exitCode: 1),
        ]);

        $this->postJson('/settings/tailscale/serve', ['enable' => true])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    /** Toggling Serve shells out and reports success. */
    public function testServeToggleEnables()
    {
        Process::fake([
            'command -v tailscale' => Process::result(output: '/usr/local/bin/tailscale'),
            'tailscale serve --bg 8000' => Process::result(output: 'Serving'),
        ]);

        $this->postJson('/settings/tailscale/serve', ['enable' => true])
            ->assertOk()
            ->assertJson(['success' => true]);

        Process::assertRan('tailscale serve --bg 8000');
    }
}
