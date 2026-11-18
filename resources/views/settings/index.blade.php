@extends('layouts.app')

@section('content')
<h1 class="page-title mb-4">Settings</h1>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card form-panel">
            <div class="card-body">
                @if($errors->any())
                    <div class="alert alert-danger mb-4">Please correct the highlighted fields below.</div>
                @endif

                <form method="POST" action="{{ route('settings.update') }}">
                    @csrf

                    @foreach(collect($fields)->groupBy('group') as $groupName => $groupFields)
                        <fieldset class="form-fieldset">
                            <legend>{{ $groupName }}</legend>

                            @foreach($groupFields as $field)
                                @php $key = $field['key']; @endphp
                                @if($field['type'] === 'checkbox')
                                    <div class="form-row form-check form-switch">
                                        <input type="checkbox" name="{{ $key }}" id="{{ $key }}" class="form-check-input @error($key) is-invalid @enderror" value="1" @checked(old($key, $field['value']) === '1' || old($key, $field['value']) === 1)>
                                        <label for="{{ $key }}" class="form-check-label">{{ $field['label'] }}</label>
                                        <div class="form-text">{{ $field['help'] }}</div>
                                        <div class="form-text setting-effective" data-setting="{{ $key }}">
                                            In effect: <strong>{{ (string) $field['value'] === '1' ? 'On' : 'Off' }}</strong>
                                            ({{ $field['overridden'] ? 'saved' : 'default' }}) · Default: {{ (string) $field['default'] === '1' ? 'On' : 'Off' }}
                                        </div>
                                        @error($key)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    </div>
                                @else
                                    <div class="form-row">
                                        <label for="{{ $key }}" class="form-label">{{ $field['label'] }}</label>
                                        @if(!empty($field['secret']))
                                            <div class="input-group">
                                                <input type="password" name="{{ $key }}" id="{{ $key }}"
                                                       class="form-control @error($key) is-invalid @enderror"
                                                       value="{{ old($key, $field['value']) }}"
                                                       autocomplete="off"
                                                       @if(!empty($field['default'])) placeholder="{{ $field['default'] }}" @endif>
                                                <button type="button" class="btn btn-secondary toggle-secret" data-target="{{ $key }}" aria-label="Show or hide value">Show</button>
                                                @error($key)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            </div>
                                        @else
                                            <input type="{{ $field['type'] }}" name="{{ $key }}" id="{{ $key }}"
                                                   class="form-control @error($key) is-invalid @enderror"
                                                   value="{{ old($key, $field['value']) }}"
                                                   @isset($field['min']) min="{{ $field['min'] }}" @endisset
                                                   @isset($field['max']) max="{{ $field['max'] }}" @endisset
                                                   @isset($field['step']) step="{{ $field['step'] }}" @endisset
                                                   @if(!empty($field['default'])) placeholder="{{ $field['default'] }}" @endif>
                                            @error($key)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        @endif
                                        <div class="form-text">{{ $field['help'] }}</div>
                                        @if(empty($field['secret']))
                                            <div class="form-text setting-effective" data-setting="{{ $key }}">
                                                In effect: <strong>{{ filled($field['value']) ? $field['value'] : '—' }}</strong>
                                                ({{ $field['overridden'] ? 'saved' : 'default' }}) · Default: {{ filled($field['default']) ? $field['default'] : 'none' }}
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </fieldset>
                    @endforeach

                    {{-- Sources when adding: unticked = hidden from Discover (Add novel / Add manga). --}}
                    @php
                        // After a failed save, show what was posted rather than what is stored.
                        $postedOffered = old('discover_offered');
                        $postedShown = (array) old('discover_shown', []);
                    @endphp
                    <fieldset class="form-fieldset" id="discover-sources">
                        <legend>Sources when adding</legend>
                        <div class="form-text mb-3">Untick a source to leave it out of Add novel / Add manga. Series you already have from it keep downloading.</div>

                        @foreach($discoverGroups as $medium => $group)
                            @php $filterable = count($group['rows']) > 8; @endphp
                            <div class="form-row discover-source-group" data-ds-group="{{ $medium }}">
                                <span class="form-label d-block">{{ $group['title'] }}</span>
                                @if($filterable)
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <button type="button" class="btn btn-secondary btn-sm" data-ds-all="1">Show all</button>
                                        <button type="button" class="btn btn-secondary btn-sm" data-ds-all="0">Hide all</button>
                                        <input type="search" class="form-control form-control-sm" style="max-width: 16rem;" data-ds-filter placeholder="Filter {{ strtolower($group['title']) }} sources…" aria-label="Filter {{ strtolower($group['title']) }} sources">
                                    </div>
                                @endif
                                @forelse($group['rows'] as $row)
                                    @php $id = 'ds-' . $medium . '-' . $loop->index; @endphp
                                    <div class="form-check discover-source-row" data-ds-text="{{ mb_strtolower($row['label'] . ' ' . ($row['host'] ?? '')) }}">
                                        <input type="hidden" name="discover_offered[]" value="{{ $row['key'] }}">
                                        <input type="checkbox" class="form-check-input" name="discover_shown[]" id="{{ $id }}" value="{{ $row['key'] }}"
                                               @checked(is_array($postedOffered) ? in_array($row['key'], $postedShown, true) : $row['shown'])>
                                        <label class="form-check-label" for="{{ $id }}">{{ $row['label'] }}@if($row['host']) <span class="text-muted small">{{ $row['host'] }}</span>@endif</label>
                                    </div>
                                @empty
                                    <div class="form-text">No {{ strtolower($group['title']) }} sources are set up.</div>
                                @endforelse
                                @if($medium === \App\Novel::MEDIUM_MANGA && $suwayomiMissing)
                                    <div class="form-text" id="discoverSuwayomiMissing">The Suwayomi sources could not be loaded right now.</div>
                                @endif
                            </div>
                        @endforeach
                    </fieldset>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save settings</button>
                        <button type="button" id="testEmail" class="btn btn-secondary">Send test email</button>
                        <button type="button" id="testFlare" class="btn btn-secondary">Test FlareSolverr</button>
                        <button type="button" id="testNotify" class="btn btn-secondary">Test notification</button>
                    </div>
                </form>
            </div>
        </div>

        <p class="page-note">
            Saved values override the matching <code>.env</code> entries. Leave a field blank to fall back to the <code>.env</code> default.
            Tests use the FlareSolverr URL currently in the form (save first to test the others).
        </p>

        {{-- Tailscale --}}
        <div class="card mt-4 form-panel" id="tailscaleCard">
            <div class="panel-head">
                <h2 class="panel-title">Tailscale</h2>
                <span id="tsBadge" class="badge badge-paused">Checking…</span>
            </div>
            <div class="card-body">
                {{-- Shown while loading --}}
                <div id="tsLoading" class="text-muted">
                    <span class="spinner-border spinner-border-sm me-1"></span> Checking Tailscale status…
                </div>

                {{-- Shown when Tailscale isn't reachable --}}
                <div id="tsUnavailable" class="d-none">
                    <p class="mb-2">Tailscale isn't connected to this instance.</p>
                    <p id="tsReason" class="mono-muted mb-3"></p>
                    <p class="text-muted mb-0" style="font-size: 13px;">
                        To put Novarr on your tailnet (and get automatic HTTPS for the PWA), run the bundled
                        Tailscale stack: <code>docker compose -f docker-compose.tailscale.yml up -d</code> with a
                        <code>TS_AUTHKEY</code> set. See the README → Tailscale.
                    </p>
                </div>

                {{-- Shown when connected --}}
                <div id="tsConnected" class="d-none">
                    <dl class="kv-grid mb-4">
                        <dt>Machine</dt><dd id="tsHostname">—</dd>
                        <dt>Tailnet address</dt><dd id="tsUrl">—</dd>
                        <dt>IP</dt><dd id="tsIp">—</dd>
                    </dl>

                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" class="form-check-input" id="tsServe">
                        <label class="form-check-label" for="tsServe">Serve over HTTPS</label>
                        <div class="form-text">Private to your tailnet — and it satisfies the PWA's HTTPS requirement. The choice persists across restarts.</div>
                    </div>
                    {{-- Danger treatment: Funnel puts Novarr on the public internet --}}
                    <div class="form-check form-switch funnel-switch">
                        <input type="checkbox" class="form-check-input" id="tsFunnel" aria-describedby="tsFunnelHelp">
                        <label class="form-check-label" for="tsFunnel">Funnel <span class="badge badge-failed ms-1">Public</span></label>
                        <div class="form-text funnel-switch-help" id="tsFunnelHelp">Exposes Novarr to anyone on the internet who has the address. Novarr has no login, so they can read, add and delete everything. You'll be asked to confirm.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function(){

    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    // Reveal/hide masked secret fields.
    document.querySelectorAll('.toggle-secret').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            if (!input) return;
            const hidden = input.type === 'password';
            input.type = hidden ? 'text' : 'password';
            btn.textContent = hidden ? 'Hide' : 'Show';
        });
    });

    // "Sources when adding": Show all / Hide all and the filter box, per group.
    document.querySelectorAll('.discover-source-group').forEach(group => {
        const rows = () => group.querySelectorAll('.discover-source-row');
        group.querySelectorAll('[data-ds-all]').forEach(btn => {
            btn.addEventListener('click', () => {
                const on = btn.dataset.dsAll === '1';
                rows().forEach(row => { row.querySelector('input[type="checkbox"]').checked = on; });
            });
        });
        const filter = group.querySelector('[data-ds-filter]');
        filter?.addEventListener('input', () => {
            const needle = filter.value.trim().toLowerCase();
            rows().forEach(row => row.classList.toggle('d-none', needle !== '' && !row.dataset.dsText.includes(needle)));
        });
    });

    async function runTest(btn, url, body) {
        const original = btn.textContent;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing…';
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify(body || {}),
            });
            const data = await response.json();
            Novarr.showToast(data.message, data.success ? 'success' : 'danger');
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.textContent = original;
        }
    }

    document.getElementById('testEmail').addEventListener('click', (e) =>
        runTest(e.currentTarget, '{{ route('settings.test_email') }}'));

    document.getElementById('testFlare').addEventListener('click', (e) =>
        runTest(e.currentTarget, '{{ route('settings.test_flaresolverr') }}', {
            flaresolverr_url: document.getElementById('flaresolverr_url').value,
        }));

    document.getElementById('testNotify').addEventListener('click', (e) =>
        runTest(e.currentTarget, '{{ route('settings.test_notification') }}', {
            notification_webhook_url: document.getElementById('notification_webhook_url').value,
        }));

    // ---- Tailscale panel ----
    const tsBadge = document.getElementById('tsBadge');
    const show = (id, on) => document.getElementById(id).classList.toggle('d-none', !on);

    async function loadTailscale() {
        try {
            const res = await fetch('{{ route('settings.tailscale_status') }}', { headers: { Accept: 'application/json' } });
            const d = await res.json();
            show('tsLoading', false);

            if (!d.available) {
                show('tsUnavailable', true);
                show('tsConnected', false);
                document.getElementById('tsReason').textContent = d.reason || '';
                tsBadge.className = 'badge badge-paused';
                tsBadge.textContent = 'Not connected';
                window.Novarr?.setFunnelState?.(false);
                return;
            }

            show('tsUnavailable', false);
            show('tsConnected', true);

            const running = d.backend === 'Running';
            tsBadge.className = 'badge ' + (running ? 'badge-active' : 'badge-attention');
            tsBadge.textContent = running ? 'Connected' : (d.backend || 'Unknown');

            document.getElementById('tsHostname').textContent = d.hostname || '—';
            document.getElementById('tsIp').textContent = (d.ips || []).join(', ') || '—';

            const urlEl = document.getElementById('tsUrl');
            if (d.url) {
                urlEl.innerHTML = '';
                const a = document.createElement('a');
                a.href = d.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = d.url;
                urlEl.appendChild(a);
            } else {
                urlEl.textContent = '—';
            }

            document.getElementById('tsServe').checked = !!d.serve;
            document.getElementById('tsFunnel').checked = !!d.funnel;
            window.Novarr?.setFunnelState?.(!!d.funnel);
        } catch (err) {
            show('tsLoading', false);
            show('tsUnavailable', true);
            document.getElementById('tsReason').textContent = 'Error: ' + err.message;
        }
    }

    async function toggleTs(kind, checkbox, url) {
        checkbox.disabled = true;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ enable: checkbox.checked }),
            });
            const d = await res.json();
            Novarr.showToast(d.message, d.success ? 'success' : 'danger');
            if (!d.success) checkbox.checked = !checkbox.checked;   // revert
        } catch (err) {
            checkbox.checked = !checkbox.checked;
            Novarr.showToast('Error: ' + err.message, 'danger');
        } finally {
            checkbox.disabled = false;
            loadTailscale();
        }
    }

    document.getElementById('tsServe')?.addEventListener('change', (e) =>
        toggleTs('serve', e.target, '{{ route('settings.tailscale_serve') }}'));
    // Enabling Funnel needs an explicit confirmation; disabling never does.
    document.getElementById('tsFunnel')?.addEventListener('change', async (e) => {
        const checkbox = e.target;
        if (checkbox.checked) {
            checkbox.checked = false;   // stays off unless confirmed
            const ok = await Novarr.confirmDialog(
                'Funnel publishes Novarr on the public internet. Novarr has no login, so anyone with the address can read, add and delete novels and run commands. Only turn this on briefly, and turn it off when you are done.',
                { title: 'Expose Novarr publicly?', confirmText: 'Turn on Funnel', cancelText: 'Keep private', danger: true },
            );
            if (!ok) return;
            checkbox.checked = true;
        }
        toggleTs('funnel', checkbox, '{{ route('settings.tailscale_funnel') }}');
    });

    loadTailscale();

})();
</script>
@endpush
