@extends('layouts.app')

@section('content')
<h1 class="mb-4">Settings</h1>

@if(session('status'))
    <div class="alert alert-success py-2">{{ session('status') }}</div>
@endif

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                @if($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('settings.update') }}">
                    @csrf

                    @foreach($fields as $key => $field)
                        @if($field['type'] === 'checkbox')
                            <div class="mb-3 form-check form-switch">
                                <input type="checkbox" name="{{ $key }}" id="{{ $key }}" class="form-check-input" value="1" @checked(old($key, $field['value']) === '1' || old($key, $field['value']) === 1)>
                                <label for="{{ $key }}" class="form-check-label">{{ $field['label'] }}</label>
                                <div class="form-text">{{ $field['help'] }}</div>
                            </div>
                        @else
                            <div class="mb-3">
                                <label for="{{ $key }}" class="form-label">{{ $field['label'] }}</label>
                                <input type="{{ $field['type'] }}" name="{{ $key }}" id="{{ $key }}"
                                       class="form-control"
                                       value="{{ old($key, $field['value']) }}"
                                       @if(!empty($field['default'])) placeholder="{{ $field['default'] }}" @endif>
                                <div class="form-text">{{ $field['help'] }}</div>
                            </div>
                        @endif
                    @endforeach

                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">Save settings</button>
                        <button type="button" id="testEmail" class="btn btn-outline-secondary">Send test email</button>
                        <button type="button" id="testFlare" class="btn btn-outline-secondary">Test FlareSolverr</button>
                        <button type="button" id="testNotify" class="btn btn-outline-secondary">Test notification</button>
                    </div>
                </form>
            </div>
        </div>
        <p class="text-muted mt-3" style="font-size: 13px;">
            Saved values override the matching <code>.env</code> entries. Leave a field blank to fall back to the <code>.env</code> default.
            Tests use the FlareSolverr URL currently in the form (save first to test the others).
        </p>

        {{-- Tailscale --}}
        <div class="card mt-4" id="tailscaleCard">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Tailscale</h6>
                <span id="tsBadge" class="badge bg-secondary">Checking…</span>
            </div>
            <div class="card-body">
                {{-- Shown while loading --}}
                <div id="tsLoading" class="text-muted" style="font-size: 14px;">
                    <span class="spinner-border spinner-border-sm me-1"></span> Checking Tailscale status…
                </div>

                {{-- Shown when Tailscale isn't reachable --}}
                <div id="tsUnavailable" class="d-none">
                    <p class="text-muted mb-2" style="font-size: 14px;">Tailscale isn't connected to this instance.</p>
                    <p id="tsReason" class="text-muted fst-italic mb-2" style="font-size: 13px;"></p>
                    <p class="text-muted mb-0" style="font-size: 13px;">
                        To put Novarr on your tailnet (and get automatic HTTPS for the PWA), run the bundled
                        Tailscale stack: <code>docker compose -f docker-compose.tailscale.yml up -d</code> with a
                        <code>TS_AUTHKEY</code> set. See the README → Tailscale.
                    </p>
                </div>

                {{-- Shown when connected --}}
                <div id="tsConnected" class="d-none">
                    <dl class="row mb-3" style="font-size: 14px;">
                        <dt class="col-sm-4 text-muted fw-normal">Machine</dt>
                        <dd class="col-sm-8" id="tsHostname">—</dd>
                        <dt class="col-sm-4 text-muted fw-normal">Tailnet address</dt>
                        <dd class="col-sm-8" id="tsUrl">—</dd>
                        <dt class="col-sm-4 text-muted fw-normal">IP</dt>
                        <dd class="col-sm-8" id="tsIp">—</dd>
                    </dl>

                    <div class="form-check form-switch mb-2">
                        <input type="checkbox" class="form-check-input" id="tsServe">
                        <label class="form-check-label" for="tsServe">
                            Serve over HTTPS <span class="text-muted">— private to your tailnet</span>
                        </label>
                    </div>
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="tsFunnel">
                        <label class="form-check-label" for="tsFunnel">
                            Funnel <span class="text-muted">— expose publicly on the internet (use with care)</span>
                        </label>
                    </div>
                    <p class="text-muted mt-2 mb-0" style="font-size: 12px;">
                        Turning on Serve gives Novarr an HTTPS URL on your tailnet (and satisfies the PWA's HTTPS requirement). The choice persists across restarts.
                    </p>
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
        runTest(e.target, '{{ route('settings.test_email') }}'));

    document.getElementById('testFlare').addEventListener('click', (e) =>
        runTest(e.target, '{{ route('settings.test_flaresolverr') }}', {
            flaresolverr_url: document.getElementById('flaresolverr_url').value,
        }));

    document.getElementById('testNotify').addEventListener('click', (e) =>
        runTest(e.target, '{{ route('settings.test_notification') }}', {
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
                tsBadge.className = 'badge bg-secondary';
                tsBadge.textContent = 'Not connected';
                return;
            }

            show('tsUnavailable', false);
            show('tsConnected', true);

            const running = d.backend === 'Running';
            tsBadge.className = 'badge ' + (running ? 'bg-success' : 'bg-warning text-dark');
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
    document.getElementById('tsFunnel')?.addEventListener('change', (e) =>
        toggleTs('funnel', e.target, '{{ route('settings.tailscale_funnel') }}'));

    loadTailscale();

})();
</script>
@endpush
