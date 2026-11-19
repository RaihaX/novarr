@extends('layouts.app')

@section('title', 'Users')

@section('breadcrumb')
    <li><a href="{{ route('settings.index') }}">Settings</a></li>
    <li aria-current="page">Users</li>
@endsection

@php
    use App\Http\Controllers\UserInviteController;
    $inviteBag = $errors->getBag('invite');
    $addBag = $errors->getBag('add');
    $result = session(UserInviteController::FLASH);
    $duplicateId = session(UserInviteController::FLASH_DUPLICATE);
    $tz = config('app.timezone');
@endphp

@section('content')
<div class="acct-shell users-page">
    <header class="acct-head">
        <h1 class="page-title">Users</h1>
    </header>

    {{-- ================= Invite made / resent: the link, once ================= --}}
    @if($result)
        <section class="invite-result {{ $result['sent'] ? 'is-sent' : 'is-unsent' }}" role="status" id="inviteResult" aria-labelledby="inviteResultTitle">
            <h2 class="invite-result-title" id="inviteResultTitle">
                @if($result['sent'])
                    Invite sent to {{ $result['email'] }}
                @else
                    {{ $result['verb'] }} {{ $result['name'] }}, but the email was not sent
                @endif
            </h2>
            <p class="invite-result-text">
                @if($result['sent'])
                    {{ $result['name'] }} can open the link in the email to choose a sign-in name and password. You can also send them this link another way:
                @else
                    {{ $result['error'] }} Send {{ $result['name'] }} this link yourself (a message or chat works):
                @endif
            </p>
            <div class="invite-link-row">
                <input type="text" class="form-control invite-link" id="inviteLink" value="{{ $result['link'] }}" readonly aria-label="Invite link for {{ $result['name'] }}" data-select-on-focus>
                <button type="button" class="btn btn-primary" data-copy-target="#inviteLink" data-copied-text="Copied" hidden>Copy link</button>
            </div>
            <p class="invite-result-note">The link works for {{ \App\UserInvite::TTL_DAYS }} days. It is shown only now; to get a new one later, use Resend.</p>
        </section>
    @endif

    <div class="acct-grid acct-grid-wide-main">
        {{-- ============ Main: sign-in, accounts, pending invites ============ --}}
        <div class="acct-col">
            <section class="card form-panel acct-card" aria-labelledby="signinTitle" id="signin">
                <div class="card-body">
                    <h2 class="acct-card-title" id="signinTitle">Require sign-in</h2>
                    <p class="acct-text">
                        Sign-in is <strong id="signinState">{{ $authOn ? 'on' : 'off' }}</strong>.
                        @if($authOn)
                            Everyone signs in with their own name and password and keeps their own reading progress, highlights and stats. Readers can read, but cannot add, edit or delete series or change settings.
                        @else
                            Anyone who can reach Novarr uses it as you (the owner), with no sign-in. You can add accounts now and turn sign-in on when they are ready.
                        @endif
                    </p>
                    @unless($authOn)
                        <p class="form-text">When it is on: everyone must sign in on the web; the iOS app must sign in again, and each Kindle must be linked again with a code (Account → Link a device).</p>
                    @endunless

                    @if($authForced !== null)
                        <div class="alert alert-secondary mb-0 acct-actions" id="signinLocked">
                            @if($authRefused)
                                The server sets <code>NOVARR_AUTH=on</code>, but sign-in stays off until the owner account below has a password.
                            @else
                                Locked {{ $authForced ? 'on' : 'off' }} by <code>NOVARR_AUTH={{ $authForced ? 'on' : 'off' }}</code> on the server. Change it there.
                            @endif
                        </div>
                    @elseif(!$authOn && $cannotTurnOn)
                        <div class="alert alert-secondary mb-0 acct-actions" id="signinLocked">{{ $cannotTurnOn }} Use “Set password” on your account below.</div>
                    @else
                        <form method="POST" action="{{ route('settings.users.auth') }}" data-turbo="false" class="acct-actions">
                            @csrf
                            <input type="hidden" name="required" value="{{ $authOn ? '0' : '1' }}">
                            <button type="submit" class="btn {{ $authOn ? 'btn-secondary' : 'btn-primary' }}" id="signinToggle">
                                {{ $authOn ? 'Turn sign-in off' : 'Turn sign-in on' }}
                            </button>
                            @unless($authOn)
                                <span class="form-text m-0">You stay signed in on this browser.</span>
                            @endunless
                        </form>
                    @endif
                </div>
            </section>

            <section class="card form-panel acct-card" aria-labelledby="usersTitle" id="accounts">
                <div class="card-body">
                    <h2 class="acct-card-title" id="usersTitle">Accounts <span class="acct-count">{{ $users->count() }}</span></h2>
                    <ul class="users-list">
                        @foreach($users as $u)
                            @php
                                $isOwner = (int) $u->id === (int) $ownerId;
                                $editBag = $errors->getBag('edit' . $u->id);
                                $passBag = $errors->getBag('password' . $u->id);
                            @endphp
                            <li class="users-row {{ $u->isDisabled() ? 'is-disabled' : '' }}" id="user-{{ $u->id }}">
                                <div class="users-row-main">
                                    <span class="users-name">
                                        {{ $u->name }}
                                        <span class="badge {{ $u->isAdmin() ? 'badge-accent' : 'badge-muted' }}">{{ $isOwner ? 'Owner' : ($u->isAdmin() ? 'Admin' : 'Reader') }}</span>
                                    </span>
                                    <span class="users-meta">
                                        <span class="users-username">{{ $u->username }}</span>
                                        @if($u->email)
                                            · <span class="users-email">{{ $u->email }}</span>
                                        @endif
                                    </span>
                                    <span class="users-meta">
                                        {{ $u->last_seen_at ? 'Seen ' . $u->last_seen_at->diffForHumans() : 'Never seen' }}
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
                                            <div class="acct-actions">
                                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                            </div>
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
                                            <div class="acct-actions">
                                                <button type="submit" class="btn btn-primary btn-sm">Save password</button>
                                            </div>
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

            <section class="card form-panel acct-card" aria-labelledby="invitesTitle" id="invites">
                <div class="card-body">
                    <h2 class="acct-card-title" id="invitesTitle">Pending invites <span class="acct-count">{{ $invites->count() }}</span></h2>
                    @if($invites->isEmpty())
                        <div class="acct-empty">
                            <x-icon name="users" :size="18" />
                            <p>No invites waiting. Invites you send show here until they are accepted.</p>
                        </div>
                    @else
                        <ul class="users-list">
                            @foreach($invites as $invite)
                                <li class="users-row {{ $invite->isExpired() ? 'is-disabled' : '' }} {{ (int) $duplicateId === (int) $invite->id ? 'is-highlighted' : '' }}" id="invite-{{ $invite->id }}">
                                    <div class="users-row-main">
                                        <span class="users-name">{{ $invite->name }}</span>
                                        <span class="users-meta"><span class="users-email">{{ $invite->email }}</span></span>
                                        <span class="users-meta">
                                            Sent {{ $invite->sentAt()?->timezone($tz)->format('j M Y') }}
                                            ·
                                            @if($invite->isExpired())
                                                <strong class="users-off">Expired {{ $invite->expires_at->diffForHumans() }}</strong>
                                            @else
                                                expires {{ $invite->expires_at->timezone($tz)->format('j M Y') }}
                                            @endif
                                        </span>
                                    </div>
                                    <div class="users-actions">
                                        <form method="POST" action="{{ route('settings.users.invites.resend', $invite->id) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn {{ (int) $duplicateId === (int) $invite->id ? 'btn-primary' : 'btn-secondary' }} btn-sm" aria-label="Resend the invite to {{ $invite->name }} (makes a new link)">Resend</button>
                                        </form>
                                        <form method="POST" action="{{ route('settings.users.invites.destroy', $invite->id) }}" class="d-inline"
                                              data-confirm="Cancel the invite for {{ $invite->name }}? The link stops working." data-confirm-title="Cancel invite?" data-confirm-text="Cancel invite">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Cancel the invite for {{ $invite->name }}">Cancel</button>
                                        </form>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        </div>

        {{-- ============ Aside: invite a reader ============ --}}
        <div class="acct-col">
            <section class="card form-panel acct-card" aria-labelledby="inviteTitle" id="invite">
                <div class="card-body">
                    <h2 class="acct-card-title" id="inviteTitle">Invite a reader</h2>
                    <p class="acct-text">They get an email with a link, then choose their own sign-in name and password. Readers can read everything and keep their own progress, highlights and stats; they cannot change series or settings.</p>
                    <form method="POST" action="{{ route('settings.users.invites.store') }}">
                        @csrf
                        <div class="form-row">
                            <label class="form-label" for="invite-name">Name</label>
                            <input type="text" name="name" id="invite-name" class="form-control @if($inviteBag->has('name')) is-invalid @endif" value="{{ $inviteBag->any() ? old('name') : '' }}" maxlength="80" autocomplete="off" required>
                            @if($inviteBag->has('name'))<div class="invalid-feedback">{{ $inviteBag->first('name') }}</div>@endif
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="invite-email">Email</label>
                            <input type="email" name="email" id="invite-email" class="form-control @if($inviteBag->has('email')) is-invalid @endif" value="{{ $inviteBag->any() ? old('email') : '' }}" maxlength="255" autocomplete="off" autocapitalize="none" spellcheck="false" required>
                            @if($inviteBag->has('email'))
                                <div class="invalid-feedback" id="inviteEmailError">{{ $inviteBag->first('email') }}
                                    @if($duplicateId)<a href="#invite-{{ $duplicateId }}">Go to the invite</a>@endif
                                </div>
                            @endif
                        </div>
                        <div class="acct-actions">
                            <button type="submit" class="btn btn-primary">Send invite</button>
                        </div>
                        <p class="form-text acct-footnote" id="inviteOriginNote">The link uses this address ({{ request()->getSchemeAndHttpHost() }}), so send invites while browsing Novarr at the address your readers will use.</p>
                    </form>

                    {{-- The old way, kept but secondary: the admin types a first password. --}}
                    <details class="acct-disclosure" id="add" @if($addBag->any()) open @endif>
                        <summary>Add without an invite</summary>
                        <p class="form-text">Make the account yourself and tell them the password.</p>
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
                            <div class="acct-actions">
                                <button type="submit" class="btn btn-primary">Add reader</button>
                            </div>
                        </form>
                    </details>
                </div>
            </section>

            <p class="acct-aside-note" id="androidStatus">
                @if(\App\Support\AndroidApp::available())
                    <x-icon name="download" :size="14" />
                    Android app{{ ($v = \App\Support\AndroidApp::version()) ? ' (version ' . $v . ')' : '' }} is available to readers: invite emails link to it.
                @else
                    No Android app to offer readers. To offer one, put the APK at <code>{{ \App\Support\AndroidApp::path() }}</code>.
                @endif
            </p>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // Delete / cancel ask first (a styled dialog; without JS the form just submits).
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            if (form.dataset.confirmed === '1' || !window.Novarr?.confirmDialog) return;
            e.preventDefault();
            const ok = await window.Novarr.confirmDialog(form.dataset.confirm, {
                title: form.dataset.confirmTitle || 'Delete account?',
                confirmText: form.dataset.confirmText || 'Delete',
                danger: true,
            });
            if (ok) {
                form.dataset.confirmed = '1';
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }
        });
    });
})();
</script>
@endpush
