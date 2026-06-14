<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

/**
 * Reads Tailscale state and toggles Serve / Funnel from the Settings UI.
 *
 * This talks to a local `tailscaled` via the `tailscale` CLI + control socket.
 * In the bundled Docker stack that daemon is the Tailscale sidecar container
 * (its socket is shared into the app container). Everything here degrades
 * gracefully: if the CLI or daemon isn't reachable, `status` reports
 * `available: false` with a reason instead of erroring, so installs without
 * Tailscale are unaffected.
 */
class TailscaleController extends Controller
{
    /** The port Octane/RoadRunner serves the app on inside the container. */
    private const APP_PORT = 8000;

    private function hasCli(): bool
    {
        return Process::run('command -v tailscale')->successful();
    }

    private function ts(array $args)
    {
        // Args are fixed constants (a mode keyword and the app port) — nothing
        // from the request reaches the command line, so a shell string is safe
        // here and keeps the calls trivially testable.
        return Process::timeout(15)->run('tailscale ' . implode(' ', $args));
    }

    /** Live tailnet + serve/funnel state for the Settings panel. */
    public function status()
    {
        if (!$this->hasCli()) {
            return response()->json([
                'available' => false,
                'reason' => 'The tailscale CLI is not installed in this container. Use the Tailscale stack (docker-compose.tailscale.yml).',
            ]);
        }

        $status = $this->ts(['status', '--json']);
        if (!$status->successful()) {
            return response()->json([
                'available' => false,
                'reason' => trim($status->errorOutput())
                    ?: 'Could not reach the Tailscale daemon — is the sidecar running and its socket mounted into this container?',
            ]);
        }

        $data = json_decode($status->output(), true) ?: [];
        $self = $data['Self'] ?? [];
        $backend = $data['BackendState'] ?? 'Unknown';

        $serveCfg = [];
        $serve = $this->ts(['serve', 'status', '--json']);
        if ($serve->successful()) {
            $serveCfg = json_decode($serve->output(), true) ?: [];
        }

        $dnsName = rtrim($self['DNSName'] ?? '', '.');

        return response()->json([
            'available' => true,
            'backend' => $backend,                       // Running | NeedsLogin | Stopped | …
            'loggedIn' => $backend === 'Running',
            'hostname' => $self['HostName'] ?? null,
            'dnsName' => $dnsName ?: null,
            'ips' => $self['TailscaleIPs'] ?? [],
            'serve' => !empty($serveCfg['TCP'] ?? []) || !empty($serveCfg['Web'] ?? []),
            'funnel' => !empty($serveCfg['AllowFunnel'] ?? []),
            'url' => $dnsName ? "https://{$dnsName}/" : null,
        ]);
    }

    public function serve(Request $request)
    {
        return $this->toggle('serve', $request->boolean('enable'));
    }

    public function funnel(Request $request)
    {
        return $this->toggle('funnel', $request->boolean('enable'));
    }

    private function toggle(string $mode, bool $enable)
    {
        if (!$this->hasCli()) {
            return response()->json([
                'success' => false,
                'message' => 'Tailscale CLI is not available in this container.',
            ], 422);
        }

        // `enable`  → proxy https://<node>/ to the local app port (background).
        // `disable` → clear the :443 handler. Args are fixed (no user input),
        // so there's nothing to inject.
        $args = $enable
            ? [$mode, '--bg', (string) self::APP_PORT]
            : [$mode, '--https=443', 'off'];

        $result = $this->ts($args);

        if ($result->successful()) {
            return response()->json([
                'success' => true,
                'message' => ucfirst($mode) . ' ' . ($enable ? 'enabled.' : 'disabled.'),
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => trim($result->errorOutput()) ?: "Failed to update {$mode}.",
        ], 502);
    }
}
