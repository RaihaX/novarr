@extends('layouts.app')

@section('title', 'Users')

@section('breadcrumb')
    <li><a href="{{ route('settings.index') }}">Settings</a></li>
    <li aria-current="page">Users</li>
@endsection

@section('content')
<h1 class="page-title mb-4">Users</h1>

<div class="row justify-content-center">
    <div class="col-lg-8 users-page">

        {{-- ================= Require sign-in ================= --}}
        <section class="card form-panel mb-4" aria-labelledby="signinTitle" id="signin">
            <div class="card-body">
                <h2 class="panel-title" id="signinTitle">Require sign-in</h2>
                <p class="mb-2">
                    Sign-in is <strong id="signinState">{{ $authOn ? 'on' : 'off' }}</strong>.
                    @if($authOn)
                        Everyone signs in with their own name and password and keeps their own reading progress, highlights and stats. Readers can read, but cannot add, edit or delete series or change settings.
                    @else
                        Anyone who can reach Novarr uses it as you (the owner), with no sign-in. You can add accounts now and turn sign-in on when they are ready.
                    @endif
                </p>
                @unless($authOn)
                    <p class="form-text mt-0">When it is on: everyone must sign in on the web; the iOS app must sign in again, and each Kindle must be linked again with a code (Account → Link a device).</p>
                @endunless

                @if($authForced !== null)
                    <div class="alert alert-secondary mb-0" id="signinLocked">
                        @if($authRefused)
                            The server sets <code>NOVARR_AUTH=on</code>, but sign-in stays off until the owner account below has a password.
                        @else
                            Locked {{ $authForced ? 'on' : 'off' }} by <code>NOVARR_AUTH={{ $authForced ? 'on' : 'off' }}</code> on the server. Change it there.
                        @endif
                    </div>
                @elseif(!$authOn && $cannotTurnOn)
                    <div class="alert alert-secondary mb-0" id="signinLocked">{{ $cannotTurnOn }} Use “Set password” on your account below.</div>
                @else
                    <form method="POST" action="{{ route('settings.users.auth') }}" data-turbo="false">
                        @csrf
                        <input type="hidden" name="required" value="{{ $authOn ? '0' : '1' }}">
                        <button type="submit" class="btn {{ $authOn ? 'btn-secondary' : 'btn-primary' }}" id="signinToggle">
                            {{ $authOn ? 'Turn sign-in off' : 'Turn sign-in on' }}
                        </button>
                        @unless($authOn)
                            <span class="form-text ms-2">You stay signed in on this browser.</span>
                        @endunless
                    </form>
                @endif
            </div>
        </section>

        {{-- ================= Accounts ================= --}}
        <section class="card form-panel mb-4" aria-labelledby="usersTitle">
            <div class="card-body">
                <h2 class="panel-title" id="usersTitle">Accounts</h2>
                <ul class="users-list">
                    @foreach($users as $u)
                        @php
                            $isOwner = (int) $u->id === (int) $ownerId;
                            $editBag = $errors->getBag('edit' . $u->id);
                            $passBag = $errors->getBag('password' . $u->id);
                        @endphp
                        <li class="users-row {{ $u->isDisabled() ? 'is-disabled' : '' }}" id="user-{{ $u->id }}">
                            <div class="users-row-main">
                                <span class="users-name">{{ $u->name }}</span>
                                <span class="users-meta">
                                    <span class="users-username">{{ $u->username }}</span>
                                    · {{ $isOwner ? 'Owner (admin)' : ($u->isAdmin() ? 'Admin' : 'Reader') }}
                                    · {{ $u->last_seen_at ? 'seen ' . $u->last_seen_at->diffForHumans() : 'never seen' }}
                                    @if(($tokenCounts[$u->id] ?? 0) > 0)
                                        · {{ $tokenCounts[$u->id] }} {{ Str::plural('device', (int) $tokenCounts[$u->id]) }}
                                    @endif
                                    @if($u->isDisabled())
                                        · <strong class="users-off">Turned off</strong>
                                    @endif
                                    @if($u->password_set_at === null)
                                        · <strong class="users-off">No password yet</strong>
                                    @endif
                                </span>
                            </div>

                            <div class="users-actions">
                                <details class="users-detail" @if($editBag->any()) open @endif>
                                    <summary class="btn btn-secondary btn-sm">Edit</summary>
                                    <form method="POST" action="{{ route('settings.users.update', $u->id) }}" class="users-form">
                                        @csrf
                                        @method('PUT')
                                        <div class="form-row">
                                            <label class="form-label" for="name-{{ $u->id }}">Display name</label>
                                            <input type="text" name="name" id="name-{{ $u->id }}" class="form-control @if($editBag->has('name')) is-invalid @endif" value="{{ $editBag->any() ? old('name', $u->name) : $u->name }}" maxlength="80" required>
                                            @if($editBag->has('name'))<div class="invalid-feedback">{{ $editBag->first('name') }}</div>@endif
                                        </div>
                                        <div class="form-row">
                                            <label class="form-label" for="username-{{ $u->id }}">Sign-in name</label>
                                            <input type="text" name="username" id="username-{{ $u->id }}" class="form-control @if($editBag->has('username')) is-invalid @endif" value="{{ $editBag->any() ? old('username', $u->username) : $u->username }}" maxlength="60" autocapitalize="none" spellcheck="false" required>
                                            @if($editBag->has('username'))<div class="invalid-feedback">{{ $editBag->first('username') }}</div>@endif
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                    </form>
                                </details>

                                <details class="users-detail" @if($passBag->any()) open @endif>
                                    <summary class="btn btn-secondary btn-sm">{{ $u->password_set_at === null ? 'Set password' : ($isOwner ? 'Change password' : 'Reset password') }}</summary>
                                    <form method="POST" action="{{ route('settings.users.password', $u->id) }}" class="users-form">
                                        @csrf
                                        <input type="text" name="username" value="{{ $u->username }}" autocomplete="username" hidden readonly>
                                        <div class="form-row">
                                            <label class="form-label" for="password-{{ $u->id }}">New password</label>
                                            <input type="password" name="password" id="password-{{ $u->id }}" class="form-control @if($passBag->has('password')) is-invalid @endif" autocomplete="new-password" minlength="8" required>
                                            @if($passBag->has('password'))<div class="invalid-feedback">{{ $passBag->first('password') }}</div>@endif
                                            <div class="form-text">At least 8 characters.{{ $isOwner ? '' : ' Tell ' . $u->name . ' the new password; they can change it on their Account page.' }}</div>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Save password</button>
                                    </form>
                                </details>

                                @unless($isOwner)
                                    <form method="POST" action="{{ route('settings.users.disable', $u->id) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-sm">{{ $u->isDisabled() ? 'Turn on' : 'Turn off' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('settings.users.destroy', $u->id) }}" class="d-inline"
                                          data-confirm="Delete {{ $u->name }}? Their reading progress, highlights and stats are deleted too. This cannot be undone.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                @endunless
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        {{-- ================= Add an account ================= --}}
        @php $addBag = $errors->getBag('add'); @endphp
        <section class="card form-panel mb-4" aria-labelledby="addTitle" id="add">
            <div class="card-body">
                <h2 class="panel-title" id="addTitle">Add a reader</h2>
                <p class="form-text mt-0">Readers can read everything and keep their own progress, highlights and stats. They cannot add, edit or delete series or change settings.</p>
                <form method="POST" action="{{ route('settings.users.store') }}">
                    @csrf
                    <div class="form-row">
                        <label class="form-label" for="add-name">Display name</label>
                        <input type="text" name="name" id="add-name" class="form-control @if($addBag->has('name')) is-invalid @endif" value="{{ $addBag->any() ? old('name') : '' }}" maxlength="80" required>
                        @if($addBag->has('name'))<div class="invalid-feedback">{{ $addBag->first('name') }}</div>@endif
                    </div>
                    <div class="form-row">
                        <label class="form-label" for="add-username">Sign-in name</label>
                        <input type="text" name="username" id="add-username" class="form-control @if($addBag->has('username')) is-invalid @endif" value="{{ $addBag->any() ? old('username') : '' }}" maxlength="60" autocapitalize="none" spellcheck="false" autocomplete="off" required>
                        @if($addBag->has('username'))<div class="invalid-feedback">{{ $addBag->first('username') }}</div>@endif
                        <div class="form-text">What they type to sign in, e.g. “sam”. Not case-sensitive.</div>
                    </div>
                    <div class="form-row">
                        <label class="form-label" for="add-password">First password</label>
                        <input type="password" name="password" id="add-password" class="form-control @if($addBag->has('password')) is-invalid @endif" autocomplete="new-password" minlength="8" required>
                        @if($addBag->has('password'))<div class="invalid-feedback">{{ $addBag->first('password') }}</div>@endif
                        <div class="form-text">At least 8 characters. They can change it on their Account page.</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Add reader</button>
                </form>
            </div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // Delete asks first (a styled dialog; without JS the form just submits).
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            if (form.dataset.confirmed === '1' || !window.Novarr?.confirmDialog) return;
            e.preventDefault();
            const ok = await window.Novarr.confirmDialog(form.dataset.confirm, { title: 'Delete account?', confirmText: 'Delete', danger: true });
            if (ok) {
                form.dataset.confirmed = '1';
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }
        });
    });
})();
</script>
@endpush
