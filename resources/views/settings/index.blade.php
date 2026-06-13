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
                        <div class="mb-3">
                            <label for="{{ $key }}" class="form-label">{{ $field['label'] }}</label>
                            <input type="{{ $field['type'] }}" name="{{ $key }}" id="{{ $key }}"
                                   class="form-control"
                                   value="{{ old($key, $field['value']) }}"
                                   @if(!empty($field['default'])) placeholder="{{ $field['default'] }}" @endif>
                            <div class="form-text">{{ $field['help'] }}</div>
                        </div>
                    @endforeach

                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">Save settings</button>
                        <button type="button" id="testEmail" class="btn btn-outline-secondary">Send test email</button>
                        <button type="button" id="testFlare" class="btn btn-outline-secondary">Test FlareSolverr</button>
                    </div>
                </form>
            </div>
        </div>
        <p class="text-muted mt-3" style="font-size: 13px;">
            Saved values override the matching <code>.env</code> entries. Leave a field blank to fall back to the <code>.env</code> default.
            Tests use the FlareSolverr URL currently in the form (save first to test the others).
        </p>
    </div>
</div>
@endsection

@push('scripts')
<script>
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
</script>
@endpush
